<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function epCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$link='https://www.eporner.com/video-HmstDuT4BNE/neutral-fixture/'; $canonical='https://www.eporner.com/video-HmstDuT4BNE/';
foreach([$link,'eporner.com/video-HmstDuT4BNE','http://eporner.com/hd-porn/HmstDuT4BNE/title/?tracking=1','https://www.eporner.com/embed/HmstDuT4BNE','https://www.eporner.com/video-HmstDuT4BNE/?tracking=1'] as $url) epCheck(Zero\epornerUrl($url)===$canonical,'video, legacy and embed Eporner links are canonicalized');
foreach(['https://www.eporner.com/','https://www.eporner.com/search/test/','https://www.eporner.com/profile/test/','https://eporner.com.evil.test/video-HmstDuT4BNE/','https://eporner.com@evil.test/video-HmstDuT4BNE/','https://eporner.com:443/video-HmstDuT4BNE/','ftp://www.eporner.com/video-HmstDuT4BNE/','https://www.eporner.com/video-HmstDuT4BNE/title/extra','https://www.eporner.com/video-abc%2Fdef/','https://www.eporner.com/video-/'] as $url) epCheck(Zero\epornerUrl($url)===null,'non-video or unsafe Eporner links are rejected');
epCheck(Zero\mediaUrl($link)===null&&Zero\adultService($link)==='eporner','Eporner uses the restricted adult intake');
$temp=sys_get_temp_dir().'/ciro-eporner-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null; $supervisor=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); $store=new Zero\Store($config->storage); mkdir($config->storage.'/downloads',0700); $runner=new Zero\Download($config);
    foreach([240=>[426,240],480=>[854,480],1440=>[2560,1440]] as $height=>[$width,$pixels]) {
        mkdir($temp.'/'.$height,0700);
        $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s='.$width.'x'.$pixels.':r=5:d=2','-f','lavfi','-i','sine=frequency=440:duration=2','-c:v','libx264','-threads','1','-pix_fmt','yuv420p','-c:a','aac','-shortest','-hls_time','2','-hls_list_size','0',$temp.'/'.$height.'/stream.m3u8'],$temp,60);
    }
    foreach([240,480,1440] as $height) $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$temp.'/'.$height.'/stream.m3u8','-c','copy',$temp.'/'.$height.'/'.$height.'P_300K_HmstDuT4BNE.mp4'],$temp,30);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=red:s=640x360','-frames:v','1','-threads','1',$temp.'/cover.jpg'],$temp,30);
    file_put_contents($temp.'/master.m3u8',"#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=150000,RESOLUTION=426x240,CODECS=\"avc1.640015,mp4a.40.2\"\n/240/stream.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=300000,RESOLUTION=854x480,CODECS=\"avc1.64001f,mp4a.40.2\"\n/480/stream.m3u8\n");
    $log=$temp.'/http.jsonl'; $trace=$temp.'/backend.jsonl'; $mode=$temp.'/mode.json'; file_put_contents($log,''); file_put_contents($trace,''); file_put_contents($mode,'{}');
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php $root='.var_export($temp,true).';$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);'. <<<'PHP'
        if($path==='/cover.jpg') { header('Content-Type: image/jpeg');readfile($root.'/cover.jpg');return; }
        if($path==='/media') {
            $base='http://'.$_SERVER['HTTP_HOST'];header('Content-Type: application/json');
            $mp4=[]; foreach([240,480] as $height) $mp4[$height.'p']=['src'=>$base.'/'.$height.'/'.$height.'P_300K_HmstDuT4BNE.mp4'];
            $mp4['1440p']=['src'=>$base.'/1440/1440P_300K_HmstDuT4BNE.mp4'];
            echo json_encode(['available'=>true,'sources'=>['hls'=>['auto'=>['src'=>$base.'/master.m3u8']],'mp4'=>$mp4]]);return;
        }
        if(preg_match('~^/(240|480|1440)/(?:240|480|1440)P_300K_HmstDuT4BNE\.mp4$~D',$path)) { header('Content-Type: video/mp4');readfile($root.$path);return; }
        if($path==='/master.m3u8'||preg_match('~^/(240|480)/stream[0-9]*\.(m3u8|ts)$~D',$path)) {
            header('Content-Type: '.(str_ends_with($path,'.m3u8')?'application/vnd.apple.mpegurl':'video/mp2t'));readfile($root.$path);return;
        }
