<?php
declare(strict_types=1);
namespace Zero;
use Throwable;
require_once __DIR__.'/Instagram.php';
require_once __DIR__.'/Articles.php';

final class WorkerStopped extends \RuntimeException {}

final class Worker
{
    public function __construct(private Config $config,private Store $store,private Api $api,private ?Articles $articles=null) {}

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
        $instagram=instagramUrl($job['url'])!==null;
        $started=hrtime(true); $timings=[];
        try {
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
            }
            if($instagram) {
                $bot->jobMessage($job,$bot->text('instagram_downloading'));
                $inspectionStarted=hrtime(true);
                $media=(new Instagram($config))->fetch($job['url'],$folder);
                $timings['prepare_ms']=(int)round((hrtime(true)-$inspectionStarted)/1e6);
                $stage='upload'; $uploadStarted=hrtime(true);
                if(count($media)>1) {
                    $bot->jobMessage($job,$bot->text('instagram_article_building'));
                    $url=($this->articles??new Articles($config))->publish($media,$job['url'],$bot->ui,fn()=>$bot->allowed((int)$job['user_id'],(int)$job['chat_id']));
                    $button=$bot->ui->button('open_instagram_article'); $button['url']=$url;
                    $bot->jobMessage($job,$bot->text('instagram_article_ready',['count'=>count($media),'page_url'=>$url,'url'=>$job['url']]),[[$button]]);
                    $store->query("UPDATE jobs SET status='done',options=? WHERE id=?",[json_encode(['article_url'=>$url],JSON_THROW_ON_ERROR),$job['id']]);
                    $timings['publish_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                    return;
                }
                $this->instagramDelivery($job,$media,$bot);
                $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
                try { $bot->finishJob($job); } catch(Throwable) { error_log('Instagram media delivered; status cleanup unavailable'); }
                return;
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
            $store->set('last_job_service',$instagram?'instagram':'youtube');
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            $key=$e instanceof MediaFailure?'error_'.$e->reason:($stage==='upload'?'error_upload':'failed');
            if($instagram) $key='instagram_'.$key;
            if($audio&&$bot->ui->definition('text','audio_'.$key)) $key='audio_'.$key;
            $notice=$bot->text($bot->ui->definition('text',$key)?$key:'failed');
            try { $bot->jobMessage($job,$notice); } catch(Throwable) {}
        } finally {
            $timings['total_ms']=(int)round((hrtime(true)-$started)/1e6);
            error_log('Job '.$job['id'].' timings '.json_encode($timings,JSON_THROW_ON_ERROR));
            Download::clean($folder);
        }
    }

    private function instagramDelivery(array $job,array $media,Bot $bot): void
    {
        if(count($media)!==1) throw new MediaFailure('format','Expected one Instagram file');
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) throw new MediaFailure('restricted','Instagram delivery no longer authorized');
        $item=$media[0];
        $bot->jobMessage($job,$bot->text('instagram_uploading',['sent'=>0,'count'=>1]));
        if($this->config->api==='http://ciro-telegram-api:8081') Download::ensureSpace('/telegram-api',filesize($item['path'])+268435456);
        $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$bot->ui->fitCaption($bot->text('instagram_caption',['index'=>1,'count'=>1,'url'=>$job['url']])),'parse_mode'=>'HTML'];
        if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
        if($item['type']==='video') $params+=['width'=>$item['width'],'height'=>$item['height'],'duration'=>$item['duration'],'supports_streaming'=>true];
        $button=$bot->ui->button('source_instagram'); $button['url']=$job['url'];
        $file=new \CURLFile($item['path'],$item['type']==='photo'?'image/jpeg':'video/mp4',$item['type']==='photo'?'photo.jpg':'video.mp4');
        $this->api->call($item['type']==='photo'?'sendPhoto':'sendVideo',$params+[$item['type']=>$file,'reply_markup'=>['inline_keyboard'=>[[$button]]]]);
    }
}
