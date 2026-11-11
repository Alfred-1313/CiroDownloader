<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function ypCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$link='https://www.youporn.com/watch/189780301/'; $canonical=$link;
foreach([$link,'youporn.com/watch/189780301','https://www.youporn.com/watch/189780301/fixture_title/?tracking=1','https://www.youporn.com/watch/189780301/عنوان_نمونه/','https://www.youporn.com/embed/189780301/fixture_title'] as $url) ypCheck(Zero\youpornUrl($url)===$canonical,'watch, titled and embed YouPorn links are canonicalized');
foreach(['https://www.youporn.com/','https://www.youporn.com/search/?query=test','https://www.youporn.com/porntags/test/','https://www.youporn.com/model/test/','https://youporn.com.evil.test/watch/189780301/','https://youporn.com@evil.test/watch/189780301/','https://youporn.com:443/watch/189780301/','ftp://www.youporn.com/watch/189780301/','https://www.youporn.com/watch/189780301/title/extra'] as $url) ypCheck(Zero\youpornUrl($url)===null,'non-video or unsafe YouPorn links are rejected');
ypCheck(Zero\mediaUrl($link)===null&&Zero\adultService($link)==='youporn','YouPorn uses the restricted adult intake');
$temp=sys_get_temp_dir().'/ciro-youporn-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); $store=new Zero\Store($config->storage); mkdir($config->storage.'/downloads',0700); $runner=new Zero\Download($config);
    foreach([240=>[426,240],480=>[854,480]] as $height=>[$width,$pixels]) {
        mkdir($temp.'/'.$height,0700);
        $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s='.$width.'x'.$pixels.':r=5:d=2','-f','lavfi','-i','sine=frequency=440:duration=2','-c:v','libx264','-threads','1','-pix_fmt','yuv420p','-c:a','aac','-shortest','-hls_time','2','-hls_list_size','0',$temp.'/'.$height.'/stream.m3u8'],$temp,60);
    }
    foreach([240,480] as $height) $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$temp.'/'.$height.'/stream.m3u8','-c','copy',$temp.'/'.$height.'/'.$height.'P_300K_189780301.mp4'],$temp,30);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=red:s=640x360','-frames:v','1','-threads','1',$temp.'/cover.jpg'],$temp,30);
    file_put_contents($temp.'/master.m3u8',"#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=150000,RESOLUTION=426x240,CODECS=\"avc1.640015,mp4a.40.2\"\n/240/stream.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=300000,RESOLUTION=854x480,CODECS=\"avc1.64001f,mp4a.40.2\"\n/480/stream.m3u8\n");
    $log=$temp.'/http.jsonl'; $trace=$temp.'/backend.jsonl'; $mode=$temp.'/mode.json'; file_put_contents($log,''); file_put_contents($trace,''); file_put_contents($mode,'{}');
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php $root='.var_export($temp,true).';$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);'. <<<'PHP'
        if($path==='/cover.jpg') { header('Content-Type: image/jpeg');readfile($root.'/cover.jpg');return; }
        if($path==='/media-hls'||$path==='/media-mp4') {
            $base='http://'.$_SERVER['HTTP_HOST'];header('Content-Type: application/json');
            if($path==='/media-hls') echo json_encode([['format'=>'hls','defaultQuality'=>[240,480],'videoUrl'=>$base.'/master.m3u8']]);
            else echo json_encode(array_map(fn($height)=>['format'=>'mp4','quality'=>$height,'videoSize'=>filesize($root.'/'.$height.'/'.$height.'P_300K_189780301.mp4'),'videoUrl'=>$base.'/'.$height.'/'.$height.'P_300K_189780301.mp4'],[240,480]));
            return;
        }
        if(preg_match('~^/(240|480)/(?:240|480)P_300K_189780301\.mp4$~D',$path)) { header('Content-Type: video/mp4');readfile($root.$path);return; }
        if($path==='/master.m3u8'||preg_match('~^/(240|480)/stream[0-9]*\.(m3u8|ts)$~D',$path)) {
            header('Content-Type: '.(str_ends_with($path,'.m3u8')?'application/vnd.apple.mpegurl':'video/mp2t'));readfile($root.$path);return;
        }