PHP
        .
        '$method=basename($path);$uploads=[];foreach($_FILES as $key=>$file) $uploads[$key]=["bytes"=>filesize($file["tmp_name"]),"mime"=>$file["type"]];'.
        '$counter=$root."/counter";$next=(int)@file_get_contents($counter)+1;file_put_contents($counter,(string)$next);'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads,"result_message_id"=>(int)($_POST["message_id"]??900+$next),"at"=>microtime(true)],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        '$result=str_starts_with($method,"send")||str_starts_with($method,"edit")?["message_id"=>(int)($_POST["message_id"]??900+$next)]:true;'.
        '$settings=json_decode(file_get_contents($root."/mode.json"),true);'.
        'if(in_array($method,["sendVideo","sendAudio","editMessageCaption"],true)) $result["caption"]=($method==="sendVideo"&&!empty($settings["drop_caption"]))?"":html_entity_decode(strip_tags((string)($_POST["caption"]??"")),ENT_QUOTES|ENT_HTML5,"UTF-8");'.
        'if($method==="sendPhoto"||$method==="editMessageMedia") $result["photo"]=[["file_id"=>"FIXTURE_EPORNER_COVER"]];'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>$result]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-errors.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base;
    $python=getenv('YTDLP_TEST_PYTHON')?:(is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'/usr/bin/python3'); $stub=$temp.'/yt-eporner';
    file_put_contents($stub,'#!'.$python."\nimport runpy\nrunpy.run_path(".json_encode(__DIR__.'/fixtures/eporner_ytdlp.py',JSON_UNESCAPED_SLASHES).',init_globals='.json_encode(['MODE'=>$mode,'TRACE'=>$trace,'BASE'=>$base,'DATABASE'=>$config->storage.'/zero.sqlite'],JSON_UNESCAPED_SLASHES).',run_name="__main__")' ."\n"); chmod($stub,0700); $config->ytDlp=$stub;
    $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api); $worker=new Zero\Worker($config,$store,$api); $serial=0;
    (new Zero\GroupSupport($bot))->cacheIdentity(['id'=>123,'username'=>'FixtureBot']);
    $records=fn()=>array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    $calls=fn(string $method)=>array_values(array_filter($records(),fn($call)=>$call['method']===$method));
    $send=function(int $user=42,int $chat=42,string $text='') use($bot,$store,&$serial,$link): void { $store->query('UPDATE users SET last_request=0'); $bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0,'text'=>$text!==''?$text:$link]]); };
    $click=function(string $data,int $user=42,int $chat=42,int $message=700) use($bot,&$serial): void { $bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'b'.$serial,'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0],'data'=>$data]]); };
    $jobs=fn()=>(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    $send(); $messages=$calls('sendMessage'); epCheck($jobs()===0&&str_contains(end($messages)['params']['text'],'Eporner خاموش است'),'Eporner defaults off and tells only the administrator how to enable it');
    $store->set('adult.pornhub','1'); $send(); epCheck($jobs()===0,'enabling Pornhub does not enable Eporner');
    $click('custom:adulteporner'); epCheck($store->get('adult.eporner')==='1'&&$store->get('adult.pornhub')==='1','Eporner has its own one-tap switch');
    $store->set('adult.pornhub','0'); epCheck($bot->adultAllowed(42,42,'eporner')&&!$bot->adultAllowed(42,42),'Eporner stays enabled independently of Pornhub');
    $send(7,7); epCheck($jobs()===0,'administrators-only is shared and defaults on');
    $click('custom:adulteporner',7,7); epCheck($store->get('adult.eporner')==='1','ordinary users cannot change the Eporner switch');
    foreach(['caption','notice','people','views','tags','error_size','error_restricted','error_format','error_network','error_blocked'] as $suffix) epCheck($bot->ui->customizable('text','eporner_'.$suffix),'Eporner user text is independently customizable: '.$suffix);
    $click('custom:section:eporner'); $edits=$calls('editMessageText'); $markup=json_decode(end($edits)['params']['reply_markup'],true)['inline_keyboard']; epCheck((bool)$markup,'Eporner has its own Personalization screen');
    $store->set('groups_enabled','0'); file_put_contents($log,''); $send(42,-100123); $messages=$calls('sendMessage'); epCheck($jobs()===0&&count($messages)===1&&(int)$messages[0]['params']['chat_id']===42,'disabled group use is explained privately to the administrator'); $store->set('groups_enabled','1');
    $store->set('adult.admins_only','0'); $send(7,7); epCheck($jobs()===1,'turning administrators-only off permits ordinary users'); $store->query('DELETE FROM jobs'); $store->set('adult.admins_only','1');
    Zero\saveYouTubeCookies($config,"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_SESSION\n");
    $send(42,-100123,'/download@FixtureBot '.$link); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    if($saved['status']!=='waiting_quality') throw new RuntimeException($store->get('last_job_error'));
    $options=json_decode($saved['options'],true); epCheck($saved['url']===$canonical&&isset($options[240],$options[480],$options['audio']),'the Eporner fixture link reaches its actual extractor and produces actual HLS qualities');
    epCheck(str_starts_with($options[480][0]['id'],'hls-')&&!str_contains($saved['options'],'fixture-token'),'HLS is preferred over slow MP4 and source URLs are not retained');
    $posters=$calls('editMessageMedia'); epCheck($saved['message_kind']==='photo'&&end($posters)['uploads']['poster']['bytes']>0,'the quality menu contains the uploaded cover');
    $cover=json_decode(end($posters)['params']['media'],true); epCheck($cover['caption']===$bot->ui->coverCaption('Neutral Fixture Video',(string)round($config->maxBytes/1000000,1))&&end($posters)['uploads']['poster']['mime']==='image/jpeg','the photo and quality-menu caption follow the shared Pornhub flow');
    $args=json_decode(file($trace)[0],true); epCheck(!in_array('--cookies',$args,true),'YouTube cookies are never sent to Eporner');
    $click('quality:'.$job['id'].':480',7,-100123,(int)$saved['message_id']); epCheck($store->claim('media')===null,'another member cannot select a quality');
    $click('quality:'.$job['id'].':480',42,-100123,(int)$saved['message_id']); $worker->process($store->claim('media')); $videos=$calls('sendVideo');
    if(!$videos) throw new RuntimeException($store->get('last_job_error'));
    $video=end($videos); epCheck($video['uploads']['video']['bytes']>0&&(int)$video['params']['message_thread_id']===321&&str_contains($video['params']['caption'],'🎞 480p')&&str_starts_with($video['params']['caption'],'<blockquote>🗑')&&!isset($video['params']['reply_markup']),'the chosen video is delivered with quality and automatic deletion notice in the original topic');
    epCheck(($video['uploads']['cover']['mime']??'')==='image/jpeg'&&($video['uploads']['cover']['bytes']??0)>0&&($video['uploads']['thumbnail']['bytes']??0)>0,'the delivered video includes both its actual site cover and thumbnail');
    epCheck((int)$store->query('SELECT COUNT(*) FROM timed_deletes')->fetchColumn()===1&&(int)$store->query("SELECT ok FROM stats WHERE service='eporner'")->fetchColumn()===1,'the video is scheduled for deletion and counted for Eporner');
    $deletion=$store->query('SELECT * FROM timed_deletes')->fetch();
    epCheck((int)$deletion['chat_id']===-100123&&(int)$deletion['message_id']===$video['result_message_id']&&(int)$deletion['at']>=time()+8,'the shared timer targets the delivered Eporner video, not the quality menu');
    // Start the actual worker after delivery: persisted timers must run without another bot message.
    $store->set('cookie_sweep_last',(string)time()); $store->set('cookies.keepalive','0');
    $environment=array_merge(getenv(),['BOT_TOKEN'=>$config->token,'ADMIN_IDS'=>'42','STORAGE_DIR'=>$config->storage,'BOT_API_URL'=>$base,'YT_DLP_BIN'=>$stub,'YOUTUBE_PROXY'=>'','YOUTUBE_COOKIES_FILE'=>'','WORKER_CONCURRENCY'=>'1']);
    $supervisor=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/worker.log','w'],2=>['file',$temp.'/worker-error.log','w']],$pipes,dirname(__DIR__),$environment);
    $until=microtime(true)+18;
    do { if(!(int)$store->query('SELECT COUNT(*) FROM timed_deletes')->fetchColumn()) break; usleep(100000); } while(microtime(true)<$until&&proc_get_status($supervisor)['running']);
    $removed=array_values(array_filter($calls('deleteMessage'),fn($call)=>(int)$call['params']['chat_id']===-100123&&(int)$call['params']['message_id']===$video['result_message_id']));
    epCheck(count($removed)===1&&$removed[0]['at']>=(int)$deletion['at']&&!(int)$store->query('SELECT COUNT(*) FROM timed_deletes')->fetchColumn(),'the actual supervisor deletes Eporner after its delay from a persisted timer, without another user action');
    proc_terminate($supervisor); proc_close($supervisor); $supervisor=null;
    $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); $click('quality:'.$job['id'].':audio',42,42,(int)$saved['message_id']); $worker->process($store->claim('media')); $audio=$calls('sendAudio'); epCheck((bool)$audio&&end($audio)['uploads']['audio']['mime']==='audio/mpeg','audio is delivered as MP3');
    $audio=end($audio); $deletion=$store->query('SELECT * FROM timed_deletes')->fetch();
    epCheck((int)$deletion['chat_id']===42&&(int)$deletion['message_id']===$audio['result_message_id']&&str_starts_with($audio['params']['caption'],'<blockquote>🗑'),'private Eporner audio shares the same deletion notice and timer');
    $store->query('DELETE FROM timed_deletes');
    // Reproduce the deployment settings: private off, group on, delay 180 seconds.
    $store->set('adult.delete_private','0'); $store->set('adult.delete_group','1'); $store->set('adult.delete_seconds','180');
    // A valid saved template may render empty when this video has no optional metadata.
    $store->set('text.eporner_caption','{tags}'); $store->set('text.eporner_notice'," \n");
    foreach([42,-100123] as $chat) {
        file_put_contents($mode,json_encode(['drop_caption'=>$chat<0]));
        $send(42,$chat); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
        $click('quality:'.$job['id'].':240',42,$chat,(int)$saved['message_id']); $worker->process($store->claim('media')); $videos=$calls('sendVideo'); $sent=end($videos);
        $deletion=$store->query('SELECT * FROM timed_deletes WHERE chat_id=? AND message_id=?',[$chat,$sent['result_message_id']])->fetch();
        epCheck($chat>0?(!$deletion&&!str_contains($sent['params']['caption'],'🗑')):($deletion&&(int)$deletion['at']>=time()+178&&str_contains($sent['params']['caption'],'180 ثانیه')),'deployment settings: '.($chat>0?'private-off omits both notice and timer':'group-on keeps its notice and 180-second timer independently of private-off'));
        epCheck(str_contains($sent['params']['caption'],'Neutral Fixture Video')&&str_contains($sent['params']['caption'],'240p'),'an empty rendered saved caption falls back to the title, duration and actual quality');
        $repairs=array_values(array_filter($calls('editMessageCaption'),fn($call)=>(int)$call['params']['message_id']===$sent['result_message_id']));
        epCheck($chat>0?!$repairs:(count($repairs)===1&&$repairs[0]['params']['caption']===$sent['params']['caption']&&!isset($repairs[0]['uploads']['video'])),'a missing Telegram caption is repaired once on the delivered message without another media upload; confirmed captions need no repair');
        epCheck($bot->adultDeleteAfter($chat)===($chat>0?0:180),'Eporner and Pornhub read the same chat-specific filter and delay');
    }
    $store->query("DELETE FROM settings WHERE key IN ('text.eporner_caption','text.eporner_notice')"); file_put_contents($mode,'{}');
    $store->set('adult.delete_private','1'); $store->set('adult.delete_seconds','10');
    // The native extractor offers resolutions beyond the older fixed 1080p ladder too.
    $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    $options=json_decode($saved['options'],true); $menus=$calls('editMessageMedia'); $menu=end($menus);
    epCheck(isset($options[1440])&&!isset($options[720])&&!isset($options[1080]),'native 1440p is offered without inventing absent 720p or 1080p formats');
    epCheck(str_contains($menu['params']['reply_markup'],'quality:'.$job['id'].':1440'),'native resolution has a selectable quality button');
    $click('quality:'.$job['id'].':2160',42,42,(int)$saved['message_id']); epCheck($store->claim('media')===null,'an unoffered resolution cannot be selected');
    $click('quality:'.$job['id'].':1440',42,42,(int)$saved['message_id']); $worker->process($store->claim('media')); $videos=$calls('sendVideo'); $sent=end($videos);
    epCheck(str_contains($sent['params']['caption'],'1440p')&&(int)$sent['params']['height']===1440,'actual native MP4 resolution survives selection, preparation and delivery');
    file_put_contents($mode,json_encode(['no_cover'=>true])); $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    epCheck($saved['status']==='waiting_quality'&&$saved['message_kind']==='text','a temporarily missing cover does not disable quality selection'); $click('quality:'.$job['id'].':cancel',42,42,(int)$saved['message_id']);
    file_put_contents($mode,json_encode(['disable'=>'inspect'])); $send(); $job=$store->claim('inspect'); $before=count($calls('editMessageMedia')); $worker->process($job);
    epCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed'&&count($calls('editMessageMedia'))===$before,'turning Eporner off during inspection prevents offering the menu'); $store->set('adult.eporner','1');
    file_put_contents($mode,'{}'); $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); $click('quality:'.$job['id'].':480',42,42,(int)$saved['message_id']); file_put_contents($mode,json_encode(['disable'=>'download'])); $before=count($calls('sendVideo')); $worker->process($store->claim('media'));
    epCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed'&&count($calls('sendVideo'))===$before,'turning Eporner off during download prevents the final upload'); $store->set('adult.eporner','1'); file_put_contents($mode,'{}');
    $send(); $job=$store->claim('inspect'); $before=count(file($trace)); $store->set('adult.eporner','0'); $worker->process($job);
    epCheck(count(file($trace))===$before&&$store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed','disabling Eporner stops queued work before any downloader request');
    $store->set('adult.eporner','1'); file_put_contents($mode,json_encode(['blocked'=>true])); $send(); $job=$store->claim('inspect'); $worker->process($job);
    epCheck($store->get('last_job_service')==='eporner'&&$store->get('last_job_sources')==='eporner=forbidden'&&!str_contains($store->get('recent_errors'),'hidden.example'),'network refusals retain only a clean service-specific diagnostic');
    epCheck(!glob($config->storage.'/downloads/job-*'),'Eporner covers and media are cleaned after success and failure');
} finally { foreach([$supervisor,$server] as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); } Zero\Download::clean($temp); }
