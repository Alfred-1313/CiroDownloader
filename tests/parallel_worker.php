<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function parallelCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function waitParallel(callable $condition,string $message,int $seconds=45): void {
    $deadline=microtime(true)+$seconds;
    do { if($condition()) { parallelCheck(true,$message); return; } usleep(50000); } while(microtime(true)<$deadline);
    throw new RuntimeException($message);
}
$temp=sys_get_temp_dir().'/ciro-parallel-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
$worker=null; $server=null; $serverPid=null;
$anonymous=in_array('--anonymous',$argv,true);
$group=in_array('--group',$argv,true); $chat=$group?-100123:7; $thread=$group?321:0;
try {
    $storage=$temp.'/data'; mkdir($storage,0700);
    $source=$temp.'/fixture.mp4';
    $runner=new Zero\Download(new Zero\Config('123:fixture',[42],$storage));
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=1920x1080:r=1:d=1','-f','lavfi','-i','anullsrc=r=8000:cl=mono','-shortest','-c:v','libx264','-threads','2','-pix_fmt','yuv420p','-c:a','aac',$source],$temp,30);
    $stub=$temp.'/downloader';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        '$url=$argv[count($argv)-1]; parse_str(parse_url($url,PHP_URL_QUERY),$q); $title=$q["v"];'.
        'if(in_array("--dump-single-json",$argv,true)) { echo json_encode(["title"=>$title,"formats"=>[["format_id"=>"137","width"=>1920,"height"=>1080,"vcodec"=>"avc1","acodec"=>"aac"]]]); exit; }'.
        '$template=$argv[array_search("-o",$argv,true)+1];'.
        '$pid=posix_getppid(); for($depth=0;$depth<8;$depth++) { if(str_contains(file_get_contents("/proc/".$pid."/cmdline"),"/bin/worker.php")) break; preg_match("/^PPid:\\s+(\\d+)/m",file_get_contents("/proc/".$pid."/status"),$parent); $pid=(int)$parent[1]; } file_put_contents(dirname($template)."/worker.pid",$pid);'.
        'copy('.var_export($source,true).',str_replace("%(ext)s","mp4",$template)); echo json_encode($title);');
    chmod($stub,0700);
    $log=$temp.'/api.jsonl'; file_put_contents($log,'');
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php '.
        '$root='.var_export($temp,true).'; $method=basename(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH));'.
        'file_put_contents($root."/api.jsonl",json_encode(["method"=>$method,"params"=>$_POST]).PHP_EOL,FILE_APPEND|LOCK_EX);'.
        'if($method==="sendVideo") { $title=$_POST["caption"]; if(!preg_match("/^[A-Za-z0-9_-]{11}$/D",$title)) exit;'.
        'if(!isset($_FILES["video"])||$_FILES["video"]["error"]!==UPLOAD_ERR_OK) exit;'.
        'file_put_contents($root."/upload-".$title,"started"); $end=microtime(true)+35;'.
        'while(!file_exists($root."/release")&&microtime(true)<$end) usleep(20000); }'.
        '$id=(int)($_POST["message_id"]??0); if(!$id) { $counter=fopen($root."/counter","c+"); flock($counter,LOCK_EX); $id=max(500,(int)stream_get_contents($counter))+1; rewind($counter); ftruncate($counter,0); fwrite($counter,(string)$id); fclose($counter); }'.
        'header("Content-Type: application/json"); echo json_encode(["ok"=>true,"result"=>["message_id"=>$id]]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); if(!$socket) throw new RuntimeException($error);
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open(['setsid',PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes,null,array_merge(getenv(),['PHP_CLI_SERVER_WORKERS'=>'6']));
    if(!is_resource($server)) throw new RuntimeException('Cannot start concurrent fixture API');
    $serverPid=proc_get_status($server)['pid'];
    waitParallel(function() use($address) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.05); if(!$connection) return false; fclose($connection); return true; },'concurrent local API starts');
    $config=new Zero\Config('123:fixture',[42],$storage,'http://'.$address,49000000,$stub);
    if(!$anonymous) Zero\saveYouTubeCookies($config,"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_COOKIE\n");
    $store=new Zero\Store($storage); $bot=new Zero\Bot($config,$store,new Zero\Telegram($config));
    $store->set('text.video_caption','{title}');
    $ids=['firstVideo1','secondVideo','thirdVideo3','fourthVideo','fifthVideo5','sixthVideo6'];
    $link=function(int $i) use($bot,$ids,$chat,$thread): void {
        $bot->handle(['update_id'=>$i+1,'message'=>['from'=>['id'=>7],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread,'text'=>'https://youtu.be/'.$ids[$i]]]);
    };
    $choose=function(int $i) use($bot,$store,$chat,$thread): void {
        $job=$store->query('SELECT * FROM jobs WHERE update_id=?',[$i+1])->fetch();
        $bot->handle(['callback_query'=>['id'=>'choose-'.$i,'from'=>['id'=>7],'message'=>['message_id'=>(int)$job['message_id'],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread],'data'=>'quality:'.$job['id'].':1080']]);
    };
    $link(0);
    $environment=array_merge(getenv(),['BOT_TOKEN'=>$config->token,'ADMIN_IDS'=>'42','STORAGE_DIR'=>$storage,'BOT_API_URL'=>$config->api,'YT_DLP_BIN'=>$stub,'MAX_FILE_BYTES'=>'49000000','WORKER_CONCURRENCY'=>'3','YOUTUBE_PROXY'=>'','YOUTUBE_COOKIES_FILE'=>'']);
    $invalid=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/invalid.log','w'],2=>['file',$temp.'/invalid-error.log','w']],$pipes,dirname(__DIR__),array_replace($environment,['WORKER_CONCURRENCY'=>'9']));
    parallelCheck(is_resource($invalid)&&proc_close($invalid)!==0&&$store->query("SELECT status FROM jobs WHERE update_id=1")->fetchColumn()==='queued','an invalid process capacity is rejected before any queued job is claimed');
    $worker=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/worker.log','w'],2=>['file',$temp.'/worker-error.log','w']],$pipes,dirname(__DIR__),$environment);
    if(!is_resource($worker)) throw new RuntimeException('Cannot start parallel worker');
    waitParallel(fn()=>$store->query("SELECT status FROM jobs WHERE update_id=1")->fetchColumn()==='waiting_quality','first 1080p menu is ready');
    $choose(0);
    waitParallel(fn()=>is_file($temp.'/upload-'.$ids[0]),'first video reaches an intentionally held upload');
    $duplicate=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/duplicate.log','w'],2=>['file',$temp.'/duplicate-error.log','w']],$pipes,dirname(__DIR__),$environment);
    parallelCheck(is_resource($duplicate)&&proc_close($duplicate)!==0&&$store->query("SELECT status FROM jobs WHERE update_id=1")->fetchColumn()==='running','a duplicate supervisor is rejected without disturbing a live upload');
    foreach([1,2,3] as $i) $link($i);
    waitParallel(fn()=>(int)$store->query("SELECT COUNT(*) FROM jobs WHERE update_id IN (2,3,4) AND status='waiting_quality'")->fetchColumn()===3,'three further links get independent quality menus while the first upload is still blocked');
    foreach([1,2,3] as $i) $choose($i);
    waitParallel(fn()=>is_file($temp.'/upload-'.$ids[1])&&is_file($temp.'/upload-'.$ids[2]),'three 1080p videos for the same user actually upload concurrently');
    parallelCheck(!is_file($temp.'/upload-'.$ids[3])&&$store->query("SELECT status FROM jobs WHERE update_id=4")->fetchColumn()==='queued','a fourth selected video is queued safely at the configured process capacity');
    $link(4);
    waitParallel(fn()=>$store->query("SELECT status FROM jobs WHERE update_id=5")->fetchColumn()==='waiting_quality','the reserved inspection slot still creates a fifth menu with all media slots occupied');
    parallelCheck(!is_file($temp.'/upload-'.$ids[4]),'unchosen media is never downloaded or uploaded');
    file_put_contents($temp.'/release','yes');
    waitParallel(fn()=>(int)$store->query("SELECT COUNT(*) FROM jobs WHERE update_id<=4 AND status='done'")->fetchColumn()===4,'all four selected videos complete without another user action');
    waitParallel(function() use($log) { $records=array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES)); return count(array_filter($records,fn($record)=>$record['method']==='deleteMessage'))===4; },'each delivered video has only its own cover removed');
    $records=array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    $uploads=array_values(array_filter($records,fn($record)=>$record['method']==='sendVideo'));
    parallelCheck(count($uploads)===4&&count(array_unique(array_column(array_column($uploads,'params'),'caption')))===4,'each selected file is uploaded once, with no duplicates');
    foreach($uploads as $upload) parallelCheck((int)$upload['params']['chat_id']===$chat&&(!$group||(int)$upload['params']['message_thread_id']===$thread),'parallel delivery preserves the original chat and forum topic');
    parallelCheck((int)$store->query('SELECT COUNT(DISTINCT message_id) FROM jobs')->fetchColumn()===5,'each link keeps a separate status message');
    // Recover an uncertain upload without retrying it; waiting menus survive.
    proc_terminate($worker); proc_close($worker); $worker=null;
    $store->query("UPDATE jobs SET status='running' WHERE update_id=4");
    $environment['WORKER_CONCURRENCY']='1';
    $worker=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/worker-restart.log','w'],2=>['file',$temp.'/worker-restart-error.log','w']],$pipes,dirname(__DIR__),$environment);
    waitParallel(fn()=>$store->query("SELECT status FROM jobs WHERE update_id=4")->fetchColumn()==='failed','a restarted supervisor marks an uncertain previous upload failed instead of resending it');
    parallelCheck($store->query("SELECT status FROM jobs WHERE update_id=5")->fetchColumn()==='waiting_quality','restart preserves the unchosen quality menu');
    unlink($temp.'/release');
    $choose(4);
    waitParallel(fn()=>is_file($temp.'/upload-'.$ids[4]),'a restarted supervisor runs media with a capacity of one');
    $link(5);
    waitParallel(fn()=>$store->query("SELECT status FROM jobs WHERE update_id=6")->fetchColumn()==='waiting_quality','inspection remains independent when media capacity is one');
    $choose(5);
    parallelCheck(!is_file($temp.'/upload-'.$ids[5])&&$store->query("SELECT status FROM jobs WHERE update_id=6")->fetchColumn()==='queued','a single configured media slot keeps a second delivery queued');
    $job=$store->query('SELECT * FROM jobs WHERE update_id=5')->fetch();
    $pidFile=glob($storage.'/downloads/job-'.$job['id'].'-*/worker.pid');
    parallelCheck(count($pidFile)===1,'the fixture identifies the actual media subprocess');
    posix_kill((int)file_get_contents($pidFile[0]),SIGKILL);
    waitParallel(fn()=>$store->query("SELECT status FROM jobs WHERE update_id=5")->fetchColumn()==='failed','a crashed media subprocess is recovered without resending its uncertain upload');
    waitParallel(fn()=>is_file($temp.'/upload-'.$ids[5]),'the slot is reclaimed and the queued sixth video starts after the crash');
    posix_kill(proc_get_status($worker)['pid'],SIGKILL);
    proc_close($worker); $worker=null;
    $duplicate=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/orphan-duplicate.log','w'],2=>['file',$temp.'/orphan-duplicate-error.log','w']],$pipes,dirname(__DIR__),$environment);
    parallelCheck(is_resource($duplicate)&&proc_close($duplicate)!==0&&$store->query("SELECT status FROM jobs WHERE update_id=6")->fetchColumn()==='running','a surviving upload retains the supervisor lock after its parent is killed');
    file_put_contents($temp.'/release','yes');
    waitParallel(fn()=>$store->query("SELECT status FROM jobs WHERE update_id=6")->fetchColumn()==='done','the surviving upload completes without being recovered or resent by another supervisor');
    waitParallel(function() use($storage) {
        $handle=fopen($storage.'/worker.lock','c'); $free=flock($handle,LOCK_EX|LOCK_NB);
        if($free) flock($handle,LOCK_UN); fclose($handle); return $free;
    },'the inherited supervisor lock is released when the last child exits');
    $worker=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/worker.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/final-worker.log','w'],2=>['file',$temp.'/final-worker-error.log','w']],$pipes,dirname(__DIR__),$environment);
    waitParallel(fn()=>str_contains((string)file_get_contents($temp.'/final-worker.log'),'worker started'),'a replacement supervisor starts safely after all old children finish');
    $records=array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    parallelCheck(count(array_filter($records,fn($record)=>$record['method']==='sendVideo'&&$record['params']['caption']===$ids[4]))===1,'the crashed job is not uploaded a second time');
} finally {
    file_put_contents($temp.'/release','yes');
    if(is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
    if($serverPid) posix_kill(-$serverPid,SIGTERM);
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
