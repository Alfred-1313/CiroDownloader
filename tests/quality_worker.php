<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function qualityCheck(bool $ok,string $text): void { if(!$ok) throw new RuntimeException($text); echo "PASS $text\n"; }
function recorded(string $path): array { return array_values(array_filter(array_map(fn($line)=>json_decode($line,true),file($path,FILE_IGNORE_NEW_LINES)?:[]),'is_array')); }
$temp=sys_get_temp_dir().'/ciro-quality-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
$server=null; $worker=null;
try {
    $storage=$temp.'/data'; mkdir($storage,0700);
    $runner=new Zero\Download(new Zero\Config('123:fake-token',[42],$storage));
    $source=$temp.'/fixture.mp4';
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=640x360:r=1:d=1','-f','lavfi','-i','anullsrc=r=8000:cl=mono','-shortest','-c:v','libx264','-threads','2','-c:a','aac',$source],$temp,30);
    $log=$temp.'/api.jsonl'; $trace=$temp.'/downloader.jsonl'; file_put_contents($log,''); file_put_contents($trace,'');
    $info=['title'=>'<title> & audio','formats'=>[
        ['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac'],
        ['format_id'=>'22','width'=>1280,'height'=>720,'vcodec'=>'avc1','acodec'=>'aac'],
    ]];
    $stub=$temp.'/yt-test';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        'file_put_contents('.var_export($trace,true).',json_encode($argv).PHP_EOL,FILE_APPEND);'.
        'if(in_array("--dump-single-json",$argv,true)) {echo '.var_export(json_encode($info),true).';exit;}'.
        '$template=$argv[array_search("-o",$argv,true)+1];copy('.var_export($source,true).',str_replace("%(ext)s","mp4",$template));'.
        'echo json_encode("<title> & audio").PHP_EOL;');
    chmod($stub,0700);
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php '.
        '$method=basename(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH));'.
        '$file=$_FILES["video"]??null;'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"video_hash"=>$file?hash_file("sha256",$file["tmp_name"]):null],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>["message_id"=>(int)($_POST["message_id"]??501)]]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr); if(!$socket) throw new RuntimeException($errstr);
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    for($i=0;$i<40;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05); if($connection) {fclose($connection);break;} usleep(50000); }
    $config=new Zero\Config('123:fake-token',[42],$storage,'http://'.$address,49000000,$stub);
    $store=new Zero\Store($storage); $bot=new Zero\Bot($config,$store,new Zero\Telegram($config));
    $store->set('text.video_caption','<b>{title}</b> — {quality} <tg-emoji emoji-id="5368324170671202286">😎</tg-emoji>');
    $bot->handle(['update_id'=>1,'message'=>['from'=>['id'=>7],'chat'=>['id'=>7,'type'=>'private'],'text'=>'https://youtu.be/ybNMMK5Q318']]);
    $environment=array_merge(getenv(),['BOT_TOKEN'=>$config->token,'ADMIN_IDS'=>'42','STORAGE_DIR'=>$storage,'BOT_API_URL'=>$config->api,'MAX_FILE_BYTES'=>'49000000','YT_DLP_BIN'=>$stub,'YOUTUBE_COOKIES_FILE'=>'','YOUTUBE_PROXY'=>'']);
    $worker=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/worker.log','w'],2=>['file',$temp.'/worker-error.log','w']],$pipes,dirname(__DIR__),$environment);
    $deadline=microtime(true)+15; $ready=false;
    do {
        $job=$store->query('SELECT * FROM jobs WHERE update_id=1')->fetch();
        $records=recorded($log);
        if($job['status']==='choosing'&&count($records)>=3) { $ready=true; break; }
        usleep(100000);
    } while(microtime(true)<$deadline&&proc_get_status($worker)['running']);
    qualityCheck($ready,'worker inspects the link and waits for quality selection');
    $invocations=recorded($trace);
    qualityCheck(count($invocations)===1&&in_array('--dump-single-json',$invocations[0],true),'no video is downloaded before the user chooses a quality');
    $keyboard=json_decode($records[2]['params']['reply_markup'],true)['inline_keyboard'];
    qualityCheck($keyboard[0][0]['text']==='360p'&&$keyboard[0][1]['text']==='720p','only available quality buttons are shown under the status message');
    $bot->handle(['callback_query'=>['id'=>'local-test','from'=>['id'=>7],'message'=>['message_id'=>501,'chat'=>['id'=>7,'type'=>'private']],'data'=>'quality:'.$job['id'].':360']]);
    $deadline=microtime(true)+20; $done=false;
    do {
        $records=recorded($log);
        $status=$store->query('SELECT status FROM jobs WHERE update_id=1')->fetchColumn();
        $last=end($records);
        if($status==='done'&&$last['method']==='editMessageText'&&str_contains($last['params']['text'],'موفقیت')) { $done=true;break; }
        usleep(100000);
    } while(microtime(true)<$deadline&&proc_get_status($worker)['running']);
    qualityCheck($done,'selected quality completes real probing, MP4 preparation and upload');
    $invocations=recorded($trace); $arguments=$invocations[1]??[];
    qualityCheck(count($invocations)===2&&$arguments[array_search('-f',$arguments,true)+1]==='18','download uses the exact format behind the selected quality');
    $uploads=array_values(array_filter($records,fn($record)=>$record['method']==='sendVideo'));
    qualityCheck(count($uploads)===1&&!empty($uploads[0]['video_hash']),'the actual video bytes are uploaded once');
    $caption=$uploads[0]['params']['caption'];
    qualityCheck($uploads[0]['params']['parse_mode']==='HTML'&&str_contains($caption,'&lt;title&gt; &amp; audio')&&str_contains($caption,'tg-emoji'),'custom captions retain premium emoji and safely escape video metadata');
    qualityCheck(count(array_filter($records,fn($record)=>$record['method']==='sendMessage'))===1,'inspection, choices, download and completion keep one status message');
    foreach($records as $record) if($record['method']==='editMessageText') qualityCheck((int)$record['params']['message_id']===501,'status edits keep the original message ID');
} finally {
    foreach([$worker,$server] as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    Zero\Download::clean($temp);
}
