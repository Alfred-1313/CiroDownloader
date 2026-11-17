<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function cmCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class CheckerMenuApi implements Zero\Api
{
    public array $calls=[]; public int $next=300;
    public function call(string $method,array $params=[]): mixed
    {
        $this->calls[]=[$method,$params];
        if(isset($params['text'])&&$method!=='answerCallbackQuery') Zero\TextFormat::validate($params['text'],4096);
        if($method==='getChatMember') return ['status'=>'administrator'];
        return $method==='sendMessage'?['message_id'=>++$this->next]:true;
    }
    public function last(): array { return end($this->calls); }
    public function button(string $suffix): string
    {
        foreach(array_reverse($this->calls) as [$method,$params]) foreach($params['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button)
            if(str_ends_with($button['callback_data']??'',$suffix)) return $button['callback_data'];
        throw new RuntimeException('Missing button '.$suffix);
    }
}
$root=sys_get_temp_dir().'/ciro-checker-menu-'.bin2hex(random_bytes(5));
try {
    $c=new Zero\Config('123:fixture',[42,55],$root);$s=new Zero\Store($root);$a=new CheckerMenuApi();$b=new Zero\Bot($c,$s,$a);$serial=100;
    $s->set('bot_username','FixtureBot');$s->set('youtube.checker.enabled','1');$s->set('youtube.checker.public','1');$s->set('instagram.checker.public','1');
    $send=function(string $text,int $owner=42,int $chat=-100,int $topic=17)use($b,&$serial):void {
        $b->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$owner],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$topic,'text'=>$text]]);
    };
    $tap=function(string $data,int $owner=42,int $chat=-100,?int $mid=null,?int $topic=17)use($b,$a,&$serial):void {
        $message=['message_id'=>$mid??$a->next,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']];if($topic!==null)$message['message_thread_id']=$topic;
        $b->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'cm'.$serial,'from'=>['id'=>$owner],'message'=>$message,'data'=>$data]]);
    };
    $send('/checker@AnotherBot');cmCheck(!$a->calls,'a hub command addressed to another bot is ignored');
    $send('/checker@FixtureBot');[$method,$p]=$a->last();$mid=$a->next;$youtube=$a->button(':youtube');$instagram=$a->button(':instagram');
    cmCheck($method==='sendMessage'&&($p['message_thread_id']??0)===17&&count(array_merge(...$p['reply_markup']['inline_keyboard']))===2,'one shared command sends a caption and exactly the two registered checker choices in the original topic');
    cmCheck((int)$s->query('SELECT COUNT(*) FROM yt_check_subs')->fetchColumn()===0&&(int)$s->query('SELECT COUNT(*) FROM ig_check_subs')->fetchColumn()===0,'opening the hub never registers a target');
    foreach([[55,-100,$mid,17],[42,-100,$mid+1,17],[42,-100,$mid,18],[42,-200,$mid,17]] as [$owner,$chat,$message,$topic]) {
        $before=count($a->calls);$tap($youtube,$owner,$chat,$message,$topic);
        cmCheck(count($a->calls)===$before+1&&$a->last()[0]==='answerCallbackQuery'&&$a->last()[1]['show_alert'],'another administrator or copied chat/message/topic gets only an alert');
    }
    $tap($youtube,42,-100,$mid,null);
    cmCheck($a->last()[0]==='editMessageText'&&(int)$a->last()[1]['message_id']===$mid&&str_contains($a->last()[1]['text'],'چکر یوتیوب'),'the requester opens YouTube on the same message and recovers an omitted topic');
    $panel=$s->query('SELECT * FROM yt_check_panels WHERE message_id=?',[$mid])->fetch();
    cmCheck((int)$panel['thread_id']===17,'the selected panel keeps the stored topic');
    $before=count($a->calls);$tap($instagram,42,-100,$mid);cmCheck(count($a->calls)===$before+1&&$a->last()[1]['show_alert'],'an old hub button cannot overwrite the selected checker panel');
    $back=$a->button(':hub');$tap($back,42,-100,$mid);$tap($a->button(':instagram'),42,-100,$mid);
    cmCheck(str_contains($a->last()[1]['text'],'چکر اینستاگرام')&&(int)$a->last()[1]['message_id']===$mid,'back to all checkers can open Instagram on the same message');
    $before=count($a->calls);$tap($back,42,-100,$mid);cmCheck(count($a->calls)===$before+1&&$a->last()[1]['show_alert'],'a retired service panel cannot replace a newer panel');
    $send('/checker',7,7,0);$private=$a->next;$tap($a->button(':youtube'),7,7,$private,0);
    cmCheck(str_contains($a->last()[1]['text'],'چکر یوتیوب'),'public users can select their private YouTube checker');
    $s->set('instagram.checker.public','0');Zero\MusicSearch::register($a,$s,$b->ui);$menu=[];
    foreach(array_reverse($a->calls) as [$method,$params]) if($method==='setMyCommands') {$menu=array_column($params['commands'],'command');break;}
    cmCheck(in_array('checker',$menu,true)&&in_array('youtube_checker',$menu,true)&&!in_array('insta_checker',$menu,true),'the shared command is registered when YouTube alone is public');
    $s->set('youtube.checker.public','0');$send('/checker',7,7,0);$hubId=$a->next;$tap($a->button(':youtube'),7,7,$hubId,0);
    cmCheck(str_contains($a->last()[1]['text'],$b->ui->plain('youtube_checker_denied')),'selection preserves the chosen checker access restriction');
    $s->set('instagram.checker.public','1');$tap($a->button(':instagram'),7,7,$hubId,0);
    cmCheck($a->last()[0]==='editMessageText'&&str_contains($a->last()[1]['text'],'چکر اینستاگرام'),'a denied choice keeps the hub available for another permitted checker');
    $send('/customize',42,42,0);$tap('custom:category:general',42,42,$a->next,0);
    $tap($a->button('custom:section:checkers'),42,42,$a->next,0);
    foreach(['checker_menu','command_checker'] as $key) cmCheck($a->button('custom:item:text:'.$key)!=='','shared hub text is reachable in Personalization: '.$key);
    foreach(['checker','checker_menu_youtube','checker_menu_instagram','checker_menu_back'] as $key) cmCheck($a->button('custom:item:button:'.$key)!=='','shared hub button is reachable in Personalization: '.$key);
} finally { Zero\Download::clean($root); }
