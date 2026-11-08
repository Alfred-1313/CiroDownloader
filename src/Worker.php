<?php
declare(strict_types=1);
namespace Zero;
use Throwable;
require_once __DIR__.'/Instagram.php';
require_once __DIR__.'/Pinterest.php';
require_once __DIR__.'/TikTok.php';
require_once __DIR__.'/Twitter.php';
require_once __DIR__.'/Articles.php';
require_once __DIR__.'/Spotify.php';
require_once __DIR__.'/SoundCloud.php';
require_once __DIR__.'/MusicMenu.php';

final class WorkerStopped extends \RuntimeException {}

final class Worker
{
    public function __construct(private Config $config,private Store $store,private Api $api,private ?Articles $articles=null,private ?Spotify $spotify=null,private ?SoundCloud $soundcloud=null,private ?MusicCatalog $catalog=null) {}

    public function recover(array $job): void
    {
        // A completed page can be queued again before its old child is reaped.
        // Recovery belongs only to the exact claim that child was processing.
        $changed=$this->store->query("UPDATE jobs SET status='failed' WHERE id=? AND status='running' AND run_token=?",[$job['id'],(int)($job['run_token']??0)])->rowCount();
        if(!$changed&&(int)$this->store->query('SELECT run_token FROM jobs WHERE id=?',[$job['id']])->fetchColumn()!==(int)($job['run_token']??0)) return;
        if($changed) {
            $bot=new Bot($this->config,$this->store,$this->api);
            try { $bot->jobMessage($job,$bot->text('restarted')); } catch(Throwable) {}
            if($service=galleryService($job['url'])) (new GalleryPages($this->config,$this->store,$this->api,$service))->clean($job);
        }
        foreach(glob($this->config->storage.'/downloads/job-'.(int)$job['id'].'-*')?:[] as $folder) Download::clean($folder);
    }

