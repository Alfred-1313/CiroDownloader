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
$chunk=17640; $seconds=$chunk/(44100*4);   // 16-bit stereo at 44.1 kHz
$stopping=false;
pcntl_async_signals(true);
foreach([SIGTERM,SIGINT] as $signal) pcntl_signal($signal,static function() use (&$stopping): void { $stopping=true; });

/** The text of a log line with the stream address removed: the key must never reach a log. */
$redact=static fn(string $text): string=>str_replace([$target,$output],'[address]',$text);
$out=null; $outPipes=[]; $restarts=[];
// The picture: the cover of the song playing, fitted into 640×360 on a dark background (or the background alone).
$fps=4.0; $videoPending='';
$artwork=new Zero\RadioArtwork();
$backgroundPath=tempnam(sys_get_temp_dir(),'radio-background-');
$background='';
if($backgroundPath!==false) {
    if(Zero\RadioArtwork::render('','',$backgroundPath)) $background=Zero\RadioArtwork::read($backgroundPath)??'';
    @unlink($backgroundPath);
}
$frame=$background; $upcoming=null; $pausedState=$radio->paused($chat);
$playback=new Zero\RadioPlayback($store,$chat);
$backgroundFile=$radio->root().'/panel-background.jpg';
if($background!=='') { $temporary=$backgroundFile.'.'.getmypid(); file_put_contents($temporary,$background); rename($temporary,$backgroundFile); }
$pumpNext=static function() use (&$upcoming): void {
    if($upcoming===null||!is_resource($upcoming['pipe'])) return;
    $room=352800-strlen($upcoming['prefetch']); // Two seconds of stereo PCM, in memory only.
    if($room>0) $upcoming['prefetch'].=(string)stream_get_contents($upcoming['pipe'],$room);
};

/**
 * The pace: sound is written about $lead seconds ahead of the clock, so a short hiccup here (starting the next song, a slow
 * disk) never leaves the stream without sound. Pictures go out by the clock ($fps a second, stamped as they arrive), and
 * a new cover is shown when the clock reaches the point of the sound where its song begins, so picture and sound change
 * together. A frame is sent whole or waits, and never holds up the sound.
 */
