<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/AdultHandoff.php';
require dirname(__DIR__).'/src/Download.php';
function processCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$root=sys_get_temp_dir().'/ciro-handoff-process-'.bin2hex(random_bytes(5)); mkdir($root,0700); $server=null; $service=null;
try {
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $log=$root.'/calls'; $update=$root.'/update'; file_put_contents($log,''); file_put_contents($update,'{}');
    $env=getenv(); $env['HANDOFF_TEST_LOG']=$log; $env['HANDOFF_TEST_UPDATE']=$update;
    $null=[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']];
    $server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/fixtures/handoff_telegram.php'],$null,$pipes,dirname(__DIR__),$env);
    for($i=0;$i<50;$i++) { $check=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($check) { fclose($check); break; } usleep(100000); }
    $config=new Zero\Config('123:main_fixture',[42],$root.'/storage','http://'.$address); $store=new Zero\Store($config->storage); $h=new Zero\AdultHandoff($config,$store);
    $store->set('adult.pornhub','1');
    $bot=new Zero\Bot($config,$store,new Zero\Telegram($config));
    $bot->handle(['callback_query'=>['id'=>'fixture','from'=>['id'=>42],'message'=>['message_id'=>70,'chat'=>['id'=>42,'type'=>'private']],'data'=>'custom:handofftoken']]);
    $bot->handle(['message'=>['message_id'=>71,'from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'text'=>'456:fixture_secret']]);
    $registered=$h->token()==='456:fixture_secret';
    $events=array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]);
    processCheck($registered&&(bool)array_filter($events,fn($r)=>$r['method']==='deleteMessage'&&$r['message']==='71'),'private admin token import deletes the token message and validates the second bot');
    $store->set('adult.handoff.enabled','1');
    $video=$root.'/video.mp4'; file_put_contents($video,'fixture-video-bytes');
    $link=$h->prepare(['user_id'=>42,'chat_id'=>42],['path'=>$video,'width'=>320,'height'=>240,'duration'=>2],'pornhub','<b>Neutral caption</b>',10,true);
    $h->linkMessage($link,42,99,'text'); preg_match('/start=v_([a-f0-9]{32})/',$link,$m); $key=$m[1];
    file_put_contents($update,json_encode(['update_id'=>5,'message'=>['from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'text'=>'/start v_'.$key]]));
    $env['BOT_TOKEN']='123:main_fixture'; $env['ADMIN_IDS']='42'; $env['STORAGE_DIR']=$config->storage; $env['BOT_API_URL']=$config->api;
    $service=proc_open([PHP_BINARY,'bin/adult-handoff.php'],$null,$pipes,dirname(__DIR__),$env);
    $db=new PDO('sqlite:'.$config->storage.'/adult-handoff/handoff.sqlite'); $db->setAttribute(PDO::ATTR_TIMEOUT,5);
    $sent=false;
    for($i=0;$i<150;$i++) { if($db->query("SELECT status FROM items WHERE token='$key'")->fetchColumn()==='sent') { $sent=true; break; } usleep(100000); }
    processCheck($sent,'supervisor polls the second bot and delivers the queued video');
    $db->exec("UPDATE items SET expires=".(time()-1)." WHERE token='$key'"); $db->exec('UPDATE deletes SET at=0');
    $records=[]; $done=false;
    for($i=0;$i<100;$i++) {
        $lines=file($log,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); $records=array_map(fn($line)=>json_decode($line,true),$lines?:[]);
        if(array_filter($records,fn($r)=>$r['method']==='editMessageText'&&$r['message']==='99')&&array_filter($records,fn($r)=>$r['method']==='deleteMessage'&&$r['message']==='901')) { $done=true; break; }
        usleep(100000);
    }
    processCheck($done,'supervisor independently expires the original link and deletes the second bot video');
    $videos=array_values(array_filter($records,fn($r)=>$r['method']==='sendVideo'));
    processCheck(count($videos)===1&&$videos[0]['spoiler']==='1'&&$videos[0]['bytes']===strlen('fixture-video-bytes')&&$videos[0]['caption']==='<b>Neutral caption</b>','the actual multipart request contains the file, caption and native spoiler exactly once');
} finally {
    foreach([$service,$server] as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    Zero\Download::clean($root);
}
