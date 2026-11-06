<?php
declare(strict_types=1);
namespace Zero;
use Throwable;

final class WorkerStopped extends \RuntimeException {}

final class Worker
{
    public function __construct(private Config $config,private Store $store,private Api $api) {}

    public function recover(array $job): void
    {
        $changed=$this->store->query("UPDATE jobs SET status='failed' WHERE id=? AND status='running'",[$job['id']])->rowCount();
        if($changed) {
            $bot=new Bot($this->config,$this->store,$this->api);
            try { $bot->jobMessage($job,$bot->text('restarted')); } catch(Throwable) {}
        }
        foreach(glob($this->config->storage.'/downloads/job-'.(int)$job['id'].'-*')?:[] as $folder) Download::clean($folder);
    }

    public function process(array $job): void
    {
        $config=$this->config; $store=$this->store; $api=$this->api;
        $bot=new Bot($config,$store,$api); $downloader=new Download($config);
        $downloads=$config->storage.'/downloads';
        $folder=$downloads.'/job-'.$job['id'].'-'.bin2hex(random_bytes(4));
        mkdir($folder,0700);
        $stage='download'; $audio=$job['phase']==='audio';
        $started=hrtime(true); $timings=[];
        try {
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
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
                return;
            }
            Download::ensureSpace($downloads);
            $bot->jobMessage($job,$bot->text($audio?'audio_downloading':'downloading'));
            $options=json_decode($job['options'],true);
            $quality=$job['quality']!==null?(int)$job['quality']:null;
            $preparationStarted=hrtime(true);
            $media=$audio?$downloader->fetchAudio($job['url'],$folder,$options['audio']??[]):
                $downloader->fetch($job['url'],$folder,$quality,$quality!==null?($options[$quality]??[]):[]);
            $timings['prepare_ms']=(int)round((hrtime(true)-$preparationStarted)/1e6);
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
            }
            $stage='upload';
            if($config->api==='http://ciro-telegram-api:8081') {
                // The local API receives a second copy of the completed file.
                Download::ensureSpace('/telegram-api',(int)filesize($media['path'])+268435456);
            }
            $bot->jobMessage($job,$bot->text($audio?'audio_uploading':'uploading'));
            $caption=$bot->ui->mediaCaption($audio?'audio':'video',['title'=>mb_substr($media['title'],0,100),
                'quality'=>$media['quality']??'','format'=>$media['format']??'','duration'=>Ui::duration($media['duration']),'url'=>$job['url']]);
            $link=$bot->ui->button($audio?'source_audio':'source_video'); $link['url']=$job['url'];
            $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$caption,'parse_mode'=>'HTML','duration'=>$media['duration'],
                'reply_markup'=>['inline_keyboard'=>[[$link]]]];
            if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
            $uploadStarted=hrtime(true);
            if($audio) $api->call('sendAudio',$params+['audio'=>new \CURLFile($media['path'],'audio/mpeg','audio.mp3'),
                'title'=>mb_substr($media['title'],0,100)]);
            else $api->call('sendVideo',$params+['video'=>new \CURLFile($media['path'],'video/mp4','video.mp4'),
                'supports_streaming'=>true,'width'=>$media['width'],'height'=>$media['height']]);
            $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
            $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
            try { $bot->finishJob($job); }
            catch(Throwable) { error_log('Media delivered; status cleanup unavailable'); }
        } catch(WorkerStopped $e) {
            throw $e;
        } catch(Throwable $e) {
            $detail=diagnosticMessage($e->getMessage());
            error_log('Job '.$job['id'].' failed ['.$stage.']: '.get_class($e).': '.$detail);
            $store->set('last_job_error','Job '.$job['id'].' ['.$stage.']: '.$detail);
            $store->set('last_job_error_reason',$e instanceof MediaFailure?$e->reason:($stage==='upload'?'upload':'process'));
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            $key=$e instanceof MediaFailure?'error_'.$e->reason:($stage==='upload'?'error_upload':'failed');
            if($audio&&$bot->ui->definition('text','audio_'.$key)) $key='audio_'.$key;
            $notice=$bot->text($bot->ui->definition('text',$key)?$key:'failed');
            try { $bot->jobMessage($job,$notice); } catch(Throwable) {}
        } finally {
            $timings['total_ms']=(int)round((hrtime(true)-$started)/1e6);
            error_log('Job '.$job['id'].' timings '.json_encode($timings,JSON_THROW_ON_ERROR));
            Download::clean($folder);
        }
    }
}