$lead=2.0; $clockStart=0.0; $audioSent=0.0; $lastFrame=0.0; $covers=[]; $lastCoverAt=-1.0;
$feedVideo=function() use (&$outPipes,&$frame,&$videoPending,&$lastFrame,&$covers,&$clockStart,$fps,$artwork,$pumpNext,&$lastCoverAt,$playback,&$pausedState): void {
    $playback->tick(max(0,microtime(true)-$clockStart),$pausedState);
    $pumpNext();
    foreach($artwork->poll() as $event) $covers[]=$event;
    if($covers) usort($covers,fn($a,$b)=>$a[0]<=>$b[0]);
    if(!isset($outPipes[3])||!is_resource($outPipes[3])) return;
    while($covers&&$covers[0][0]<=microtime(true)-$clockStart) {
        [$at,$bytes]=array_shift($covers);
        if($at>=$lastCoverAt) { $frame=$bytes; $lastCoverAt=$at; }
    }
    if($frame==='') return;
    if($videoPending===''&&microtime(true)-$lastFrame>=1/$fps) { $videoPending=$frame; $lastFrame=microtime(true); }
    if($videoPending==='') return;
    $count=@fwrite($outPipes[3],$videoPending);
    if($count) $videoPending=(string)substr($videoPending,$count);
};
/** Show $bytes from the point of the sound written so far on. */
$showCover=function(string $bytes,int $pending=0) use (&$covers,&$audioSent): void { $covers[]=[$audioSent+$pending/(44100*4),$bytes]; };
$openOutput=function() use (&$out,&$outPipes,$output,$target,&$restarts,&$videoPending,&$feedVideo,&$clockStart,&$audioSent,&$lastFrame): bool {
    $now=time(); $restarts=array_values(array_filter($restarts,fn($time)=>$now-$time<120));
    if(count($restarts)>=3) return false;
    $restarts[]=$now;
    // Sound comes in on stdin; the picture (the cover of the song playing) as JPEG frames on a second pipe, stamped by the
    // clock and turned into a steady 10 frames a second. The pace is set here (see $lead), not by ffmpeg.
    $command=['ffmpeg','-hide_banner','-loglevel','error','-y','-filter_threads','1','-f','s16le','-ar','44100','-ac','2','-thread_queue_size','512','-probesize','32','-analyzeduration','0','-i','pipe:0',
        '-f','image2pipe','-c:v','mjpeg','-threads','1','-probesize','32','-analyzeduration','0','-use_wallclock_as_timestamps','1','-thread_queue_size','64','-i','pipe:3',
        '-map','1:v','-map','0:a','-filter:v','fps=10,format=yuv420p','-c:v','libx264','-threads','2','-preset','ultrafast','-tune','stillimage,zerolatency','-g','20','-keyint_min','20','-b:v','300k','-maxrate','300k','-bufsize','600k',
        '-c:a','aac','-b:a','128k','-ar','44100','-max_interleave_delta','500000','-flush_packets','1','-f','flv',$output];
    $out=proc_open($command,[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['pipe','w'],3=>['pipe','r']],$outPipes,null,['PATH'=>getenv('PATH')?:'/usr/bin:/bin']);
    if(!is_resource($out)) return false;
    stream_set_blocking($outPipes[0],false); stream_set_blocking($outPipes[2],false); stream_set_blocking($outPipes[3],false);
    // A first picture at once: ffmpeg looks at both inputs before it starts.
    // After reconnecting, resume at the decoded position. Keep the presentation timeline so the panel and pending
    // artwork do not reset to a previous song or wait for the whole session's elapsed time again.
    $clockStart=microtime(true)-$audioSent; $videoPending=''; $lastFrame=0.0; $feedVideo();
    return true;
};
$drainErrors=function() use (&$outPipes,$redact): void {
    if(!isset($outPipes[2])||!is_resource($outPipes[2])) return;
    $text=(string)fread($outPipes[2],8192);
    if($text!=='') error_log('Radio output: '.mb_substr($redact(trim($text)),0,300));
};
/** Write bytes into the output, waiting for it to take them (it plays in real time). False when the output is gone. */
$write=function(string $data) use (&$out,&$outPipes,$openOutput,$drainErrors,$redact,$feedVideo,&$stopping,&$clockStart,&$audioSent,$lead): bool {
    $length=strlen($data); $written=0;
    // Ahead of the clock by the lead already: wait, keeping the pictures going.
    while(!$stopping&&$audioSent-(microtime(true)-$clockStart)>$lead) { $feedVideo(); usleep(20000); }
    while($written<$length) {
        if(!is_resource($out)||!(proc_get_status($out)['running']??false)) {
            // It ended: everything it said before ending is read (with the address removed) before it is replaced.
            if(isset($outPipes[2])&&is_resource($outPipes[2])) { stream_set_blocking($outPipes[2],true); $said=(string)stream_get_contents($outPipes[2],8192); if($said!=='') error_log('Radio output: '.mb_substr($redact(trim($said)),0,300)); }
            if(is_resource($out)) { @fclose($outPipes[0]); @fclose($outPipes[3]); proc_close($out); }
            if(!$openOutput()) return false;
        }
        $count=@fwrite($outPipes[0],substr($data,$written));
        // A full pipe: ffmpeg is busy; meanwhile any waiting picture still gets through.
        if($count===false||$count===0) { if($stopping) return true; $feedVideo(); usleep(10000); if(!(proc_get_status($out)['running']??false)) continue; }
        else $written+=$count;
    }
    $audioSent+=$length/(44100*4);
    $feedVideo();
    $drainErrors();
    return true;
};