PHP
        .
        '$method=basename($path);$uploads=[];foreach($_FILES as $key=>$file) $uploads[$key]=["bytes"=>filesize($file["tmp_name"]),"mime"=>$file["type"]];'.
        '$counter=$root."/counter";$next=(int)@file_get_contents($counter)+1;file_put_contents($counter,(string)$next);'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads,"result_message_id"=>(int)($_POST["message_id"]??900+$next)],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        '$result=str_starts_with($method,"send")||str_starts_with($method,"edit")?["message_id"=>(int)($_POST["message_id"]??900+$next)]:true;'.
        'if($method==="sendPhoto"||$method==="editMessageMedia") $result["photo"]=[["file_id"=>"FIXTURE_YOUPORN_COVER"]];'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>$result]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-errors.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base;
    $python=getenv('YTDLP_TEST_PYTHON')?:(is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'/usr/bin/python3'); $stub=$temp.'/yt-youporn';
    file_put_contents($stub,'#!'.$python."\nimport runpy\nrunpy.run_path(".json_encode(__DIR__.'/fixtures/youporn_ytdlp.py',JSON_UNESCAPED_SLASHES).',init_globals='.json_encode(['MODE'=>$mode,'TRACE'=>$trace,'BASE'=>$base,'DATABASE'=>$config->storage.'/zero.sqlite'],JSON_UNESCAPED_SLASHES).',run_name="__main__")' ."\n"); chmod($stub,0700); $config->ytDlp=$stub;
    $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api); $worker=new Zero\Worker($config,$store,$api); $serial=0;
    (new Zero\GroupSupport($bot))->cacheIdentity(['id'=>123,'username'=>'FixtureBot']);
    $records=fn()=>array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    $calls=fn(string $method)=>array_values(array_filter($records(),fn($call)=>$call['method']===$method));
    $send=function(int $user=42,int $chat=42,string $text='') use($bot,$store,&$serial,$link): void { $store->query('UPDATE users SET last_request=0'); $bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0,'text'=>$text!==''?$text:$link]]); };
    $click=function(string $data,int $user=42,int $chat=42,int $message=700) use($bot,&$serial): void { $bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'b'.$serial,'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0],'data'=>$data]]); };
    $jobs=fn()=>(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    $send(); $messages=$calls('sendMessage'); ypCheck($jobs()===0&&str_contains(end($messages)['params']['text'],'YouPorn خاموش است'),'YouPorn defaults off and tells only the administrator how to enable it');
    $store->set('adult.pornhub','1'); $send(); ypCheck($jobs()===0,'enabling Pornhub does not enable YouPorn');
    $click('custom:adultyouporn'); ypCheck($store->get('adult.youporn')==='1'&&$store->get('adult.pornhub')==='1','YouPorn has its own one-tap switch');
    $store->set('adult.pornhub','0'); ypCheck($bot->adultAllowed(42,42,'youporn')&&!$bot->adultAllowed(42,42),'YouPorn stays enabled independently of Pornhub');
    $send(7,7); ypCheck($jobs()===0,'administrators-only is shared and defaults on');
    $click('custom:adultyouporn',7,7); ypCheck($store->get('adult.youporn')==='1','ordinary users cannot change the YouPorn switch');
    foreach(['caption','notice','people','views','tags','error_size','error_restricted','error_format','error_network','error_blocked'] as $suffix) ypCheck($bot->ui->customizable('text','youporn_'.$suffix),'YouPorn user text is independently customizable: '.$suffix);
    $click('custom:section:youporn'); $edits=$calls('editMessageText'); $markup=json_decode(end($edits)['params']['reply_markup'],true)['inline_keyboard']; ypCheck((bool)$markup,'YouPorn has its own Personalization screen');
    $store->set('groups_enabled','0'); file_put_contents($log,''); $send(42,-100123); $messages=$calls('sendMessage'); ypCheck($jobs()===0&&count($messages)===1&&(int)$messages[0]['params']['chat_id']===42,'disabled group use is explained privately to the administrator'); $store->set('groups_enabled','1');
    $store->set('adult.admins_only','0'); $send(7,7); ypCheck($jobs()===1,'turning administrators-only off permits ordinary users'); $store->query('DELETE FROM jobs'); $store->set('adult.admins_only','1');
    Zero\saveYouTubeCookies($config,"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_SESSION\n");
    $send(42,-100123,'/download@FixtureBot '.$link); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    if($saved['status']!=='waiting_quality') throw new RuntimeException($store->get('last_job_error'));
    $options=json_decode($saved['options'],true); ypCheck($saved['url']===$canonical&&isset($options[240],$options[480],$options['audio']),'the sample YouPorn link reaches its actual extractor and produces actual HLS qualities');
    ypCheck(str_starts_with($options[480][0]['id'],'hls-')&&!str_contains($saved['options'],'fixture-token'),'HLS is preferred over slow MP4 and source URLs are not retained');
    $posters=$calls('editMessageMedia'); ypCheck($saved['message_kind']==='photo'&&end($posters)['uploads']['poster']['bytes']>0,'the quality menu contains the uploaded cover');
    $cover=json_decode(end($posters)['params']['media'],true); ypCheck($cover['caption']===$bot->ui->coverCaption('Neutral Fixture Video',(string)round($config->maxBytes/1000000,1))&&end($posters)['uploads']['poster']['mime']==='image/jpeg','the photo and quality-menu caption follow the shared Pornhub flow');
    $args=json_decode(file($trace)[0],true); ypCheck(!in_array('--cookies',$args,true),'YouTube cookies are never sent to YouPorn');
    $click('quality:'.$job['id'].':480',7,-100123,(int)$saved['message_id']); ypCheck($store->claim('media')===null,'another member cannot select a quality');
    $click('quality:'.$job['id'].':480',42,-100123,(int)$saved['message_id']); $worker->process($store->claim('media')); $videos=$calls('sendVideo');
    if(!$videos) throw new RuntimeException($store->get('last_job_error'));
    $video=end($videos); ypCheck($video['uploads']['video']['bytes']>0&&(int)$video['params']['message_thread_id']===321&&str_contains($video['params']['caption'],'🎞 480p')&&str_starts_with($video['params']['caption'],'<blockquote>🗑')&&!isset($video['params']['reply_markup']),'the chosen video is delivered with quality and automatic deletion notice in the original topic');
    ypCheck(($video['uploads']['cover']['mime']??'')==='image/jpeg'&&($video['uploads']['cover']['bytes']??0)>0&&($video['uploads']['thumbnail']['bytes']??0)>0,'the delivered video includes both its actual site cover and thumbnail');
    ypCheck((int)$store->query('SELECT COUNT(*) FROM timed_deletes')->fetchColumn()===1&&(int)$store->query("SELECT ok FROM stats WHERE service='youporn'")->fetchColumn()===1,'the video is scheduled for deletion and counted for YouPorn');
    $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); $click('quality:'.$job['id'].':audio',42,42,(int)$saved['message_id']); $worker->process($store->claim('media')); $audio=$calls('sendAudio'); ypCheck((bool)$audio&&end($audio)['uploads']['audio']['mime']==='audio/mpeg','audio is delivered as MP3');
    file_put_contents($mode,json_encode(['no_cover'=>true])); $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    ypCheck($saved['status']==='waiting_quality'&&$saved['message_kind']==='text','a temporarily missing cover does not disable quality selection'); $click('quality:'.$job['id'].':cancel',42,42,(int)$saved['message_id']);
    file_put_contents($mode,json_encode(['disable'=>'inspect'])); $send(); $job=$store->claim('inspect'); $before=count($calls('editMessageMedia')); $worker->process($job);
    ypCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed'&&count($calls('editMessageMedia'))===$before,'turning YouPorn off during inspection prevents offering the menu'); $store->set('adult.youporn','1');
    file_put_contents($mode,'{}'); $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); $click('quality:'.$job['id'].':480',42,42,(int)$saved['message_id']); file_put_contents($mode,json_encode(['disable'=>'download'])); $before=count($calls('sendVideo')); $worker->process($store->claim('media'));
    ypCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed'&&count($calls('sendVideo'))===$before,'turning YouPorn off during download prevents the final upload'); $store->set('adult.youporn','1'); file_put_contents($mode,'{}');
    $send(); $job=$store->claim('inspect'); $before=count(file($trace)); $store->set('adult.youporn','0'); $worker->process($job);
    ypCheck(count(file($trace))===$before&&$store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed','disabling YouPorn stops queued work before any downloader request');
    $store->set('adult.youporn','1'); file_put_contents($mode,json_encode(['blocked'=>true])); $send(); $job=$store->claim('inspect'); $worker->process($job);
    ypCheck($store->get('last_job_service')==='youporn'&&$store->get('last_job_sources')==='youporn=forbidden'&&!str_contains($store->get('recent_errors'),'hidden.example'),'network refusals retain only a clean service-specific diagnostic');
    ypCheck(!glob($config->storage.'/downloads/job-*'),'YouPorn covers and media are cleaned after success and failure');
} finally { if(is_resource($server)) { proc_terminate($server); proc_close($server); } Zero\Download::clean($temp); }
