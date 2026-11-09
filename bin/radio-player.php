<?php
declare(strict_types=1);
/**
 * One radio session: plays the queue of one chat into its live stream, one song after another with no gap, as one
 * continuous ffmpeg output. Started and stopped by bin/radio.php.  Usage: php bin/radio-player.php CHAT_ID
 *
 * Songs are decoded one at a time into raw audio and written into the single output ffmpeg that pushes to RTMP, so
 * the stream is never reconnected between songs. While the next song is still being prepared the stream carries
 * silence; with nothing left to play for RADIO_IDLE_SECONDS (default 60) the session ends. Skip and stop arrive as
 * a command in the station's row. RADIO_TEST_OUTPUT (tests only) replaces the RTMP address with a file; "{chat}" in it is the chat's number.
 */
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Radio.php';
umask(0077);

$chat=(int)($argv[1]??0);
$config=Zero\Config::load(); $store=new Zero\Store($config->storage); $radio=new Zero\Radio($config,$store);
$station=$radio->station($chat); $target=$radio->target($chat);
if($station===null||$target===null||$chat===0) exit(0);
$testOutput=(string)getenv('RADIO_TEST_OUTPUT');
$output=$testOutput!==''?str_replace('{chat}',(string)abs($chat),$testOutput):$target;
$idleLimit=max(1,(int)(getenv('RADIO_IDLE_SECONDS')?:Zero\Radio::IDLE_SECONDS));
$chunk=65536; $seconds=$chunk/(44100*4);   // 16-bit stereo at 44.1 kHz
$stopping=false;
pcntl_async_signals(true);
foreach([SIGTERM,SIGINT] as $signal) pcntl_signal($signal,static function() use (&$stopping): void { $stopping=true; });