if(!$openOutput()) { error_log('Radio: ffmpeg could not start'); exit(1); }
$silence=str_repeat("\0",$chunk); $idle=0.0; $failed=false;
$store->query("UPDATE radio_stations SET now='',command=CASE command WHEN 'skip' THEN '' ELSE command END WHERE chat_id=?",[$chat]);
/** The raw-audio decoder of one queued song; started ahead of time so the next song follows the last without a gap. */
$decode=function(array $item) use ($artwork): ?array {
    $process=proc_open(['ffmpeg','-v','error','-threads','1','-i',(string)$item['file'],'-vn','-threads','1','-f','s16le','-ar','44100','-ac','2','pipe:1'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,null,['PATH'=>getenv('PATH')?:'/usr/bin:/bin']);
    if(!is_resource($process)) return null;
    stream_set_blocking($pipes[1],false); $artwork->prepare($item);
    return ['item'=>$item,'process'=>$process,'pipe'=>$pipes[1],'prefetch'=>''];
};
$release=function(?array $decoder,string $status) use ($store): void {
    if($decoder===null) return;
    @fclose($decoder['pipe']); @proc_terminate($decoder['process']); @proc_close($decoder['process']);
    if($status==='remember') $store->query("UPDATE radio_queue SET status='done' WHERE id=?",[$decoder['item']['id']]);
    if($status==='done') {
        $store->query("UPDATE radio_queue SET status='done',file='' WHERE id=?",[$decoder['item']['id']]);
        @unlink((string)$decoder['item']['file']); if((string)($decoder['item']['cover']??'')!=='') @unlink((string)$decoder['item']['cover']);
    }
};
$current=null; $upcoming=null; $buffer=''; $previous=null; $requested=null; $lastMode=$radio->mode($chat);
$forget=function(?array $item) use ($store): void {
    if($item===null) return;
    @unlink((string)$item['file']); if(($item['cover']??'')!=='') @unlink($item['cover']);
    $store->query("UPDATE radio_queue SET file='',cover='' WHERE id=? AND status='done'",[$item['id']]);
};
$remember=function(array $decoder) use (&$previous,$forget,$release): void {
    if($previous!==null&&(int)$previous['id']!==(int)$decoder['item']['id']) $forget($previous);
    $release($decoder,'remember'); $previous=$decoder['item'];
};
while(!$stopping) {
    $row=$store->query('SELECT live,command FROM radio_stations WHERE chat_id=?',[$chat])->fetch();
    if(!$row||(int)$row['live']!==1||$row['command']==='stop') break;
    $mode=$radio->mode($chat);
    if($mode!==$lastMode) { $release($upcoming,'drop'); $upcoming=null; $lastMode=$mode; }
    if(in_array($row['command'],['previous','restart'],true)) {
        $store->query("UPDATE radio_stations SET command='' WHERE chat_id=?",[$chat]);
        $audible=(int)($radio->progress($chat)['id']??0);
        $now=$current['item']??$requested;
        $replay=$row['command']==='previous'?($previous??$now):
            (($previous!==null&&(int)$previous['id']===$audible)?$previous:($now??$previous));
        if($replay!==null) {
            if($current!==null) {
                $playback->finish((int)$current['item']['id'],$audioSent);
                if((int)$replay['id']!==(int)$current['item']['id']) {
                    $release($upcoming,'drop'); $upcoming=null;
                    $store->query("UPDATE radio_queue SET status='ready' WHERE id=?",[$current['item']['id']]); $upcoming=$decode($current['item']);
                }
                $release($current,'drop'); $current=null;
            } elseif($requested!==null&&(int)$requested['id']!==(int)$replay['id']) {
                // A command can arrive between repeat-one's EOF and reopening its decoder. Keep that interrupted song.
                $release($upcoming,'drop'); $store->query("UPDATE radio_queue SET status='ready' WHERE id=?",[$requested['id']]); $upcoming=$decode($requested);
            }
            if($previous!==null&&(int)$previous['id']===(int)$replay['id']) $previous=null;
            $requested=$replay; $buffer='';
        }
    }
    if($row['command']==='skip') {
        // The song playing ends now and the leftover of its last block goes with it.
        $store->query("UPDATE radio_stations SET command='' WHERE chat_id=?",[$chat]);
        if($current!==null) { $playback->finish((int)$current['item']['id'],$audioSent); $remember($current); }
        $current=null; $buffer='';
    }
    $pausedState=$radio->paused($chat);
    if($pausedState) {
        if($current!==null) $playback->silence($audioSent,$seconds);
        if(!$write($silence)) { $failed=true; break; }
        continue;
    }
    if($current===null) {
        // The next song: the one already started, if it is still wanted, else the first ready one.
        if($requested!==null) { $current=$decode($requested); $requested=null; }
        if($current===null&&$upcoming!==null) {
            $still=$store->query("SELECT 1 FROM radio_queue WHERE id=? AND status='ready'",[$upcoming['item']['id']])->fetchColumn();
            if($still!==false) { $current=$upcoming; } else $release($upcoming,'drop');
            $upcoming=null;
        }
        if($current===null) {
            $item=$store->query("SELECT * FROM radio_queue WHERE chat_id=? AND status='ready' ORDER BY play_order,id LIMIT 1",[$chat])->fetch();
            if($item&&is_file((string)$item['file'])) $current=$decode($item);
            elseif($item) $store->query("UPDATE radio_queue SET status='failed' WHERE id=?",[$item['id']]);
        }
        if($current===null) {
            // Nothing ready: silence keeps the stream alive while the next song is prepared, until nothing is waiting any more.
            if($radio->queued($chat)===0) { if($idle===0.0) $showCover($background,strlen($buffer)); $idle+=$seconds; if($idle>=$idleLimit) break; } else $idle=0.0;
            if(!$write($buffer!==''?str_pad($buffer,$chunk,"\0"):$silence)) { $failed=true; break; }
            $buffer='';
            continue;
        }
        $idle=0.0;
        $store->query("UPDATE radio_queue SET status='playing' WHERE id=?",[$current['item']['id']]);
        $label=trim(($current['item']['artists']!==''?$current['item']['artists'].' – ':'').$current['item']['title']);
        $store->query('UPDATE radio_stations SET now=? WHERE chat_id=?',[$label,$chat]);
        $showCover($background,strlen($buffer));
        $artwork->start((int)$current['item']['id'],$audioSent+strlen($buffer)/(44100*4));
        $playback->start($current['item'],$audioSent+strlen($buffer)/(44100*4));
    }
    if($upcoming===null) {
        $item=$store->query("SELECT * FROM radio_queue WHERE chat_id=? AND status='ready' ORDER BY play_order,id LIMIT 1",[$chat])->fetch();
        if($item&&is_file((string)$item['file'])) $upcoming=$decode($item);
    }
    $pumpNext();
    $need=$chunk-strlen($buffer);
    if($current['prefetch']!=='') { $data=substr($current['prefetch'],0,$need); $current['prefetch']=(string)substr($current['prefetch'],strlen($data)); }
    else $data=(string)fread($current['pipe'],$need);
    if($data===''&&!feof($current['pipe'])) { $feedVideo(); usleep(10000); continue; }
    if($data===''&&feof($current['pipe'])) {
        $playback->finish((int)$current['item']['id'],$audioSent+strlen($buffer)/(44100*4));
        $mode=$radio->mode($chat);
        if($mode==='one') { $requested=$current['item']; $release($current,'drop'); }
        else {
            $remember($current);
            if($mode==='all') $radio->enqueue($chat,(int)$current['item']['added_by'],json_decode($current['item']['source'],true),$current['item']['title'],$current['item']['artists'],(int)$current['item']['duration']);
        }
        $current=null; continue;
    }   // the leftover joins the next song
    $buffer.=$data;
    if(strlen($buffer)>=$chunk) { if(!$write($buffer)) { $failed=true; break; } $buffer=''; }
}
if(!$failed&&$buffer!=='') $write(str_pad($buffer,$chunk,"\0"));
// The sound is ahead of the clock: the pictures go on until the clock has caught up, so the end is not cut short.
if(!$failed&&!$stopping) { $until=microtime(true)+$lead+1.0; while(microtime(true)<$until&&$audioSent>microtime(true)-$clockStart) { $feedVideo(); usleep(20000); } }
// A failed output must not eat the song that was playing: it goes back to the queue for the next session.
if($failed&&$current!==null) { $store->query("UPDATE radio_queue SET status='ready' WHERE id=? AND status='playing'",[$current['item']['id']]); $adder=(int)$current['item']['added_by']; $release($current,'drop'); $current=null; }
$release($current,'done'); $release($upcoming,'drop');
$artwork->close();
if($requested!==null) {
    $store->query("UPDATE radio_queue SET status='done' WHERE id=? AND status='playing'",[$requested['id']]);
    $forget($requested);
}
$forget($previous); $playback->clear();
$store->query("UPDATE radio_stations SET now='' WHERE chat_id=?",[$chat]);
if(is_resource($out)) {
    // The end of the input lets ffmpeg send what it still holds; it is stopped only if it does not finish by itself.
    @fclose($outPipes[0]); @fclose($outPipes[3]);
    // Asked to stop (the stream ended, the radio was switched off): no waiting.
    for($wait=0;$wait<($stopping?10:100)&&(proc_get_status($out)['running']??false);$wait++) usleep(100000);
    @proc_terminate($out); @proc_close($out);
}
if($failed) {
    error_log('Radio: the stream output failed; the session ended');
    // Whoever queued the song is told once, privately, that the stream could not be reached.
    try {
        if(($adder??0)>0&&$testOutput==='') (new Zero\Telegram($config))->call('sendMessage',['chat_id'=>$adder,'parse_mode'=>'HTML','text'=>(new Zero\Ui($store))->text('radio_output_failed',['chat'=>(string)$station['title']])]);
    } catch(Throwable) { /* The person may never have started the bot. */ }
    exit(1);
}
