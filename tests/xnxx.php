<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function xnCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$link='https://amp.xnxx.com/video-55awb78/fixture_video'; $canonical='https://www.xnxx.com/video-55awb78/fixture_video';
foreach([$link,'xnxx.com/video-55awb78/fixture_video','https://video.xnxx.com/video55awb78/fixture_video?tracking=1'] as $url) xnCheck(Zero\xnxxUrl($url)===$canonical,'the video URL is canonicalized for the extractor');
foreach(['https://amp.xnxx.com/','https://xnxx.com/profiles/test','https://xnxx.com.evil.test/video-55awb78/test','https://xnxx.com@evil.test/video-55awb78/test','https://amp.xnxx.com:443/video-55awb78/test','ftp://xnxx.com/video-55awb78/test','https://xnxx.com/video-55awb78/test/extra'] as $url) xnCheck(Zero\xnxxUrl($url)===null,'non-video or unsafe address is rejected');
xnCheck(Zero\mediaUrl($link)===null&&Zero\adultService($link)==='xnxx','XNXX uses the restricted adult route');
$temp=sys_get_temp_dir().'/ciro-xnxx-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); $store=new Zero\Store($config->storage); mkdir($config->storage.'/downloads',0700); $runner=new Zero\Download($config);
    foreach([240=>[426,240],480=>[854,480]] as $height=>[$width,$pixels]) {
        mkdir($temp.'/'.$height,0700);
        $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s='.$width.'x'.$pixels.':r=5:d=2','-f','lavfi','-i','sine=frequency=440:duration=2','-c:v','libx264','-threads','1','-pix_fmt','yuv420p','-c:a','aac','-shortest','-hls_time','2','-hls_list_size','0',$temp.'/'.$height.'/stream.m3u8'],$temp,60);
    }
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=640x360','-frames:v','1','-threads','1',$temp.'/cover.jpg'],$temp,30);
    file_put_contents($temp.'/master.m3u8',"#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=150000,RESOLUTION=426x240,CODECS=\"avc1.640015,mp4a.40.2\"\n/240/stream.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=300000,RESOLUTION=854x480,CODECS=\"avc1.64001f,mp4a.40.2\"\n/480/stream.m3u8\n");
    $log=$temp.'/http.jsonl'; $trace=$temp.'/backend.jsonl'; $mode=$temp.'/mode.json'; file_put_contents($log,''); file_put_contents($trace,''); file_put_contents($mode,'{}');
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php $root='.var_export($temp,true).';$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);'. <<<'PHP'
        if($path==='/cover.jpg') { header('Content-Type: image/jpeg');readfile($root.'/cover.jpg');return; }
        if($path==='/master.m3u8'||preg_match('~^/(240|480)/stream[0-9]*\.(m3u8|ts)$~D',$path)) {
            header('Content-Type: '.(str_ends_with($path,'.m3u8')?'application/vnd.apple.mpegurl':'video/mp2t'));readfile($root.$path);return;
        }
