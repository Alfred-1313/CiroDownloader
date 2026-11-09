<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Radio.php';
require dirname(__DIR__).'/src/Download.php';
function transitionCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-radio-transition-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $children=[];
try {
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $radio=new Zero\Radio($config,$store);
    $radio->save(-1001,'Local radio','group',42,'rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf'); $radio->setLive(-1001,true);
    $runner=new Zero\Download($config); $ids=[];
    foreach([1=>8,2=>3] as $i=>$seconds) {
        $path=$temp.'/song-'.$i.'.m4a';
        $runner->run(['/usr/bin/ffmpeg','-v','error','-y','-f','lavfi','-i','sine=frequency='.(300+$i*200).':sample_rate=44100:duration='.$seconds,'-c:a','aac',$path],$temp,30);
        $id=$radio->enqueue(-1001,42,['service'=>'spotify','m'=>['title'=>'Song '.$i,'artists'=>['A'],'duration'=>$seconds]],'Song '.$i,'A',$seconds)['id']; $ids[]=$id;
        $store->query("UPDATE radio_queue SET status=?,file=? WHERE id=?",[$i===1?'ready':'pending',$path,$id]);
    }
    $cover=$temp.'/slow-cover.jpg'; $runner->run(['/usr/bin/ffmpeg','-v','error','-y','-filter_threads','1','-f','lavfi','-i','color=c=blue:s=300x300','-frames:v','1','-threads','1',$cover],$temp,30);
    $store->query('UPDATE radio_queue SET cover=? WHERE id=?',[$cover,$ids[1]]);
    $tools=$temp.'/tools'; mkdir($tools,0700);
    file_put_contents($tools.'/ffmpeg',"#!/usr/bin/env python3\nimport os,sys,time\nif any(x.endswith('slow-cover.jpg') for x in sys.argv):\n open(".var_export($temp.'/render-start',true).",'w').write(str(time.monotonic()))\n time.sleep(3.5)\nos.execv('/usr/bin/ffmpeg',['/usr/bin/ffmpeg']+sys.argv[1:])\n"); chmod($tools.'/ffmpeg',0700);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket); $rtmp='rtmp://'.$address.'/live/fixture';
    $receiver=proc_open(['python3','-B',__DIR__.'/fixtures/radio_receiver.py',$rtmp,$temp],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$temp.'/receiver-errors.log','w']],$pipes); $children[]=$receiver; usleep(400000);
    // A slow Telegram edit must not hold a database writer lock or the audio loop.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $apiAddress=stream_socket_get_name($socket,false); fclose($socket);
    $router=$temp.'/telegram.php';
    file_put_contents($router,'<?php file_put_contents('.var_export($temp.'/http-started',true).',"called"); usleep(3500000);header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>["message_id"=>900,"photo"=>[["file_id"=>"PANEL_FIXTURE"]]]]);');
    $api=proc_open([PHP_BINARY,'-S',$apiAddress,$router],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$temp.'/api.log','w']],$pipes); $children[]=$api; usleep(200000);
    $store->query("INSERT INTO radio_panels(chat_id,message_id,station_id,user_id,updated,expires) VALUES(42,900,-1001,42,0,?)",[time()+60]);
    $env=['PATH'=>$tools.':'.getenv('PATH'),'HOME'=>$temp,'BOT_TOKEN'=>'123:fixture','ADMIN_IDS'=>'42','STORAGE_DIR'=>$temp,'RADIO_TEST_OUTPUT'=>$rtmp,'RADIO_IDLE_SECONDS'=>'2','BOT_API_URL'=>'http://'.$apiAddress];
    $player=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/radio-player.php','-1001'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$temp.'/player.log','w']],$pipes,null,$env); $children[]=$player;
    $until=microtime(true)+10;
    while(microtime(true)<$until&&$store->query('SELECT status FROM radio_queue WHERE id=?',[$ids[0]])->fetchColumn()!=='playing') usleep(50000);
    transitionCheck($store->query('SELECT status FROM radio_queue WHERE id=?',[$ids[0]])->fetchColumn()==='playing','the first track starts before the next cover becomes available');
    $panel=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/radio-panel.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$temp.'/panel.log','w']],$pipes,null,$env); $children[]=$panel;
    usleep(1000000); $store->query("UPDATE radio_queue SET status='ready' WHERE id=?",[$ids[1]]);
    $until=microtime(true)+30;
    while(microtime(true)<$until&&($status=proc_get_status($player))['running']) usleep(50000);
    transitionCheck(!$status['running']&&$status['exitcode']===0,'both ready tracks finish through a real RTMP receiver'); proc_close($player);
    $until=microtime(true)+5; while(microtime(true)<$until&&proc_get_status($receiver)['running']) usleep(50000); proc_close($receiver);
    transitionCheck(is_file($temp.'/http-started')&&(int)$store->query('SELECT updated FROM radio_panels WHERE message_id=900')->fetchColumn()>0,'a deliberately slow Telegram panel edit completed while the radio was playing');
    transitionCheck(is_file($temp.'/render-start'),'the slow-cover fixture exercised image conversion during playback');
    $start=(float)file_get_contents($temp.'/render-start'); $events=array_map(fn($line)=>json_decode($line,true),file($temp.'/packets.jsonl',FILE_IGNORE_NEW_LINES));
    transitionCheck(count($events)>400,'the receiver observed actual AAC packets, including both tracks');
    $gap=0.0;
    for($i=1;$i<count($events);$i++) if($events[$i]['t']>=$start&&$events[$i-1]['t']<=$start+5) $gap=max($gap,$events[$i]['t']-$events[$i-1]['t']);
    transitionCheck($gap<1.8,'slow next-cover conversion never stalls the outgoing AAC packets (largest gap '.round($gap,3).' s)');
} finally {
    foreach($children as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    Zero\Download::clean($temp);
}
