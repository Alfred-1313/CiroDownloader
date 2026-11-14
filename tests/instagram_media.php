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
    $reject=$temp.'/reject-catbox'; $failFallback=$temp.'/fail-fallback'; $invalidFallback=$temp.'/invalid-fallback'; $failTelegram=$temp.'/fail-telegram';
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php require '.var_export(__DIR__.'/fixtures/instagram_telegram.php',true).';'.
        '$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); $method=basename($path);'.
        'if($path==="/photo.jpg"||$path==="/clip.mp4") { header("Content-Type: ".($path==="/photo.jpg"?"image/jpeg":"video/mp4")); $root='.var_export($temp,true).'; readfile($root.($path==="/clip.mp4"&&is_file($root."/with-audio")?"/audio-clip.mp4":$path)); return; }'.
        'if(isset($_FILES["video"])) copy($_FILES["video"]["tmp_name"],'.var_export($temp.'/delivered.mp4',true).');'.
        '$uploads=[];foreach($_FILES as $key=>$file) { if(is_array($file["tmp_name"])) $file=array_map(fn($field)=>$field[0],$file);$uploads[$key]=["name"=>$file["name"],"mime"=>$file["type"],"hash"=>hash_file("sha256",$file["tmp_name"])]; }'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads,"protocol"=>$_SERVER["SERVER_PROTOCOL"]],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        'if(in_array($method,["sendPhoto","sendVideo","editMessageMedia"],true)&&is_file('.var_export($failTelegram,true).')) { http_response_code(503);echo "connection interrupted";return; }'.
        'if($method==="upload") { if(is_file('.var_export($reject,true).')) { http_response_code(412);echo "rejected";return; } if(is_file('.var_export($fail,true).')) { http_response_code(503);echo "offline";return; }'.
        '$file=$_FILES["fileToUpload"]; echo "https://files.catbox.moe/fixture.".($file["type"]==="video/mp4"?"mp4":"jpg");return; }'.
        'if($method==="fallback") { if(is_file('.var_export($failFallback,true).')) { http_response_code(503);echo "offline";return; }'.
        '$url=is_file('.var_export($invalidFallback,true).')?"https://invalid.example/file.jpg":"https://qu.ax/fixture.".($_FILES["files"]["type"][0]==="video/mp4"?"mp4":"jpg");'.
        'header("Content-Type: application/json");echo json_encode(["success"=>true,"files"=>[["url"=>$url]]]);return; }'.
        '$counter='.var_export($temp.'/message-counter',true).';$next=(int)@file_get_contents($counter)+1;file_put_contents($counter,(string)$next);'.
        '$result=instagramTelegramFixture($method,$_POST,$_FILES,'.var_export($temp,true).',900+$next)??match($method) { "createAccount"=>["access_token"=>str_repeat("A",64)],"createPage"=>["url"=>"https://telegra.ph/Instagram-11-06"],default=>["message_id"=>900+$next] };'.
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
    $process=function(array $kinds,bool $group=false,bool $music=true,bool $missing=false,bool $withAudio=false,?string $captionText=null) use(&$counter,$mode,$trace,$description,$bot,$store,$worker,$log,$temp): array {
        $id=++$counter; file_put_contents($mode,json_encode(['kinds'=>$kinds,'description'=>$captionText??$description,'music'=>$music,'missing'=>$missing])); file_put_contents($log,''); file_put_contents($trace,'');
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
    igMediaCheck(json_decode($photos[0]['params']['reply_markup'],true)['inline_keyboard']===[]&&!str_contains($photos[0]['params']['caption'],'href=')&&!str_contains($photos[0]['params']['caption'],'مشاهده'),'a default photo caption has no original-post link and its source button is off');
    igMediaCheck(str_contains($photos[0]['params']['caption'],'متن اصلی پست')&&str_contains($photos[0]['params']['caption'],'&lt;script&gt;')&&str_contains($photos[0]['params']['caption'],'&amp; عکس‌ها'),'a single Instagram photo includes the escaped original post caption');
    igMediaCheck(count(file($trace))===1&&!in_array('--cookies',json_decode(file($trace)[0],true)['args'],true),'the actual gallery-dl CLI handles a cookie-free post in one invocation');
    igMediaCheck(end($calls)['method']==='deleteMessage'&&!array_filter($calls,fn($call)=>$call['method']==='editMessageMedia'),'single-file delivery cleans up its one status and never shows a quality poster');
    $cookies="# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t2147483647\tsessionid\tFAKE_SESSION\n"; Zero\Instagram::saveCookies($config,$cookies);
    $job=$process(['video'],true); $calls=$requests();
    if($job['status']!=='done') throw new RuntimeException($store->get('last_job_error'));
    $videos=array_values(array_filter($calls,fn($call)=>$call['method']==='sendVideo')); $invocation=json_decode(file($trace)[0],true);
    igMediaCheck(count($videos)===1&&$videos[0]['uploads']['video']['mime']==='video/mp4'&&(int)$videos[0]['params']['chat_id']===-100123&&(int)$videos[0]['params']['message_thread_id']===321,'a silent Instagram video is prepared and delivered to the original group topic');
    igMediaCheck($invocation['cookie_mode']===0600&&file_get_contents($config->instagramCookiePath())===$cookies,'Instagram downloads use private cookie copies without refreshing the shared jar');
    igMediaCheck(json_decode($videos[0]['params']['reply_markup'],true)['inline_keyboard']===[]&&!str_contains($videos[0]['params']['caption'],'href='),'a default video also has no caption link or source button');
    igMediaCheck(str_contains($videos[0]['params']['caption'],'متن اصلی پست')&&str_contains($videos[0]['params']['caption'],'&lt;script&gt;'),'a single Instagram Reel includes the original caption in the group topic');
    $job=$process(['video'],music:false,withAudio:true);
    igMediaCheck($job['status']==='done','Reels without a separate music record still deliver normally');
    $info=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-of','json',$temp.'/delivered.mp4'],$temp,30),true);
    igMediaCheck(in_array('audio',array_column($info['streams'],'codec_type'),true),'the video keeps its own soundtrack when standalone music records are excluded');
    $store->set('text.instagram_caption','<b>سفارشی</b> <blockquote>{description}</blockquote>');
    $job=$process(['photo']); $sent=array_values(array_filter($requests(),fn($call)=>$call['method']==='sendPhoto'))[0];
    igMediaCheck(str_starts_with($sent['params']['caption'],'<b>سفارشی</b>')&&str_contains($sent['params']['caption'],'&lt;script&gt;')&&!str_contains($sent['params']['caption'],'{description}'),'a saved single-post caption can insert the description without losing its own formatting');
    $store->query('DELETE FROM settings WHERE key=?',['text.instagram_caption']);
    $job=$process(['photo'],captionText:str_repeat('متن 😎 <b>نوشته</b> & ',1000));
    $sent=array_values(array_filter($requests(),fn($call)=>$call['method']==='sendPhoto'))[0];
    $caption=$sent['params']['caption']; Zero\TextFormat::validate($caption,1024);
    igMediaCheck($job['status']==='done'&&str_contains($caption,'متن 😎')&&str_contains($caption,'&lt;b&gt;')&&Zero\TextFormat::length(html_entity_decode(strip_tags($caption),ENT_QUOTES|ENT_HTML5,'UTF-8'))<=1024,'long original captions stay within Telegram UTF-16 limits and remain escaped');
    $job=$process(['photo'],captionText:''); $sent=array_values(array_filter($requests(),fn($call)=>$call['method']==='sendPhoto'))[0];
    igMediaCheck($job['status']==='done'&&str_contains($sent['params']['caption'],'اینستاگرام')&&!str_contains($sent['params']['caption'],'{description}'),'a post with no original text retains the normal single-post caption');
    $job=$process(['photo','video','photo'],true); $calls=$requests();
    $slides=array_values(array_filter($calls,fn($call)=>in_array($call['method'],['sendPhoto','sendVideo'],true)));
    $state=json_decode($job['options'],true)['pages']; $firstMessage=$state['message']; $cache=$storage.'/instagram-pages/'.$state['folder'];
    $markup=json_decode($slides[0]['params']['reply_markup'],true)['inline_keyboard'];
    igMediaCheck($job['status']==='done'&&count($slides)===1&&$slides[0]['method']==='sendPhoto'&&count($markup[0])===1&&$markup[0][0]['callback_data']==='igpage:'.$job['id'].':2:1'&&$markup[0][0]['text']==='بعدی ⏭️'&&!isset($markup[0][0]['style']),'the first slide has one full-row neutral Next button with no counter or Previous button');
    igMediaCheck(count($markup)===1&&!str_contains($slides[0]['params']['caption'],'href='),'a default carousel keeps its navigation without an original-post link or source button');
    igMediaCheck(str_contains($slides[0]['params']['caption'],'متن اصلی پست')&&str_contains($slides[0]['params']['caption'],'&lt;script&gt;')&&!array_filter($calls,fn($call)=>in_array($call['method'],['upload','fallback','createAccount','createPage'],true)),'native pages include an escaped original caption and never contact an external host');
    igMediaCheck(count(glob($cache.'/*'))===2&&!is_file($cache.'/1.jpg')&&!glob($cache.'/*cookies*')&&(fileperms($cache.'/2.mp4')&0777)===0600,'only unsent private slides survive; the sent slide is stored by Telegram');
    file_put_contents($cache.'/1.jpg','legacy confirmed copy'); (new Zero\InstagramPages($config,$store,$api))->prune();
    igMediaCheck(!is_file($cache.'/1.jpg')&&is_file($cache.'/2.mp4'),'maintenance releases confirmed copies left by older versions while preserving unsent slides');
    // A new poller/store instance must use the durable cursor, not process memory.
    $pageBot=new Zero\Bot($config,new Zero\Store($storage),$api);
    $toggleSource=fn()=>$pageBot->handle(['callback_query'=>['id'=>'source-toggle','from'=>['id'=>42],'message'=>['message_id'=>777,'chat'=>['id'=>42,'type'=>'private']],'data'=>'custom:igsource']]);
    $turn=function(array $saved,int $page,?int $user=null,?int $message=null,?int $thread=null,?int $revision=null,bool $legacy=false,bool $omitThread=false,?int $chat=null) use($pageBot): void {
        $state=json_decode($saved['options'],true)['pages'];
        $destination=$chat??(int)$saved['chat_id'];
        $caption=['message_id'=>$message??$state['message'],'from'=>['id'=>123,'is_bot'=>true],
            'chat'=>['id'=>$destination,'type'=>$destination<0?'supergroup':'private']];
        if(!$omitThread) $caption['message_thread_id']=$thread??(int)$saved['thread_id'];
        $pageBot->handle(['callback_query'=>['id'=>'page-fixture','from'=>['id'=>$user??(int)$saved['user_id']],
            'message'=>$caption,
            'data'=>'igpage:'.$saved['id'].':'.$page.($legacy?'':':'.($revision??$state['revision']))]]);
    };
    foreach([['user'=>999],['user'=>42],['thread'=>999],['message'=>999],['message'=>999,'omitThread'=>true],['chat'=>-100456,'omitThread'=>true]] as $wrong) $turn($job,2,...$wrong);
    igMediaCheck($store->claim('media')===null,'another sender, topic or message cannot advance the carousel');
    $turn($job,2,user:999,omitThread:true); $calls=$requests(); $answer=end($calls)['params'];
    igMediaCheck(($answer['text']??'')===$bot->ui->plain('instagram_page_owner')&&$store->claim('media')===null,'another user receives an owner-only notice even when topic metadata is absent');
    $store->set('groups_enabled','0'); $turn($job,2); $store->set('groups_enabled','1');
    igMediaCheck($store->claim('media')===null,'disabled group access prevents page callbacks');
    $store->set('admins_only','1'); $turn($job,2); $store->set('admins_only','0');
    igMediaCheck($store->claim('media')===null,'administrator-only access prevents ordinary-user page callbacks');
    $store->set('processing_limit.group','1');
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,created,message_id,status) VALUES(?,?,?,?,?,1,'running')",[80000,$job['user_id'],$job['chat_id'],$job['url'],time()]);
    $turn($job,2); igMediaCheck($store->claim('media')===null,'next-page requests respect the configured per-user processing limit');
    $store->query('DELETE FROM jobs WHERE update_id=80000'); $store->set('processing_limit.group','0');
    $toggleSource(); // Explicitly enable the optional source row for toggle coverage.
    file_put_contents($log,''); $turn($job,2,omitThread:true); $turn($job,2,omitThread:true); $calls=$requests();
    igMediaCheck(!array_filter($calls,fn($call)=>in_array($call['method'],['sendPhoto','sendVideo','editMessageMedia'],true))&&$store->claim('inspect')===null,'callbacks enqueue media without blocking the poller or the inspection lane');
    $next=$store->claim('media'); igMediaCheck($next&&$next['phase']==='instagram_page'&&(int)$next['thread_id']===321&&$store->claim('media')===null,'the non-admin sender can use the caption buttons without topic metadata while preserving the stored topic');
    $worker->recover($job);
    igMediaCheck($store->query('SELECT status FROM jobs WHERE id=?',[$next['id']])->fetchColumn()==='running'&&is_dir($cache),'reaping the previous page worker cannot fail or erase a newly claimed page');
    $worker->process($next); $job=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); $calls=$requests();
    $edits=array_values(array_filter($calls,fn($call)=>$call['method']==='editMessageMedia')); $state=json_decode($job['options'],true)['pages'];
    $shown=json_decode(file_get_contents($temp.'/telegram-media.json'),true)['messages'][$job['chat_id'].'.'.$firstMessage];
    igMediaCheck(count($edits)===1&&json_decode($edits[0]['params']['media'],true)['type']==='video'&&$edits[0]['uploads']['slide']['mime']==='video/mp4'&&$shown['thread']===321&&$state['message']===$firstMessage&&count(file($trace))===1,'the next slide replaces the first photo with a real multipart video in the same message and topic');
    igMediaCheck(!array_filter($calls,fn($call)=>in_array($call['method'],['sendPhoto','sendVideo','sendMessage','deleteMessage'],true)),'navigation leaves only one media message and sends no extra status or replacement messages');
    $buttons=json_decode($edits[0]['params']['reply_markup'],true)['inline_keyboard'][0];
    igMediaCheck(count($buttons)===2&&$buttons[0]['callback_data']==='igpage:'.$job['id'].':1:2'&&$buttons[1]['callback_data']==='igpage:'.$job['id'].':3:2'&&!isset($buttons[0]['style'])&&!isset($buttons[1]['style'])&&str_contains($shown['caption'],'متن اصلی پست'),'the middle slide keeps its caption and uses two wide neutral navigation buttons without a counter');
    $toggleSource();
    file_put_contents($log,''); $turn($job,3); $worker->process($store->claim('media')); $calls=$requests(); $job=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    $edits=array_values(array_filter($calls,fn($call)=>$call['method']==='editMessageMedia')); $lastButtons=json_decode($edits[0]['params']['reply_markup'],true)['inline_keyboard'][0];
    igMediaCheck(count($edits)===1&&count($lastButtons)===1&&$lastButtons[0]['callback_data']==='igpage:'.$job['id'].':2:3'&&is_dir($cache),'the last slide has one full-row Previous button and retains the files for returning');
    igMediaCheck(count(json_decode($edits[0]['params']['reply_markup'],true)['inline_keyboard'])===1,'disabling the source button in Personalization takes effect on the next slide edit');
    file_put_contents($log,''); $turn($job,2); $worker->process($store->claim('media')); $calls=$requests(); $job=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    $edits=array_values(array_filter($calls,fn($call)=>$call['method']==='editMessageMedia'));
    igMediaCheck(count($edits)===1&&$edits[0]['uploads']===[]&&str_starts_with(json_decode($edits[0]['params']['media'],true)['media'],'fixture_video_'),'Previous restores the video through its confirmed Telegram file ID without uploading it again');
    $turn($job,1,revision:2); igMediaCheck($store->claim('media')===null,'a delayed button from an earlier visit cannot navigate again after returning to the same slide');
    $toggleSource();
    file_put_contents($log,''); $turn($job,1); $worker->process($store->claim('media')); $calls=$requests(); $job=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    $edits=array_values(array_filter($calls,fn($call)=>$call['method']==='editMessageMedia')); $shown=json_decode(file_get_contents($temp.'/telegram-media.json'),true)['messages'][$job['chat_id'].'.'.$firstMessage];
    igMediaCheck(count($edits)===1&&$edits[0]['uploads']===[]&&$shown['type']==='photo'&&json_decode($job['options'],true)['pages']['message']===$firstMessage,'returning to the first photo replaces the video and keeps the original message ID');
    igMediaCheck(count(json_decode($edits[0]['params']['reply_markup'],true)['inline_keyboard'])===2,'enabling the source button restores its separate row on the next slide edit');
    $store->query('INSERT OR REPLACE INTO states VALUES(?,?,?)',[42,'pending-private-input',time()+600]);
    $pageBot->handle(['callback_query'=>['id'=>'counter','from'=>['id'=>42],'message'=>['message_id'=>999,'chat'=>['id'=>42,'type'=>'private']],'data'=>'igpage:noop']]);
    igMediaCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='pending-private-input'&&$store->claim('media')===null,'an old removed-counter callback remains harmless to private input and the media queue');
    $job=$process(['photo','photo']); $legacy=json_decode($job['options'],true)['pages']; unset($legacy['current'],$legacy['target'],$legacy['revision']); $legacy['next']=2;
    $job['options']=json_encode(['pages'=>$legacy]); $store->query('UPDATE jobs SET options=? WHERE id=?',[$job['options'],$job['id']]);
    $turn($job,2,legacy:true); $worker->process($store->claim('media'));
    igMediaCheck(json_decode($store->query('SELECT options FROM jobs WHERE id=?',[$job['id']])->fetchColumn(),true)['pages']['current']===2,'a still-cached carousel from the previous release can use its old Next button once');
    $job=$process(['photo','photo']); $state=json_decode($job['options'],true)['pages']; $cache=$storage.'/instagram-pages/'.$state['folder'];
    $state['expires']=time()-1; $job['options']=json_encode(['pages'=>$state]); $store->query('UPDATE jobs SET options=? WHERE id=?',[$job['options'],$job['id']]);
    $turn($job,2); igMediaCheck($store->claim('media')===null,'expired buttons never download or send another page');
    touch($cache,time()-Zero\InstagramPages::TTL-1); (new Zero\InstagramPages($config,$store,$api))->prune();
    igMediaCheck(!is_dir($cache),'abandoned page files expire and are cleaned after one day');
    $job=$process(['photo','video']); touch($failTelegram); $turn($job,2); $worker->process($store->claim('media')); unlink($failTelegram);
    $job=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); $turn($job,2);
    igMediaCheck($job['status']==='failed'&&$store->claim('media')===null,'an uncertain page upload is terminal and never resent by a repeated callback');
    $job=$process(['photo','photo']); $turn($job,2); $next=$store->claim('media'); $store->set('admins_only','1'); file_put_contents($log,''); $worker->process($next); $store->set('admins_only','0');
    igMediaCheck(!array_filter($requests(),fn($call)=>in_array($call['method'],['sendPhoto','sendVideo','editMessageMedia'],true)),'the worker rechecks access after a page was queued');
    $job=$process(['photo','photo']); $state=json_decode($job['options'],true)['pages']; $cache=$storage.'/instagram-pages/'.$state['folder'];
    $turn($job,2); $next=$store->claim('media'); $worker->recover($next); $turn($job,2);
    igMediaCheck(!is_dir($cache)&&$store->claim('media')===null&&$store->query('SELECT status FROM jobs WHERE id=?',[$next['id']])->fetchColumn()==='failed','recovering an interrupted page releases its files and never replays an uncertain upload');
    $toggleSource();
    foreach([['photo'],['video']] as $kind) {
        $job=$process($kind); $sent=array_values(array_filter($requests(),fn($call)=>in_array($call['method'],['sendPhoto','sendVideo'],true)));
        igMediaCheck($job['status']==='done'&&json_decode($sent[0]['params']['reply_markup'],true)['inline_keyboard']===[],'the disabled source button is absent from a single Instagram '.$kind[0]);
    }
    $toggleSource();
    $job=$process(['photo','photo']); $state=json_decode($job['options'],true)['pages']; $message=$state['message'];
    $turn($job,2,omitThread:true); $worker->process($store->claim('media'));
    $saved=json_decode($store->query('SELECT options FROM jobs WHERE id=?',[$job['id']])->fetchColumn(),true)['pages'];
    igMediaCheck(!$bot->admin((int)$job['user_id'])&&$saved['current']===2&&$saved['message']===$message,'a regular user in private chat can navigate the caption on a bot-authored message');
    $pageBot->handle(['callback_query'=>['id'=>'custom-mode','from'=>['id'=>42],'message'=>['message_id'=>777,'chat'=>['id'=>42,'type'=>'private']],'data'=>'custom:igmode:article']]);
    igMediaCheck($store->get('instagram.carousel_mode')==='article','Personalization can select the retained article output for future requests');
    $calls=$requests(); $rows=json_decode(end($calls)['params']['reply_markup'],true)['inline_keyboard'];
    $headings=array_filter(array_merge(...$rows),fn($button)=>($button['callback_data']??'')==='custom:hint:sec_settings'&&!isset($button['style']));
    $modes=array_filter(array_merge(...$rows),fn($button)=>str_starts_with($button['callback_data']??'','custom:igmode:'));
    $chosen=array_filter($modes,fn($button)=>($button['style']??'')==='primary'&&str_starts_with($button['text'],'✅'));
    igMediaCheck(count($headings)===1&&count($modes)===2&&count($chosen)===1&&array_values($chosen)[0]['callback_data']==='custom:igmode:article','the output feature has a white settings title and ticks the chosen mode');
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
