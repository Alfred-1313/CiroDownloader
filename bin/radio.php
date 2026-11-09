<?php
declare(strict_types=1);
/**
 * The radio service: for every station whose live stream is on and that has songs waiting, one player process
 * (bin/radio-player.php), and one process at a time that prepares the next songs (bin/radio-prepare.php).
 * RADIO_STATIONS (default 3, at most 6) is how many streams may play at once.
 */
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Radio.php';
umask(0077);
$config=Zero\Config::load(); $store=new Zero\Store($config->storage);
$lock=Zero\lockProcess($config->storage.'/radio.lock');
$radio=new Zero\Radio($config,$store);
$limit=max(1,min(6,(int)(getenv('RADIO_STATIONS')?:3)));
$lookahead=3;
$php=PHP_BINARY; $players=[]; $prepare=null; $stopping=false; $lastTidy=0; $lastBeat=0;
pcntl_async_signals(true);
foreach([SIGTERM,SIGINT] as $signal) pcntl_signal($signal,static function() use (&$stopping): void { $stopping=true; });
$env=['PATH'=>getenv('PATH')?:'/usr/bin:/bin','HOME'=>getenv('HOME')?:'/tmp'];
foreach(['BOT_TOKEN','ADMIN_IDS','STORAGE_DIR','BOT_API_URL','MAX_FILE_BYTES','YT_DLP_BIN','YOUTUBE_PROXY','GALLERY_PROXY','YOUTUBE_POT_URL','YOUTUBE_COOKIES_FILE','RADIO_TEST_OUTPUT','RADIO_IDLE_SECONDS'] as $name) if(getenv($name)!==false) $env[$name]=(string)getenv($name);
echo "Ciro Downloader radio started\n";
// A crash may have left songs half prepared or playing.
$store->query("UPDATE radio_queue SET status='pending' WHERE status='preparing'");
$store->query("UPDATE radio_queue SET status='done' WHERE status='playing'");
$null=['file','/dev/null','r']; $log=['file','php://stderr','w'];
while(!$stopping) {
    try {
        if(time()-$lastBeat>=10) { $store->set('radio_heartbeat',(string)time()); $lastBeat=time(); }
        if(time()-$lastTidy>=300) { $radio->tidy(); $lastTidy=time(); }
        // Players: one per live station with songs waiting, never for a stopped one.
        $on=Zero\Radio::enabled($store);
        foreach($players as $chat=>$player) {
            $row=$store->query('SELECT live,command FROM radio_stations WHERE chat_id=?',[$chat])->fetch();
            $running=proc_get_status($player)['running']??false;
            if(!$running) { proc_close($player); unset($players[$chat]); continue; }
            if(!$on||!$row||(int)$row['live']!==1) { proc_terminate($player); }
        }
        foreach($radio->stations() as $station) {
            $chat=(int)$station['chat_id'];
            if(!$on||isset($players[$chat])||count($players)>=$limit||(int)$station['live']!==1||$station['command']==='stop'||$radio->queued($chat)===0) continue;
            $player=proc_open([$php,__DIR__.'/radio-player.php',(string)$chat],[0=>$null,1=>['file','/dev/null','w'],2=>$log],$pipes,dirname(__DIR__),$env);
            if(is_resource($player)) $players[$chat]=$player;
        }
        // Preparing: one song at a time, the next few of each station that is live or has been fed lately.
        if($prepare!==null&&!(proc_get_status($prepare['process'])['running']??false)) {
            proc_close($prepare['process']);
            // Still "preparing" means the process died: back to the queue for one more try.
            $store->query("UPDATE radio_queue SET status=CASE WHEN tries>=1 THEN 'failed' ELSE 'pending' END,tries=tries+1 WHERE id=? AND status='preparing'",[$prepare['id']]);
            $prepare=null;
        }
        if($prepare===null) {
            foreach($radio->stations() as $station) {
                $chat=(int)$station['chat_id'];
                $ahead=(int)$store->query("SELECT COUNT(*) FROM radio_queue WHERE chat_id=? AND status IN ('ready','preparing')",[$chat])->fetchColumn();
                if($ahead>=$lookahead||$station['command']==='stop') continue;
                $next=$store->query("SELECT id FROM radio_queue WHERE chat_id=? AND status='pending' ORDER BY id LIMIT 1",[$chat])->fetchColumn();
                if($next===false) continue;
                $store->query("UPDATE radio_queue SET status='preparing' WHERE id=?",[$next]);
                $process=proc_open([$php,__DIR__.'/radio-prepare.php',(string)$next],[0=>$null,1=>['file','/dev/null','w'],2=>$log],$pipes,dirname(__DIR__),$env);
                if(is_resource($process)) $prepare=['id'=>(int)$next,'process'=>$process]; else $store->query("UPDATE radio_queue SET status='pending' WHERE id=?",[$next]);
                break;
            }
        }
    } catch(Throwable $e) { error_log('Radio supervisor: '.get_class($e).': '.Zero\diagnosticMessage($e->getMessage())); sleep(2); }
    usleep(500000);
}
foreach($players as $player) { proc_terminate($player); }
if($prepare!==null) proc_terminate($prepare['process']);
