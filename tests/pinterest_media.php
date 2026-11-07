<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function pinMediaCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-pin-media-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); $runner=new Zero\Download($config);
    mkdir($config->storage,0700); mkdir($config->storage.'/downloads',0700);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=red:s=160x120','-frames:v','1','-update','1','-threads','1',$temp.'/photo.jpg'],$temp,30);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=red:s=320x180:r=1:d=1','-f','lavfi','-i','sine=frequency=440:duration=1','-c:v','libx264','-threads','2','-pix_fmt','yuv420p','-c:a','aac','-shortest',$temp.'/clip.mp4'],$temp,30);
    $log=$temp.'/http.jsonl'; $mode=$temp.'/mode.json'; $trace=$temp.'/backend.jsonl'; file_put_contents($log,''); file_put_contents($trace,'');
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php require '.var_export(__DIR__.'/fixtures/instagram_telegram.php',true).';'.
        '$method=basename(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH));$root='.var_export($temp,true).';'.
        'if(in_array($method,["photo.jpg","clip.mp4"],true)) { header("Content-Type: ".($method==="photo.jpg"?"image/jpeg":"video/mp4"));readfile($root."/".$method);return; }'.
        '$uploads=[];foreach($_FILES as $key=>$file) $uploads[$key]=["mime"=>$file["type"],"hash"=>hash_file("sha256",$file["tmp_name"])];'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        'if($method==="upload") { echo "https://files.catbox.moe/fixture.".($_FILES["fileToUpload"]["type"]==="video/mp4"?"mp4":"jpg");return; }'.
        '$counter=$root."/counter";$next=(int)@file_get_contents($counter)+1;file_put_contents($counter,(string)$next);'.
        '$result=instagramTelegramFixture($method,$_POST,$_FILES,$root,900+$next)??match($method) {"setMessageReaction"=>true,"createAccount"=>["access_token"=>str_repeat("A",64)],"createPage"=>["url"=>"https://telegra.ph/Pinterest-11-07"],default=>["message_id"=>900+$next]};'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>$result]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base;
    $python=getenv('GALLERY_DL_PYTHON')?:(is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'/usr/bin/python3');
    $stub=$temp.'/gallery-fixture';
    // Only upstream responses and short-link redirects are fixtures. The pinned
    // Pinterest parser, adapter, downloader and metadata postprocessors run intact.
    file_put_contents($stub,'#!'.$python."\n".
        "import json,os,sys,runpy\nfrom gallery_dl.extractor.pinterest import PinterestAPI,PinterestPinitExtractor\n".
        'mode=json.load(open('.json_encode($mode,JSON_UNESCAPED_SLASHES)."))\n".
        'base='.json_encode($base,JSON_UNESCAPED_SLASHES)."\n".
        'args=sys.argv[1:];cookie=args[args.index("--cookies")+1] if "--cookies" in args else None'."\n".
        'with open('.json_encode($trace,JSON_UNESCAPED_SLASHES).',"a") as trace:trace.write(json.dumps({"args":args,"cookie_mode":os.stat(cookie).st_mode&0o777 if cookie else None})+"\n")'."\n".
        'image={"url":base+"/photo.jpg","width":160,"height":120}'."\n".
        'video={"video_list":{"V_720P":{"url":base+"/clip.mp4","width":320,"height":180},"V_HLSV4":{"url":base+"/must-not-fetch.m3u8","width":320,"height":180}}}'."\n".
        'pin={"id":"123456789","description":mode["description"],"images":{"orig":image}}'."\n".
        'if mode["kind"]=="video":pin["videos"]=video'."\n".
        'if mode["kind"] in ("story","oversize","broken"):'."\n".
        '    blocks=[{"type":"story_pin_image_block","image_signature":"abcdef0123","image":{"images":{"originals":image}}},{"type":"story_pin_video_block","video":video},{"type":"story_pin_image_block","image_signature":"abcdef0456","image":{"images":{"originals":image}}},{"type":"story_pin_music_block","audio":{"audio_url":base+"/must-not-fetch.m4a"}},{"type":"story_pin_paragraph_block","text":"story text"}]'."\n".
        '    if mode["kind"]=="oversize":blocks=blocks[:1]*21'."\n".
        '    if mode["kind"]=="broken":blocks[1]["video"]={}'."\n".
        '    pin["story_pin_data"]={"id":"story","pages":[{"id":"page1","blocks":blocks}]}'."\n".
        'if mode["kind"]=="carousel":pin["carousel_data"]={"carousel_slots":[{"id":str(i),"images":{"orig":dict(image)}} for i in range(3)]}'."\n".
        'def call(self,resource,options):'."\n".
        '    if mode["kind"]=="blocked":raise self.extractor.exc.AuthorizationError("Pinterest login required")'."\n".
        '    if resource!="Pin":raise AssertionError("Only single-pin API calls are allowed")'."\n".
        '    return {"resource_response":{"data":pin}}'."\n".
        'PinterestAPI._call=call'."\n".
        'PinterestPinitExtractor.request_location=lambda self,url:mode["redirect"]'."\n".
        'runpy.run_path('.json_encode(dirname(__DIR__).'/bin/pinterest-download.py',JSON_UNESCAPED_SLASHES).',run_name="__main__")'."\n");
    chmod($stub,0700); $config->galleryDl=$stub;
    $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
    $worker=new Zero\Worker($config,$store,$api,new Zero\Articles($config,$base,$base.'/upload'));
    $requests=fn()=>array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    $description="متن اصلی پین\n<script> & عکس‌ها"; $serial=0;
    $process=function(string $kind,bool $group=false,bool $short=false,string $redirect='https://www.pinterest.com/pin/123456789/',?callable $beforeWorker=null) use(&$serial,$mode,$log,$trace,$description,$bot,$store,$worker): array {
        $id=++$serial; $user=10+$id; $chat=$group?-100123:$user;
        file_put_contents($mode,json_encode(['kind'=>$kind,'description'=>$description,'redirect'=>$redirect])); file_put_contents($log,''); file_put_contents($trace,'');
        $bot->handle(['update_id'=>1000+$id,'message'=>['message_id'=>$id,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0,'text'=>$short?'https://pin.it/AbC123':'https://pinterest.com/pin/123456789/?tracking=1']]);
        $job=$store->claim('media'); if(!$job) throw new RuntimeException('Pinterest media job not queued'); if($beforeWorker) $beforeWorker(); $worker->process($job);
        return $store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    };
    $job=$process('photo'); $photos=array_values(array_filter($requests(),fn($call)=>$call['method']==='sendPhoto'));
    if($job['status']!=='done') throw new RuntimeException($store->get('last_job_error'));
    pinMediaCheck(count($photos)===1&&$photos[0]['uploads']['photo']['hash']===hash_file('sha256',$temp.'/photo.jpg')&&json_decode(file($trace)[0],true)['cookie_mode']===null,'a public pin photo uses the actual Pinterest parser and uploads real bytes without cookies or quality selection');
    pinMediaCheck(json_decode($photos[0]['params']['reply_markup'],true)['inline_keyboard']===[]&&!str_contains($photos[0]['params']['caption'],'href='),'Pinterest source links default off in captions and buttons');
    $reactions=array_values(array_filter($requests(),fn($call)=>$call['method']==='setMessageReaction'));
    pinMediaCheck(count($reactions)===1&&json_decode($reactions[0]['params']['reaction'],true)[0]['emoji']==='👾','Pinterest links receive the configured default reaction');
    $cookies="# Netscape HTTP Cookie File\n.pinterest.com\tTRUE\t/\tTRUE\t2147483647\t_pinterest_sess\tFAKE_PINTEREST_SESSION\n";
    Zero\Pinterest::saveCookies($config,$cookies); $job=$process('video',true,true);
    $videos=array_values(array_filter($requests(),fn($call)=>$call['method']==='sendVideo'));
    pinMediaCheck($job['status']==='done'&&count($videos)===1&&(int)$videos[0]['params']['chat_id']===-100123&&(int)$videos[0]['params']['message_thread_id']===321,'a short pin link resolves to one video and retains its group topic');
    $info=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-of','json',$temp.'/delivered.mp4'],$temp,30),true);
    pinMediaCheck(in_array('audio',array_column($info['streams'],'codec_type'),true)&&json_decode(file($trace)[0],true)['cookie_mode']===0600&&file_get_contents($config->pinterestCookiePath())===$cookies,'direct MP4 delivery keeps its audio and uses an isolated private cookie copy');
    $job=$process('story',true); $state=json_decode($job['options'],true)['pages']; $message=$state['message'];
    $slides=array_values(array_filter($requests(),fn($call)=>in_array($call['method'],['sendPhoto','sendVideo'],true)));
    $rows=json_decode($slides[0]['params']['reply_markup'],true)['inline_keyboard'];
    pinMediaCheck(count($state['items'])===3&&count($slides)===1&&$rows[0][0]['callback_data']==='pinpage:'.$job['id'].':2:1'&&!isset($rows[0][0]['style'])&&str_contains($slides[0]['params']['caption'],'&lt;script&gt;'),'a mixed story excludes music/text assets and displays its first slide with an escaped caption and neutral Next button');
    $cache=$config->storage.'/pinterest-pages/'.$state['folder'];
    pinMediaCheck(count(glob($cache.'/*'))===3&&!glob($cache.'/*cookies*')&&(fileperms($cache.'/2.mp4')&0777)===0600,'Pinterest retains only private prepared visual files in its separate page cache');
    $turn=function(int $page,?int $user=null,?int $thread=321,?string $prefix=null) use($bot,$store,&$job,$message): void {
        $state=json_decode($job['options'],true)['pages']; $caption=['message_id'=>$message,'chat'=>['id'=>(int)$job['chat_id'],'type'=>'supergroup']]; if($thread!==null) $caption['message_thread_id']=$thread;
        $bot->handle(['callback_query'=>['id'=>'pin-turn','from'=>['id'=>$user??(int)$job['user_id']],'message'=>$caption,'data'=>($prefix??'pinpage').':'.$job['id'].':'.$page.':'.$state['revision']]]);
    };
    $turn(2,999); $turn(2,thread:999); $turn(2,prefix:'igpage'); pinMediaCheck($store->claim('media')===null,'another user, wrong topic and cross-service callbacks cannot advance a Pinterest post');
    $turn(2,thread:null); $next=$store->claim('media'); $worker->process($next); $job=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    $edits=array_values(array_filter($requests(),fn($call)=>$call['method']==='editMessageMedia')); $edit=end($edits); $rows=json_decode($edit['params']['reply_markup'],true)['inline_keyboard'];
    pinMediaCheck((int)$edit['params']['message_id']===$message&&json_decode($edit['params']['media'],true)['type']==='video'&&count($rows[0])===2&&!isset($rows[0][0]['style'])&&!isset($rows[0][1]['style']),'Next replaces the first photo with video in the same message and offers wide neutral Previous/Next controls');
    $store->set('pinterest.source_button','1'); $turn(1); $next=$store->claim('media'); $worker->process($next); $edits=array_values(array_filter($requests(),fn($call)=>$call['method']==='editMessageMedia')); $edit=end($edits);
    pinMediaCheck($edit['uploads']===[]&&str_contains(json_decode($edit['params']['media'],true)['media'],'fixture_photo_')&&count(json_decode($edit['params']['reply_markup'],true)['inline_keyboard'])===2,'Previous reuses the confirmed photo file ID and applies the Pinterest source setting');
    $job=$process('carousel'); pinMediaCheck($job['status']==='done'&&count(json_decode($job['options'],true)['pages']['items'])===3,'ordinary image carousels retain every ordered slot');
    foreach(['oversize','broken','blocked'] as $kind) {
        $job=$process($kind); pinMediaCheck($job['status']==='failed'&&!array_filter($requests(),fn($call)=>in_array($call['method'],['sendPhoto','sendVideo','createPage'],true)),'unsupported or incomplete pins fail without partial delivery: '.$kind);
        if($kind==='blocked') pinMediaCheck($store->get('last_job_service')==='pinterest'&&$store->get('last_job_error_reason')==='blocked','a Pinterest login requirement has service-specific diagnostics');
    }
    foreach(['https://www.pinterest.com/user/board/','https://pinterest.evil/pin/123/','https://www.pinterest.com:443/pin/123/'] as $redirect) {
        $job=$process('photo',short:true,redirect:$redirect); pinMediaCheck($job['status']==='failed'&&!array_filter($requests(),fn($call)=>in_array($call['method'],['sendPhoto','sendVideo'],true)),'a short link cannot trigger a board download or follow an unrelated destination');
    }
    $store->set('pinterest.carousel_mode','article'); $job=$process('story'); $pages=array_values(array_filter($requests(),fn($call)=>$call['method']==='createPage'));
    pinMediaCheck($job['status']==='done'&&count($pages)===1&&$pages[0]['params']['title']==='پست پینترست'&&str_contains($pages[0]['params']['content'],'pinterest.com'),'the optional article mode uses Pinterest captions and source with the shared verified publication pipeline');
    $job=$process('photo',beforeWorker:fn()=>$store->set('admins_only','1')); $store->set('admins_only','0');
    pinMediaCheck($job['status']==='failed'&&file_get_contents($trace)==='','revoked access prevents the Pinterest worker from invoking its downloader');
    $job=$process('photo',group:true,beforeWorker:fn()=>$store->set('groups_enabled','0')); $store->set('groups_enabled','1');
    pinMediaCheck($job['status']==='failed'&&file_get_contents($trace)==='','disabling groups also stops queued Pinterest work before fetching media');
    pinMediaCheck(!glob($config->storage.'/downloads/job-*'),'private Pinterest download files and cookie copies are cleaned after processing');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