/** The text of a log line with the stream address removed: the key must never reach a log. */
$redact=static fn(string $text): string=>str_replace([$target,$output],'[address]',$text);
$out=null; $outPipes=[]; $restarts=[];
$openOutput=function() use (&$out,&$outPipes,$output,$target,&$restarts): bool {
    $now=time(); $restarts=array_values(array_filter($restarts,fn($time)=>$now-$time<120));
    if(count($restarts)>=3) return false;
    $restarts[]=$now;
    $command=['ffmpeg','-hide_banner','-loglevel','error','-y','-re','-f','s16le','-ar','44100','-ac','2','-thread_queue_size','512','-i','pipe:0','-re','-f','lavfi','-i','color=c=0x202020:s=640x360:r=15',
        '-map','1:v','-map','0:a','-c:v','libx264','-preset','ultrafast','-tune','stillimage','-pix_fmt','yuv420p','-g','30','-keyint_min','30','-b:v','300k','-maxrate','300k','-bufsize','600k',
        '-c:a','aac','-b:a','128k','-ar','44100','-f','flv',$output];
    $out=proc_open($command,[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$outPipes,null,['PATH'=>getenv('PATH')?:'/usr/bin:/bin']);
    if(!is_resource($out)) return false;
    stream_set_blocking($outPipes[2],false);
    return true;
};
$drainErrors=function() use (&$outPipes,$redact): void {
    if(!isset($outPipes[2])||!is_resource($outPipes[2])) return;
    $text=(string)fread($outPipes[2],8192);
    if($text!=='') error_log('Radio output: '.mb_substr($redact(trim($text)),0,300));
};
/** Write bytes into the output, waiting for it to take them (it plays in real time). False when the output is gone. */
$write=function(string $data) use (&$out,&$outPipes,$openOutput,$drainErrors,$redact): bool {
    $length=strlen($data); $written=0;
    while($written<$length) {
        if(!is_resource($out)||!(proc_get_status($out)['running']??false)) {
            // It ended: everything it said before ending is read (with the address removed) before it is replaced.
            if(isset($outPipes[2])&&is_resource($outPipes[2])) { stream_set_blocking($outPipes[2],true); $said=(string)stream_get_contents($outPipes[2],8192); if($said!=='') error_log('Radio output: '.mb_substr($redact(trim($said)),0,300)); }
            if(is_resource($out)) { @fclose($outPipes[0]); proc_close($out); }
            if(!$openOutput()) return false;
        }
        $count=@fwrite($outPipes[0],substr($data,$written));
        if($count===false||$count===0) { usleep(20000); if(!(proc_get_status($out)['running']??false)) continue; }
        else $written+=$count;
    }
    $drainErrors();
    return true;
};

if(!$openOutput()) { error_log('Radio: ffmpeg could not start'); exit(1); }
$silence=str_repeat("\0",$chunk); $idle=0.0; $failed=false;
$store->query("UPDATE radio_stations SET now='',command=CASE command WHEN 'skip' THEN '' ELSE command END WHERE chat_id=?",[$chat]);
/** The raw-audio decoder of one queued song; started ahead of time so the next song follows the last without a gap. */
$decode=function(array $item): ?array {
    $process=proc_open(['ffmpeg','-v','error','-i',(string)$item['file'],'-vn','-f','s16le','-ar','44100','-ac','2','pipe:1'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,null,['PATH'=>getenv('PATH')?:'/usr/bin:/bin']);
    return is_resource($process)?['item'=>$item,'process'=>$process,'pipe'=>$pipes[1]]:null;
};
$release=function(?array $decoder,string $status) use ($store): void {
    if($decoder===null) return;
    @fclose($decoder['pipe']); @proc_terminate($decoder['process']); @proc_close($decoder['process']);
    if($status==='done') {
        $store->query("UPDATE radio_queue SET status='done',file='' WHERE id=?",[$decoder['item']['id']]);
        @unlink((string)$decoder['item']['file']);
    }
};
$current=null; $upcoming=null; $buffer='';
while(!$stopping) {
    $row=$store->query('SELECT live,command FROM radio_stations WHERE chat_id=?',[$chat])->fetch();
    if(!$row||(int)$row['live']!==1||$row['command']==='stop') break;
    if($row['command']==='skip') {
        // The song playing ends now and the leftover of its last block goes with it.
        $store->query("UPDATE radio_stations SET command='' WHERE chat_id=?",[$chat]);
        $release($current,'done'); $current=null; $buffer='';
    }
    if($current===null) {
        // The next song: the one already started, if it is still wanted, else the first ready one.
        if($upcoming!==null) {
            $still=$store->query("SELECT 1 FROM radio_queue WHERE id=? AND status='ready'",[$upcoming['item']['id']])->fetchColumn();
            if($still!==false) { $current=$upcoming; } else $release($upcoming,'drop');
            $upcoming=null;
        }
        if($current===null) {
            $item=$store->query("SELECT * FROM radio_queue WHERE chat_id=? AND status='ready' ORDER BY id LIMIT 1",[$chat])->fetch();
            if($item&&is_file((string)$item['file'])) $current=$decode($item);
            elseif($item) $store->query("UPDATE radio_queue SET status='failed' WHERE id=?",[$item['id']]);
        }
        if($current===null) {
            // Nothing ready: silence keeps the stream alive while the next song is prepared, until nothing is waiting any more.
            if($radio->queued($chat)===0) { $idle+=$seconds; if($idle>=$idleLimit) break; } else $idle=0.0;
            if(!$write($buffer!==''?str_pad($buffer,$chunk,"\0"):$silence)) { $failed=true; break; }
            $buffer='';
            continue;
        }
        $idle=0.0;
        $store->query("UPDATE radio_queue SET status='playing' WHERE id=?",[$current['item']['id']]);
        $label=trim(($current['item']['artists']!==''?$current['item']['artists'].' – ':'').$current['item']['title']);
        $store->query('UPDATE radio_stations SET now=? WHERE chat_id=?',[$label,$chat]);
    }
    if($upcoming===null) {
        $item=$store->query("SELECT * FROM radio_queue WHERE chat_id=? AND status='ready' ORDER BY id LIMIT 1",[$chat])->fetch();
        if($item&&is_file((string)$item['file'])) $upcoming=$decode($item);
    }
    $data=(string)fread($current['pipe'],$chunk-strlen($buffer));
    if($data===''&&feof($current['pipe'])) { $release($current,'done'); $current=null; continue; }   // the leftover joins the next song
    $buffer.=$data;
    if(strlen($buffer)>=$chunk) { if(!$write($buffer)) { $failed=true; break; } $buffer=''; }
}
if(!$failed&&$buffer!=='') $write(str_pad($buffer,$chunk,"\0"));
// A failed output must not eat the song that was playing: it goes back to the queue for the next session.
if($failed&&$current!==null) { $store->query("UPDATE radio_queue SET status='ready' WHERE id=? AND status='playing'",[$current['item']['id']]); $adder=(int)$current['item']['added_by']; $release($current,'drop'); $current=null; }
$release($current,'done'); $release($upcoming,'drop');
$store->query("UPDATE radio_stations SET now='' WHERE chat_id=?",[$chat]);
if(is_resource($out)) { @fclose($outPipes[0]); usleep(300000); @proc_terminate($out); @proc_close($out); }
if($failed) {
    error_log('Radio: the stream output failed; the session ended');
    // Whoever queued the song is told once, privately, that the stream could not be reached.
    try {
        if(($adder??0)>0&&$testOutput==='') (new Zero\Telegram($config))->call('sendMessage',['chat_id'=>$adder,'parse_mode'=>'HTML','text'=>(new Zero\Ui($store))->text('radio_output_failed',['chat'=>(string)$station['title']])]);
    } catch(Throwable) { /* The person may never have started the bot. */ }
    exit(1);
}
