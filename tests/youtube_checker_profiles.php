<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function ypCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class CheckerProfileApi implements Zero\Api
{
    public array $calls=[],$types=[]; public int $next=400; public bool $reject=false; public array $missing=[];
    public function call(string $method,array $p=[]): mixed
    {
        $this->calls[]=[$method,$p];$id=(int)($p['message_id']??0);
        if($method==='getChatMember') return ['status'=>'administrator'];
        if($id&&in_array($id,$this->missing,true)) throw new RuntimeException('Missing fixture message',404);
        $caption=$p['caption']??$p['media']['caption']??null;if($caption!==null) Zero\TextFormat::validate($caption,1024);
        if(in_array($method,['sendMessage','sendPhoto'],true)) {$id=++$this->next;$this->types[$id]=$method==='sendPhoto'?'photo':'text';}
        if($method==='editMessageText'&&($this->types[$id]??'text')==='photo') throw new RuntimeException('Cannot edit a photo as text',400);
        if($method==='editMessageCaption'&&($this->types[$id]??'')!=='photo') throw new RuntimeException('Missing photo caption',400);
        if($method==='editMessageMedia'||$method==='sendPhoto') {
            if($this->reject) throw new RuntimeException('Fixture image refusal',400);
            $this->types[$id]='photo';return ['message_id'=>$id,'photo'=>[['file_id'=>'profile-file-'.$id]]];
        }
        if($method==='sendMessage') return ['message_id'=>$id];
        if($method==='deleteMessage') unset($this->types[$id]);
        return true;
    }
    public function last(): array { return end($this->calls)?:[]; }
}
$root=sys_get_temp_dir().'/ciro-checker-profiles-'.bin2hex(random_bytes(5));
try {
    $c=new Zero\Config('123:fixture',[42,55],$root);$s=new Zero\Store($root);$a=new CheckerProfileApi();$b=new Zero\Bot($c,$s,$a);$m=new Zero\YoutubeChecker($c,$s,$a);$panel=new Zero\YoutubeCheckerPanel($b);
    $s->set('youtube.checker.enabled','1');$s->set('bot_id','123');$s->set('bot_username','FixtureBot');$serial=100;
    $send=function(string $text,int $owner=42,int $chat=-100,int $topic=17,?int $reply=null)use($b,&$serial):void {
        $message=['message_id'=>++$serial,'from'=>['id'=>$owner],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$topic,'text'=>$text];
        if($reply)$message['reply_to_message']=['message_id'=>$reply,'from'=>['id'=>123,'is_bot'=>true]];
        $b->handle(['update_id'=>$serial,'message'=>$message]);
    };
    $current=function()use($s):array{return $s->query('SELECT * FROM yt_check_panels WHERE owner=42 AND chat_id=-100 ORDER BY rowid DESC LIMIT 1')->fetch();};
    $tap=function(string $op,?int $id=null,int $owner=42,?array $p=null,?int $topic=17)use($b,$current,&$serial):void {
        $p??=$current();$message=['message_id'=>(int)$p['message_id'],'chat'=>['id'=>-100,'type'=>'supergroup']];if($topic!==null)$message['message_thread_id']=$topic;
        $b->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'yp'.$serial,'from'=>['id'=>$owner],'message'=>$message,'data'=>'yc:'.$p['token'].':'.$op.($id===null?'':':'.$id)]]);
    };
    $send('/youtube_checker @fixture');$tap('save',7);$p=$current();$data=json_decode($p['data'],true);$id=(int)$data['id'];$mid=(int)$p['message_id'];
    ypCheck($p['view']==='item'&&$p['photo']===''&&$a->types[$mid]==='text','adding a channel opens its usable details immediately while the background scan is pending');
    $image='https://yt3.googleusercontent.com/channel-profile';
    $snapshot=['account_id'=>'UC'.str_repeat('A',22),'mode'=>7,'videos'=>[],'shorts'=>[],'posts'=>[],'avatar'=>$image];
    $m->poll(fn()=>$snapshot);ypCheck($m->checksReady(),'a completed channel scan schedules the open details photo');$before=count($a->calls);$panel->refreshProfiles();$p=$current();
    ypCheck(count($a->calls)===$before+1&&$a->last()[0]==='editMessageMedia'&&(int)$a->last()[1]['message_id']===$mid&&$a->last()[1]['media']['media']===$image,'the first scan adds the verified channel avatar to the same group/topic panel without a new message');
    ypCheck($p['photo']==='profile-file-'.$mid&&str_contains($a->last()[1]['media']['caption'],'چکر @fixture')&&count($a->last()[1]['reply_markup']['inline_keyboard'])>1,'the channel caption and management buttons remain below the photo and its confirmed file ID is saved');
    ypCheck(!$m->profilePanels()&&(int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'photo enrichment neither repeats itself nor replays existing channel media');
    $panel->open(42,42,0,0,'item',['id'=>$id]);
    ypCheck($a->last()[0]==='sendPhoto'&&$a->last()[1]['chat_id']===42&&!isset($a->last()[1]['message_thread_id']),'a new private details panel can send the cached profile as a photo without the group topic');
    $before=count($a->calls);$tap('pause',$id,55);$tap('pause',$id,42,null,18);
    ypCheck(count($a->calls)===$before+2&&$a->last()[0]==='answerCallbackQuery'&&$a->last()[1]['show_alert'],'another administrator and a copied topic cannot mutate a photo panel');
    $tap('mode',$id,42,null,null);ypCheck($a->last()[0]==='editMessageCaption'&&$current()['photo']!=='','mode selection keeps the picture and recovers omitted topic metadata');$tap('item',$id);
    $tap('check',$id);$m->poll(fn()=>$snapshot);$panel->refreshChecks();
    ypCheck($a->last()[0]==='editMessageCaption'&&str_contains($a->last()[1]['caption'],'بررسی تازه موفق بود'),'manual verification updates the same photo caption with its real result');
    $tap('home');$home=$current();
    ypCheck((int)$home['message_id']!==$mid&&$home['photo']===''&&!isset($a->types[$mid])&&$a->types[(int)$home['message_id']]==='text','returning to the channel list confirms a text replacement and retires the photo panel');
    $before=count($a->calls);$tap('pause',$id,42,$p);ypCheck(count($a->calls)===$before+1&&$a->last()[1]['show_alert'],'buttons copied from a replaced photo panel are stale');
    $tap('item',$id);ypCheck($a->last()[0]==='editMessageMedia'&&str_starts_with($a->last()[1]['media']['media'],'profile-file-'),'reopening details reuses the confirmed Telegram profile photo without another source request');
    $old=$current();$tap('rename',$id);$request=json_decode($current()['data'],true);$reply=(int)$request['input_message'];$send('@replacement',42,-100,17,$reply);$renamed=$current();
    ypCheck($renamed['photo']===''&&(int)$renamed['message_id']!==(int)$old['message_id']&&!isset($a->types[(int)$old['message_id']]),'changing the channel removes the previous channel photo while the replacement is being resolved');
    $snapshot['account_id']='UC'.str_repeat('B',22);$snapshot['avatar']='https://yt3.ggpht.com/replacement-profile';$m->poll(fn()=>$snapshot);$panel->refreshProfiles();
    ypCheck($a->last()[0]==='editMessageMedia'&&$a->last()[1]['media']['media']===$snapshot['avatar']&&str_contains($a->last()[1]['media']['caption'],'@replacement'),'the replacement account receives only its own scanned profile image');
    // A late scan cannot turn a list or closed menu back into an account panel.
    $tap('home');$s->query('UPDATE yt_check_targets SET next_check=0 WHERE id=(SELECT target FROM yt_check_subs WHERE id=?)',[$id]);$snapshot['avatar']='https://yt3.ggpht.com/profile-v2';$m->poll(fn()=>$snapshot);$before=count($a->calls);$panel->refreshProfiles();
    ypCheck(count($a->calls)===$before,'a scan finishing after navigation cannot overwrite the list');
    $a->reject=true;$tap('item',$id);$rejected=$current();$a->reject=false;
    ypCheck($rejected['photo']===''&&$a->types[(int)$rejected['message_id']]==='text'&&!$m->profilePanels(),'a rejected avatar leaves text and buttons usable without automatic retry messages');
    $tap('item',$id);$p=$current();
    $s->query('UPDATE yt_check_targets SET next_check=0 WHERE id=(SELECT target FROM yt_check_subs WHERE id=?)',[$id]);$snapshot['avatar']='';$m->poll(fn()=>$snapshot);$panel->refreshProfiles();
    ypCheck($a->last()[0]==='editMessageCaption'&&(int)$current()['message_id']===(int)$p['message_id'],'temporarily missing optional metadata keeps the same-channel photo without posting a replacement notification');
    $a->missing[]=(int)$p['message_id'];
    $s->query('UPDATE yt_check_targets SET next_check=0 WHERE id=(SELECT target FROM yt_check_subs WHERE id=?)',[$id]);$snapshot['avatar']='https://yt3.ggpht.com/profile-v3';$m->poll(fn()=>$snapshot);$beforeSends=count(array_filter($a->calls,fn($call)=>str_starts_with($call[0],'send')));$panel->refreshProfiles();
    ypCheck(count(array_filter($a->calls,fn($call)=>str_starts_with($call[0],'send')))===$beforeSends&&!$m->profilePanels(),'a deleted details message is never recreated by an automatic profile refresh');$a->missing=[];
    $tap('close');$before=count($a->calls);$panel->refreshProfiles();ypCheck(count($a->calls)===$before,'closing the panel prevents later profile edits');
    // Optional images cannot corrupt checker state or cross service boundaries.
    foreach(['https://evil.test/a','http://yt3.ggpht.com/a','https://user@yt3.ggpht.com/a','https://yt3.ggpht.com:443/a']as$url)ypCheck(!Zero\YoutubeChecker::avatarUrl($url),'untrusted avatar address is rejected');
    ypCheck((int)$s->query("SELECT COUNT(*) FROM ig_check_targets WHERE avatar<>''")->fetchColumn()===0,'Instagram checker pictures remain independent');
    $sub=$m->owned($id,42);$generation=(int)$sub['generation'];
    $s->query("UPDATE yt_check_targets SET avatar_version=0,failures=1,state='blocked',next_check=? WHERE id=?",[time()+900,$sub['target']]);$next=(int)$s->query('SELECT next_check FROM yt_check_targets WHERE id=?',[$sub['target']])->fetchColumn();$m->profile($sub);
    ypCheck((int)$s->query('SELECT next_check FROM yt_check_targets WHERE id=?',[$sub['target']])->fetchColumn()===$next,'opening details for an uncached avatar cannot bypass a rate-limit backoff');
    foreach(['avatar','avatar_file','avatar_version']as$column)$s->db->exec('ALTER TABLE yt_check_targets DROP COLUMN '.$column);
    foreach(['photo','photo_source']as$column)$s->db->exec('ALTER TABLE yt_check_panels DROP COLUMN '.$column);
    $reopened=new Zero\Store($root);$upgraded=new Zero\YoutubeChecker($c,$reopened,$a);
    ypCheck((int)$upgraded->owned($id,42)['generation']===$generation&&$upgraded->used(42)===1&&in_array('photo',array_column($reopened->query('PRAGMA table_info(yt_check_panels)')->fetchAll(),'name'),true),'existing YouTube subscriptions migrate without changing ownership, quota, modes or generation');
} finally { Zero\Download::clean($root); }
