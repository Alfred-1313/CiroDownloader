<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Radio.php';
require dirname(__DIR__).'/src/Download.php';
function transportCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function transportWait(callable $ready,string $label,int $seconds=10): void {
    $until=microtime(true)+$seconds;
    do { if($ready()) { transportCheck(true,$label); return; } usleep(50000); } while(microtime(true)<$until);
    throw new RuntimeException($label);
}
$temp=sys_get_temp_dir().'/ciro-radio-transport-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $children=[];
try {
    $make=function(string $name,array $lengths) use($temp,&$children): array {
        $root=$temp.'/'.$name; mkdir($root,0700); $config=new Zero\Config('123:fixture',[42],$root); $store=new Zero\Store($root); $radio=new Zero\Radio($config,$store);
        $radio->save(-1001,'Transport','group',42,'rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf'); $radio->setLive(-1001,true);
        mkdir($root.'/radio/files',0700); $ids=[]; $paths=[];
        foreach($lengths as $n=>$length) {
            $path=$root.'/radio/files/song-'.$n.'.m4a';
            (new Zero\Download($config))->run(['ffmpeg','-v','error','-y','-f','lavfi','-i','sine=frequency='.(440+$n*220).':sample_rate=44100:duration='.$length,'-c:a','aac',$path],$root,30);
            $item=$radio->enqueue(-1001,42,['service'=>'spotify','m'=>['title'=>'Song '.$n,'artists'=>['Artist'],'duration'=>$length]],'Song '.$n,'Artist',$length); $ids[]=$item['id']; $paths[]=$path;
            $store->query("UPDATE radio_queue SET status='ready',file=? WHERE id=?",[$path,$item['id']]);
        }
        return [$root,$store,$radio,$ids,$paths];
    };
    $start=function(string $root) use(&$children) {
        $process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/radio-player.php','-1001'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$root.'/player.log','w']],$pipes,null,
            ['PATH'=>getenv('PATH'),'BOT_TOKEN'=>'123:fixture','ADMIN_IDS'=>'42','STORAGE_DIR'=>$root,'RADIO_TEST_OUTPUT'=>$root.'/out.flv','RADIO_IDLE_SECONDS'=>'2']);
        $children[]=$process; return $process;
    };
    $stop=function($process,Zero\Radio $radio,string $root): void {
        $radio->command(-1001,'stop'); $until=microtime(true)+10;
        while(microtime(true)<$until&&($status=proc_get_status($process))['running']) usleep(50000);
        transportCheck(!$status['running']&&$status['exitcode']===0,'the controlled session stops cleanly'); proc_close($process);
        transportCheck(!glob($root.'/radio/files/*'),'completed and cached previous-track audio is released on stop');
        transportCheck(!array_filter($radio->queue(-1001,100),fn($item)=>$item['status']==='playing'),'stopping leaves no stranded playing item in the queue');
    };
    [$root,$store,$radio,$ids]=$make('pause',[10]); $process=$start($root);
    transportWait(fn()=>(int)($radio->progress(-1001)['id']??0)===$ids[0],'the presentation snapshot identifies the audible track');
    $radio->togglePause(-1001); usleep(3300000); $first=$radio->progress(-1001); usleep(1400000); $second=$radio->progress(-1001);
    transportCheck($radio->paused(-1001)&&(int)$first['id']===$ids[0]&&abs($first['elapsed']-$second['elapsed'])<0.3,'pause keeps the track and freezes elapsed time after the existing stream buffer drains');
    $radio->togglePause(-1001); $elapsed=$second['elapsed'];
    transportWait(fn()=>($radio->progress(-1001)['elapsed']??0)>$elapsed+0.7,'resume continues from the same track position');
    $stop($process,$radio,$root);

    [$root,$store,$radio,$ids]=$make('pause-before-start',[5]); $radio->togglePause(-1001); $process=$start($root); usleep(2300000);
    transportCheck($radio->paused(-1001)&&empty($radio->progress(-1001)['id'])&&$store->query('SELECT status FROM radio_queue WHERE id=?',[$ids[0]])->fetchColumn()==='ready','pause while preparing prevents a ready song from starting or advancing');
    $radio->togglePause(-1001); transportWait(fn()=>(int)($radio->progress(-1001)['id']??0)===$ids[0],'resume starts the held song'); $stop($process,$radio,$root);

    [$root,$store,$radio,$ids]=$make('restart',[12]); $process=$start($root);
    transportWait(fn()=>($radio->progress(-1001)['elapsed']??0)>=2.5,'the restart fixture reaches an audible position inside the song');
    $radio->togglePause(-1001); $radio->command(-1001,'restart');
    transportWait(fn()=>(int)($radio->progress(-1001)['id']??0)===$ids[0]&&($radio->progress(-1001)['elapsed']??99)<1.3,'Restart resets the audible song position');
    transportCheck(!$radio->paused(-1001)&&(int)$store->query('SELECT COUNT(*) FROM radio_queue')->fetchColumn()===1,'Restart resumes playback with the same prepared audio and queue item'); $stop($process,$radio,$root);

    [$root,$store,$radio,$ids]=$make('shuffle',[3,3,3,3]); $radio->setMode(-1001,'shuffle');
    $order=array_column($radio->queue(-1001,100),'id'); $priorities=array_column($radio->queue(-1001,100),'play_order');
    transportCheck($order===array_column($radio->queue(-1001,100),'id')&&count(array_unique($priorities))===4&&min($priorities)>0,'shuffle assigns stable random priorities without losing any song');
    // A fixed permutation isolates real decoder order from randomness, so the test can never pass by chance.
    $expected=[$ids[2],$ids[0],$ids[3],$ids[1]];
    foreach($expected as $n=>$id) $store->query('UPDATE radio_queue SET play_order=? WHERE id=?',[$n+1,$id]);
    $process=$start($root); $seen=[]; $until=microtime(true)+18;
    while(microtime(true)<$until&&count($seen)<4) { $id=(int)($radio->progress(-1001)['id']??0); if($id>0&&!in_array($id,$seen,true)) $seen[]=$id; usleep(50000); }
    transportCheck($seen===$expected,'the real player follows the shuffled playlist shown by the panel'); $stop($process,$radio,$root);

    [$root,$store,$radio,$ids]=$make('shuffle-during-play',[12,3,3]); $process=$start($root);
    transportWait(fn()=>(int)($radio->progress(-1001)['id']??0)===$ids[0],'the mode-change fixture starts with the original queue');
    // Publish a deterministic shuffle and Skip together while the old next decoder is already prefetched.
    $store->db->exec('BEGIN IMMEDIATE'); $store->set('radio.mode.-1001','shuffle');
    $store->query('UPDATE radio_queue SET play_order=? WHERE id=?',[2,$ids[1]]); $store->query('UPDATE radio_queue SET play_order=? WHERE id=?',[1,$ids[2]]); $radio->command(-1001,'skip'); $store->db->exec('COMMIT');
    transportWait(fn()=>(int)($radio->progress(-1001)['id']??0)===$ids[2],'changing to Shuffle replaces the old prefetched next decoder');
    transportWait(fn()=>(int)($radio->progress(-1001)['id']??0)===$ids[1],'the remaining shuffled song plays next'); $stop($process,$radio,$root);

    [$root,$store,$radio,$ids]=$make('previous',[3,8]); $process=$start($root);
    transportWait(fn()=>(int)($radio->progress(-1001)['id']??0)===$ids[1],'the next track follows the first');
    $radio->previous(-1001,42);
    transportWait(fn()=>(int)($radio->progress(-1001)['id']??0)===$ids[0],'Previous replays the retained previous audio without another download');
    transportWait(fn()=>(int)($radio->progress(-1001)['id']??0)===$ids[1],'the interrupted next track returns after replaying Previous');
    transportCheck(($radio->progress(-1001)['elapsed']??99)<2,'the interrupted track restarts from its beginning');
    $stop($process,$radio,$root);

    [$root,$store,$radio,$ids]=$make('repeat-one',[2]); $store->set('radio.mode.-1001','one'); $process=$start($root);
    transportWait(fn()=>(int)($radio->progress(-1001)['id']??0)===$ids[0],'single-track repeat starts'); usleep(4800000);
    transportCheck(proc_get_status($process)['running']&&(int)$store->query('SELECT COUNT(*) FROM radio_queue')->fetchColumn()===1&&(int)($radio->progress(-1001)['id']??0)===$ids[0],'repeat-one plays through multiple loops using the same prepared file and queue row');
    $stop($process,$radio,$root);

    [$root,$store,$radio,$ids]=$make('repeat-list',[2,2]); $store->set('radio.mode.-1001','all'); $process=$start($root);
    transportWait(fn()=>(int)$store->query("SELECT COUNT(*) FROM radio_queue WHERE status='pending'")->fetchColumn()===2,'playlist repeat queues completed tracks again');
    $pending=$store->query("SELECT title FROM radio_queue WHERE status='pending' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    transportCheck($pending===['Song 0','Song 1']&&count(glob($root.'/radio/files/*'))<=1,'playlist repeat preserves order and keeps at most one completed audio file');
    $stop($process,$radio,$root);
} finally {
    foreach($children as $process) if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    Zero\Download::clean($temp);
}
