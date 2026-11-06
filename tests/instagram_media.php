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
    $photo=$temp.'/photo.jpg'; $clip=$temp.'/clip.mp4';
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=160x120','-frames:v','1','-update','1','-threads','1',$photo],$temp,30);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=320x180:r=1:d=1','-c:v','libx264','-threads','2','-pix_fmt','yuv420p',$clip],$temp,30);
    $log=$temp.'/http.jsonl'; file_put_contents($log,''); $fail=$temp.'/fail-upload';
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php '.
        '$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); $method=basename($path);'.
        'if($path==="/photo.jpg"||$path==="/clip.mp4") { header("Content-Type: ".($path==="/photo.jpg"?"image/jpeg":"video/mp4")); readfile('.var_export($temp,true).'.$path); return; }'.
        '$uploads=[];foreach($_FILES as $key=>$file) $uploads[$key]=["name"=>$file["name"],"mime"=>$file["type"],"hash"=>hash_file("sha256",$file["tmp_name"])];'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        'if($method==="upload") { if(is_file('.var_export($fail,true).')) { http_response_code(503);echo "offline";return; }'.
        '$file=$_FILES["fileToUpload"]; echo "https://files.catbox.moe/fixture.".($file["type"]==="video/mp4"?"mp4":"jpg");return; }'.
        '$result=match($method) { "createAccount"=>["access_token"=>str_repeat("A",64)],"createPage"=>["url"=>"https://telegra.ph/Instagram-11-06"],default=>["message_id"=>900] };'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>$result]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $mode=$temp.'/mode.json'; $trace=$temp.'/backend.jsonl'; file_put_contents($trace,'');
    $python=getenv('GALLERY_DL_PYTHON')?:(is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'/usr/bin/python3');
    $stub=$temp.'/gallery-fixture';
    // Exercise the actual pinned Instagram items(), CLI, HTTP downloader,
    // filename formatter and postprocessors; only upstream post extraction is local.
    file_put_contents($stub,'#!'.$python."\n".
        "import json,sys,os\nimport gallery_dl\nfrom gallery_dl.extractor.instagram import InstagramPostExtractor\n".
        'mode=json.load(open('.json_encode($mode,JSON_UNESCAPED_SLASHES)."))\n".
        'args=sys.argv[1:];cookie=args[args.index("--cookies")+1] if "--cookies" in args else None'."\n".
        'with open('.json_encode($trace,JSON_UNESCAPED_SLASHES).',"a") as trace:trace.write(json.dumps({"args":args,"cookie_mode":os.stat(cookie).st_mode&0o777 if cookie else None})+"\n")'."\n".
        'files=[{"num":i+1,"display_url":'.json_encode($base.'/photo.jpg',JSON_UNESCAPED_SLASHES).',"video_url":'.json_encode($base.'/clip.mp4',JSON_UNESCAPED_SLASHES).' if kind=="video" else None} for i,kind in enumerate(mode["kinds"])]'."\n".
        'post={"description":mode["description"],"post_url":"https://www.instagram.com/p/ABC_def12/","post_shortcode":"ABC_def12","post_id":"123","username":"fixture","_files":files}'."\n".
        "InstagramPostExtractor.login=lambda self:None\nInstagramPostExtractor.metadata=lambda self:{}\nInstagramPostExtractor.posts=lambda self:[post]\nInstagramPostExtractor._parse_post=lambda self,value:value\ngallery_dl.main()\n");
    chmod($stub,0700); $config->galleryDl=$stub; $config->api=$base;
    $store=new Zero\Store($storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
    $publisher=new Zero\Articles($config,$base,$base.'/upload'); $worker=new Zero\Worker($config,$store,$api,$publisher);
    $description="متن اصلی پست\n<script>alert('fixture')</script> & عکس‌ها";
    $requests=fn()=>array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    $counter=0;
    $process=function(array $kinds,bool $group=false) use(&$counter,$mode,$trace,$description,$bot,$store,$worker,$log): array {
        $id=++$counter; file_put_contents($mode,json_encode(['kinds'=>$kinds,'description'=>$description])); file_put_contents($log,''); file_put_contents($trace,'');
        $user=10+$id; $chat=$group?-100123:$user;
        $bot->handle(['update_id'=>1000+$id,'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0,'text'=>'https://instagram.com/p/ABC_def12/?img_index=2']]);
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
    $store->set('text.instagram_article_title','عنوان سفارشی');
    $job=$process(['photo','video','photo']); $calls=$requests();
    if($job['status']!=='done') throw new RuntimeException($store->get('last_job_error'));
    $uploads=array_values(array_filter($calls,fn($call)=>$call['method']==='upload')); $pages=array_values(array_filter($calls,fn($call)=>$call['method']==='createPage'));
    igMediaCheck(count($uploads)===3&&count($pages)===1&&!array_filter($calls,fn($call)=>in_array($call['method'],['sendPhoto','sendVideo'],true)),'mixed carousel slides are uploaded through real multipart requests and produce one confirmed article');
    $nodes=json_decode($pages[0]['params']['content'],true); $figures=array_values(array_filter($nodes,fn($node)=>$node['tag']==='figure'));
    igMediaCheck(array_map(fn($node)=>$node['children'][0]['tag'],$figures)===['img','video','img']&&$nodes[0]['children'][0]==='متن اصلی پست'&&$nodes[1]['children'][0]==="<script>alert('fixture')</script> & عکس‌ها",'the article preserves slide order and the original caption as text nodes');
    igMediaCheck($pages[0]['params']['title']==='عنوان سفارشی'&&!str_contains(json_encode($pages),'FAKE_SESSION')&&(fileperms($storage.'/telegraph-account.json')&0777)===0600,'article customization applies and neither cookie values nor the private account are published');
    $last=end($calls); $buttons=json_decode($last['params']['reply_markup'],true)['inline_keyboard'];
    igMediaCheck($last['method']==='editMessageText'&&$buttons[0][0]['url']==='https://telegra.ph/Instagram-11-06'&&$buttons[0][0]['style']==='primary'&&!array_filter($calls,fn($call)=>$call['method']==='deleteMessage'),'the same request status becomes the article link and is retained');
    touch($fail); $job=$process(['photo','photo']); $calls=$requests();
    igMediaCheck($job['status']==='failed'&&!array_filter($calls,fn($call)=>$call['method']==='createPage')&&count(array_filter($calls,fn($call)=>$call['method']==='upload'))===1,'a hosting failure stops publication without a fake article link or retry loop');
    igMediaCheck(!array_filter($calls,fn($call)=>$call['method']==='createAccount'),'parallel-safe article account storage is reused across requests');
    file_put_contents($log,''); $denied=false;
    try { $publisher->publish([
        ['type'=>'photo','path'=>$photo,'index'=>1,'description'=>$description],
        ['type'=>'photo','path'=>$photo,'index'=>2,'description'=>$description],
    ],'https://instagram.com/p/ABC_def12/',$bot->ui,fn()=>false); }
    catch(Zero\MediaFailure $failure) { $denied=$failure->reason==='restricted'; }
    igMediaCheck($denied&&$requests()===[],'revoked access prevents any article-service or media-host request');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