    public function process(array $job): void
    {
        $config=$this->config; $store=$this->store; $api=$this->api;
        $config->cookieFallback=$store->get('cookies.fallback','0')==='1';
        $bot=new Bot($config,$store,$api); $downloader=new Download($config);
        $downloads=$config->storage.'/downloads';
        $folder=$downloads.'/job-'.$job['id'].'-'.bin2hex(random_bytes(4));
        mkdir($folder,0700);
        $stage='download'; $audio=$job['phase']==='audio';
        $service=galleryService($job['url']); $gallery=$service!==null; $music=musicService($job['url']);
        // A track chosen from a menu may be known only by its details; those jobs name their service in the phase.
        $track=json_decode((string)($job['options']??'{}'),true)['track']??null;
        if($music===null&&is_array($track)&&in_array($job['phase'],musicServices(),true)) $music=$job['phase'];
        if($music!==null&&$job['phase']!==$music) $music=null;
        $collection=$job['phase']==='inspect'?collectionUrl($job['url']):null;
        $started=hrtime(true); $timings=[];
        try {
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                if($gallery) (new GalleryPages($config,$store,$api,$service))->clean($job);
                $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
            }
            if($music!==null) {
                $this->musicDelivery($job,$bot,$folder,$timings,$stage,$music,is_array($track)?$track:null);
                return;
            }
            if($gallery) {
                $pages=new GalleryPages($config,$store,$api,$service);
                if($job['phase']===$service.'_page') {
                    $stage='upload'; $uploadStarted=hrtime(true);
                    $pages->deliver($job,$bot);
                    $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                    return;
                }
                $bot->jobMessage($job,$bot->text($service.'_downloading'));
                $inspectionStarted=hrtime(true);
                $media=GalleryMedia::forService($config,$service)->fetch($job['url'],$folder);
                $timings['prepare_ms']=(int)round((hrtime(true)-$inspectionStarted)/1e6);
                $stage='upload'; $uploadStarted=hrtime(true);
                if(count($media)>1&&$store->get($service.'.carousel_mode','pages')==='article') {
                    $bot->jobMessage($job,$bot->text($service.'_article_building'));
                    $url=($this->articles??new Articles($config))->publish($media,$job['url'],$bot->ui,fn()=>$bot->allowed((int)$job['user_id'],(int)$job['chat_id']));
                    $button=$bot->ui->button('open_'.$service.'_article'); $button['url']=$url;
                    $bot->jobMessage($job,$bot->text($service.'_article_ready',['count'=>count($media),'page_url'=>$url,'url'=>$job['url']]),[[$button]]);
                    $store->query("UPDATE jobs SET status='done',options=? WHERE id=?",[json_encode(['article_url'=>$url],JSON_THROW_ON_ERROR),$job['id']]);
                    $timings['publish_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                    return;
                }
                if(count($media)>1) {
                    $job=$pages->prepare($job,$media);
                    $pages->deliver($job,$bot);
                    $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                    return;
                }
                $this->galleryDelivery($job,$media,$bot,$service);
                $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
                try { $bot->finishJob($job); } catch(Throwable) { error_log(ucfirst($service).' media delivered; status cleanup unavailable'); }
                return;
            }
            if($collection!==null) {
                $stage='inspect';
                (new MusicMenu($bot,$this->catalog,$this->soundcloud))->open($job,$collection,$folder);
                $timings['menu_ms']=(int)round((hrtime(true)-$started)/1e6);
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
            if($gallery) (new GalleryPages($config,$store,$api,$service))->clean($job);
            $detail=diagnosticMessage($e->getMessage());
            error_log('Job '.$job['id'].' failed ['.$stage.']: '.get_class($e).': '.$detail);
            $store->set('last_job_error','Job '.$job['id'].' ['.$stage.']: '.$detail);
            $store->set('last_job_sources',preg_match('/\[((?:[a-z]+=[a-z]+(?:, )?){1,5})\]/',$detail,$sources)===1?$sources[1]:'');
            $store->set('last_job_error_reason',$e instanceof MediaFailure?$e->reason:($stage==='upload'?'upload':'process'));
            $store->set('last_job_service',$gallery?$service:($music??($collection!==null?'menu':'youtube')));
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            $key=$e instanceof MediaFailure?'error_'.$e->reason:($stage==='upload'?'error_upload':'failed');
            if($gallery) $key=$service.'_'.$key;
            elseif($music!==null) $key=$music.'_'.$key;
            if($audio&&$bot->ui->definition('text','audio_'.$key)) $key='audio_'.$key;
            $notice=$bot->text($collection!==null?'menu_failed':($bot->ui->definition('text',$key)?$key:($music!==null?$music.'_failed':'failed')));
            try { $bot->jobMessage($job,$notice); } catch(Throwable) {}
        } finally {
            $timings['total_ms']=(int)round((hrtime(true)-$started)/1e6);
            error_log('Job '.$job['id'].' timings '.json_encode($timings,JSON_THROW_ON_ERROR));
            Download::clean($folder);
        }
    }

    /**
     * Spotify or SoundCloud track: find the audio, tag it with the real title, artists and cover and send it
     * as one audio message on the same status message. Texts and settings are named after the service.
     */
    private function musicDelivery(array $job,Bot $bot,string $folder,array &$timings,string &$stage,string $service,?array $track=null): void
    {
        $config=$this->config; $store=$this->store; $api=$this->api;
        Download::ensureSpace($config->storage.'/downloads');
        $bot->jobMessage($job,$bot->text($service.'_downloading'));
        $started=hrtime(true);
        $media=$service==='spotify'
            ?($track!==null?($this->spotify??new Spotify($config))->fromDetails($track,$folder):($this->spotify??new Spotify($config))->fetch($job['url'],$folder))
            :($this->soundcloud??new SoundCloud($config))->fetch($job['url'],$folder);
        $timings['prepare_ms']=(int)round((hrtime(true)-$started)/1e6);
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
        }
        $stage='upload';
        if($config->api==='http://ciro-telegram-api:8081') Download::ensureSpace('/telegram-api',(int)filesize($media['path'])+268435456);
        $bot->jobMessage($job,$bot->text($service.'_uploading'));
        $caption=$bot->ui->fitCaption($bot->text($service.'_caption',['title'=>mb_substr($media['title'],0,100),'artist'=>mb_substr($media['performer'],0,150),
            'year'=>$media['year'],'duration'=>Ui::duration($media['length']),'url'=>$job['url']]));
        $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$caption,'parse_mode'=>'HTML','duration'=>$media['duration'],
            'title'=>mb_substr($media['title'],0,100),'performer'=>mb_substr($media['performer'],0,100),
            'audio'=>new \CURLFile($media['path'],'audio/mpeg',$media['name'])];
        if($media['thumbnail']!==null) $params['thumbnail']=new \CURLFile($media['thumbnail'],'image/jpeg','cover.jpg');
        $rows=$bot->ui->gallerySourceRows($service,$media['page']??$job['url']);
        if($rows) $params['reply_markup']=['inline_keyboard'=>$rows];
        if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
        $uploadStarted=hrtime(true);
        $api->call('sendAudio',$params);
        $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
        $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
        try { $bot->finishJob($job); }
        catch(Throwable) { error_log(ucfirst($service).' audio delivered; status cleanup unavailable'); }
    }

    private function galleryDelivery(array $job,array $media,Bot $bot,string $service): void
    {
        if(count($media)!==1) throw new MediaFailure('format','Expected one gallery media file');
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) throw new MediaFailure('restricted','Gallery delivery no longer authorized');
        $item=$media[0];
        $bot->jobMessage($job,$bot->text($service.'_uploading',['sent'=>0,'count'=>1]));
        if($this->config->api==='http://ciro-telegram-api:8081') Download::ensureSpace('/telegram-api',filesize($item['path'])+268435456);
        $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$bot->ui->fitCaption($bot->text($service.'_caption',['index'=>1,'count'=>1,'url'=>$job['url']])),'parse_mode'=>'HTML'];
        if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
        if($item['type']==='video') $params+=['width'=>$item['width'],'height'=>$item['height'],'duration'=>$item['duration'],'supports_streaming'=>true];
        $file=new \CURLFile($item['path'],$item['type']==='photo'?'image/jpeg':'video/mp4',$item['type']==='photo'?'photo.jpg':'video.mp4');
        $this->api->call($item['type']==='photo'?'sendPhoto':'sendVideo',$params+[$item['type']=>$file,'reply_markup'=>['inline_keyboard'=>$bot->ui->gallerySourceRows($service,$job['url'])]]);
    }
}
