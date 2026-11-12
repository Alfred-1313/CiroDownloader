<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function expectJob(bool $ok,string $message): void {
    if(!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function calls(string $path): array {
    return array_map(fn($line)=>json_decode($line,true),file($path,FILE_IGNORE_NEW_LINES)?:[]);
}
$temp=sys_get_temp_dir().'/ciro-jobs-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
$server=null; $worker=null;
try {
    $storage=$temp.'/data'; mkdir($storage,0700);
    // Upgrade an existing database rather than testing only fresh installations.
    $legacy=new PDO('sqlite:'.$storage.'/zero.sqlite');
    $legacy->exec("CREATE TABLE jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,update_id INTEGER UNIQUE NOT NULL,
        user_id INTEGER NOT NULL,chat_id INTEGER NOT NULL,url TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'queued',created INTEGER NOT NULL);
        INSERT INTO jobs(update_id,user_id,chat_id,url,created) VALUES(1,77,77,'https://youtu.be/ybNMMK5Q318',1)");
    $legacy=null;
    $store=new Zero\Store($storage);
    $job=$store->claim();
    expectJob($job!==null&&$job['message_id']===null,'old queued jobs survive the additive database migration');
    $store->query("UPDATE jobs SET status='done'");

    foreach([[100,'inspect',time()],[101,'download',time()],[102,'audio',time()]] as [$update,$phase,$created]) {
        $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,created,message_id,phase) VALUES(?,?,?, ?,?,501,?)",[$update,$update,$update,'https://youtu.be/ybNMMK5Q318',$created,$phase]);
    }
    expectJob((int)$store->claim()['update_id']===101&&(int)$store->claim()['update_id']===102,'chosen video and audio start before fresh link inspections and retain FIFO order');
    $store->query("UPDATE jobs SET status='done' WHERE update_id IN (101,102)");
    $store->query("UPDATE jobs SET created=? WHERE update_id=100",[time()-31]);
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,created,message_id,phase) VALUES(103,103,103,'https://youtu.be/ybNMMK5Q318',?,501,'download')",[time()]);
    expectJob((int)$store->claim()['update_id']===100,'an older inspection is promoted so continuous selected downloads cannot starve it');
    $store->query("UPDATE jobs SET status='done'");
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,created,message_id,phase) VALUES(104,104,104,'https://youtu.be/ybNMMK5Q318',?,501,'inspect')",[time()]);
    expectJob($store->claim('media')===null,'media slots cannot consume inspection work');
    expectJob((int)$store->claim('inspect')['update_id']===104&&$store->claim('inspect')===null,'the reserved inspection lane claims only one copy of an inspection');
    $store->query("UPDATE jobs SET status='done'");

    $log=$temp.'/requests.jsonl'; file_put_contents($log,'');
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php '.
        '$method=basename(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH));'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        '$result=["message_id"=>(int)($_POST["message_id"]??501)];'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>$result]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
    if(!$socket) throw new RuntimeException($errstr);
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],
        [0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    if(!is_resource($server)) throw new RuntimeException('Cannot start local Telegram test endpoint');
    for($i=0;$i<40;$i++) {
        $connection=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);
        if($connection) { fclose($connection); break; }
        usleep(50000);
    }
    $config=new Zero\Config('123:fake-test-token',[42],$storage,'http://'.$address);
    $bot=new Zero\Bot($config,$store,new Zero\Telegram($config));
    Zero\saveYouTubeCookies($config,"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_COOKIE\n");
    $url='https://youtu.be/ybNMMK5Q318';
    expectJob($store->enqueue(2,42,42,$url)==='queued'&&$store->claim()===null,
        'worker waits until the initial Telegram message ID is stored');
    $job=$store->query('SELECT * FROM jobs WHERE update_id=2')->fetch();
    $bot->jobMessage($job,$bot->text('queued'));
    $claimed=$store->claim();
    expectJob($claimed!==null&&(int)$claimed['message_id']===501,'publishing the queue message makes the job claimable');
    $bot->jobMessage($claimed,$bot->text('downloading'));
    $bot->jobMessage($claimed,$bot->text('uploading'));
    $bot->jobMessage($claimed,$bot->text('delivered'));
    $recorded=calls($log);
    expectJob(array_column($recorded,'method')===['sendMessage','editMessageText','editMessageText','editMessageText'],
        'queue, download, upload and completion share one status message');
    expectJob(count(array_filter(array_slice($recorded,1),fn($call)=>(int)$call['params']['message_id']===501))===3,
        'all later stages edit the original message ID');
    $store->query("UPDATE jobs SET status='done'");

    // Exercise the real worker failure path with a local downloader and API.
    file_put_contents($log,'');
    $stub=$temp.'/failed-download';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php fwrite(STDERR, 'ERROR: The provided YouTube account cookies are no longer valid. Sign in to confirm you are not a bot.');exit(1);");
    chmod($stub,0700);
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,created,message_id,phase,expires) VALUES(4,88,88,?,'choosing',?,700,'inspect',?)",[$url,time()-31*86400,time()-30*86400]);
    $bot->handle(['update_id'=>3,'message'=>['from'=>['id'=>99],'chat'=>['id'=>99,'type'=>'private'],'text'=>$url]]);
    $store->set('cookies.keepalive','0');
    $environment=array_merge(getenv(),['BOT_TOKEN'=>$config->token,'ADMIN_IDS'=>'42','STORAGE_DIR'=>$storage,
        'BOT_API_URL'=>$config->api,'YT_DLP_BIN'=>$stub,'YOUTUBE_COOKIES_FILE'=>'','YOUTUBE_PROXY'=>'']);
    $worker=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],
        [0=>['file','/dev/null','r'],1=>['file',$temp.'/worker.log','w'],2=>['file',$temp.'/worker-error.log','w']],$pipes,dirname(__DIR__),$environment);
    if(!is_resource($worker)) throw new RuntimeException('Cannot start worker test');
    $deadline=microtime(true)+12;
    do {
        $recorded=calls($log);
        if($store->query('SELECT status FROM jobs WHERE update_id=3')->fetchColumn()==='failed'&&count($recorded)>=4) break;
        usleep(100000);
    } while(microtime(true)<$deadline&&proc_get_status($worker)['running']);
    $alerts=array_values(array_filter($recorded,fn($entry)=>$entry['method']==='sendMessage'&&(int)$entry['params']['chat_id']===42));
    expectJob(count($alerts)===1&&str_contains($alerts[0]['params']['text'],'cookies'),'the failed worker sends the clean error privately to the administrator');
    $recorded=array_values(array_filter($recorded,fn($entry)=>(int)($entry['params']['chat_id']??99)!==42));
    expectJob(array_column($recorded,'method')===['sendMessage','editMessageText','editMessageText'],
        'real worker cookie failure edits the queued message instead of sending three messages');
    expectJob(str_contains($recorded[2]['params']['text'],'کوکی')&&(int)$recorded[2]['params']['message_id']===501,
        'Persian cookie failure is shown in the original status message');
    expectJob($store->query('SELECT status FROM jobs WHERE update_id=3')->fetchColumn()==='failed','worker records the failed job');
    expectJob($store->query('SELECT status FROM jobs WHERE update_id=4')->fetchColumn()==='waiting_quality','the real worker leaves an old unchosen menu unchanged without sending an expiry notice');
    $pidFile=$temp.'/slow-downloader.pid';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php file_put_contents(".var_export($pidFile,true).",getmypid().PHP_EOL,FILE_APPEND); sleep(30); exit(1);");
    $bot->handle(['update_id'=>5,'message'=>['from'=>['id'=>97],'chat'=>['id'=>97,'type'=>'private'],'text'=>$url]]);
    $deadline=microtime(true)+8;
    while(!is_file($pidFile)&&microtime(true)<$deadline) usleep(50000);
    expectJob(is_file($pidFile),'a real downloader subprocess is running before shutdown');
    $downloaderPid=(int)trim((string)file_get_contents($pidFile));
    proc_terminate($worker); proc_close($worker); $worker=null;
    expectJob(!posix_kill($downloaderPid,0),'stopping the supervisor also terminates the active downloader process group');
    $worker=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],
        [0=>['file','/dev/null','r'],1=>['file',$temp.'/restart.log','w'],2=>['file',$temp.'/restart-error.log','w']],$pipes,dirname(__DIR__),$environment);
    $deadline=microtime(true)+8;
    while($store->query('SELECT status FROM jobs WHERE update_id=5')->fetchColumn()!=='failed'&&microtime(true)<$deadline) usleep(50000);
    expectJob($store->query('SELECT status FROM jobs WHERE update_id=5')->fetchColumn()==='failed'&&count(file($pidFile))===1,'restart recovers the interrupted inspection without relaunching its downloader');
} finally {
    foreach([$worker,$server] as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    Zero\Download::clean($temp);
}
