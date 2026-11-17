<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';require dirname(__DIR__).'/src/Bot.php';require dirname(__DIR__).'/src/Download.php';require dirname(__DIR__).'/src/Worker.php';
function fjCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class JoinApi implements Zero\Api
{
    public array $calls=[],$members=[],$admins=[]; public int $mid=100; public bool $fail=false,$failSticker=false;
    public function call(string $m,array $p=[]): mixed {
        $this->calls[]=[$m,$p];
        if(in_array($m,['sendMessage','editMessageText'],true))Zero\TextFormat::validate($p['text'],4096);
        if(isset($p['caption']))Zero\TextFormat::validate($p['caption'],1024);
        if($m==='getChat')return ['id'=>-10010,'type'=>'channel','title'=>'Fixture Channel','username'=>'fixture'];
        if($m==='getMe')return ['id'=>123,'username'=>'FixtureBot'];
        if($m==='getChatAdministrators')return $this->admins[$p['chat_id']]??[];
        if($m==='getChatMember') { if($this->fail)throw new RuntimeException('Fixture API refusal',429); return $this->members[$p['chat_id']][$p['user_id']]??['status'=>'left']; }
        if($m==='sendSticker'&&$this->failSticker)throw new RuntimeException('Fixture premium refusal',400);
        return in_array($m,['sendMessage','sendSticker'],true)?['message_id'=>++$this->mid]:true;
    }
    public function last(): array { foreach(array_reverse($this->calls) as [$m,$p])if(in_array($m,['sendMessage','editMessageText','editMessageCaption'],true))return $p;return []; }
}
$root=sys_get_temp_dir().'/ciro-force-join-'.bin2hex(random_bytes(5));mkdir($root,0700);
try {
    $c=new Zero\Config('123:fixture',[42],$root);$s=new Zero\Store($root);$a=new JoinApi();$b=new Zero\Bot($c,$s,$a);$serial=0;$f=$b->membership();$s->set('bot_username','FixtureBot');
    $send=function(string $text,int $user=7,int $chat=7,int $thread=0,?array $sticker=null)use($b,&$serial):void{
        $m=['message_id'=>++$serial,'from'=>['id'=>$user],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread,'text'=>$text];if($sticker)$m['sticker']=$sticker;$b->handle(['update_id'=>$serial,'message'=>$m]);
    };
    $panel=fn(string $kind='user',int $user=7)=>$s->query('SELECT * FROM membership_panels WHERE owner=? AND kind=? ORDER BY rowid DESC LIMIT 1',[$user,$kind])->fetch();
    $tap=function(array $p,string $op='check',int $user=7,?int $thread=null)use($b,&$serial):void{
        $m=['message_id'=>$p['message_id'],'chat'=>['id'=>$p['chat_id'],'type'=>$p['chat_id']<0?'supergroup':'private']];if($thread!==null)$m['message_thread_id']=$thread;
        $b->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'fj'.$serial,'from'=>['id'=>$user],'message'=>$m,'data'=>'fj:'.$p['token'].':'.$op]]);
    };
    fjCheck($f->verdict(7,7)==='ok'&&count($a->calls)===0,'a disabled membership filter performs no Telegram lookups');
    $a->members[-10010][123]=['status'=>'administrator'];$send($b->label('membership_settings'),42,42);$admin=$panel('admin',42);$tap($admin,'add',42);$send('@fixture',42,42);
    fjCheck(count($f->channels())===1&&!$f->active(),'adding an administrator-verified channel does not silently activate the filter');$tap($panel('admin',42),'toggle',42);
    fjCheck($f->active(),'the private administrator panel activates configured required channels');
    $send('/start');$p=$panel();fjCheck($p&&str_contains($a->last()['text'],'عضویت')&&count($a->last()['reply_markup']['inline_keyboard'])===2,'nonmembers receive channel links and an owner-bound verification button');
    $before=count($a->calls);$tap($p,'check',42);fjCheck(count($a->calls)===$before,'a copied private confirmation is rejected before any mutation');
    $send('https://www.youtube.com/watch?v=AAAAAAAAAAA');fjCheck((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'a blocked link consumes no job, quota or accepted-link reaction');
    $a->members[-10010][7]=['status'=>'member'];$s->set('membership.tap.7','0');$tap($p);
    fjCheck(!$panel()&&$f->verdict(7,7)==='ok','confirmation freshly checks membership instead of reusing a cached refusal');
    $s->query('DELETE FROM membership_cache');$a->members[-10010][7]=['status'=>'restricted','is_member'=>true];fjCheck($f->verdict(7,7)==='ok','restricted members with is_member=true still qualify');
    $s->query('DELETE FROM membership_cache');$a->members[-10010][7]=['status'=>'kicked'];fjCheck($f->verdict(7,7)==='join','a removed channel member loses access');
    $s->query("INSERT INTO membership_channels(chat_id,title,url) VALUES(-10020,'Second','https://t.me/second')");$s->query('DELETE FROM membership_cache');
    $human=fn(int $id)=>['user'=>['id'=>$id,'is_bot'=>false],'status'=>'administrator'];$a->admins[-10055]=[$human(61),$human(62),['user'=>['id'=>63,'is_bot'=>true],'status'=>'administrator']];
    $a->members[-10010][61]=['status'=>'member'];$a->members[-10020][62]=['status'=>'member'];$a->members[-10010][63]=['status'=>'member'];$a->members[-10020][63]=['status'=>'member'];
    fjCheck($f->verdict(8,-10055)==='join','different administrators split across channels and bot administrators cannot sponsor a group');
    $a->members[-10020][61]=['status'=>'administrator'];$s->query('DELETE FROM membership_cache');fjCheck($f->verdict(8,-10055)==='ok'&&$f->verdict(9,-10055)==='ok','one human administrator in every channel grants access to other users of that group');
    fjCheck($f->verdict(9,-10066)==='join'&&$f->verdict(9,9)==='join','group sponsorship grants neither private access nor access in another group');
    $s->set('membership.group_admin','0');fjCheck($f->verdict(9,-10055)==='join','switching off the exception takes effect despite a cached positive group result');$s->set('membership.group_admin','1');
    $a->admins[-10055]=[];$s->query('UPDATE membership_cache SET expires=0');fjCheck($f->verdict(9,-10055)==='join','an expired group grant is rechecked against the current administrators');
    $a->fail=true;$s->query('DELETE FROM membership_cache');fjCheck($f->verdict(9,9)==='unavailable','API and rate-limit failures do not grant membership');$before=count($a->calls);$f->verdict(10,10);fjCheck(count($a->calls)===$before,'a rate refusal prevents requests through another user or channel');$a->fail=false;$s->set('membership.retry','0');$s->query('DELETE FROM membership_cache');
    $before=count($a->calls);$send('ordinary group conversation',9,-10055,17);fjCheck(count($a->calls)===$before,'ordinary group conversation stays ignored');
    $send('/checker',9,-10055,17);$gp=$panel('user',9);$before=count($a->calls);$tap($gp,'check',9,18);fjCheck(count($a->calls)===$before+1,'a membership confirmation cannot move to a different forum topic');
    $before=count($a->calls);$tap($gp,'check',42,17);fjCheck(count($a->calls)===$before+1&&end($a->calls)[0]==='answerCallbackQuery','another administrator gets an ownership alert without a new group message');
    $a->admins[-10055]=[$human(61)];$s->set('membership.tap.9','0');$tap($gp,'check',9);fjCheck(!$panel('user',9),'a valid group confirmation recovers omitted topic metadata from its stored owner binding');
    $a->members[-10010][7]=['status'=>'member'];$a->members[-10020][7]=['status'=>'member'];$s->query('DELETE FROM membership_cache');$s->set('membership.enabled','1');
    fjCheck($b->requestVideo(999,7,7,'https://www.youtube.com/watch?v=AAAAAAAAAAA')==='queued','a verified user can enqueue a normal download');$job=$s->claim('inspect');
    $a->members[-10010][7]=['status'=>'left'];$s->query('DELETE FROM membership_cache');mkdir($root.'/downloads',0700);(new Zero\Worker($c,$s,$a))->process($job);
    fjCheck($s->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed'&&!array_filter($a->calls,fn($v)=>in_array($v[0],['sendVideo','sendAudio','sendDocument'],true)),'the worker rechecks access and blocks a queued download after membership is lost');
    $f->prompt($b,7,7,0,'join',['message_id'=>321,'message_kind'=>'photo']);$photo=$panel('user_photo',7);fjCheck($photo&&(int)$photo['message_id']===321&&isset($a->last()['caption']),'membership can replace the existing quality-photo caption without creating a second status message');
    $a->members[-10010][7]=['status'=>'member'];$s->set('membership.tap.7','0');$tap($photo);fjCheck(!$panel('user_photo',7),'photo-based membership confirmation edits the caption and clears its old controls');
    $b->handle(['inline_query'=>['id'=>'blocked-inline','from'=>['id'=>11],'query'=>'s:abcdefghijklmnop']]);$last=end($a->calls);fjCheck($last[0]==='answerInlineQuery'&&$last[1]['results']===[]&&$last[1]['is_personal']&&$last[1]['cache_time']===0,'inline sharing cannot reuse public cached results to bypass membership');
    foreach(['membership_prompt','membership_group_prompt','membership_success','membership_unavailable'] as $key)fjCheck($b->ui->customizable('text',$key),'public membership copy is editable: '.$key);
    foreach(['membership_channel','membership_check'] as $key)fjCheck($b->ui->customizable('button',$key),'public membership buttons support the shared editor: '.$key);
    $s->set('button.icon.membership_check','5368324170671202286');fjCheck(($b->ui->button('membership_check','fj:test:check')['icon_custom_emoji_id']??'')==='5368324170671202286','membership buttons retain the shared premium custom-emoji setting');
    $send('/admin',42,42);$custom=function(string $op)use($b,$a,&$serial):void{$last=$a->last();$b->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'custom'.$serial,'from'=>['id'=>42],'message'=>['message_id'=>$last['message_id']??$a->mid,'chat'=>['id'=>42,'type'=>'private']],'data'=>'custom:'.$op]]);};
    $custom('section:membership');fjCheck(str_contains(json_encode($a->last()),'custom:item:text:membership_prompt'),'General Personalization exposes the membership screen and its editable fields');
    $custom('membershipsticker');$send('',42,42,0,['file_id'=>'premium-fixture']);fjCheck($s->get('membership.sticker')==='premium-fixture','a premium sticker is saved only after Telegram confirms the preview');
    $custom('membershipsticker');$a->failSticker=true;$send('',42,42,0,['file_id'=>'rejected-fixture']);fjCheck($s->get('membership.sticker')==='premium-fixture'&&str_starts_with((string)$s->query('SELECT key FROM states WHERE user_id=42')->fetchColumn(),'ui:'),'a rejected sticker preview preserves the working value and pending input');
    $s->set('membership.enabled','0');$before=count($a->calls);fjCheck($f->verdict(9,9)==='ok'&&count($a->calls)===$before,'disabling the filter restores access immediately');
} finally { Zero\Download::clean($root); }
