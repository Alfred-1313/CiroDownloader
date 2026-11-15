<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require_once dirname(__DIR__).'/src/AdultHandoff.php';
function handoffCheck(bool $ok,string $why): void { if(!$ok) throw new RuntimeException($why); echo "PASS $why\n"; }
final class HandoffApi implements Zero\Api {
    public array $calls=[]; public bool $failEdit=false;
    public function call(string $method,array $params=[]): mixed {
        if($method==='sendVideo'&&isset($params['video'])) $params['bytes']=file_get_contents($params['video']->getFilename());
        $this->calls[]=[$method,$params];
        if($method==='getMe') return ['id'=>456,'is_bot'=>true,'username'=>'fixture_bot'];
        if($method==='getWebhookInfo') return ['url'=>''];
        if($method==='sendVideo') return ['message_id'=>901,'caption'=>$params['caption']??''];
        if(str_starts_with($method,'edit')&&$this->failEdit) throw new RuntimeException('Network failure');
        return ['message_id'=>901];
    }
}
$root=sys_get_temp_dir().'/ciro-handoff-'.bin2hex(random_bytes(5));
try {
    $cfg=new Zero\Config('123:main_fixture',[42],$root); $store=new Zero\Store($root); $api=new HandoffApi(); $h=new Zero\AdultHandoff($cfg,$store);
    $store->set('adult.pornhub','1'); $store->set('groups_enabled','1');
    handoffCheck(!$h->enabled()&&$h->ttl()===120,'handoff is off by default and links last two minutes');
    $h->configure('456:fixture_secret',$api); handoffCheck(($h->token()==='456:fixture_secret')&&((fileperms($root.'/adult-handoff/token')&0777)===0600),'only a private token file stores the validated second bot token');
    $store->set('adult.handoff.enabled','1');
    $make=function(int $chat=42) use($h,$root) { $file=$root.'/video-'.bin2hex(random_bytes(3)).'.mp4'; file_put_contents($file,'neutral-video'); return $h->prepare(['user_id'=>42,'chat_id'=>$chat],['path'=>$file,'width'=>320,'height'=>240,'duration'=>2],'pornhub','<b>Neutral caption</b>',10,true); };
    $link=$make(-100123); preg_match('/start=v_([a-f0-9]{32})/',$link,$m); $key=$m[1];
    $h->linkMessage($link,-100123,99,'photo');
    $h->configure('456:new_fixture_secret',$api);
    handoffCheck($h->token()==='456:new_fixture_secret','a rotated token for the same bot keeps pending handoffs usable');
    $other=new class implements Zero\Api { public function call(string $method,array $params=[]): mixed { return $method==='getMe'?['id'=>789,'is_bot'=>true,'username'=>'other_bot']:['url'=>'']; } };
    try { $h->configure('789:other_fixture_secret',$other); $rejected=false; } catch(RuntimeException) { $rejected=true; }
    handoffCheck($rejected&&$h->token()==='456:new_fixture_secret','a different bot cannot take over pending links and deletion timers');
    $db=new PDO('sqlite:'.$root.'/adult-handoff/handoff.sqlite'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $row=$db->query("SELECT * FROM items WHERE token='$key'")->fetch(PDO::FETCH_ASSOC);
    handoffCheck((int)$row['expires']>=time()+118&&(int)$row['expires']<=time()+120,'expiry is measured from publishing the link');
    handoffCheck($h->request(7,7,'/start v_'.$key)==='owner'&&$h->claim()===null,'another user cannot claim the private handoff');
    handoffCheck($h->request(42,-100123,'/start v_'.$key)==='invalid','group starts are refused');
    $store->set('adult.pornhub','0'); handoffCheck($h->request(42,42,'/start v_'.$key)==='expired','revoked adult-site access is checked again before redemption'); $store->set('adult.pornhub','1');
    handoffCheck($h->request(42,42,'/start v_'.$key)==='queued'&&$h->request(42,42,'/start v_'.$key)==='queued','only the owner may queue a link and repeated taps cannot send twice');
    $row=$h->claim(); $h->deliver($row,$api); $video=end($api->calls);
    handoffCheck($video[0]==='sendVideo'&&$video[1]['chat_id']===42&&$video[1]['bytes']==='neutral-video'&&$video[1]['caption']==='<b>Neutral caption</b>'&&$video[1]['has_spoiler']===true,'second bot sends the exact file, caption and native spoiler privately');
    handoffCheck(!is_file($root.'/adult-handoff/'.$key.'.mp4')&&(int)$db->query('SELECT COUNT(*) FROM deletes')->fetchColumn()===1,'confirmed delivery removes the file and persists its own timer');
    $db->exec('UPDATE deletes SET at=0'); $h->deleteDue($api); $delete=end($api->calls);
    handoffCheck($delete[0]==='deleteMessage'&&$delete[1]['chat_id']===42&&$delete[1]['message_id']===901&&(int)$db->query('SELECT COUNT(*) FROM deletes')->fetchColumn()===0,'second bot deletes the exact delivered message when due');
    $db->exec("UPDATE items SET expires=0 WHERE token='$key'"); $h->purge($api); $edit=end($api->calls);
    handoffCheck($edit[0]==='editMessageCaption'&&$edit[1]['chat_id']===-100123&&$edit[1]['message_id']===99&&$edit[1]['reply_markup']['inline_keyboard']===[],'expiry edits the original group cover and removes its link');
    $store->set('adult.handoff.ttl','300'); handoffCheck($h->ttl()===300,'the configured duration changes new links');
    $link=$make(); preg_match('/start=v_([a-f0-9]{32})/',$link,$m); $key=$m[1]; $h->linkMessage($link,42,101,'text');
    $db->exec("UPDATE items SET expires=".(time()-1)." WHERE token='$key'"); $api->failEdit=true; $h->purge($api);
    handoffCheck(!is_file($root.'/adult-handoff/'.$key.'.mp4')&&(int)$db->query("SELECT COUNT(*) FROM items WHERE token='$key' AND notified=0")->fetchColumn()===1,'expiry removes the file even if the message edit needs a retry');
    $api->failEdit=false; $db->exec("UPDATE items SET notice_at=0 WHERE token='$key'"); $h->purge($api); $edit=end($api->calls);
    handoffCheck($edit[0]==='editMessageText'&&$edit[1]['message_id']===101&&(int)$db->query("SELECT COUNT(*) FROM items WHERE token='$key'")->fetchColumn()===0,'expiry edits are retried and then forgotten');
    $link=$make(); preg_match('/start=v_([a-f0-9]{32})/',$link,$m); $key=$m[1]; $h->linkMessage($link,42,102,'text');
    handoffCheck($h->request(42,42,'/start v_'.$key)==='queued','a valid link can be accepted before expiration');
    $db->exec("UPDATE items SET expires=".(time()-1)." WHERE token='$key'"); $h->purge($api);
    handoffCheck(is_file($root.'/adult-handoff/'.$key.'.mp4')&&($queued=$h->claim())!==null,'an accepted request survives link expiry while waiting in the delivery queue');
    $h->deliver($queued,$api);
    $orphan=$root.'/adult-handoff/'.str_repeat('a',32).'.mp4'; file_put_contents($orphan,'neutral'); touch($orphan,time()-700);
    $orphanToken=$root.'/adult-handoff/token.'.str_repeat('b',12); file_put_contents($orphanToken,'fixture'); touch($orphanToken,time()-700);
    $h->purgeOrphans(); handoffCheck(!is_file($orphan)&&!is_file($orphanToken),'orphaned media and partial token writes are removed after a crash');
    $bot=new Zero\Bot($cfg,$store,$api);
    $click=fn($user,$data)=>$bot->handle(['callback_query'=>['id'=>'fixture','from'=>['id'=>$user],'message'=>['message_id'=>700,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]);
    $click(42,'custom:handoffttl');
    $bot->handle(['message'=>['message_id'=>701,'from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'text'=>'۲۴۰']]);
    handoffCheck($store->get('adult.handoff.ttl')==='240','an administrator can set the duration with Persian digits');
    $click(7,'custom:handofftoggle'); handoffCheck($h->enabled(),'ordinary users cannot change the feature switch');
    foreach(Zero\adultServices() as $site) foreach([['text','handoff_ready'],['text','handoff_expired'],['text','handoff_queued'],['text','handoff_denied'],['text','handoff_failed'],['button','handoff_open']] as [$kind,$base]) {
        $scoped=Zero\UiRegistry::key($site,$kind,$base); handoffCheck((new Zero\Ui($store))->customizable($kind,$scoped),$site.' exposes '.$base.' in personalization');
    }
} finally { Zero\Download::clean($root); }
