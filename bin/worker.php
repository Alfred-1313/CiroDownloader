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
    try { $bot->jobMessage($job,$bot->text('restarted')); } catch(Throwable) {}
}
echo "Ciro Downloader worker started\n";
$lastHeartbeat=0;
while(true) {
    try {
        if(time()-$lastHeartbeat>=10) {
            $store->set('worker_heartbeat',(string)time()); $lastHeartbeat=time();
            // An interrupted initial Telegram request must not leave an unclaimable job forever.
            $store->query("UPDATE jobs SET status='failed' WHERE status='queued' AND message_id=0 AND created<?",[time()-120]);
            $expired=$store->query("SELECT * FROM jobs WHERE status IN ('choosing','confirming') AND expires>0 AND expires<?",[time()])->fetchAll();
            foreach($expired as $old) {
                $changed=$store->query("UPDATE jobs SET status='failed' WHERE id=? AND status IN ('choosing','confirming') AND expires<?",[$old['id'],time()])->rowCount();
                if($changed) { try { $bot->jobMessage($old,$bot->text('quality_expired')); } catch(Throwable) {} }
            }
            $store->query("DELETE FROM jobs WHERE status IN ('done','failed','cancelled') AND created<?",[time()-86400*7]);
        }
        if(!$store->enabled()||!($job=$store->claim())) { sleep(1); continue; }
        $folder=$downloads.'/job-'.$job['id'].'-'.bin2hex(random_bytes(4));
        mkdir($folder,0700);
        $stage='download';
        try {
            if(!$bot->allowed((int)$job['user_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->denial()); continue;
            }
            if($job['phase']==='inspect') {
                $stage='inspect';
                $bot->jobMessage($job,$bot->text('inspecting'));
                $info=$downloader->inspect($job['url'],$folder);
                if(!$bot->allowed((int)$job['user_id'])) {
                    $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                    $bot->jobMessage($job,$bot->denial());
                } else $bot->qualityMenu($job,$info);
                continue;
            }
            if(disk_free_space($downloads)<$config->maxBytes*4) throw new RuntimeException('Insufficient disk space');
            $bot->jobMessage($job,$bot->text('downloading'));
            $options=json_decode($job['options'],true);
            $quality=$job['quality']!==null?(int)$job['quality']:null;
            $media=$downloader->fetch($job['url'],$folder,$quality,$quality!==null?($options[$quality]??[]):[]);
            if(!$bot->allowed((int)$job['user_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->denial()); continue;
            }
            $stage='upload';
            $bot->jobMessage($job,$bot->text('uploading'));
            $caption=$bot->text('video_caption',['title'=>mb_substr($media['title'],0,100),'quality'=>$media['quality'],'url'=>$job['url']]);
            $api->call('sendVideo',['chat_id'=>(int)$job['chat_id'],'video'=>new CURLFile($media['path'],'video/mp4','video.mp4'),
                'caption'=>$caption,'parse_mode'=>'HTML','supports_streaming'=>true,
                'width'=>$media['width'],'height'=>$media['height'],'duration'=>$media['duration']]);
            $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
            try { $bot->jobMessage($job,$bot->text('delivered')); }
            catch(Throwable) { error_log('Video delivered; final status edit unavailable'); }
        } catch(Throwable $e) {
            $detail=Zero\diagnosticMessage($e->getMessage());
            error_log('Job '.$job['id'].' failed ['.$stage.']: '.get_class($e).': '.$detail);
            $store->set('last_job_error','Job '.$job['id'].' ['.$stage.']: '.$detail);
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            $key=$e instanceof Zero\MediaFailure?'error_'.$e->reason:($stage==='upload'?'error_upload':'failed');
            $notice=$bot->text($bot->ui->definition('text',$key)?$key:'failed');
            try { $bot->jobMessage($job,$notice); } catch(Throwable) {}
        } finally { Zero\Download::clean($folder); }
        $store->query("DELETE FROM jobs WHERE status IN ('done','failed','cancelled') AND created<?",[time()-86400*7]);
    } catch(Throwable $e) { error_log('Worker error: '.get_class($e)); sleep(5); }
}
