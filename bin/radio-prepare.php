<?php
declare(strict_types=1);
/**
 * Prepares the audio of one queued song (the same sources and matching as a music download) and leaves it ready for
 * the player. Started by bin/radio.php.  Usage: php bin/radio-prepare.php QUEUE_ID
 */
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
umask(0077);
$id=(int)($argv[1]??0);
$config=Zero\Config::load(); $store=new Zero\Store($config->storage); $radio=new Zero\Radio($config,$store);
$item=$store->query("SELECT * FROM radio_queue WHERE id=? AND status='preparing'",[$id])->fetch();
if(!$item) exit(0);
$downloads=$config->storage.'/downloads'; if(!is_dir($downloads)) mkdir($downloads,0700,true);
$folder=$downloads.'/radio-'.$id.'-'.bin2hex(random_bytes(3)); mkdir($folder,0700);
pcntl_async_signals(true);
foreach([SIGTERM,SIGINT] as $signal) pcntl_signal($signal,static function(): void {
    pcntl_signal(SIGTERM,SIG_IGN); pcntl_signal(SIGINT,SIG_IGN);
    // Unwind Download::run so its timeout process terminates the whole downloader group.
    throw new Zero\WorkerStopped('Radio preparation stopping');
});
try {
    $source=json_decode((string)$item['source'],true);
    if(!is_array($source)) throw new RuntimeException('Invalid song source');
    Zero\Download::ensureSpace($downloads);
    $media=(new Zero\Worker($config,$store,new Zero\Telegram($config)))->radioAudio($source,$folder);
    $length=(int)($media['length']??$media['duration']??0);
    if($length>Zero\Radio::MAX_SECONDS) { $store->query("UPDATE radio_queue SET status='failed' WHERE id=?",[$id]); exit(0); }
    $files=$radio->root().'/files'; if(!is_dir($files)&&!mkdir($files,0700,true)&&!is_dir($files)) throw new RuntimeException('Cannot create the radio folder');
    $destination=$files.'/'.$id.'.'.(pathinfo((string)$media['path'],PATHINFO_EXTENSION)?:'audio');
    if(!rename((string)$media['path'],$destination)) throw new RuntimeException('Cannot keep the prepared song');
    // The cover, for the picture of the stream while the song plays.
    $cover='';
    if(Zero\RadioArtwork::render((string)($media['thumbnail']??''),$destination,$files.'/'.$id.'.jpg')) $cover=$files.'/'.$id.'.jpg';
    $store->query("UPDATE radio_queue SET cover=? WHERE id=?",[$cover,$id]);
    $store->query("UPDATE radio_queue SET status='ready',file=?,duration=?,title=?,artists=? WHERE id=?",[$destination,$length,mb_substr((string)($media['title']??$item['title']),0,150),
        mb_substr((string)($media['performer']??$item['artists']),0,150),$id]);
} catch(Zero\WorkerStopped) {
    $changed=$store->query("UPDATE radio_queue SET status='pending' WHERE id=? AND status='preparing'",[$id])->rowCount();
    if($changed&&isset($destination)) { @unlink($destination); @unlink($radio->root().'/files/'.$id.'.jpg'); }
} catch(Throwable $e) {
    // A passing failure (network, a busy source) gets one more try; then the song is skipped.
    error_log('Radio song '.$id.' not prepared: '.get_class($e).': '.Zero\diagnosticMessage($e->getMessage()));
    $store->query("UPDATE radio_queue SET status=?,tries=tries+1 WHERE id=?",[(int)$item['tries']>=1?'failed':'pending',$id]);
} finally { Zero\Download::clean($folder); }
