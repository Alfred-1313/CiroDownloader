<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function navigationCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function navigationWait(callable $ready,string $label,int $seconds=10): void {
    $until=microtime(true)+$seconds;
    do { if($ready()) { navigationCheck(true,$label); return; } usleep(50000); } while(microtime(true)<$until);
    throw new RuntimeException($label);
}
$temp=sys_get_temp_dir().'/ciro-radio-navigation-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null; $worker=null;
try {
    $root=$temp.'/data'; mkdir($root,0700); file_put_contents($temp.'/calls.jsonl',''); file_put_contents($temp.'/mode.json','{}');
    $config=new Zero\Config('123:fixture',[42],$root);
    (new Zero\Download($config))->run(['ffmpeg','-v','error','-y','-filter_threads','1','-f','lavfi','-i','color=c=red:s=320x320','-frames:v','1','-threads','1',$temp.'/preview.jpg'],$temp,30);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket); $config->api='http://'.$address;
    $server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/fixtures/radio_panel_server.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$temp.'/server.log','w']],$pipes,null,array_merge(getenv(),['RADIO_PANEL_FIXTURE'=>$temp]));
    for($n=0;$n<50;$n++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $store=new Zero\Store($root); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api); $radio=new Zero\Radio($config,$store);
    $radio->save(-1001,'Navigation','group',55,'rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf'); $radio->setLive(-1001,true);
    $source=['service'=>'spotify','m'=>['title'=>'Work','artists'=>['Artist'],'duration'=>194]]; $token=$radio->pick($source,'Work','Artist',194);
    $click=function(int $user=55,int $chat=-1001,int $message=501) use($bot,$token): void {
        $bot->handle(['callback_query'=>['id'=>'navigation'.random_int(1,99999),'from'=>['id'=>$user],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0,'audio'=>['file_id'=>'AUDIO','duration'=>194,'thumbnail'=>['file_id'=>'THUMBNAIL','file_size'=>1600]]],'data'=>'rd:p:'.$token]]);
    };
    $calls=fn()=>array_map(fn($line)=>json_decode($line,true),file($temp.'/calls.jsonl',FILE_IGNORE_NEW_LINES));
    $method=fn(string $name)=>array_values(array_filter($calls(),fn($entry)=>$entry['method']===$name));
    $click(66); navigationCheck($radio->queued(-1001)===0&&!$method('deleteMessage')&&!$method('sendPhoto'),'a member cannot queue a song, replace its message or open the player');
    $click(); $panel=$store->query('SELECT * FROM radio_panels WHERE chat_id=-1001')->fetch(); $photo=$method('sendPhoto')[0];
    navigationCheck($panel&&$panel['kind']==='photo'&&(int)$panel['thread_id']===321&&(int)$photo['params']['message_thread_id']===321,'Play opens the photo player directly in the audio message forum topic');
    navigationCheck(($photo['files']['photo']??'')===hash_file('sha256',$temp.'/preview.jpg'),'the real audio thumbnail is downloaded and uploaded above the caption before radio preparation');
    navigationCheck(str_contains($photo['params']['caption'],'Artist')&&str_contains($photo['params']['caption'],'Work')&&str_contains($photo['params']['caption'],'03:14')&&str_contains($photo['params']['caption'],'آماده‌سازی'),'the queued song has its title, known duration and honest preparation state instead of an empty player');
    $all=$calls(); $opened=array_search('sendPhoto',array_column($all,'method'),true); $deleted=array_search('deleteMessage',array_column($all,'method'),true);
    navigationCheck($deleted>$opened&&(int)$all[$deleted]['params']['message_id']===501,'the delivered audio is deleted only after the photo player is confirmed');
    navigationCheck(!glob($root.'/radio/files/*'),'preview artwork creates no retained local media files');
    $click(); navigationCheck($radio->queued(-1001)===1&&count($method('sendPhoto'))===1,'repeated Play reuses the station player without duplicating the song or panel');
    $row=$radio->queue(-1001,1)[0]; $snapshot=['id'=>(int)$row['id'],'title'=>'Work','artists'=>'Artist','duration'=>194,'elapsed'=>4,'at'=>microtime(true),'cover'=>''];
    $save=function() use($store,&$snapshot): void { $snapshot['at']=microtime(true); $store->set('radio.progress.-1001',json_encode($snapshot)); }; $save();
    $store->set('cookies.keepalive','0'); $store->set('cookies.alerts','0');
    $worker=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$temp.'/worker.log','w']],$pipes,null,
        ['PATH'=>getenv('PATH'),'BOT_TOKEN'=>'123:fixture','ADMIN_IDS'=>'42','STORAGE_DIR'=>$root,'BOT_API_URL'=>$config->api]);
    $count=count($method('editMessageCaption'));
    navigationWait(fn()=>count($method('editMessageCaption'))>$count,'the worker refreshes the photo caption automatically with no radio service or user click');
    $edits=$method('editMessageCaption'); $first=end($edits); $snapshot['elapsed']=65; $save(); $count=count($edits);
    navigationWait(fn()=>count($method('editMessageCaption'))>$count,'the same message receives the next periodic progress update');
    $edits=$method('editMessageCaption'); $second=end($edits);
    navigationCheck($first['params']['message_id']===$second['params']['message_id']&&$first['params']['caption']!==$second['params']['caption']&&preg_match('/01:0[56]/',$second['params']['caption'])===1,'elapsed minutes and the bar move in the existing panel message');
    preg_match('/<code>([━●─]+)/u',$first['params']['caption'],$firstBar); preg_match('/<code>([━●─]+)/u',$second['params']['caption'],$secondBar);
    navigationCheck(!empty($firstBar[1])&&!empty($secondBar[1])&&$firstBar[1]!==$secondBar[1]&&str_contains($second['params']['caption'],'03:14'),'the progress marker advances while the total duration stays correct');
    navigationCheck(count($method('sendPhoto'))===1&&!$method('editMessageMedia'),'periodic updates keep the Telegram preview cover without uploading it again');
    $radio->save(-1002,'Rejected photo','group',42,'rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf'); $radio->choose(42,-1002); file_put_contents($temp.'/mode.json','{"reject_photo":true}'); $before=count($method('deleteMessage'));
    $click(42,42,502); navigationCheck(count($method('deleteMessage'))===$before,'a rejected photo keeps the original audio available');
    navigationCheck((new Zero\Ui($store))->customizable('text','radio_player_preparing'),'the preparation state belongs to user Personalization');
    file_put_contents($temp.'/mode.json','{"delay_edit":5}'); $snapshot['elapsed']=100; $save(); $store->query('UPDATE radio_panels SET updated=0 WHERE chat_id=-1001'); $count=count($method('editMessageCaption'));
    navigationWait(fn()=>count($method('editMessageCaption'))>$count,'the shutdown fixture holds a real caption HTTP request open');
    $started=microtime(true); proc_terminate($worker); $until=$started+4;
    do { $status=proc_get_status($worker); if($status['running']) usleep(50000); } while($status['running']&&microtime(true)<$until);
    navigationCheck(!$status['running']&&microtime(true)-$started<3,'worker shutdown reaps a slow radio panel child without waiting for Telegram');
    proc_close($worker); $worker=null; $lock=fopen($root.'/worker.lock','c');
    navigationCheck(flock($lock,LOCK_EX|LOCK_NB),'the radio refresh child releases the inherited worker lock on shutdown'); flock($lock,LOCK_UN); fclose($lock);
} finally {
    foreach([$worker,$server] as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    Zero\Download::clean($temp);
}
