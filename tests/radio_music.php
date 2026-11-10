<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function rmCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }

// A made-up address and key: real ones are secrets and never belong in a test.
$KEY='rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf';
$artistId='3TVXtAsR1Inumwj472S9r4'; $albumId='4yP0hdKOZPNshxUOjY0cZj'; $trackId='t1'.str_repeat('x',20);
$temp=sys_get_temp_dir().'/ciro-radio-music-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); mkdir($config->storage,0700); mkdir($config->storage.'/downloads',0700); $runner=new Zero\Download($config);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','sine=frequency=440:sample_rate=44100:duration=12','-c:a','aac',$temp.'/source.m4a'],$temp,30);
    $log=$temp.'/http.jsonl'; $ytLog=$temp.'/yt.jsonl'; $mode=$temp.'/mode.json'; file_put_contents($log,''); file_put_contents($ytLog,''); file_put_contents($mode,'{}');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/fixtures/music_sites.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes,null,array_merge(getenv(),['FIXTURE_ROOT'=>$temp]));
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base; $config->paceMessages=false;
    $stub=$temp.'/yt-dlp-fixture';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\nconst FIXTURE_ROOT=".var_export($temp,true).";\nrequire ".var_export(__DIR__.'/fixtures/music_ytdlp.php',true).";\n");
    chmod($stub,0700); $config->ytDlp=$stub;
    $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
    $catalog=new Zero\MusicCatalog($config,$base,$base.'/deezer',$base.'/itunes',$base.'/sc',$base.'/mb'); $catalog->pause=0; $soundcloud=new Zero\SoundCloud($config,$base);
    $bot->musicCatalog=$catalog; $bot->musicSoundCloud=$soundcloud;
    $worker=new Zero\Worker($config,$store,$api,null,new Zero\Spotify($config,$base),$soundcloud,$catalog);
    $radio=new Zero\Radio($config,$store); $store->set('radio.access','chat');
    $records=fn(string $file)=>array_map(fn($line)=>json_decode($line,true),file($file,FILE_IGNORE_NEW_LINES));
    $calls=fn()=>$records($log); $called=fn(string $method)=>array_values(array_filter($calls(),fn($call)=>$call['method']===$method));
    $update=0; $group=-100123; $store->query('INSERT INTO users(id) VALUES(55),(66),(42)');
    $send=function(string $text,int $user,int $chat) use($bot,&$update,$store) {
        $update++; $store->query('UPDATE users SET last_request=0');
        $bot->handle(['update_id'=>$update,'message'=>['message_id'=>$update,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0,'text'=>$text]]);
    };
    $tap=function(string $data,int $user,int $chat) use($bot,&$update,$store) {
        $update++; $store->query('UPDATE users SET last_request=0');
        $message=(int)$store->query('SELECT message_id FROM music_menus WHERE user_id=? AND chat_id=? ORDER BY id DESC LIMIT 1',[$user,$chat])->fetchColumn();
        $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'cb'.$update,'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0],'data'=>$data]]);
    };
    $menuId=fn()=>(int)$store->query('SELECT id FROM music_menus ORDER BY id DESC LIMIT 1')->fetchColumn();
    $answerText=function() use($called): string { $found=$called('answerCallbackQuery'); return $found?(string)end($found)['params']['text']:''; };
    $screen=function() use($calls) {
        $found=array_values(array_filter($calls(),fn($call)=>in_array($call['method'],['editMessageText','sendMessage','editMessageCaption','editMessageMedia','sendPhoto'],true)&&str_contains((string)($call['params']['reply_markup']??''),'mm:')));
        if(!$found) return ['data'=>[],'labels'=>[]]; $params=end($found)['params']; $buttons=array_merge(...json_decode($params['reply_markup'],true)['inline_keyboard']);
        return ['data'=>array_column($buttons,'callback_data'),'labels'=>array_column($buttons,'text')];
    };
    $queue=fn()=>$store->query("SELECT * FROM radio_queue WHERE status IN ('pending','ready','preparing','playing') ORDER BY id")->fetchAll();
    $sources=fn()=>array_map(fn($row)=>json_decode($row['source'],true),$queue());

    $radio->save($group,'The Group','group',55,$KEY); $radio->setLive($group,true);

    // 1. A song sent to a group that has a radio carries a play button; only the group's administrators can use it.
    $send('https://open.spotify.com/track/'.$trackId,55,$group); $job=$store->claim('media'); $worker->process($job);
    $audio=$called('sendAudio'); $markup=json_decode((string)end($audio)['params']['reply_markup'],true)['inline_keyboard']??[];
    $data=array_column(array_merge(...$markup),'callback_data');
    rmCheck(count($data)===1&&preg_match('/^rd:p:[A-Za-z0-9_-]{16}$/D',$data[0])===1,'a song sent to a group with a radio carries a play button');
    $token=substr($data[0],5); $picked=$radio->picked($token);
    rmCheck($picked!==null&&$picked['source']['service']==='spotify'&&$picked['source']['u']==='https://open.spotify.com/track/'.$trackId&&$picked['title']!==''&&$picked['duration']>0,'the button stands for that song\'s source, title and length');
    $update++; $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'p1','from'=>['id'=>66,'is_bot'=>false],'message'=>['message_id'=>5,'chat'=>['id'=>$group,'type'=>'supergroup'],'message_thread_id'=>321],'data'=>$data[0]]]);
    rmCheck($radio->queued($group)===0,'a member of the group pressing it queues nothing');
    $update++; $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'p2','from'=>['id'=>55,'is_bot'=>false],'message'=>['message_id'=>5,'chat'=>['id'=>$group,'type'=>'supergroup'],'message_thread_id'=>321],'data'=>$data[0]]]);
    rmCheck($radio->queued($group)===1&&$sources()[0]['u']==='https://open.spotify.com/track/'.$trackId,'an administrator of the group queues it');
    $radio->clear($group);
    // A group without a radio, or a private chat of somebody who runs none, gets no button.
    $send('https://open.spotify.com/track/'.$trackId,55,55); $job=$store->claim('media'); $worker->process($job);
    $audio=$called('sendAudio'); $private=json_decode((string)end($audio)['params']['reply_markup']??'[]',true)['inline_keyboard']??[];
    rmCheck(count(array_filter(array_merge(...($private?:[[]])),fn($button)=>str_starts_with((string)($button['callback_data']??''),'rd:p:')))===1,'in a private chat the button appears for a person who runs a radio (here the group administrator)');
    $send('https://open.spotify.com/track/'.$trackId,66,66); $job=$store->claim('media'); $worker->process($job);
    $audio=$called('sendAudio'); $stranger=json_decode((string)(end($audio)['params']['reply_markup']??'[]'),true)['inline_keyboard']??[];
    rmCheck(!array_filter(array_merge(...($stranger?:[[]])),fn($button)=>str_starts_with((string)($button['callback_data']??''),'rd:p:')),'and not for somebody who runs none');

    $store->set('radio.access','all'); $send('https://open.spotify.com/track/'.$trackId,66,$group); $job=$store->claim('media'); $worker->process($job);
    $audio=$called('sendAudio'); $public=json_decode((string)end($audio)['params']['reply_markup'],true)['inline_keyboard']??[];
    $publicButtons=array_values(array_filter(array_column(array_merge(...($public?:[[]])),'callback_data'),fn($data)=>str_starts_with((string)$data,'rd:p:')));
    rmCheck(count($publicButtons)===1,'when public access is enabled an ordinary user receives the radio play button');
    $update++; $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'public-play','from'=>['id'=>66,'is_bot'=>false],'message'=>['message_id'=>5,'chat'=>['id'=>$group,'type'=>'supergroup'],'message_thread_id'=>321],'data'=>$publicButtons[0]]]);
    rmCheck($radio->queued($group)===1,'the ordinary user can use the delivered play button to queue the song'); $radio->clear($group); $store->set('radio.access','chat');

    // 2. "Play all" in an album: every song, first to last, for the administrator who owns the menu.
    $send('https://open.spotify.com/album/'.$albumId,55,$group); $job=$store->claim('inspect'); $worker->process($job);
    $view=$screen(); $id=$menuId();
    rmCheck(in_array('mm:'.$id.':y',$view['data'],true)&&in_array('mm:'.$id.':a:each',$view['data'],true)&&in_array('▶️ پخش همه',$view['labels'],true),'an album menu offers "play all" next to "download all"');
    $tap('mm:'.$id.':y',55,$group); $queued=$queue();
    rmCheck(count($queued)===3&&array_column($queued,'title')===['Hit Song 1','Hit Song 2','Hit Song 3']&&str_contains($answerText(),'3 آهنگ'),'pressing it queues all three songs in the order of the list');
    rmCheck(array_unique(array_column($sources(),'service'))===['spotify']&&str_starts_with((string)$sources()[0]['u'],'https://open.spotify.com/track/'),'each keeps the address it would be downloaded from');
    $radio->clear($group);
    // A member who owns a menu in the group is not offered it.
    $send('https://open.spotify.com/album/'.$albumId,66,$group); $job=$store->claim('inspect'); $worker->process($job);
    rmCheck(!in_array('mm:'.$menuId().':y',$screen()['data'],true),'a member of the group is not offered "play all"');

    // 3. Choose some songs and play only those, in list order.
    $send('https://open.spotify.com/album/'.$albumId,55,$group); $job=$store->claim('inspect'); $worker->process($job); $id=$menuId();
    $tap('mm:'.$id.':m:1',55,$group); $tap('mm:'.$id.':k:2',55,$group); $tap('mm:'.$id.':k:0',55,$group); $view=$screen();
    rmCheck(in_array('mm:'.$id.':y:p',$view['data'],true)&&!in_array('mm:'.$id.':y',$view['data'],true),'choosing songs offers "play chosen" instead of "play all"');
    $tap('mm:'.$id.':y:p',55,$group);
    rmCheck(array_column($queue(),'title')===['Hit Song 1','Hit Song 3'],'only the chosen songs are queued, in the order of the list');
    $radio->clear($group);

    // 4. An artist's popular songs and a search result list can be played too.
    $send('https://open.spotify.com/artist/'.$artistId,55,$group); $job=$store->claim('inspect'); $worker->process($job); $id=$menuId(); $view=$screen();
    rmCheck(in_array('mm:'.$id.':y',$view['data'],true)&&!in_array('mm:'.$id.':a:each',$view['data'],true),'an artist\'s popular songs offer "play all" although they offer no "download all"');
    $tap('mm:'.$id.':y',55,$group); rmCheck($radio->queued($group)===10,'all ten popular songs are queued');
    $radio->clear($group);
    $send('/music pishro-separ',55,55); $job=$store->claim('inspect'); $worker->process($job); $id=$menuId(); $view=$screen();
    rmCheck(in_array('mm:'.$id.':y',$view['data'],true),'a list of search results offers "play all" to a person who runs a radio');
    $tap('mm:'.$id.':y',55,55); rmCheck($radio->queued($group)===2&&$answerText()!=='','and queues them on that radio');
    $radio->clear($group);

    // 5. Without a radio nothing is offered; with the radio off, nothing either.
    $send('https://open.spotify.com/album/'.$albumId,66,66); $job=$store->claim('inspect'); $worker->process($job);
    rmCheck(!in_array('mm:'.$menuId().':y',$screen()['data'],true),'a person who runs no radio is offered no "play all"');
    $store->set('radio.enabled','0'); $send('https://open.spotify.com/album/'.$albumId,55,$group); $job=$store->claim('inspect'); $worker->process($job);
    rmCheck(!in_array('mm:'.$menuId().':y',$screen()['data'],true),'with the radio switched off the button is gone');
    $store->set('radio.enabled','1');

    // 6. "Download all": every song sent carries the play button.
    $send('https://open.spotify.com/album/'.$albumId,55,$group); $job=$store->claim('inspect'); $worker->process($job); $id=$menuId();
    $before=count($called('sendAudio')); $tap('mm:'.$id.':a:each',55,$group); $job=$store->claim('media'); $worker->process($job);
    $sent=array_slice($called('sendAudio'),$before);
    $withButton=array_filter($sent,fn($call)=>(bool)array_filter(array_merge(...json_decode((string)($call['params']['reply_markup']??'[]'),true)['inline_keyboard']),fn($button)=>str_starts_with((string)($button['callback_data']??''),'rd:p:')));
    rmCheck(count($sent)===3&&count($withButton)===3,'every song of a whole album sent to the group carries the play button');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    exec('rm -rf '.escapeshellarg($temp));
}
