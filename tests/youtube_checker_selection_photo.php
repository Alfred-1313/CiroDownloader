<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function ysCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class SelectionPhotoApi implements Zero\Api
{
    public array $calls=[];public int $next=400;
    public function call(string $method,array $p=[]): mixed {
        $this->calls[]=[$method,$p];$caption=$p['caption']??$p['media']['caption']??null;if($caption!==null)Zero\TextFormat::validate($caption,1024);
        if($method==='getChatMember')return ['status'=>'administrator'];
        if($method==='editMessageMedia')return ['message_id'=>$p['message_id'],'photo'=>[['file_id'=>'selection-photo-'.$p['message_id']]]];
        if(in_array($method,['sendMessage','sendPhoto'],true))return ['message_id'=>++$this->next,'photo'=>$method==='sendPhoto'?[['file_id'=>'selection-photo-'.$this->next]]:[]];
        return true;
    }
    public function last():array{return end($this->calls)?:[];}
}
$root=sys_get_temp_dir().'/ciro-selection-photo-'.bin2hex(random_bytes(5));mkdir($root,0700);
try {
    $c=new Zero\Config('123:fixture',[42,55],$root.'/data','https://api.telegram.org',49000000,$root.'/yt-test');$s=new Zero\Store($c->storage);$a=new SelectionPhotoApi();$b=new Zero\Bot($c,$s,$a);$m=new Zero\YoutubeChecker($c,$s,$a);$panels=new Zero\YoutubeCheckerPanel($b);$serial=100;
    $s->set('youtube.checker.enabled','1');$s->set('bot_username','FixtureBot');$s->set('bot_id','123');
    $send=function(string $text,int $owner=42,?int $reply=null)use($b,&$serial):void {
        $message=['message_id'=>++$serial,'from'=>['id'=>$owner],'chat'=>['id'=>-100,'type'=>'supergroup'],'message_thread_id'=>17,'text'=>$text];
        if($reply)$message['reply_to_message']=['message_id'=>$reply,'from'=>['id'=>123,'is_bot'=>true]];
        $b->handle(['update_id'=>$serial,'message'=>$message]);
    };
    $current=function(int $owner=42)use($s):array{return $s->query('SELECT * FROM yt_check_panels WHERE owner=? ORDER BY rowid DESC LIMIT 1',[$owner])->fetch();};
    $cooldown=function()use($s):void{$s->set('youtube.checker.profile.42','0');$s->set('youtube.checker.profile.55','0');};
    $tap=function(string $op,?int $id=null,?array $p=null,int $owner=42,?int $topic=17)use($b,$current,&$serial):void {
        $p??=$current($owner);$message=['message_id'=>(int)$p['message_id'],'chat'=>['id'=>-100,'type'=>'supergroup']];if($topic!==null)$message['message_thread_id']=$topic;
        $b->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'ys'.$serial,'from'=>['id'=>$owner],'message'=>$message,'data'=>'yc:'.$p['token'].':'.$op.($id===null?'':':'.$id)]]);
    };
    $send('/youtube_checker @fixture');$first=$current();$firstData=json_decode($first['data'],true);$target=(int)$firstData['profile_target'];
    ysCheck($first['view']==='new'&&$firstData['profile_wait']&&(int)$s->query('SELECT COUNT(*) FROM yt_check_subs')->fetchColumn()===0&&(int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'the screenshot selection screen requests a background photo before registration without a subscription or media job');
    $m->prune();ysCheck((int)$s->query('SELECT COUNT(*) FROM yt_check_targets WHERE id=?',[$target])->fetchColumn()===1,'cleanup preserves metadata requested by a still-open unsaved selection');
    $send('/youtube_checker @fixture',55);$other=$current(55);
    ysCheck((int)json_decode($other['data'],true)['profile_target']===$target,'two owners share one metadata target without sharing panel ownership');
    $cookie=fn(string $v)=>"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSID\t$v\n";
    Zero\youtubeCookiePool($c)->add($cookie('MANUAL_FIXTURE'));Zero\YoutubeCheckerMedia::pool($c)->add($cookie('CHECKER_FIXTURE'));
    $image='https://yt3.ggpht.com/selection-profile';$account='UC'.str_repeat('A',22);
    $result=['account_id'=>$account,'avatar'=>$image];
    file_put_contents($root.'/python',"#!/usr/bin/env php\n<?php ".'$i=array_search("--cookies",$argv,true);if(!in_array("--profile-only",$argv,true)||in_array("--mode",$argv,true)||$i===false||!str_contains(file_get_contents($argv[$i+1]),"CHECKER_FIXTURE")){exit(1);}echo '.var_export(json_encode($result),true).';');chmod($root.'/python',0700);
    ysCheck($m->pollProfile()&&!$m->pollProfile(),'one real worker subprocess reads only profile metadata with the independent checker jar and serves both pending owners');
    ysCheck((int)$s->query('SELECT COUNT(*) FROM yt_check_seen')->fetchColumn()===0&&(int)$s->query('SELECT COUNT(*) FROM yt_check_subs')->fetchColumn()===0,'profile retrieval does not baseline content or register either requester');
    $before=count($a->calls);$panels->refreshProfiles();$p=$current();$other=$current(55);$last=$a->last();
    ysCheck(count($a->calls)===$before+2&&$p['photo']!==''&&$other['photo']!==''&&(int)$p['message_id']===(int)$first['message_id'],'the avatar is added in place to both original owner/topic-bound selection messages');
    ysCheck($last[0]==='editMessageMedia'&&str_contains($last[1]['media']['caption'],'کدام محتواها ارسال شوند')&&count(array_filter(array_merge(...$last[1]['reply_markup']['inline_keyboard']),fn($button)=>str_contains($button['callback_data']??'',':save:')))===7,'the exact content-selection caption and all seven existing content choices remain under the photo');
    $before=count($a->calls);$tap('save',7,$p,55);$tap('save',7,$p,42,18);
    ysCheck(count($a->calls)===$before+2&&(int)$s->query('SELECT COUNT(*) FROM yt_check_subs')->fetchColumn()===0,'a different administrator or topic cannot confirm the photo selection');
    $tap('save',7,$p,42,null);$p=$current();$subId=(int)json_decode($p['data'],true)['id'];
    ysCheck($p['view']==='item'&&$p['photo']!==''&&$a->last()[0]==='editMessageCaption'&&(int)$p['message_id']===(int)$first['message_id'],'confirmation keeps the selection photo on the same details message and recovers omitted topic metadata');
    $m->poll(function(){throw new Zero\MediaFailure('format','Fixture unavailable content tab');});$tap('item',$subId);$record=$s->query('SELECT * FROM yt_check_targets WHERE id=?',[$target])->fetch();
    ysCheck($record['state']==='format'&&$record['avatar']===$image&&$current()['photo']!==''&&$a->last()[0]==='editMessageCaption','a failed video/post scan leaves the independently retrieved profile visible in the registered panel');
    $s->query('UPDATE yt_check_targets SET next_check=0 WHERE id=?',[$target]);$m->poll(fn()=>['account_id'=>$account,'mode'=>7,'videos'=>[['id'=>'AAAAAAAAAAA','url'=>'https://www.youtube.com/watch?v=AAAAAAAAAAA','time'=>time()]],'shorts'=>[],'posts'=>[]]);
    ysCheck((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&(int)$m->owned($subId,42)['posts_ready']===1,'the first actual media scan still baselines existing uploads after profile preview');
    $tap('close',null,$other,55);$send('/youtube_checker @cancelled');$cancel=$current();$cancelTarget=(int)json_decode($cancel['data'],true)['profile_target'];$tap('close');$calls=0;
    ysCheck(!$m->pollProfile(function()use(&$calls){$calls++;return [];} )&&$calls===0,'closing an unsaved selection cancels the pending source lookup');$m->prune();
    ysCheck(!(int)$s->query('SELECT COUNT(*) FROM yt_check_targets WHERE id=?',[$cancelTarget])->fetchColumn(),'unregistered preview metadata is cleaned after its menu closes');
    // Add via the ForceReply route must show the same preview as a direct command.
    $send('/youtube_checker');$tap('add');$prompt=(int)json_decode($current()['data'],true)['input_message'];$send('@replychannel',42,$prompt);$replyPanel=$current();
    $calls=0;ysCheck(!$m->pollProfile(function()use(&$calls){$calls++;return [];})&&$calls===0,'different channel handles cannot bypass the owner profile lookup cooldown');$cooldown();
    $m->pollProfile(fn()=>['account_id'=>'UC'.str_repeat('B',22),'avatar'=>'https://yt3.ggpht.com/reply-profile']);$panels->refreshProfiles();
    ysCheck($current()['view']==='new'&&$current()['photo']!==''&&(int)$current()['message_id']===(int)$replyPanel['message_id'],'the Add-channel reply flow also receives a photo before choosing content');$tap('close');
    $send('/youtube_checker @ratelimited');$rate=$current();$rateTarget=(int)json_decode($rate['data'],true)['profile_target'];$cooldown();$m->pollProfile(function(){throw new Zero\MediaFailure('blocked','Fixture rate refusal');});$panels->refreshProfiles();
    $record=$s->query('SELECT * FROM yt_check_targets WHERE id=?',[$rateTarget])->fetch();$next=(int)$record['next_check'];$s->query('UPDATE yt_check_targets SET avatar_checked=0 WHERE id=?',[$rateTarget]);$send('/youtube_checker @ratelimited');$calls=0;
    ysCheck($next>=time()+899&&!$m->pollProfile(function()use(&$calls){$calls++;return [];})&&$calls===0,'a profile refusal preserves the channel backoff when a new selection message is opened');
    $tap('save',7);ysCheck((int)$s->query('SELECT next_check FROM yt_check_targets WHERE id=?',[$rateTarget])->fetchColumn()===$next,'confirming registration does not bypass a profile rate refusal');
    // Match the reported screenshot: existing checker is unavailable because one content tab failed.
    $failedId=$m->add(42,-100,17,'@failedtab',7);$failed=$m->owned($failedId,42);$future=time()+1200;
    $s->query("UPDATE yt_check_targets SET state='format',failures=2,next_check=? WHERE id=?",[$future,$failed['target']]);$panels->open(42,-100,17,0,'item',['id'=>$failedId]);
    $cooldown();$m->pollProfile(fn()=>['account_id'=>'UC'.str_repeat('C',22),'avatar'=>'https://yt3.ggpht.com/recovered-profile']);$panels->refreshProfiles();$record=$s->query('SELECT * FROM yt_check_targets WHERE id=?',[$failed['target']])->fetch();
    ysCheck($current()['photo']!==''&&$record['state']==='format'&&(int)$record['checked']===0&&(int)$record['next_check']===$future,'an existing unavailable checker can get its photo independently without claiming a successful media check or changing that schedule');
    ysCheck(!glob($c->storage.'/downloads/checker-*')&&file_get_contents($c->cookiePath())===$cookie('MANUAL_FIXTURE'),'profile preparation leaves no temporary credentials and preserves manual YouTube cookies');
    $s->set('youtube.checker.public','1');$m->add(7,7,0,'@limitedone',7);$m->add(7,7,0,'@limitedtwo',7);$m->add(7,7,0,'@limitedthree',7);
    ysCheck($m->previewProfile('@overquota',7,7)===null&&!$s->query("SELECT 1 FROM yt_check_targets WHERE username='@overquota'")->fetchColumn(),'ordinary users at the channel cap cannot use unsaved previews to create additional source requests');
} finally { Zero\Download::clean($root); }
