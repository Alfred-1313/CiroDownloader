<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Radio.php';
function plCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }

// A made-up address and key: real ones are secrets and never belong in a test.
$KEY='rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf';
$temp=sys_get_temp_dir().'/ciro-radio-player-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
$children=[];
try {
    $config=new Zero\Config('123:fake',[42],$temp); $store=new Zero\Store($temp); $radio=new Zero\Radio($config,$store);
    $env=['PATH'=>getenv('PATH')?:'/usr/bin:/bin','HOME'=>$temp,'BOT_TOKEN'=>'123:fake','ADMIN_IDS'=>'42','STORAGE_DIR'=>$temp,'RADIO_TEST_OUTPUT'=>$temp.'/out.flv','RADIO_IDLE_SECONDS'=>'2'];
    $spawn=function(string $script,array $args=[],array $extra=[]) use($env,$temp,&$children) {
        $log=$temp.'/'.basename($script,'.php').'-'.count($children).'.log';
        $process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/'.$script,...$args],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$log,'w']],$pipes,dirname(__DIR__),array_replace($env,$extra));
        $children[]=$process; return [$process,$log];
    };
    $finish=function($process,int $timeout=40): int {
        $end=time()+$timeout; while(time()<$end) { $status=proc_get_status($process); if(!$status['running']) { $code=$status['exitcode']; proc_close($process); return $code; } usleep(100000); }
        proc_terminate($process); proc_close($process); return -1;
    };
    $tone=function(string $name,int $seconds,int $frequency) use($temp,$radio): string {
        $dir=$temp.'/radio/files'; if(!is_dir($dir)) mkdir($dir,0700,true);
        $path=$dir.'/'.$name; $codec=str_ends_with($name,'.mp3')?'libmp3lame':'aac';
        exec('ffmpeg -hide_banner -loglevel error -y -f lavfi -i '.escapeshellarg("sine=frequency=$frequency:sample_rate=44100:duration=$seconds").' -c:a '.$codec.' '.escapeshellarg($path).' 2>&1',$output,$code);
        if($code!==0) throw new RuntimeException('ffmpeg could not make the test tone');
        return $path;
    };
    $ready=function(int $chat,string $title,string $file) use($radio,$store): int {
        $added=$radio->enqueue($chat,42,['service'=>'spotify','m'=>['title'=>$title,'artists'=>['Artist'],'duration'=>3]],$title,'Artist',3);
        $store->query("UPDATE radio_queue SET status='ready',file=? WHERE id=?",[$file,$added['id']]); return $added['id'];
    };
    $probe=function(string $path): array {
        $duration=(float)trim((string)shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 '.escapeshellarg($path).' 2>/dev/null'));
        preg_match_all('/silence_start: ([0-9.]+)/',(string)shell_exec('ffmpeg -hide_banner -nostats -i '.escapeshellarg($path).' -af silencedetect=noise=-50dB:d=0.3 -f null - 2>&1'),$silences);
        $streams=trim((string)shell_exec('ffprobe -v error -show_entries stream=codec_type -of csv=p=0 '.escapeshellarg($path).' 2>/dev/null'));
        return ['duration'=>$duration,'silences'=>array_map('floatval',$silences[1]),'streams'=>preg_split('/\s+/',$streams)?:[]];
    };

    // 1. Two songs play back to back into one stream, with silence only when nothing is left.
    $radio->save(-1001,'Radio','channel',42,$KEY); $radio->setLive(-1001,true);
    $first=$ready(-1001,'One',$tone('a.m4a',3,440)); $second=$ready(-1001,'Two',$tone('b.mp3',3,880));
    [$player]=$spawn('radio-player.php',['-1001']); $code=$finish($player);
    $info=$probe($temp.'/out.flv');
    plCheck($code===0&&$info['duration']>7.0&&$info['duration']<9.6&&in_array('video',$info['streams'],true)&&in_array('audio',$info['streams'],true),'two songs and the idle wait make one stream of the sum of their lengths, with picture and sound');
    plCheck(count($info['silences'])===1&&$info['silences'][0]>5.5,'there is no gap between the songs: silence starts only after the last one');
    $states=array_column($store->query('SELECT id,status,file FROM radio_queue WHERE id IN ('.$first.','.$second.') ORDER BY id')->fetchAll(),'status');
    plCheck($states===['done','done']&&!is_file($temp.'/radio/files/a.m4a')&&!is_file($temp.'/radio/files/b.mp3')&&$store->query('SELECT now FROM radio_stations WHERE chat_id=-1001')->fetchColumn()==='','played songs are marked done, their files removed and the title cleared');

    // 2. Skip: the song playing stops and the next one starts at once.
    @unlink($temp.'/out.flv');
    $long=$ready(-1001,'Long One',$tone('c.m4a',6,330)); $next=$ready(-1001,'Long Two',$tone('d.m4a',6,550));
    [$player]=$spawn('radio-player.php',['-1001']);
    for($i=0;$i<80&&$store->query('SELECT status FROM radio_queue WHERE id=?',[$long])->fetchColumn()!=='playing';$i++) usleep(100000);
    $nowPlaying=$store->query('SELECT now FROM radio_stations WHERE chat_id=-1001')->fetchColumn();
    usleep(800000); $radio->command(-1001,'skip'); $code=$finish($player); $info=$probe($temp.'/out.flv');
    plCheck($nowPlaying==='Artist – Long One','the song playing is shown as artist and title');
    plCheck($code===0&&$info['duration']<12.5&&$info['duration']>7.0&&$store->query('SELECT status FROM radio_queue WHERE id=?',[$next])->fetchColumn()==='done','skip ends the song early and the next one plays to its end');

    // 3. Stop ends the session and leaves the rest of the queue for later.
    @unlink($temp.'/out.flv');
    $radio->setLive(-1001,true);
    $a=$ready(-1001,'Stop One',$tone('e.m4a',6,300)); $b=$ready(-1001,'Stop Two',$tone('f.m4a',6,500));
    [$player]=$spawn('radio-player.php',['-1001']);
    for($i=0;$i<80&&$store->query('SELECT status FROM radio_queue WHERE id=?',[$a])->fetchColumn()!=='playing';$i++) usleep(100000);
    $radio->command(-1001,'stop'); $started=microtime(true); $code=$finish($player,15);
    plCheck($code===0&&microtime(true)-$started<4.0&&$store->query('SELECT status FROM radio_queue WHERE id=?',[$b])->fetchColumn()==='ready','stop ends the session within a moment; songs not played yet stay queued');
    // 4. The stream ending ends the session too.
    $radio->setLive(-1001,true); $store->query("UPDATE radio_stations SET command='' WHERE chat_id=-1001");
    [$player]=$spawn('radio-player.php',['-1001']); usleep(1500000); $radio->setLive(-1001,false); $started=microtime(true); $code=$finish($player,15);
    plCheck($code===0&&microtime(true)-$started<4.0,'when the live stream ends the player stops');
    // A player for a chat without an address does nothing.
    [$player]=$spawn('radio-player.php',['-4242']); plCheck($finish($player,10)===0,'a player for a chat that is not a station exits at once');

    // 5. A broken output is reported without the address or the key in the log, and the session ends.
    $radio->setLive(-1001,true); $store->query("UPDATE radio_stations SET command='' WHERE chat_id=-1001"); $store->query("DELETE FROM radio_queue WHERE chat_id=-1001 AND status<>'ready'");
    $store->query("UPDATE radio_queue SET status='done' WHERE chat_id=-1001"); $ready(-1001,'Never Heard',$tone('g.m4a',30,260));
    [$player,$log]=$spawn('radio-player.php',['-1001'],['RADIO_TEST_OUTPUT'=>$temp.'/no-such-folder/out.flv']); $code=$finish($player,60); $text=(string)file_get_contents($log);
    plCheck($code===1&&$store->query("SELECT status FROM radio_queue WHERE title='Never Heard'")->fetchColumn()==='ready'&&str_contains($text,'Radio output')&&!str_contains($text,'no-such-folder')&&!str_contains($text,'FakeKeyForTests')&&str_contains($text,'[address]'),'when the output fails the log shows no address or key, the session ends after a few tries and the song stays queued');

    // 6. The service: starts a player for a live station with songs and stops it with the stream; recovers after a crash.
    @unlink($temp.'/out.flv'); $store->query('DELETE FROM radio_queue'); $radio->setLive(-1001,true); $store->query("UPDATE radio_stations SET command='' WHERE chat_id=-1001");
    $crashed=$radio->enqueue(-1001,42,['service'=>'spotify','m'=>['title'=>'Half','artists'=>['A'],'duration'=>3]],'Half','A',3)['id']; $store->query("UPDATE radio_queue SET status='preparing' WHERE id=?",[$crashed]);
    $old=$radio->enqueue(-1001,42,['service'=>'spotify','m'=>['title'=>'Old','artists'=>['A'],'duration'=>3]],'Old','A',3)['id']; $store->query("UPDATE radio_queue SET status='playing' WHERE id=?",[$old]);
    $song=$ready(-1001,'Service Song',$tone('h.m4a',3,700));
    [$service,$serviceLog]=$spawn('radio.php');
    for($i=0;$i<150&&$store->query('SELECT status FROM radio_queue WHERE id=?',[$song])->fetchColumn()!=='done';$i++) usleep(100000);
    plCheck($store->query('SELECT status FROM radio_queue WHERE id=?',[$song])->fetchColumn()==='done'&&is_file($temp.'/out.flv'),'the service starts a player for a live station and the song is played');
    plCheck($store->query('SELECT status FROM radio_queue WHERE id=?',[$old])->fetchColumn()==='done'&&in_array($store->query('SELECT status FROM radio_queue WHERE id=?',[$crashed])->fetchColumn(),['pending','preparing','failed'],true),'after a crash a song that was playing is closed and one that was being prepared is taken again');
    plCheck((int)$store->get('radio_heartbeat','0')>time()-30,'the service reports that it is alive');
    proc_terminate($service); $stopped=$finish($service,10); plCheck($stopped!==-1,'the service stops when asked');

    // 6b. Several groups at once: up to RADIO_STATIONS players; switching the radio off stops them all.
    $store->query('DELETE FROM radio_queue'); foreach([-1001,-1002,-1003] as $chat) { $radio->save($chat,'Radio '.abs($chat),'channel',42,$KEY); $radio->setLive($chat,true); }
    $playing=fn()=>(int)$store->query("SELECT COUNT(*) FROM radio_queue WHERE status='playing'")->fetchColumn();
    foreach([-1001=>'p',-1002=>'q',-1003=>'r'] as $chat=>$letter) $ready($chat,'Song '.$letter,$tone($letter.'.m4a',8,300+abs($chat)));
    [$service]=$spawn('radio.php',[],['RADIO_STATIONS'=>'2','RADIO_TEST_OUTPUT'=>$temp.'/out-{chat}.flv']);
    for($i=0;$i<120&&$playing()<2;$i++) usleep(100000); usleep(1500000);
    plCheck($playing()===2&&count(glob($temp.'/out-100?.flv'))===2,'two groups play at the same time and a third waits because the limit is two');
    $store->set('radio.enabled','0');
    for($i=0;$i<100&&$playing()>0;$i++) usleep(100000);
    plCheck($playing()===0,'switching the radio off stops every player');
    $store->set('radio.enabled','1'); proc_terminate($service); $finish($service,10);
    foreach([-1001,-1002,-1003] as $chat) $radio->remove($chat);
    $radio->save(-1001,'Radio','channel',42,$KEY); $radio->setLive(-1001,true);

    // 7. Preparing a song that cannot be fetched: one more try, then it is dropped.
    $bad=$radio->enqueue(-1001,42,['service'=>'spotify','m'=>['title'=>'Bad','artists'=>['A'],'duration'=>3]],'Bad','A',3)['id'];
    $store->query("UPDATE radio_queue SET status='preparing',source=? WHERE id=?",[json_encode(['service'=>'spotify']),$bad]);
    [$prepare]=$spawn('radio-prepare.php',[(string)$bad]); $finish($prepare,30); $first=$store->query('SELECT status,tries FROM radio_queue WHERE id=?',[$bad])->fetch();
    $store->query("UPDATE radio_queue SET status='preparing' WHERE id=?",[$bad]); [$prepare]=$spawn('radio-prepare.php',[(string)$bad]); $finish($prepare,30); $second=$store->query('SELECT status,tries FROM radio_queue WHERE id=?',[$bad])->fetch();
    plCheck($first['status']==='pending'&&(int)$first['tries']===1&&$second['status']==='failed','a song that cannot be prepared gets one more try and is then dropped');
} finally {
    foreach($children as $process) if(is_resource($process)) { @proc_terminate($process); @proc_close($process); }
    exec('rm -rf '.escapeshellarg($temp));
}