PHP
        .
        '$method=basename($path);$uploads=[];foreach($_FILES as $key=>$file) $uploads[$key]=["bytes"=>filesize($file["tmp_name"]),"mime"=>$file["type"]];'.
        '$counter=$root."/counter";$next=(int)@file_get_contents($counter)+1;file_put_contents($counter,(string)$next);'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads,"result_message_id"=>(int)($_POST["message_id"]??900+$next)],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        '$result=str_starts_with($method,"send")||str_starts_with($method,"edit")?["message_id"=>(int)($_POST["message_id"]??900+$next)]:true;'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>$result]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-errors.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base;
    $python=getenv('YTDLP_TEST_PYTHON')?:(is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'/usr/bin/python3'); $stub=$temp.'/yt-xnxx';
    file_put_contents($stub,'#!'.$python."\nimport runpy\nrunpy.run_path(".json_encode(__DIR__.'/fixtures/xnxx_ytdlp.py',JSON_UNESCAPED_SLASHES).',init_globals='.json_encode(['MODE'=>$mode,'TRACE'=>$trace,'BASE'=>$base,'DATABASE'=>$config->storage.'/zero.sqlite'],JSON_UNESCAPED_SLASHES).',run_name="__main__")' ."\n"); chmod($stub,0700); $config->ytDlp=$stub;
    $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api); $worker=new Zero\Worker($config,$store,$api); $serial=0;
    (new Zero\GroupSupport($bot))->cacheIdentity(['id'=>123,'username'=>'FixtureBot']);
    $records=fn()=>array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    $calls=fn(string $method)=>array_values(array_filter($records(),fn($call)=>$call['method']===$method));
    $send=function(int $user=42,int $chat=42,string $text='') use($bot,$store,&$serial,$link): void { $store->query('UPDATE users SET last_request=0'); $bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0,'text'=>$text!==''?$text:$link]]); };
    $click=function(string $data,int $user=42,int $chat=42,int $message=700) use($bot,&$serial): void { $bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'b'.$serial,'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0],'data'=>$data]]); };
    $jobs=fn()=>(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    $send(); $messages=$calls('sendMessage'); xnCheck($jobs()===0&&str_contains(end($messages)['params']['text'],'XNXX خاموش است'),'XNXX defaults off and tells only the administrator how to enable it');
    $store->set('adult.pornhub','1'); $send(); xnCheck($jobs()===0,'enabling Pornhub does not enable XNXX');
    $click('custom:adultxnxx'); xnCheck($store->get('adult.xnxx')==='1'&&$store->get('adult.pornhub')==='1','XNXX has its own one-tap switch');
    $store->set('adult.pornhub','0'); xnCheck($bot->adultAllowed(42,42,'xnxx')&&!$bot->adultAllowed(42,42),'XNXX stays enabled independently of Pornhub');
    $send(7,7); xnCheck($jobs()===0,'administrators-only is shared and defaults on');
    $click('custom:adultxnxx',7,7); xnCheck($store->get('adult.xnxx')==='1','ordinary users cannot change the XNXX switch');
    foreach(['caption','notice','people','views','tags','error_size','error_restricted','error_format','error_network','error_blocked'] as $suffix) xnCheck($bot->ui->customizable('text','xnxx_'.$suffix),'XNXX user text is independently customizable: '.$suffix);
    $click('custom:section:xnxx'); $edits=$calls('editMessageText'); $markup=json_decode(end($edits)['params']['reply_markup'],true)['inline_keyboard']; xnCheck((bool)$markup,'XNXX has its own Personalization screen');
    $store->set('groups_enabled','0'); file_put_contents($log,''); $send(42,-100123); $messages=$calls('sendMessage'); xnCheck($jobs()===0&&count($messages)===1&&(int)$messages[0]['params']['chat_id']===42,'disabled group use is explained privately to the administrator'); $store->set('groups_enabled','1');
    $store->set('adult.admins_only','0'); $send(7,7); xnCheck($jobs()===1,'turning administrators-only off permits ordinary users'); $store->query('DELETE FROM jobs'); $store->set('adult.admins_only','1');
    Zero\saveYouTubeCookies($config,"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_SESSION\n");
    $send(42,-100123,'/download@FixtureBot '.$link); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    if($saved['status']!=='waiting_quality') throw new RuntimeException($store->get('last_job_error'));
    $options=json_decode($saved['options'],true); xnCheck($saved['url']===$canonical&&isset($options[240],$options[480],$options['audio']),'AMP input reaches the real extractor and produces actual HLS qualities');
    $posters=$calls('editMessageMedia'); xnCheck($saved['message_kind']==='photo'&&end($posters)['uploads']['poster']['bytes']>0,'the quality menu contains the uploaded cover');
    $args=json_decode(file($trace)[0],true); xnCheck(!in_array('--cookies',$args,true),'YouTube cookies are never sent to XNXX');
    $click('quality:'.$job['id'].':480',7,-100123,(int)$saved['message_id']); xnCheck($store->claim('media')===null,'another member cannot select a quality');
    $click('quality:'.$job['id'].':480',42,-100123,(int)$saved['message_id']); $worker->process($store->claim('media')); $videos=$calls('sendVideo');
    if(!$videos) throw new RuntimeException($store->get('last_job_error'));
    $video=end($videos); xnCheck($video['uploads']['video']['bytes']>0&&(int)$video['params']['message_thread_id']===321&&str_contains($video['params']['caption'],'🎞 480p')&&str_starts_with($video['params']['caption'],'<blockquote>🗑')&&!isset($video['params']['reply_markup']),'the chosen video is delivered with quality and automatic deletion notice in the original topic');
    xnCheck((int)$store->query('SELECT COUNT(*) FROM timed_deletes')->fetchColumn()===1&&(int)$store->query("SELECT ok FROM stats WHERE service='xnxx'")->fetchColumn()===1,'the video is scheduled for deletion and counted for XNXX');
    $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); $click('quality:'.$job['id'].':audio',42,42,(int)$saved['message_id']); $worker->process($store->claim('media')); $audio=$calls('sendAudio'); xnCheck((bool)$audio&&end($audio)['uploads']['audio']['mime']==='audio/mpeg','audio is delivered as MP3');
    $send(); $job=$store->claim('inspect'); $before=count(file($trace)); $store->set('adult.xnxx','0'); $worker->process($job);
    xnCheck(count(file($trace))===$before&&$store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed','disabling XNXX stops queued work before any downloader request');
} finally { if(is_resource($server)) { proc_terminate($server); proc_close($server); } Zero\Download::clean($temp); }
