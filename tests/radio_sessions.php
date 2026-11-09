<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function sessionCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function sessionWait(callable $ready,string $label,int $seconds=12): void {
    $until=microtime(true)+$seconds;
    do { if($ready()) { sessionCheck(true,$label); return; } usleep(50000); } while(microtime(true)<$until);
    throw new RuntimeException($label);
}
$temp=sys_get_temp_dir().'/ciro-radio-sessions-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null; $worker=null;
try {
    $root=$temp.'/data'; mkdir($root,0700); file_put_contents($temp.'/calls.jsonl',''); file_put_contents($temp.'/mode.json','{}');
    $config=new Zero\Config('123:fixture',[42],$root);
    (new Zero\Download($config))->run(['ffmpeg','-v','error','-y','-filter_threads','1','-f','lavfi','-i','color=c=red:s=320x320','-frames:v','1','-threads','1',$temp.'/preview.jpg'],$temp,30);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket); $config->api='http://'.$address;
    $server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/fixtures/radio_panel_server.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$temp.'/server.log','w']],$pipes,null,array_merge(getenv(),['RADIO_PANEL_FIXTURE'=>$temp]));
    for($n=0;$n<50;$n++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $store=new Zero\Store($root); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api); $radio=new Zero\Radio($config,$store);
    foreach([-1001,-1002,-1003] as $chat) $radio->save($chat,'Session '.$chat,$chat===-1003?'channel':'group',42,'rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf');
    $store->set('cookies.keepalive','0'); $store->set('cookies.alerts','0');
    $calls=fn()=>array_map(fn($line)=>json_decode($line,true),file($temp.'/calls.jsonl',FILE_IGNORE_NEW_LINES));
    $photos=fn()=>array_values(array_filter($calls(),fn($call)=>$call['method']==='sendPhoto'&&(int)$call['params']['chat_id']===-1001));
    $current=fn(int $chat=-1001)=>(int)$store->query("SELECT message_id FROM radio_panels WHERE chat_id=? AND station_id=? AND view IN ('player','menu') ORDER BY message_id DESC LIMIT 1",[$chat,$chat])->fetchColumn();
    $event=function(string $kind,int $message,int $chat=-1001) use($bot): void {
        $post=['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat===-1003?'channel':'supergroup','title'=>'Session'],'date'=>time(),$kind=>$kind==='video_chat_ended'?['duration'=>10]:[]];
        if($chat!==-1003) { $post['from']=['id'=>42,'is_bot'=>false]; $post['message_thread_id']=321; }
        $bot->handle(['update_id'=>$message,($chat===-1003?'channel_post':'message')=>$post]);
    };
    $click=function(string $data,int $message,int $user=55,int $chat=-1001) use($bot): void {
        $bot->handle(['callback_query'=>['id'=>'session'.random_int(1,99999),'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat===-1003?'channel':($chat<0?'supergroup':'private')],'message_thread_id'=>$chat===-1001?321:0],'data'=>$data]]);
    };
    $startWorker=function() use($root,$config,$temp) {
        return proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$temp.'/worker.log','a']],$pipes,null,
            ['PATH'=>getenv('PATH'),'BOT_TOKEN'=>'123:fixture','ADMIN_IDS'=>'42','STORAGE_DIR'=>$root,'BOT_API_URL'=>$config->api]);
    };
    $view=new Zero\RadioPlayerPanel($bot); $view->show(-1001,42,0,$radio->station(-1001),321); $old=$current();
    $other=$view->show(-1002,42,0,$radio->station(-1002)); file_put_contents($temp.'/mode.json',json_encode(['missing_messages'=>[$old]]));
    $count=count($photos()); $event('video_chat_started',10); $a=$radio->session(-1001);
    sessionCheck($a!=='0'&&count($photos())===$count&&(int)$store->query('SELECT pending FROM radio_sessions WHERE chat_id=-1001')->fetchColumn()===1,'a real stream start schedules a fresh management panel without blocking on Telegram artwork');
    $click('rd:t:-1001:0',$old); sessionCheck(!$radio->paused(-1001),'controls from before the stream cannot pause the new stream');
    $worker=$startWorker(); sessionWait(fn()=>$current()>0&&$current()!==$old,'the worker posts a new management panel even when the previous message was deleted'); $first=$current();
    $cards=$photos(); $card=end($cards); $rows=json_decode($card['params']['reply_markup'],true)['inline_keyboard']; $actions=array_column(array_merge(...$rows),'callback_data');
    sessionCheck((int)$card['params']['message_thread_id']===321&&in_array('rd:x:-1001:'.$a,$actions,true)&&in_array('rd:s:-1001:'.$a,$actions,true),'the automatic panel opens the full management controls in the service event topic');
    sessionCheck(!array_filter($actions,fn($data)=>strlen($data)>64||!str_ends_with($data,':'.$a)),'every management button is bound to this stream within Telegram callback limits');
    $private=$view->show(42,42,0,$radio->station(-1001));
    $files=$root.'/radio/files'; mkdir($files,0700,true); $oldAudio=$files.'/old.m4a'; $oldCover=$files.'/old.jpg'; $ready=$files.'/ready.m4a'; file_put_contents($oldAudio,'old audio'); copy($temp.'/preview.jpg',$oldCover); file_put_contents($ready,'ready audio');
    $source=fn(string $name)=>['service'=>'spotify','m'=>['title'=>$name,'artists'=>['Artist'],'duration'=>30]];
    $playing=$radio->enqueue(-1001,42,$source('Old'),'Old','Artist',30)['id']; $next=$radio->enqueue(-1001,42,$source('Next'),'Next','Artist',30)['id'];
    $store->query("UPDATE radio_queue SET status='playing',file=?,cover=? WHERE id=?",[$oldAudio,$oldCover,$playing]); $store->query("UPDATE radio_queue SET status='ready',file=? WHERE id=?",[$ready,$next]);
    $store->set('radio.progress.-1001',json_encode(['id'=>$playing,'title'=>'Old','artists'=>'Artist','duration'=>30,'elapsed'=>20,'cover'=>$oldCover,'session'=>$a,'at'=>microtime(true)])); $store->set('radio.paused.-1001','1');
    file_put_contents($temp.'/mode.json',json_encode(['missing_messages'=>[$old,$first]])); $event('video_chat_ended',11); $event('video_chat_started',12); $b=$radio->session(-1001);
    sessionCheck($b!==$a&&!$radio->paused(-1001)&&empty($radio->progress(-1001)),'same-second end/restart rotates the session and clears old progress and pause');
    sessionCheck(!is_file($oldAudio)&&!is_file($oldCover)&&is_file($ready)&&$store->query('SELECT status FROM radio_queue WHERE id=?',[$next])->fetchColumn()==='ready','ending a stream releases its playing file while preserving prepared upcoming songs');
    foreach(['t','x','s','h','j','l','z'] as $op) $click('rd:'.$op.':-1001:'.$a,$first);
    $click('rd:D:-1001:'.$a,$private,42,42); $click('rd:x:-1001',$first);
    sessionCheck(!$radio->paused(-1001)&&$radio->mode(-1001)==='off'&&$radio->station(-1001)['command']===''&&$radio->queued(-1001)===1,'old and legacy buttons cannot pause, clear, stop, restart or otherwise mutate the new stream');
    sessionCheck($radio->station(-1001)!==null,'an old private management confirmation cannot delete the new stream station');
    sessionWait(fn()=>$current()>0&&$current()!==$first,'restarting the stream automatically posts another fresh management panel'); $second=$current();
    sessionWait(fn()=>!(bool)$store->query("SELECT 1 FROM radio_panels WHERE chat_id=42 AND message_id=? AND view='expired'",[$private])->fetchColumn(),'the worker disables the previous private management keyboard too');
    sessionCheck($current(-1002)===$other&&$radio->session(-1002)==='0','restarting one radio does not invalidate another chat panel');
    $click('rd:t:-1001:'.$b,$second); sessionCheck($radio->paused(-1001),'the new panel can pause its own stream');
    $click('rd:x:-1001:'.$b,$second,66); sessionCheck($radio->queued(-1001)===1,'a valid new session never grants an ordinary member control');
    $count=count($photos()); $event('video_chat_started',12); $event('video_chat_ended',11); usleep(1100000);
    sessionCheck($radio->session(-1001)===$b&&(int)$radio->station(-1001)['live']===1&&count($photos())===$count,'duplicate starts and late old end events cannot replace the current panel or end the new stream');
    $event('video_chat_ended',13); sessionWait(fn()=>$current()===0,'ending a stream immediately expires its active management panel');
    proc_terminate($worker); proc_close($worker); $worker=null; $event('video_chat_started',14); $c=$radio->session(-1001);
    $worker=$startWorker(); sessionWait(fn()=>$current()>0&&$current()!==$second,'a pending start announcement survives a worker restart');
    sessionCheck($c!==$b,'each restarted stream has its own independent controls');
    $event('video_chat_started',20,-1003); sessionWait(fn()=>$current(-1003)>0,'a configured channel stream also receives its own management panel');
    $channel=$current(-1003); $click($radio->callback('i',-1003),$channel,55,-1003);
    sessionCheck($radio->mode(-1003)==='one','a verified human channel administrator can operate the channel stream controls');
    $click($radio->callback('h',-1003),$channel,66,-1003); sessionCheck($radio->mode(-1003)==='one','channel controls retain administrator-only authorization');
    sessionCheck((new Zero\Ui($store))->customizable('text','radio_player_expired'),'the old-stream warning is editable in Personalization');
} finally {
    foreach([$worker,$server] as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    Zero\Download::clean($temp);
}
