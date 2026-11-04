<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function qualityCheck(bool $ok,string $text): void { if(!$ok) throw new RuntimeException($text); echo "PASS $text\n"; }
function recorded(string $path): array { return array_values(array_filter(array_map(fn($line)=>json_decode($line,true),file($path,FILE_IGNORE_NEW_LINES)?:[]),'is_array')); }
$temp=sys_get_temp_dir().'/ciro-quality-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
$server=null; $worker=null;
$audio=in_array('--audio',$argv,true);
$shorts=in_array('--shorts',$argv,true);
$legacy=in_array('--legacy-caption',$argv,true);
$group=in_array('--group',$argv,true); $disabled=$group&&in_array('--disabled',$argv,true);
$chat=$group?-1001234567890:7; $thread=$group?321:0;
try {
    $storage=$temp.'/data'; mkdir($storage,0700);
    $runner=new Zero\Download(new Zero\Config('123:fake-token',[42],$storage));
    $source=$temp.'/fixture.mp4';
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s='.($shorts?'360x640':'640x360').':r=1:d=1','-f','lavfi','-i','anullsrc=r=8000:cl=mono','-shortest','-c:v','libx264','-threads','2','-c:a','aac',$source],$temp,30);
    $sound=$temp.'/fixture.m4a';
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','sine=frequency=440:sample_rate=44100:duration=2.5','-c:a','aac',$sound],$temp,30);
    $log=$temp.'/api.jsonl'; $trace=$temp.'/downloader.jsonl'; file_put_contents($log,''); file_put_contents($trace,'');
    $info=['title'=>'<title> & audio','thumbnail'=>'https://i.ytimg.com/vi/ybNMMK5Q318/'.($shorts?'oar2':'maxresdefault').'.jpg','formats'=>[
        ['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac','abr'=>96],
        ['format_id'=>'22','width'=>1280,'height'=>720,'vcodec'=>'avc1','acodec'=>'aac','abr'=>128],
        ['format_id'=>'160','width'=>256,'height'=>144,'vcodec'=>'avc1','acodec'=>'none'],
        ['format_id'=>'137','width'=>1920,'height'=>1080,'vcodec'=>'avc1','acodec'=>'none'],
        ['format_id'=>'140','vcodec'=>'none','acodec'=>'aac','abr'=>128],
    ]];
    $stub=$temp.'/yt-test';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        'file_put_contents('.var_export($trace,true).',json_encode($argv).PHP_EOL,FILE_APPEND);'.
        'if(in_array("--dump-single-json",$argv,true)) {echo '.var_export(json_encode($info),true).';exit;}'.
        '$template=$argv[array_search("-o",$argv,true)+1];$audio=$argv[array_search("-f",$argv,true)+1]==="140/22/18";'.
        'copy($audio?'.var_export($sound,true).':'.var_export($source,true).',str_replace("%(ext)s",$audio?"m4a":"mp4",$template));'.
        'echo json_encode("<title> & audio").PHP_EOL;');
    chmod($stub,0700);
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php '.
        '$method=basename(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH));'.
        '$file=$_FILES["video"]??$_FILES["audio"]??null;'.
        'if(isset($_FILES["audio"])) copy($file["tmp_name"],'.var_export($temp.'/received.mp3',true).');'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"video_hash"=>$file?hash_file("sha256",$file["tmp_name"]):null,"mime"=>$file["type"]??null],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        '$kindFile='.var_export($temp.'/message-kind',true).';$kind=is_file($kindFile)?file_get_contents($kindFile):"text";'.
        '$valid=!(($method==="editMessageText"&&$kind==="photo")||($method==="editMessageCaption"&&$kind!=="photo"));'.
        'if($method==="editMessageMedia") file_put_contents($kindFile,"photo");'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>$valid,"error_code"=>400,"result"=>["message_id"=>(int)($_POST["message_id"]??501)]]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr); if(!$socket) throw new RuntimeException($errstr);
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    for($i=0;$i<40;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05); if($connection) {fclose($connection);break;} usleep(50000); }
    $config=new Zero\Config('123:fake-token',[42],$storage,'http://'.$address,49000000,$stub);
    if($legacy) {
        $database=new PDO('sqlite:'.$storage.'/zero.sqlite');
        $database->exec('CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL)');
        foreach(['ui_html_version'=>'1','text.video_caption'=>"<b>🎬 ویدیوی شما آماده است</b>\n\n<blockquote>{title}</blockquote>\nکیفیت: <b>{quality}</b>\n{url}"] as $key=>$value) $database->prepare('INSERT INTO settings VALUES(?,?)')->execute([$key,$value]);
        $database=null;
    }
    $store=new Zero\Store($storage); $bot=new Zero\Bot($config,$store,new Zero\Telegram($config));
    if(!$legacy) {
        $store->set('text.video_caption','<b>{title}</b> — {quality} <blockquote>{duration}</blockquote> <tg-emoji emoji-id="5368324170671202286">😎</tg-emoji>');
        $store->set('text.audio_caption','<blockquote><b>{title}</b> {duration}</blockquote> {format} <tg-emoji emoji-id="5368324170671202286">😎</tg-emoji>');
    }
    $bot->handle(['update_id'=>1,'message'=>['from'=>['id'=>7],'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$thread,'text'=>$shorts?'https://www.youtube.com/shorts/ybNMMK5Q318':'https://youtu.be/ybNMMK5Q318']]);
    if($disabled) $store->set('groups_enabled','0');
    $environment=array_merge(getenv(),['BOT_TOKEN'=>$config->token,'ADMIN_IDS'=>'42','STORAGE_DIR'=>$storage,'BOT_API_URL'=>$config->api,'MAX_FILE_BYTES'=>'49000000','YT_DLP_BIN'=>$stub,'YOUTUBE_COOKIES_FILE'=>'','YOUTUBE_PROXY'=>'']);
    $worker=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/worker.log','w'],2=>['file',$temp.'/worker-error.log','w']],$pipes,dirname(__DIR__),$environment);
    if($disabled) {
        $deadline=microtime(true)+10;
        do {
            $job=$store->query('SELECT * FROM jobs WHERE update_id=1')->fetch();
            $records=recorded($log);
            if($job['status']==='failed'&&count($records)>=2) break;
            usleep(100000);
        } while(microtime(true)<$deadline&&proc_get_status($worker)['running']);
        $records=recorded($log);
        qualityCheck($job['status']==='failed'&&recorded($trace)===[],'the real worker rejects a queued group request after the feature is disabled, before contacting YouTube');
        qualityCheck(count($records)===2&&$records[1]['method']==='editMessageText'&&(int)$records[1]['params']['chat_id']===$chat&&str_contains($records[1]['params']['text'],'غیرفعال'),'disabled group work edits its original status without sending a file or a private message');
        return;
    }
    $deadline=microtime(true)+15; $ready=false;
    do {
        $job=$store->query('SELECT * FROM jobs WHERE update_id=1')->fetch();
        $records=recorded($log);
        if($job['status']==='waiting_quality'&&count($records)>=3) { $ready=true; break; }
        usleep(100000);
    } while(microtime(true)<$deadline&&proc_get_status($worker)['running']);
    qualityCheck($ready,'worker inspects the link and waits for quality selection');
    $poster=json_decode($records[2]['params']['media']??'{}',true);
    qualityCheck($records[2]['method']==='editMessageMedia'&&$poster['type']==='photo'&&$poster['media']===$info['thumbnail'],'the same status message becomes the actual video poster');
    qualityCheck(str_contains($poster['caption'],'&lt;title&gt; &amp; audio')&&$poster['parse_mode']==='HTML'&&!str_contains($poster['caption'],'<blockquote>'),'the poster caption escapes the title and stays simple before delivery');
    $invocations=recorded($trace);
    qualityCheck(count($invocations)===1&&in_array('--dump-single-json',$invocations[0],true),'no video is downloaded before the user chooses a quality');
    $keyboard=json_decode($records[2]['params']['reply_markup'],true)['inline_keyboard'];
    qualityCheck($keyboard[0][0]['text']==='360p'&&$keyboard[0][1]['text']==='720p','only available quality buttons are shown under the status message');
    qualityCheck(count($keyboard[1])===1&&$keyboard[1][0]['callback_data']==='quality:'.$job['id'].':audio','audio extraction has its own row below the video qualities');
    qualityCheck(count($keyboard)===2&&(int)$job['expires']===0,'quality selection has no deadline and no cancel button');
    $bot->handle(['callback_query'=>['id'=>'local-test','from'=>['id'=>7],'message'=>['message_id'=>501,'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$thread],'data'=>'quality:'.$job['id'].($audio?':audio':':360')]]);
    $afterChoice=recorded($log); $selected=end($afterChoice);
    qualityCheck($selected['method']==='editMessageCaption'&&json_decode($selected['params']['reply_markup'],true)['inline_keyboard']===[]&&!str_contains($selected['params']['caption'],'<blockquote>'),'choosing media uses a simple status caption and removes every button');
    $deadline=microtime(true)+20; $done=false;
    do {
        $records=recorded($log);
        $status=$store->query('SELECT status FROM jobs WHERE update_id=1')->fetchColumn();
        $last=end($records);
        if($status==='done'&&$last['method']==='editMessageCaption'&&$last['params']['caption']==='') { $done=true;break; }
        usleep(100000);
    } while(microtime(true)<$deadline&&proc_get_status($worker)['running']);
    qualityCheck($done,'selected quality completes real probing, MP4 preparation and upload');
    $invocations=recorded($trace); $arguments=$invocations[1]??[];
    qualityCheck(count($invocations)===2&&$arguments[array_search('-f',$arguments,true)+1]===($audio?'140/22/18':'18'),'download uses only saved formats for the selected media');
    $uploads=array_values(array_filter($records,fn($record)=>$record['method']===($audio?'sendAudio':'sendVideo')));
    qualityCheck(count($uploads)===1&&!empty($uploads[0]['video_hash']),'the actual media bytes are uploaded once');
    if($group) qualityCheck((int)$records[0]['params']['chat_id']===$chat&&(int)$records[0]['params']['message_thread_id']===$thread&&(int)$uploads[0]['params']['chat_id']===$chat&&(int)$uploads[0]['params']['message_thread_id']===$thread,'group progress and the real uploaded media stay in the original forum topic');
    qualityCheck(count(array_filter($records,fn($record)=>$record['method']===($audio?'sendVideo':'sendAudio')))===0,'the unselected media type is never sent');
    if($audio) {
        $probe=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$temp.'/received.mp3'],$temp,10),true);
        $streams=array_column($probe['streams'],'codec_name','codec_type');
        qualityCheck(($streams['audio']??'')==='mp3'&&!isset($streams['video'])&&$uploads[0]['mime']==='audio/mpeg','sendAudio receives a real MP3 without a video stream');
        qualityCheck(abs((float)$probe['format']['duration']-2.5)<0.2,'the entire audio track is delivered without trimming');
    }
    $caption=$uploads[0]['params']['caption'];
    qualityCheck($uploads[0]['params']['parse_mode']==='HTML'&&str_contains($caption,'&lt;title&gt; &amp; audio')&&str_contains($caption,'<b>')&&str_contains($caption,'<blockquote>'),'formatting and metadata appear on the delivered media caption');
    if(!$legacy) qualityCheck(str_contains($caption,'tg-emoji'),'custom captions retain premium emoji');
    else qualityCheck(str_contains($caption,'360p')&&!str_contains($caption,'https://')&&!str_contains($caption,'ویدیوی شما آماده است'),'an upgraded legacy caption shows quality and duration without the old heading or a duplicate raw URL');
    qualityCheck(str_contains($caption,Zero\Ui::duration((int)$uploads[0]['params']['duration'])),'the final caption contains the formatted duration');
    if($group) qualityCheck(!isset($uploads[0]['params']['reply_markup']),'delivered group video and audio have no buttons beneath their captions');
    else {
        $sourceButton=json_decode($uploads[0]['params']['reply_markup'],true)['inline_keyboard'][0][0];
        qualityCheck($sourceButton['style']==='primary'&&$sourceButton['url']==='https://www.youtube.com/watch?v=ybNMMK5Q318'&&!isset($sourceButton['callback_data']),'the delivered private file has a blue URL button linking to the original YouTube video');
    }
    qualityCheck(count(array_filter($records,fn($record)=>$record['method']==='sendMessage'))===1,'inspection, choices, download and completion keep one status message');
    qualityCheck(end($records)['params']['caption']===''&&json_decode(end($records)['params']['reply_markup'],true)['inline_keyboard']===[],'the old poster caption is cleared only after a successful file upload');
    foreach($records as $record) if(str_starts_with($record['method'],'editMessage')) qualityCheck((int)$record['params']['message_id']===501,'all text, media and caption edits keep the original message ID');
} finally {
    foreach([$worker,$server] as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    Zero\Download::clean($temp);
}
