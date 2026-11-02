<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
umask(0077);
$config=Zero\Config::load(); $store=new Zero\Store($config->storage);
$lock=Zero\lockProcess($config->storage.'/worker.lock');
$api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api); $downloader=new Zero\Download($config);
$downloads=$config->storage.'/downloads';
if(!is_dir($downloads)) mkdir($downloads,0700,true);
// Do not blindly resend an upload whose final result is unknown after a restart.
$interrupted=$store->query("SELECT * FROM jobs WHERE status='running'")->fetchAll();
$store->query("UPDATE jobs SET status='failed' WHERE status='running'");
foreach(glob($downloads.'/job-*')?:[] as $dir) Zero\Download::clean($dir);
foreach($interrupted as $job) {
    try { $bot->send((int)$job['chat_id'],$bot->text('restarted')); } catch(Throwable) {}
}
echo "Ciro Downloader worker started\n";
while(true) {
    try {
        if(!$store->enabled()||!($job=$store->claim())) { sleep(1); continue; }
        $folder=$downloads.'/job-'.$job['id'].'-'.bin2hex(random_bytes(4));
        mkdir($folder,0700);
        try {
            if(disk_free_space($downloads)<$config->maxBytes*4) throw new RuntimeException('Insufficient disk space');
            $bot->send((int)$job['chat_id'],$bot->text('downloading'));
            $media=$downloader->fetch($job['url'],$folder);
            $bot->send((int)$job['chat_id'],$bot->text('uploading'));
            $caption=strtr($bot->text('video_caption'),[
                '{title}'=>htmlspecialchars(mb_substr($media['title'],0,100), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{quality}'=>htmlspecialchars($media['height'].'p', ENT_QUOTES, 'UTF-8'),
                '{url}'=>htmlspecialchars($job['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ]);
            // Limit UTF-16 units conservatively for Telegram captions (including emoji).
            $caption=mb_substr($caption,0,500);
            $api->call('sendVideo',['chat_id'=>(int)$job['chat_id'],'video'=>new CURLFile($media['path'],'video/mp4','video.mp4'),
                'caption'=>$caption,'width'=>$media['width'],'height'=>$media['height'],'duration'=>$media['duration']]);
            $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
        } catch(Throwable $e) {
            error_log('Job '.$job['id'].' failed: '.get_class($e));
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            try { $bot->send((int)$job['chat_id'],$bot->text('failed')); } catch(Throwable) {}
        } finally { Zero\Download::clean($folder); }
        $store->query("DELETE FROM jobs WHERE status IN ('done','failed') AND created<?",[time()-86400*7]);
    } catch(Throwable $e) { error_log('Worker error: '.get_class($e)); sleep(5); }
}
