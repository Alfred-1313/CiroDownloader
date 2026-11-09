<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function beegCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$link='https://beeg.com/-0123456789012345';
foreach(['https://beeg.com/-0123456789012345','beeg.com/-0123456789012345','https://www.beeg.com/-0123456789012345/?t=1-5&tracking=fixture'] as $url) beegCheck(Zero\beegUrl($url)===$link,'Beeg video IDs preserve their leading zeros and drop tracking');
beegCheck(Zero\beegUrl('https://www.beeg.com/video/-0123456789012345/')==='https://beeg.com/video/-0123456789012345'&&Zero\beegUrl('http://beeg.com/1234567890')==='https://beeg.com/1234567890','legacy and /video/ URLs retain their supported path');
foreach(['https://beeg.com/','https://beeg.com/tags/fixture','https://beeg.com/model/fixture','https://beeg.com/-123/extra','https://beeg.com.evil/-123','https://beeg.com@evil/-123','https://www.beeg.com:443/-123','file:///123','https://beeg.com/-123 extra','https://beeg.com/--123'] as $url) beegCheck(Zero\beegUrl($url)===null,'non-video or unsafe Beeg URL rejected');
beegCheck(Zero\mediaUrl($link)===null&&Zero\adultUrl($link)===$link&&Zero\adultService($link)==='beeg','Beeg stays outside the always-on public media routes');
$temp=sys_get_temp_dir().'/ciro-beeg-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
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
        if(str_starts_with($path,'/facts/file/')||str_starts_with($path,'/video/play_url/')) {
            $id=basename($path);
            if($id!=='123456789012345'||($_SERVER['HTTP_ORIGIN']??'')!=='https://beeg.com'||($_SERVER['HTTP_REFERER']??'')!=='https://beeg.com/') {
                http_response_code(400);header('Content-Type: application/json');echo '{"error":"Bad Request"}';return;
            }
            $mode=json_decode(file_get_contents($root.'/mode.json'),true);
            if(str_starts_with($path,'/video/play_url/')) { header('Content-Type: text/plain');echo $mode['playback']??'master.m3u8';return; }
            header('Content-Type: application/json');
            $data=['file'=>['fl_duration'=>2,'stuff'=>['sf_name'=>'Neutral Fixture Video','sf_story'=>'Local test']],
                'fc_facts'=>[['id'=>1,'fc_created'=>'2026-11-09T00:00:00Z']], 'tags'=>[['tg_name'=>'Topic One'],['tg_name'=>'Tag Two']]];
            if(!empty($mode['sparse'])) {unset($data['file']['stuff']);$data['fc_facts']=null;if(($_GET['tag']??'')==='27173') $data['file']['data']=[['cd_column'=>'sf_name','cd_value'=>'Neutral detail title']];}
            echo json_encode($data);return;
        }
        if($path==='/cover.jpg') { header('Content-Type: image/jpeg');readfile($root.'/cover.jpg');return; }
        if($path==='/master.m3u8'||preg_match('~^/(240|480)/stream[0-9]*\.(m3u8|ts)$~D',$path)) {
            if(($_SERVER['HTTP_ORIGIN']??'')!=='https://beeg.com'||($_SERVER['HTTP_REFERER']??'')!=='https://beeg.com/') {http_response_code(403);return;}
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
    $python=getenv('YTDLP_TEST_PYTHON')?:(is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'/usr/bin/python3'); $stub=$temp.'/yt-beeg';
    file_put_contents($stub,'#!'.$python."\nimport runpy\nrunpy.run_path(".json_encode(__DIR__.'/fixtures/beeg_ytdlp.py',JSON_UNESCAPED_SLASHES).',init_globals='.json_encode(['MODE'=>$mode,'TRACE'=>$trace,'BASE'=>$base,'DATABASE'=>$config->storage.'/zero.sqlite'],JSON_UNESCAPED_SLASHES).',run_name="__main__")' ."\n"); chmod($stub,0700); $config->ytDlp=$stub;
    $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api); $worker=new Zero\Worker($config,$store,$api); $serial=0;
    (new Zero\GroupSupport($bot))->cacheIdentity(['id'=>123,'username'=>'FixtureBot']);
    $records=fn()=>array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    $calls=fn(string $method)=>array_values(array_filter($records(),fn($call)=>$call['method']===$method));
    $send=function(int $user=42,int $chat=42,string $text='') use($bot,$store,&$serial,$link): void { $store->query('UPDATE users SET last_request=0'); $bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0,'text'=>$text!==''?$text:$link]]); };
    $click=function(string $data,int $user=42,int $chat=42,int $message=700) use($bot,&$serial): void { $bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'b'.$serial,'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0],'data'=>$data]]); };
    $jobs=fn()=>(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    $send(); $messages=$calls('sendMessage'); beegCheck($jobs()===0&&str_contains(end($messages)['params']['text'],'Beeg خاموش است'),'Beeg defaults off and tells only the administrator how to enable it');
    $store->set('adult.pornhub','1'); $send(); beegCheck($jobs()===0,'enabling Pornhub does not enable Beeg');
    $click('custom:adultbeeg'); beegCheck($store->get('adult.beeg')==='1'&&$store->get('adult.pornhub')==='1','Beeg has its own one-tap switch');
    $store->set('adult.pornhub','0'); beegCheck($bot->adultAllowed(42,42,'beeg')&&!$bot->adultAllowed(42,42),'Beeg stays enabled independently of Pornhub');
    $send(7,7); beegCheck($jobs()===0,'administrators-only is shared and defaults on');
    $click('custom:adultbeeg',7,7); beegCheck($store->get('adult.beeg')==='1','ordinary users cannot change the Beeg switch');
    foreach(['quality_prompt','caption','notice','people','views','tags','error_size','error_restricted','error_format','error_network','error_blocked'] as $suffix) beegCheck($bot->ui->customizable('text','beeg_'.$suffix),'Beeg user text is independently customizable: '.$suffix);
    $click('custom:section:beeg'); $edits=$calls('editMessageText'); $markup=json_decode(end($edits)['params']['reply_markup'],true)['inline_keyboard']; beegCheck((bool)$markup,'Beeg has its own Personalization screen');
    $store->set('groups_enabled','0'); file_put_contents($log,''); $send(42,-100123); $messages=$calls('sendMessage'); beegCheck($jobs()===0&&count($messages)===1&&(int)$messages[0]['params']['chat_id']===42,'disabled group use is explained privately to the administrator'); $store->set('groups_enabled','1');
    $store->set('adult.admins_only','0'); $send(7,7); beegCheck($jobs()===1,'turning administrators-only off permits ordinary users'); $store->query('DELETE FROM jobs'); $store->set('adult.admins_only','1');
    Zero\saveYouTubeCookies($config,"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_SESSION\n");
    $send(42,-100123,'/download@FixtureBot '.$link); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    if($saved['status']!=='waiting_quality') throw new RuntimeException($store->get('last_job_error'));
    $options=json_decode($saved['options'],true); beegCheck(isset($options[240],$options[480],$options['audio'],$options['meta'])&&$options['meta']['tags']===['Topic One','Tag Two'],'the Beeg API accepts the numeric ID and site headers; the new playback endpoint supplies real HLS qualities and audio');
    $posters=$calls('editMessageMedia'); beegCheck($saved['message_kind']==='photo'&&end($posters)['uploads']['poster']['bytes']>0,'the Beeg OpenGraph cover is uploaded into the same quality-menu message');
    $cover=json_decode(end($posters)['params']['media'],true)['caption']; beegCheck(str_contains($cover,'240p · 480p')&&str_contains($cover,'00:02')&&str_contains($cover,'<b>🎬 Neutral Fixture Video</b>'),'the cover caption lists the actual available qualities and duration with a bold title');
    $args=json_decode(file($trace)[0],true); beegCheck(!in_array('--cookies',$args,true)&&$store->get('youtube_probe_url')==='','Beeg receives no YouTube cookies and never replaces its probe address');
    $click('quality:'.$job['id'].':480',7,-100123,(int)$saved['message_id']); beegCheck($store->claim('media')===null,'another member cannot choose this request quality');
    $click('quality:'.$job['id'].':480',42,-100123,(int)$saved['message_id']); $work=$store->claim('media'); $worker->process($work); $videos=$calls('sendVideo');
    if(!$videos) throw new RuntimeException($store->get('last_job_error'));
    $video=end($videos); beegCheck($video['uploads']['video']['bytes']>0&&$video['uploads']['video']['mime']==='video/mp4'&&(int)$video['params']['chat_id']===-100123&&(int)$video['params']['message_thread_id']===321,'the chosen Beeg video is downloaded and uploaded as real MP4 bytes in the original group topic');
    beegCheck(str_starts_with($video['params']['caption'],'<blockquote>🗑')&&str_contains($video['params']['caption'],"</blockquote>\n\n<b>🔞 ")&&str_contains($video['params']['caption'],'#topic_one')&&!isset($video['params']['reply_markup']),'caption and deletion notice match the existing adult flow, with no source button');
    beegCheck(str_contains($video['params']['caption'],'🎞 480p')&&str_contains($video['params']['caption'],'Neutral Fixture Video')&&$video['uploads']['thumbnail']['mime']==='image/jpeg'&&$video['uploads']['thumbnail']['bytes']<200000,'the delivered caption has the title and measured quality, with a real JPEG video thumbnail');
    $deletion=$store->query('SELECT * FROM timed_deletes')->fetch(); beegCheck($deletion&&(int)$deletion['chat_id']===-100123&&(int)$deletion['message_id']===$video['result_message_id']&&(int)$deletion['at']>=time()+8,'the exact delivered Beeg message is scheduled for the shared deletion delay');
    beegCheck((int)$store->query("SELECT ok FROM stats WHERE service='beeg'")->fetchColumn()===1,'successful Beeg delivery is counted as its own service');
    $scheduled=(int)$deletion['message_id']; Zero\TimedDeletes::run($store,$api,time()+20); $removed=$calls('deleteMessage'); beegCheck((bool)array_filter($removed,fn($call)=>(int)$call['params']['message_id']===$scheduled&&(int)$call['params']['chat_id']===-100123),'scheduled deletion targets the delivered message in its original chat');
    $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); $click('quality:'.$job['id'].':audio',42,42,(int)$saved['message_id']); $worker->process($store->claim('media')); $audio=$calls('sendAudio'); beegCheck((bool)$audio&&end($audio)['uploads']['audio']['mime']==='audio/mpeg','the audio choice produces an actual MP3 through the same Beeg path');
    file_put_contents($mode,json_encode(['no_cover'=>true])); $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    beegCheck($saved['status']==='waiting_quality'&&$saved['message_kind']==='text','a missing site cover still allows quality selection'); $click('quality:'.$job['id'].':cancel',42,42,(int)$saved['message_id']);
    file_put_contents($mode,json_encode(['sparse'=>true])); $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    beegCheck($saved['status']==='waiting_quality'&&$saved['title']==='Neutral detail title','missing legacy facts and HLS resources do not crash; the optional detail response supplies the title');
    $click('quality:'.$job['id'].':cancel',42,42,(int)$saved['message_id']);
    file_put_contents($mode,json_encode(['playback'=>'https://foreign.example/private-value'])); $send(); $worker->process($store->claim('inspect'));
    beegCheck(str_contains($store->get('last_job_error'),'unsupported playback address')&&!str_contains($store->get('last_job_error'),'foreign.example'),'a foreign playback destination is rejected without exposing its address');
    file_put_contents($mode,'{}');
    $send(); $store->set('adult.beeg','0'); $before=count(file($trace)); $worker->process($store->claim('inspect')); beegCheck(count(file($trace))===$before,'disabling Beeg stops queued work before any downloader request'); $store->set('adult.beeg','1');
    file_put_contents($mode,json_encode(['disable'=>'inspect'])); $send(); $job=$store->claim('inspect'); $worker->process($job);
    beegCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed','turning Beeg off during inspection prevents offering a new quality menu'); $store->set('adult.beeg','1');
    file_put_contents($mode,'{}'); $send(); $job=$store->claim('inspect'); $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); $click('quality:'.$job['id'].':480',42,42,(int)$saved['message_id']);
    file_put_contents($mode,json_encode(['disable'=>'download'])); $before=count($calls('sendVideo')); $worker->process($store->claim('media'));
    beegCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed'&&count($calls('sendVideo'))===$before,'turning Beeg off during download prevents the final upload'); $store->set('adult.beeg','1');
    file_put_contents($mode,json_encode(['failure'=>true])); $send(); $worker->process($store->claim('inspect')); beegCheck($store->get('last_job_service')==='beeg'&&$store->get('last_job_sources')==='beeg=certificate','failed Beeg requests retain a service-specific clean cause');
    $recent=json_decode($store->get('recent_errors','[]'),true); beegCheck($recent[0]['s']==='beeg'&&!str_contains(json_encode($recent),'hidden.example'),'recent error reports retain no private downloader URL');
    beegCheck(!glob($config->storage.'/downloads/job-*'),'temporary Beeg files are cleaned after success and failure');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
