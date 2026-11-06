<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function igMediaCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$temp=sys_get_temp_dir().'/ciro-ig-media-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $storage=$temp.'/data'; mkdir($storage,0700); mkdir($storage.'/downloads',0700);
    $config=new Zero\Config('123:fixture',[42],$storage); $runner=new Zero\Download($config);
    $photo=$temp.'/photo.jpg'; $clip=$temp.'/clip.mp4'; $audioClip=$temp.'/audio-clip.mp4';
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=160x120','-frames:v','1','-update','1','-threads','1',$photo],$temp,30);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=320x180:r=1:d=1','-c:v','libx264','-threads','2','-pix_fmt','yuv420p',$clip],$temp,30);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$clip,'-f','lavfi','-i','sine=frequency=440:duration=1','-c:v','copy','-c:a','aac','-shortest',$audioClip],$temp,30);
    $log=$temp.'/http.jsonl'; file_put_contents($log,''); $fail=$temp.'/fail-upload';
    $reject=$temp.'/reject-catbox'; $failFallback=$temp.'/fail-fallback'; $invalidFallback=$temp.'/invalid-fallback';
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php '.
        '$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); $method=basename($path);'.
        'if($path==="/photo.jpg"||$path==="/clip.mp4") { header("Content-Type: ".($path==="/photo.jpg"?"image/jpeg":"video/mp4")); $root='.var_export($temp,true).'; readfile($root.($path==="/clip.mp4"&&is_file($root."/with-audio")?"/audio-clip.mp4":$path)); return; }'.
        'if(isset($_FILES["video"])) copy($_FILES["video"]["tmp_name"],'.var_export($temp.'/delivered.mp4',true).');'.
        '$uploads=[];foreach($_FILES as $key=>$file) { if(is_array($file["tmp_name"])) $file=array_map(fn($field)=>$field[0],$file);$uploads[$key]=["name"=>$file["name"],"mime"=>$file["type"],"hash"=>hash_file("sha256",$file["tmp_name"])]; }'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads,"protocol"=>$_SERVER["SERVER_PROTOCOL"]],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        'if($method==="upload") { if(is_file('.var_export($reject,true).')) { http_response_code(412);echo "rejected";return; } if(is_file('.var_export($fail,true).')) { http_response_code(503);echo "offline";return; }'.
        '$file=$_FILES["fileToUpload"]; echo "https://files.catbox.moe/fixture.".($file["type"]==="video/mp4"?"mp4":"jpg");return; }'.
        'if($method==="fallback") { if(is_file('.var_export($failFallback,true).')) { http_response_code(503);echo "offline";return; }'.
        '$url=is_file('.var_export($invalidFallback,true).')?"https://invalid.example/file.jpg":"https://qu.ax/fixture.".($_FILES["files"]["type"][0]==="video/mp4"?"mp4":"jpg");'.
        'header("Content-Type: application/json");echo json_encode(["success"=>true,"files"=>[["url"=>$url]]]);return; }'.
        '$result=match($method) { "createAccount"=>["access_token"=>str_repeat("A",64)],"createPage"=>["url"=>"https://telegra.ph/Instagram-11-06"],default=>["message_id"=>900] };'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>$result]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $mode=$temp.'/mode.json'; $trace=$temp.'/backend.jsonl'; file_put_contents($trace,'');
    $python=getenv('GALLERY_DL_PYTHON')?:(is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'/usr/bin/python3');
    $stub=$temp.'/gallery-fixture';
    // Keep the real Instagram parser, including extra music/audio entries.
    // Only the upstream API response is replaced by a local raw post.
    file_put_contents($stub,'#!'.$python."\n".
        "import json,sys,os,runpy\nfrom gallery_dl.extractor.instagram import InstagramPostExtractor\n".
        'mode=json.load(open('.json_encode($mode,JSON_UNESCAPED_SLASHES)."))\n".
        'args=sys.argv[1:];cookie=args[args.index("--cookies")+1] if "--cookies" in args else None'."\n".
        'with open('.json_encode($trace,JSON_UNESCAPED_SLASHES).',"a") as trace:trace.write(json.dumps({"args":args,"cookie_mode":os.stat(cookie).st_mode&0o777 if cookie else None})+"\n")'."\n".
        'base='.json_encode($base,JSON_UNESCAPED_SLASHES)."\n".
        'files=[{"pk":100+i,"code":"ABC_def12","taken_at":1700000000,"image_versions2":{"candidates":[{"url":base+"/photo.jpg","width":160,"height":120}]},"video_versions":[{"url":base+"/clip.mp4","width":320,"height":180}] if kind=="video" else []} for i,kind in enumerate(mode["kinds"])]'."\n".
        'post={"pk":123,"code":"ABC_def12","caption":{"text":mode["description"]},"user":{"pk":7,"username":"fixture"},"taken_at":1700000000}'."\n".
        'if len(files)>1:post.update(carousel_media=files,carousel_media_count=len(files))'."\n".
        'else:post.update(files[0])'."\n".
        'if mode["music"]:post["clips_metadata"]={"original_sound_info":{"audio_asset_id":456,"original_audio_title":"Fixture sound","duration_in_ms":1000,"progressive_download_url":base+"/sound.m4a"}}'."\n".
        'if mode["music"] and len(files)>1:post["music_metadata"]={"music_info":post["clips_metadata"]}'."\n".
        'if mode["missing"]:files[-1].pop("image_versions2")'."\n".
        "InstagramPostExtractor.posts=lambda self:[post]\n".
        'runpy.run_path('.json_encode(dirname(__DIR__).'/bin/instagram-download.py',JSON_UNESCAPED_SLASHES).',run_name="__main__")'."\n");
    chmod($stub,0700); $config->galleryDl=$stub; $config->api=$base;
    $store=new Zero\Store($storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
    $publisher=new Zero\Articles($config,$base,$base.'/upload',$base.'/fallback'); $worker=new Zero\Worker($config,$store,$api,$publisher);
    $description="متن اصلی پست\n<script>alert('fixture')</script> & عکس‌ها";
    $requests=fn()=>array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    $counter=0;
    $process=function(array $kinds,bool $group=false,bool $music=true,bool $missing=false,bool $withAudio=false) use(&$counter,$mode,$trace,$description,$bot,$store,$worker,$log,$temp): array {
        $id=++$counter; file_put_contents($mode,json_encode(['kinds'=>$kinds,'description'=>$description,'music'=>$music,'missing'=>$missing])); file_put_contents($log,''); file_put_contents($trace,'');
        if(is_file($temp.'/with-audio')) unlink($temp.'/with-audio');
        if($withAudio) touch($temp.'/with-audio');
        $user=10+$id; $chat=$group?-100123:$user;
        $url=$kinds===['video']?'https://www.instagram.com/reel/DfZRdXotNPJ/?utm_source=ig_web_copy_link&obrf=fixture':'https://instagram.com/p/ABC_def12/?img_index=2';
        $bot->handle(['update_id'=>1000+$id,'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0,'text'=>$url]]);
        $job=$store->claim('media'); if(!$job) throw new RuntimeException('Instagram media job was not queued');
        $worker->process($job);
        return $store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    };
    $job=$process(['photo']); $calls=$requests();
    if($job['status']!=='done') throw new RuntimeException($store->get('last_job_error'));
    $photos=array_values(array_filter($calls,fn($call)=>$call['method']==='sendPhoto'));
    igMediaCheck(count($photos)===1&&$photos[0]['uploads']['photo']['hash']===hash_file('sha256',$photo),'a single Instagram photo uploads its real bytes directly without quality selection');
    igMediaCheck(count(file($trace))===1&&!in_array('--cookies',json_decode(file($trace)[0],true)['args'],true),'the actual gallery-dl CLI handles a cookie-free post in one invocation');
    igMediaCheck(end($calls)['method']==='deleteMessage'&&!array_filter($calls,fn($call)=>$call['method']==='editMessageMedia'),'single-file delivery cleans up its one status and never shows a quality poster');
    $cookies="# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t2147483647\tsessionid\tFAKE_SESSION\n"; Zero\Instagram::saveCookies($config,$cookies);
    $job=$process(['video'],true); $calls=$requests();
    if($job['status']!=='done') throw new RuntimeException($store->get('last_job_error'));
    $videos=array_values(array_filter($calls,fn($call)=>$call['method']==='sendVideo')); $invocation=json_decode(file($trace)[0],true);
    igMediaCheck(count($videos)===1&&$videos[0]['uploads']['video']['mime']==='video/mp4'&&(int)$videos[0]['params']['chat_id']===-100123&&(int)$videos[0]['params']['message_thread_id']===321,'a silent Instagram video is prepared and delivered to the original group topic');
    igMediaCheck($invocation['cookie_mode']===0600&&file_get_contents($config->instagramCookiePath())===$cookies,'Instagram downloads use private cookie copies without refreshing the shared jar');
    $job=$process(['video'],music:false,withAudio:true);
    igMediaCheck($job['status']==='done','Reels without a separate music record still deliver normally');
    $info=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-of','json',$temp.'/delivered.mp4'],$temp,30),true);
    igMediaCheck(in_array('audio',array_column($info['streams'],'codec_type'),true),'the video keeps its own soundtrack when standalone music records are excluded');
    $store->set('text.instagram_article_title','عنوان سفارشی');
    $job=$process(['photo','video','photo']); $calls=$requests();
    if($job['status']!=='done') throw new RuntimeException($store->get('last_job_error'));
    $uploads=array_values(array_filter($calls,fn($call)=>$call['method']==='upload')); $pages=array_values(array_filter($calls,fn($call)=>$call['method']==='createPage'));
    igMediaCheck(count($uploads)===3&&count($pages)===1&&!array_filter($calls,fn($call)=>in_array($call['method'],['sendPhoto','sendVideo'],true)),'mixed carousel slides are uploaded through real multipart requests and produce one confirmed article');
    igMediaCheck(!array_filter(array_merge($uploads,$pages),fn($call)=>$call['protocol']!=='HTTP/1.1'),'media hosting and article publication use HTTP/1.1 without HTTP/2 stream negotiation');
    $nodes=json_decode($pages[0]['params']['content'],true); $figures=array_values(array_filter($nodes,fn($node)=>$node['tag']==='figure'));
    igMediaCheck(array_map(fn($node)=>$node['children'][0]['tag'],$figures)===['img','video','img']&&$nodes[0]['children'][0]==='متن اصلی پست'&&$nodes[1]['children'][0]==="<script>alert('fixture')</script> & عکس‌ها",'the article preserves slide order and the original caption as text nodes');
    igMediaCheck($pages[0]['params']['title']==='عنوان سفارشی'&&!str_contains(json_encode($pages),'FAKE_SESSION')&&(fileperms($storage.'/telegraph-account.json')&0777)===0600,'article customization applies and neither cookie values nor the private account are published');
    $last=end($calls); $buttons=json_decode($last['params']['reply_markup'],true)['inline_keyboard'];
    igMediaCheck($last['method']==='editMessageText'&&$buttons[0][0]['url']==='https://telegra.ph/Instagram-11-06'&&$buttons[0][0]['style']==='primary'&&!array_filter($calls,fn($call)=>$call['method']==='deleteMessage'),'the same request status becomes the article link and is retained');
    igMediaCheck(!array_filter($calls,fn($call)=>$call['method']==='sound.m4a'),'separate music records are not downloaded as extra slides');
    $job=$process(array_fill(0,20,'photo')); $calls=$requests();
    igMediaCheck($job['status']==='done'&&count(array_filter($calls,fn($call)=>$call['method']==='upload'))===20,'a full 20-slide carousel with additional music retains every visual slide');
    touch($reject); $job=$process(['photo','video','photo']); $calls=$requests();
    $fallbacks=array_values(array_filter($calls,fn($call)=>$call['method']==='fallback')); $pages=array_values(array_filter($calls,fn($call)=>$call['method']==='createPage'));
    igMediaCheck($job['status']==='done'&&count($fallbacks)===3&&count($pages)===1&&count(array_filter($calls,fn($call)=>$call['method']==='upload'))===1,'an explicit Catbox 412 switches the whole remaining article to Qu.ax after one rejected upload');
    $nodes=json_decode($pages[0]['params']['content'],true); $figures=array_values(array_filter($nodes,fn($node)=>$node['tag']==='figure'));
    igMediaCheck(array_map(fn($node)=>$node['children'][0]['tag'],$figures)===['img','video','img']&&$fallbacks[0]['uploads']['files']['hash']===hash_file('sha256',$photo),'the fallback preserves mixed slide order and uploads the real bytes through files[] multipart');
    file_put_contents($log,''); $checks=0; $revoked=false;
    try { $publisher->publish([
        ['type'=>'photo','path'=>$photo,'index'=>1],['type'=>'photo','path'=>$photo,'index'=>2],
    ],'https://instagram.com/p/ABC_def12/',$bot->ui,function() use(&$checks): bool { return ++$checks<3; }); }
    catch(Zero\MediaFailure $failure) { $revoked=$failure->reason==='restricted'; }
    $calls=$requests();
    igMediaCheck($revoked&&count(array_filter($calls,fn($call)=>$call['method']==='upload'))===1&&!array_filter($calls,fn($call)=>$call['method']==='fallback'),'revoked access is checked again before transferring media to the fallback host');
    touch($failFallback); $job=$process(['photo','photo']); $calls=$requests();
    igMediaCheck($job['status']==='failed'&&count(array_filter($calls,fn($call)=>$call['method']==='fallback'))===1&&!array_filter($calls,fn($call)=>$call['method']==='createPage'),'a fallback hosting failure stops without a false article or retry loop');
    unlink($failFallback); touch($invalidFallback); $job=$process(['photo','photo']); $calls=$requests();
    igMediaCheck($job['status']==='failed'&&!array_filter($calls,fn($call)=>$call['method']==='createPage'),'an untrusted fallback URL cannot be inserted into a public article');
    unlink($invalidFallback); unlink($reject);
    $job=$process(['photo','photo'],missing:true); $calls=$requests();
    igMediaCheck($job['status']==='failed'&&!array_filter($calls,fn($call)=>in_array($call['method'],['upload','createPage','sendPhoto','sendVideo'],true)),'a genuinely missing visual slide still prevents partial delivery or publication');
    igMediaCheck($store->get('last_job_error_reason')==='process'&&str_contains($store->get('last_job_error'),'expected=2 received=1'),'the adapter preserves gallery-dl failure status and its useful slide-count diagnosis');
    touch($fail); $job=$process(['photo','photo']); $calls=$requests();
    igMediaCheck($job['status']==='failed'&&!array_filter($calls,fn($call)=>$call['method']==='createPage')&&count(array_filter($calls,fn($call)=>$call['method']==='upload'))===1,'a hosting failure stops publication without a fake article link or retry loop');
    igMediaCheck(str_contains($store->get('last_job_error'),'provider=catbox operation=upload')&&str_contains($store->get('last_job_error'),'HTTP=503'),'hosting diagnostics identify the service, operation and HTTP response');
    igMediaCheck(!array_filter($calls,fn($call)=>$call['method']==='createAccount'),'parallel-safe article account storage is reused across requests');
    file_put_contents($log,''); $denied=false;
    try { $publisher->publish([
        ['type'=>'photo','path'=>$photo,'index'=>1,'description'=>$description],
        ['type'=>'photo','path'=>$photo,'index'=>2,'description'=>$description],
    ],'https://instagram.com/p/ABC_def12/',$bot->ui,fn()=>false); }
    catch(Zero\MediaFailure $failure) { $denied=$failure->reason==='restricted'; }
    igMediaCheck($denied&&$requests()===[],'revoked access prevents any article-service or media-host request');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $closed=stream_socket_get_name($socket,false); fclose($socket);
    $isolated=$temp.'/offline-article'; mkdir($isolated,0700);
    $offlineConfig=clone $config; $offlineConfig->storage=$isolated; $detail='';
    try { (new Zero\Articles($offlineConfig,'http://'.$closed,$base.'/upload'))->publish([
        ['type'=>'photo','path'=>$photo,'index'=>1],['type'=>'photo','path'=>$photo,'index'=>2],
    ],'https://instagram.com/p/ABC_def12/',$bot->ui,fn()=>true); }
    catch(Zero\MediaFailure $failure) { $detail=$failure->getMessage(); }
    igMediaCheck(str_contains($detail,'provider=telegraph operation=createAccount')&&str_contains($detail,'HTTP=0 curl=7')&&!str_contains($detail,'FAKE_SESSION'),'a failed connection keeps its curl cause and service without leaking cookies');
    file_put_contents($log,''); $detail='';
    try { (new Zero\Articles($config,$base,'http://'.$closed,$base.'/fallback'))->publish([
        ['type'=>'photo','path'=>$photo,'index'=>1],['type'=>'photo','path'=>$photo,'index'=>2],
    ],'https://instagram.com/p/ABC_def12/',$bot->ui,fn()=>true); }
    catch(Zero\MediaFailure $failure) { $detail=$failure->getMessage(); }
    igMediaCheck(str_contains($detail,'provider=catbox')&&str_contains($detail,'curl=7')&&$requests()===[],'an uncertain transport failure never uploads the media again to another host');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
