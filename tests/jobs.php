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
    file_put_contents($stub,"#!/usr/bin/env php\n<?php fwrite(STDERR, 'The provided YouTube account cookies are no longer valid. Sign in to confirm you are not a bot.');exit(1);");
    chmod($stub,0700);
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,created,message_id,phase,expires) VALUES(4,88,88,?,'choosing',?,700,'inspect',?)",[$url,time()-31*86400,time()-30*86400]);
    $bot->handle(['update_id'=>3,'message'=>['from'=>['id'=>99],'chat'=>['id'=>99,'type'=>'private'],'text'=>$url]]);
    $environment=array_merge(getenv(),['BOT_TOKEN'=>$config->token,'ADMIN_IDS'=>'42','STORAGE_DIR'=>$storage,
        'BOT_API_URL'=>$config->api,'YT_DLP_BIN'=>$stub,'YOUTUBE_COOKIES_FILE'=>'','YOUTUBE_PROXY'=>'']);
    $worker=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],
        [0=>['file','/dev/null','r'],1=>['file',$temp.'/worker.log','w'],2=>['file',$temp.'/worker-error.log','w']],$pipes,dirname(__DIR__),$environment);
    if(!is_resource($worker)) throw new RuntimeException('Cannot start worker test');
    $deadline=microtime(true)+12;
    do {
        $recorded=calls($log);
        if(count($recorded)>=3) break;
        usleep(100000);
    } while(microtime(true)<$deadline&&proc_get_status($worker)['running']);
    expectJob(array_column($recorded,'method')===['sendMessage','editMessageText','editMessageText'],
        'real worker cookie failure edits the queued message instead of sending three messages');
    expectJob(str_contains($recorded[2]['params']['text'],'کوکی')&&(int)$recorded[2]['params']['message_id']===501,
        'Persian cookie failure is shown in the original status message');
    expectJob($store->query('SELECT status FROM jobs WHERE update_id=3')->fetchColumn()==='failed','worker records the failed job');
    expectJob($store->query('SELECT status FROM jobs WHERE update_id=4')->fetchColumn()==='waiting_quality','the real worker leaves an old unchosen menu unchanged without sending an expiry notice');
} finally {
    foreach([$worker,$server] as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    Zero\Download::clean($temp);
}
