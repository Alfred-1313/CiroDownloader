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
            $expired=$store->query("SELECT * FROM jobs WHERE status='confirming' AND expires>0 AND expires<?",[time()])->fetchAll();
            foreach($expired as $old) {
                $changed=$store->query("UPDATE jobs SET status='failed' WHERE id=? AND status='confirming' AND expires<?",[$old['id'],time()])->rowCount();
                if($changed) { try { $bot->jobMessage($old,$bot->text('quality_expired')); } catch(Throwable) {} }
            }
            $store->query("DELETE FROM jobs WHERE status IN ('done','failed','cancelled') AND created<?",[time()-86400*7]);
        }
        if(!$store->enabled()) { sleep(1); continue; }
        if(!($job=$store->claim())) { usleep(100000); continue; }
        $folder=$downloads.'/job-'.$job['id'].'-'.bin2hex(random_bytes(4));
        mkdir($folder,0700);
        $stage='download'; $audio=$job['phase']==='audio';
        $started=hrtime(true); $timings=[];
        try {
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); continue;
            }
            if($job['phase']==='inspect') {
                $stage='inspect';
                $bot->jobMessage($job,$bot->text('inspecting'));
                $inspectionStarted=hrtime(true);
                $info=$downloader->inspect($job['url'],$folder);
                $timings['inspect_ms']=(int)round((hrtime(true)-$inspectionStarted)/1e6);
                if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
                    $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                    $bot->jobMessage($job,$bot->denial((int)$job['chat_id']));
                } else {
                    $store->set('youtube_probe_url',$job['url']);
                    $bot->qualityMenu($job,$info);
                }
                continue;
            }
            Zero\Download::ensureSpace($downloads);
            $bot->jobMessage($job,$bot->text($audio?'audio_downloading':'downloading'));
            $options=json_decode($job['options'],true);
            $quality=$job['quality']!==null?(int)$job['quality']:null;
            $preparationStarted=hrtime(true);
            $media=$audio?$downloader->fetchAudio($job['url'],$folder,$options['audio']??[]):
                $downloader->fetch($job['url'],$folder,$quality,$quality!==null?($options[$quality]??[]):[]);
            $timings['prepare_ms']=(int)round((hrtime(true)-$preparationStarted)/1e6);
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); continue;
            }
            $stage='upload';
            if($config->api==='http://ciro-telegram-api:8081') {
                // The local API receives a second copy of the completed file.
                Zero\Download::ensureSpace('/telegram-api',(int)filesize($media['path'])+268435456);
            }
            $bot->jobMessage($job,$bot->text($audio?'audio_uploading':'uploading'));
            $caption=$bot->ui->mediaCaption($audio?'audio':'video',['title'=>mb_substr($media['title'],0,100),
                'quality'=>$media['quality']??'','format'=>$media['format']??'','duration'=>Zero\Ui::duration($media['duration']),'url'=>$job['url']]);
            $link=$bot->ui->button($audio?'source_audio':'source_video'); $link['url']=$job['url'];
            $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$caption,'parse_mode'=>'HTML','duration'=>$media['duration'],
                'reply_markup'=>['inline_keyboard'=>[[$link]]]];
            if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
            $uploadStarted=hrtime(true);
            if($audio) $api->call('sendAudio',$params+['audio'=>new CURLFile($media['path'],'audio/mpeg','audio.mp3'),
                'title'=>mb_substr($media['title'],0,100)]);
            else $api->call('sendVideo',$params+['video'=>new CURLFile($media['path'],'video/mp4','video.mp4'),
                'supports_streaming'=>true,'width'=>$media['width'],'height'=>$media['height']]);
            $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
            $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
            try { $bot->finishJob($job); }
            catch(Throwable) { error_log('Media delivered; status cleanup unavailable'); }
        } catch(Throwable $e) {
            $detail=Zero\diagnosticMessage($e->getMessage());
            error_log('Job '.$job['id'].' failed ['.$stage.']: '.get_class($e).': '.$detail);
            $store->set('last_job_error','Job '.$job['id'].' ['.$stage.']: '.$detail);
            $store->set('last_job_error_reason',$e instanceof Zero\MediaFailure?$e->reason:($stage==='upload'?'upload':'process'));
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            $key=$e instanceof Zero\MediaFailure?'error_'.$e->reason:($stage==='upload'?'error_upload':'failed');
            if($audio&&$bot->ui->definition('text','audio_'.$key)) $key='audio_'.$key;
            $notice=$bot->text($bot->ui->definition('text',$key)?$key:'failed');
            try { $bot->jobMessage($job,$notice); } catch(Throwable) {}
        } finally {
            $timings['total_ms']=(int)round((hrtime(true)-$started)/1e6);
            error_log('Job '.$job['id'].' timings '.json_encode($timings,JSON_THROW_ON_ERROR));
            Zero\Download::clean($folder);
        }
        $store->query("DELETE FROM jobs WHERE status IN ('done','failed','cancelled') AND created<?",[time()-86400*7]);
    } catch(Throwable $e) { error_log('Worker error: '.get_class($e)); sleep(5); }
}

