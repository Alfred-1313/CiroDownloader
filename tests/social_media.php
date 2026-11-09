<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function socialMediaCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$service=$argv[1]??'tiktok'; if(!in_array($service,['tiktok','twitter'],true)) throw new RuntimeException('Unknown fixture service');
$temp=sys_get_temp_dir().'/ciro-social-media-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); mkdir($config->storage,0700); mkdir($config->storage.'/downloads',0700); $runner=new Zero\Download($config);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=green:s=160x120','-frames:v','1','-update','1','-threads','1',$temp.'/photo.jpg'],$temp,30);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=green:s=320x180:r=2:d=1','-f','lavfi','-i','sine=frequency=440:duration=1','-c:v','libx264','-threads','2','-pix_fmt','yuv420p','-c:a','aac','-shortest',$temp.'/clip.mp4'],$temp,30);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$temp.'/clip.mp4','-c','copy','-hls_time','1','-hls_list_size','0',$temp.'/stream.m3u8'],$temp,30);
    $log=$temp.'/http.jsonl'; $trace=$temp.'/backend.jsonl'; $mode=$temp.'/mode.json'; file_put_contents($log,''); file_put_contents($trace,'');
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php require '.var_export(__DIR__.'/fixtures/instagram_telegram.php',true).';$root='.var_export($temp,true).';'.
        '$method=basename(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH));'.
        'if(in_array($method,["photo","photo.jpg","clip.mp4","stream.m3u8","stream0.ts"],true)) { $name=$method==="photo"?"photo.jpg":$method;header("Content-Type: ".match(pathinfo($name,PATHINFO_EXTENSION)){"jpg"=>"image/jpeg","mp4"=>"video/mp4","m3u8"=>"application/vnd.apple.mpegurl",default=>"video/mp2t"});readfile($root."/".$name);return; }'.
        '$uploads=[];foreach($_FILES as $key=>$file) $uploads[$key]=["hash"=>hash_file("sha256",$file["tmp_name"]),"mime"=>$file["type"]];'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        'if($method==="upload") { echo "https://files.catbox.moe/fixture.".($_FILES["fileToUpload"]["type"]==="video/mp4"?"mp4":"jpg");return; }'.
        '$counter=$root."/counter";$next=(int)@file_get_contents($counter)+1;file_put_contents($counter,(string)$next);'.
        '$result=instagramTelegramFixture($method,$_POST,$_FILES,$root,900+$next)??match($method){"setMessageReaction"=>true,"createAccount"=>["access_token"=>str_repeat("A",64)],"createPage"=>["url"=>"https://telegra.ph/Social-11-07"],default=>["message_id"=>900+$next]};'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>$result]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base;
    $python=getenv('GALLERY_DL_PYTHON')?:(is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'/usr/bin/python3'); $stub=$temp.'/gallery-fixture';
    $globals=['MODE'=>$mode,'TRACE'=>$trace,'SERVICE'=>$service,'BASE'=>$base,'ADAPTER'=>dirname(__DIR__).'/bin/'.$service.'-download.py'];
    file_put_contents($stub,'#!'.$python."\nimport runpy\nrunpy.run_path(".json_encode(__DIR__.'/fixtures/social_gallery.py',JSON_UNESCAPED_SLASHES).',init_globals='.json_encode($globals,JSON_UNESCAPED_SLASHES).',run_name="__main__")' ."\n"); chmod($stub,0700); $config->galleryDl=$stub;
    $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api); $worker=new Zero\Worker($config,$store,$api,new Zero\Articles($config,$base,$base.'/upload'));
    $description="متن اصلی پست\n<script> & تصاویر"; $counter=0;
    $records=fn(string $file)=>array_map(fn($line)=>json_decode($line,true),file($file,FILE_IGNORE_NEW_LINES));
    $calls=fn()=>$records($log); $traces=fn()=>$records($trace);
    $process=function(string $kind,bool $group=false,bool $short=false,?callable $beforeWorker=null,string $redirect='https://www.tiktok.com/@fixture/video/1234567890123456789?tracking=1') use(&$counter,$description,$service,$mode,$log,$trace,$bot,$store,$worker): array {
        $id=++$counter; $user=10+$id; $chat=$group?-100123:$user;
        file_put_contents($mode,json_encode(['kind'=>$kind,'description'=>$description,'redirect'=>$redirect])); file_put_contents($log,''); file_put_contents($trace,'');
        $url=$service==='twitter'?'https://twitter.com/fixture/status/1234567890123456789/photo/1':($short?'https://vm.tiktok.com/AbC123/':'https://www.tiktok.com/@fixture/'.($kind==='video'?'video':'photo').'/1234567890123456789?photoindex=2');
        $bot->handle(['update_id'=>1000+$id,'message'=>['message_id'=>$id,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0,'text'=>$url]]);
        $job=$store->claim('media'); if(!$job) throw new RuntimeException('Social media job was not queued'); if($beforeWorker) $beforeWorker(); $worker->process($job);
        return $store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    };
    $job=$process('photo'); if($job['status']!=='done') throw new RuntimeException($store->get('last_job_error'));
    $photos=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendPhoto')); $events=$traces();
    socialMediaCheck(count($photos)===1&&$photos[0]['uploads']['photo']['hash']===hash_file('sha256',$temp.'/photo.jpg')&&$events[0]['cookie_mode']===null,'actual '.$service.' extractor sends a public photo without cookies or quality selection');
    socialMediaCheck(json_decode($photos[0]['params']['reply_markup'],true)['inline_keyboard']===[]&&!str_contains($photos[0]['params']['caption'],'href='),'source button and caption link default off: '.$service);
    $reaction=array_values(array_filter($calls(),fn($call)=>$call['method']==='setMessageReaction'));
    socialMediaCheck(count($reaction)===1&&json_decode($reaction[0]['params']['reaction'],true)[0]['emoji']==='👾','accepted '.$service.' link receives its configured reaction');
    $domain=$service==='twitter'?'x.com':'tiktok.com'; $session=$service==='twitter'?'auth_token':'sessionid';
    $cookies="# Netscape HTTP Cookie File\n.$domain\tTRUE\t/\tTRUE\t2147483647\t$session\tFAKE_SOCIAL_SESSION\n"; $class=$service==='twitter'?Zero\Twitter::class:Zero\TikTok::class; $class::saveCookies($config,$cookies);
    $job=$process('video',group:true,short:$service==='tiktok'); $videos=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendVideo'));
    if($job['status']!=='done') throw new RuntimeException($store->get('last_job_error'));
    $events=$traces(); $auth=array_values(array_filter($events,fn($event)=>$event['event']==='api'));
    socialMediaCheck(count($videos)===1&&(int)$videos[0]['params']['chat_id']===-100123&&(int)$videos[0]['params']['message_thread_id']===321&&$events[0]['cookie_mode']===0600&&$auth[0]['authenticated']===true,'private cookie copy reaches the real '.$service.' extractor and video retains its group topic');
    $probe=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-of','json',$temp.'/delivered.mp4'],$temp,30),true);
    socialMediaCheck(in_array('audio',array_column($probe['streams'],'codec_type'),true)&&file_get_contents($config->galleryCookiePath($service))===$cookies,'video keeps its soundtrack and the shared session file stays unchanged: '.$service);
    $job=$process('album',group:true); if($job['status']!=='done') throw new RuntimeException($store->get('last_job_error'));
    $state=json_decode($job['options'],true)['pages']; $message=$state['message']; $prefix=Zero\galleryPrefix($service).'page';
    $slides=array_values(array_filter($calls(),fn($call)=>in_array($call['method'],['sendPhoto','sendVideo'],true))); $rows=json_decode($slides[0]['params']['reply_markup'],true)['inline_keyboard'];
    socialMediaCheck(count($state['items'])===3&&count($slides)===1&&$rows[0][0]['callback_data']===$prefix.':'.$job['id'].':2:1'&&!isset($rows[0][0]['style'])&&str_contains($slides[0]['params']['caption'],'&lt;script&gt;'),'ordered '.$service.' album displays one slide with escaped text and a neutral Next button');
    $cache=$config->storage.'/'.$service.'-pages/'.$state['folder']; socialMediaCheck(count(glob($cache.'/*'))===2&&!is_file($cache.'/1.jpg')&&!glob($cache.'/*cookies*')&&!array_filter(glob($cache.'/*'),fn($path)=>(fileperms($path)&0777)!==0600),'unsent '.$service.' slides remain private while the sent slide is released');
    $turn=function(int $page,?int $user=null,?int $thread=321,?string $foreign=null,?int $revision=null) use($bot,&$job,$prefix): void {
        $state=json_decode($job['options'],true)['pages']; $caption=['message_id'=>(int)$state['message'],'chat'=>['id'=>(int)$job['chat_id'],'type'=>'supergroup']]; if($thread!==null) $caption['message_thread_id']=$thread;
        $bot->handle(['callback_query'=>['id'=>'social-page','from'=>['id'=>$user??(int)$job['user_id']],'message'=>$caption,'data'=>($foreign??$prefix).':'.$job['id'].':'.$page.':'.($revision??$state['revision'])]]);
    };
    $turn(2,user:999); $turn(2,thread:999); $turn(2,foreign:'igpage'); socialMediaCheck($store->claim('media')===null,'owner/topic/service checks protect '.$service.' caption buttons');
    $turn(2,thread:null); $turn(2,thread:null); $next=$store->claim('media'); socialMediaCheck($next!==null&&$store->claim('media')===null,'duplicate '.$service.' clicks reserve only one transition'); $worker->process($next); $job=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    $edits=array_values(array_filter($calls(),fn($call)=>$call['method']==='editMessageMedia')); $edit=end($edits); $rows=json_decode($edit['params']['reply_markup'],true)['inline_keyboard'];
    socialMediaCheck((int)$edit['params']['message_id']===$message&&count($rows[0])===2&&!isset($rows[0][0]['style'])&&!isset($rows[0][1]['style']),'Next edits the same '.$service.' message with neutral Previous/Next controls');
    $turn(3,revision:1); socialMediaCheck($store->claim('media')===null,'stale '.$service.' caption callbacks cannot move a newer slide');
    $store->set($service.'.source_button','1'); $turn(1); $next=$store->claim('media'); $worker->process($next); $edits=array_values(array_filter($calls(),fn($call)=>$call['method']==='editMessageMedia')); $edit=end($edits);
    socialMediaCheck($edit['uploads']===[]&&count(json_decode($edit['params']['reply_markup'],true)['inline_keyboard'])===2,'Previous reuses the file ID and applies the separate '.$service.' source setting');
    if($service==='tiktok') {
        $job=$process('maximum',group:true); socialMediaCheck($job['status']==='done'&&count(json_decode($job['options'],true)['pages']['items'])===35,'TikTok retains all 35 photos without standalone music');
        for($page=2;$page<=35;$page++) {
            $turn($page); $next=$store->claim('media'); if(!$next) throw new RuntimeException('TikTok navigation stopped at page '.$page); $worker->process($next); $job=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
            if($job['status']!=='done') throw new RuntimeException('TikTok transition failed at page '.$page);
        }
        $last=json_decode($job['options'],true)['pages']; socialMediaCheck($last['current']===35&&$last['revision']===35,'TikTok navigation crosses the old 20-slide boundary and reaches photo 35 in the same message');
        $job=$process('oversize'); socialMediaCheck($job['status']==='failed','an oversized TikTok album is rejected without truncation');
        $job=$process('photo',short:true,redirect:'https://www.tiktok.com/@fixture'); socialMediaCheck($job['status']==='failed'&&!array_filter($traces(),fn($event)=>$event['event']==='api'),'a short TikTok profile link cannot trigger a feed download');
    } else {
        $job=$process('quote'); socialMediaCheck($job['status']==='done'&&count(json_decode($job['options'],true)['pages']['items'])===3,'X receives only the requested post media, excluding quoted media');
        $job=$process('hls'); socialMediaCheck($job['status']==='done','an HLS-only X video passes through the bundled yt-dlp backend');
    }
    foreach(['broken','wrong','blocked'] as $kind) {
        $job=$process($kind); socialMediaCheck($job['status']==='failed'&&!array_filter($calls(),fn($call)=>in_array($call['method'],['sendPhoto','sendVideo','createPage'],true)),'no partial '.$service.' delivery on '.$kind.' responses');
    }
    $store->set($service.'.carousel_mode','article'); $job=$process('album'); $pages=array_values(array_filter($calls(),fn($call)=>$call['method']==='createPage'));
    socialMediaCheck($job['status']==='done'&&count($pages)===1&&$pages[0]['params']['title']===$bot->ui->plain($service.'_article_title'),'optional '.$service.' article mode preserves its own title and ordered media');
    $job=$process('photo',beforeWorker:fn()=>$store->set('admins_only','1')); $store->set('admins_only','0'); socialMediaCheck($job['status']==='failed'&&file_get_contents($trace)==='','revoked access stops '.$service.' work before downloading');
    $job=$process('photo',group:true,beforeWorker:fn()=>$store->set('groups_enabled','0')); $store->set('groups_enabled','1'); socialMediaCheck($job['status']==='failed'&&file_get_contents($trace)==='','disabling groups stops queued '.$service.' work');
    socialMediaCheck(!glob($config->storage.'/downloads/job-*'),'temporary '.$service.' downloads and session copies are cleaned');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
