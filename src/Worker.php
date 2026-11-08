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
        $config->musicCopy=$store->get('music.mp3','0')!=='1';
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
        $label=$gallery?$service:($music??($collection!==null?'menu':($job['phase']==='batch'?'batch':'youtube')));
        $started=hrtime(true); $timings=[];
        try {
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                if($gallery) (new GalleryPages($config,$store,$api,$service))->clean($job);
                $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
            }
            if($job['phase']==='batch') {
                $this->musicBatch($job,$bot,$folder,$timings,$stage);
                return;
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
            try { $store->pushError($label,$e instanceof MediaFailure?$e->reason:($stage==='upload'?'upload':'process'),$store->get('last_job_sources')); } catch(Throwable) {}
            $key=$e instanceof MediaFailure?'error_'.$e->reason:($stage==='upload'?'error_upload':'failed');
            if($gallery) $key=$service.'_'.$key;
            elseif($music!==null) $key=$music.'_'.$key;
            if($audio&&$bot->ui->definition('text','audio_'.$key)) $key='audio_'.$key;
            $notice=$bot->text($job['phase']==='batch'?'batch_failed':($collection!==null?'menu_failed':($bot->ui->definition('text',$key)?$key:($music!==null?$music.'_failed':'failed'))));
            try { $bot->jobMessage($job,$notice); } catch(Throwable) {}
        } finally {
            $timings['total_ms']=(int)round((hrtime(true)-$started)/1e6);
            // A finished request is counted for the status report; a quality menu or a stopped batch is not a result yet.
            try {
                $end=$store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn();
                if(in_array($end,['done','failed'],true)&&!($job['phase']==='inspect'&&$collection===null)) $store->record($label,$end==='done',$timings['total_ms']);
            } catch(Throwable) {}
            error_log('Job '.$job['id'].' timings '.json_encode($timings,JSON_THROW_ON_ERROR));
            Download::clean($folder);
        }
    }

    /**
     * Spotify or SoundCloud track: find the audio, tag it with the real title, artists and cover and send it
     * as one audio message on the same status message. Texts and settings are named after the service.
     */
    /** One track's tagged audio: from its address, or from details listed by Deezer or iTunes. */
    private function prepareTrack(string $service,array $track,string $folder): array
    {
        if($service==='soundcloud') return ($this->soundcloud??new SoundCloud($this->config))->fetch((string)$track['u'],$folder);
        $spotify=$this->spotify??new Spotify($this->config);
        return isset($track['m'])?$spotify->fromDetails($track['m'],$folder):$spotify->fetch((string)$track['u'],$folder);
    }
    /** One audio message with the service's caption and, when turned on, its source button. */
    private function sendTrack(array $job,Bot $bot,string $service,array $media,string $source): void
    {
        $config=$this->config;
        if($config->api==='http://ciro-telegram-api:8081') Download::ensureSpace('/telegram-api',(int)filesize($media['path'])+268435456);
        $caption=$bot->ui->fitCaption($bot->text($service.'_caption',['title'=>mb_substr($media['title'],0,100),'artist'=>mb_substr($media['performer'],0,150),
            'year'=>$media['year'],'duration'=>Ui::duration($media['length']),'url'=>$source]));
        $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$caption,'parse_mode'=>'HTML','duration'=>$media['duration'],
            'title'=>mb_substr($media['title'],0,100),'performer'=>mb_substr($media['performer'],0,100),
            'audio'=>new \CURLFile($media['path'],$media['mime']??'audio/mpeg',$media['name'])];
        if($media['thumbnail']!==null) $params['thumbnail']=new \CURLFile($media['thumbnail'],'image/jpeg','cover.jpg');
        $rows=$bot->ui->gallerySourceRows($service,$media['page']??$source);
        if($rows) $params['reply_markup']=['inline_keyboard'=>$rows];
        if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
        $this->api->call('sendAudio',$params);
    }

    /**
     * Spotify or SoundCloud track: find the audio, tag it with the real title, artists and cover and send it
     * as one audio message on the same status message. Texts and settings are named after the service.
     */
    private function musicDelivery(array $job,Bot $bot,string $folder,array &$timings,string &$stage,string $service,?array $track=null): void
    {
        $config=$this->config; $store=$this->store;
        Download::ensureSpace($config->storage.'/downloads');
        $bot->jobMessage($job,$bot->text($service.'_downloading'));
        $started=hrtime(true);
        $media=$this->prepareTrack($service,$track!==null?['m'=>$track]:['u'=>$job['url']],$folder);
        $timings['prepare_ms']=(int)round((hrtime(true)-$started)/1e6);
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
        }
        $stage='upload';
        $bot->jobMessage($job,$bot->text($service.'_uploading'));
        $uploadStarted=hrtime(true);
        $this->sendTrack($job,$bot,$service,$media,$job['url']);
        $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
        $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
        try { $bot->finishJob($job); }
        catch(Throwable) { error_log(ucfirst($service).' audio delivered; status cleanup unavailable'); }
    }

    private const PARALLEL=3;
    /** Prepares up to PARALLEL tracks of a batch at the same time, each in its own process (downloads wait on the network). */
    private function prepareWave(array $indexes,array $tracks,string $service,string $dir): void
    {
        $children=[];
        foreach($indexes as $index) {
            $pid=pcntl_fork();
            if($pid===0) {
                // The child shares nothing with the database; it only reads the network and writes its files.
                try {
                    $work=$dir.'/work-'.$index; mkdir($work,0700);
                    $media=$this->prepareTrack($service,$tracks[$index],$work);
                    $extension=pathinfo($media['path'],PATHINFO_EXTENSION);
                    rename($media['path'],$dir.'/'.$index.'.'.$extension);
                    if($media['thumbnail']!==null) rename($media['thumbnail'],$dir.'/'.$index.'.jpg');
                    $meta=array_diff_key($media,['path'=>1,'thumbnail'=>1])+['extension'=>$extension,'cover'=>$media['thumbnail']!==null];
                    file_put_contents($dir.'/'.$index.'.json',json_encode($meta,JSON_THROW_ON_ERROR));
                } catch(Throwable $e) {
                    error_log('Batch track '.($index+1).' skipped: '.($e instanceof MediaFailure?$e->reason:get_class($e)));
                    file_put_contents($dir.'/'.$index.'.fail',$e instanceof MediaFailure?$e->reason:'process');
                }
                Download::clean($dir.'/work-'.$index);
                exit(0);
            }
            if($pid>0) $children[]=$pid;
        }
        foreach($children as $pid) pcntl_waitpid($pid,$status);
    }

    /**
     * A whole album, playlist or set. Every track is prepared first (three at a time, kept in a folder so a stopped run
     * continues where it was), then all of them are sent together: one media group per ten tracks with a single caption,
     * or ZIP files. Stopping sends nothing and returns to the menu with a "continue" button; after delivery the status
     * message offers a way back that deletes what was sent.
     */
    private function musicBatch(array $job,Bot $bot,string $folder,array &$timings,string &$stage): void
    {
        $config=$this->config; $store=$this->store;
        $batch=json_decode((string)$job['options'],true)['batch']??null;
        if(!is_array($batch)||!in_array($batch['mode']??'',['each','zip'],true)||!in_array($batch['service']??'',musicServices(),true)||!is_array($batch['tracks']??null)||!preg_match('/^\d+-[a-f0-9]{8}$/D',(string)($batch['key']??''))) throw new MediaFailure('format','Invalid batch request');
        $service=$batch['service']; $zip=$batch['mode']==='zip'; $title=mb_substr((string)($batch['title']??''),0,100); $menuId=(int)$batch['menu'];
        $tracks=array_values(array_slice(array_values(array_filter($batch['tracks'],fn($track)=>is_array($track)&&(isset($track['u'])||isset($track['m'])))),0,MusicMenu::BATCH_MAX));
        $total=count($tracks);
        $dir=$config->storage.'/music-batches/'.$batch['key'];
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)) throw new MediaFailure('storage','Cannot create the batch folder');
        $menus=new MusicMenu($bot,$this->catalog,$this->soundcloud);
        $stop=[[$bot->ui->button('batch_stop','mb:'.(int)$job['id'])]];
        $started=hrtime(true); $stopped=false;
        $pending=fn()=>array_values(array_filter(array_keys($tracks),fn($i)=>!is_file($dir.'/'.$i.'.json')&&!is_file($dir.'/'.$i.'.fail')));
        while($todo=$pending()) {
            if($store->get('batch.stop.'.(int)$job['id'])==='1') { $stopped=true; break; }
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
            }
            Download::ensureSpace($config->storage.'/downloads');
            $bot->jobMessage($job,$bot->text('batch_progress',['title'=>$title,'done'=>(string)($total-count($todo)),'total'=>(string)$total,'failed'=>(string)count(glob($dir.'/*.fail')?:[])]),$stop);
            $this->prepareWave(array_slice($todo,0,self::PARALLEL),$tracks,$service,$dir);
        }
        $store->query('DELETE FROM settings WHERE key=?',['batch.stop.'.(int)$job['id']]);
        $good=array_values(array_filter(array_keys($tracks),fn($i)=>is_file($dir.'/'.$i.'.json')));
        $failed=$total-count($good)-count($pending());
        $timings['prepare_ms']=(int)round((hrtime(true)-$started)/1e6); $timings['tracks']=count($good);
        if($stopped) {
            // Nothing is sent. The menu comes back with a way to continue, and what is ready stays on disk.
            $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
            $menus->paused($menuId,$batch,$total-count($pending()),$total);
            return;
        }
        if(!$good) throw new MediaFailure('format','No track of the list could be prepared');
        $stage='upload'; $uploadStarted=hrtime(true);
        $bot->jobMessage($job,$bot->text('batch_sending',['title'=>$title,'total'=>(string)count($good)]));
        $items=[];
        foreach($good as $position=>$i) {
            $meta=json_decode((string)file_get_contents($dir.'/'.$i.'.json'),true);
            $items[]=$meta+['path'=>$dir.'/'.$i.'.'.$meta['extension'],'thumbnail'=>$meta['cover']?$dir.'/'.$i.'.jpg':null,'order'=>$position+1];
        }
        $back=[[$bot->ui->button('batch_back','mm:'.$menuId.':r')]];
        $ids=$zip?$this->sendZips($job,$bot,$items,$title,$folder,$failed,$back):$this->sendGroups($job,$bot,$items,$title,$failed,$back);
        $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
        $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
        // The last message carries the way back (when Telegram accepted it), so the old menu is no longer needed.
        $menus->delivered($menuId,$batch,$ids['ids'],count($items),$failed,$ids['marked']?end($ids['ids']):0);
    }

    /**
     * The caption of one group: a bold quote with the title, a quote with one "• performer - title" line per track
     * (as many as fit Telegram's limit, then "and N more") and, on the last group, a note about tracks that were not found.
     */
    private function groupCaption(Bot $bot,string $key,array $values,array $items,string $note): string
    {
        $ui=$bot->ui; $plain=fn(string $html)=>html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $render=fn(string $tracks)=>$ui->text($key,['tracks'=>$tracks,'note'=>$note]+$values,['tracks','note']);
        $room=1000-TextFormat::length($plain($render('')));
        $lines=[];
        foreach($items as $item) {
            $name=trim((string)($item['performer']??'')) !== '' ? trim((string)$item['performer']).' - '.$item['title'] : (string)$item['title'];
            $lines[]='• '.(mb_strlen($name)>64?mb_substr($name,0,63).'…':$name);
        }
        $need=fn(array $list)=>TextFormat::length(implode("\n",$list));
        if($need($lines)>$room) {
            $kept=[];
            foreach($lines as $n=>$line) {
                $more='• '.$ui->plainWith('batch_more',['n'=>(string)(count($lines)-$n)]);
                if($need(array_merge($kept,[$line,$more]))>$room) { $kept[]=$more; break; }
                $kept[]=$line;
            }
            $lines=$kept;
        }
        return $render(implode("\n",array_map([Ui::class,'escape'],$lines)));
    }
    private function failedNote(Bot $bot,int $failed): string { return $failed>0?"\n".$bot->text('batch_failed_note',['failed'=>(string)$failed]):''; }
    /**
     * Sends the prepared tracks as media groups of at most ten audios (balanced sizes). Every group has one caption on its
     * last track; the very last message gets the way back. @return array{ids:list<int>,marked:bool}
     */
    private function sendGroups(array $job,Bot $bot,array $items,string $title,int $failed,array $back): array
    {
        $parts=(int)ceil(count($items)/10); $chunks=array_chunk($items,(int)ceil(count($items)/$parts)); $ids=[]; $marked=false;
        foreach($chunks as $part=>$chunk) {
            $last=$part===count($chunks)-1;
            $caption=$this->groupCaption($bot,'batch_caption',['title'=>$title,'count'=>(string)count($items),'part'=>(string)($part+1),'parts'=>(string)count($chunks)],$chunk,$last?$this->failedNote($bot,$failed):'');
            $sent=$this->sendMediaGroup($job,$bot,'audio',$chunk,$caption,$last?$back:[]);
            $ids=array_merge($ids,$sent['ids']); $marked=$sent['marked'];
        }
        return ['ids'=>$ids,'marked'=>$marked];
    }
    /** ZIP files of the prepared tracks, each below the upload limit, sent as groups of documents. @return array{ids:list<int>,marked:bool} */
    private function sendZips(array $job,Bot $bot,array $items,string $title,string $folder,int $failed,array $back): array
    {
        $limit=max(262144,$this->config->maxBytes-2097152); $archives=[]; $current=[]; $bytes=0;
        $base=trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u',' ',$title)??'')?:'music';
        $close=function() use(&$current,&$bytes,&$archives,$folder) {
            if(!$current) return;
            $file=$folder.'/part-'.(count($archives)+1).'.zip'; $zip=new \ZipArchive();
            if($zip->open($file,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true) throw new MediaFailure('storage','Cannot create the ZIP file');
            foreach($current as $item) { $name=sprintf('%02d - %s',$item['order'],$item['name']); $zip->addFile($item['path'],$name); $zip->setCompressionName($name,\ZipArchive::CM_STORE); }
            $zip->close();
            $archives[]=['path'=>$file,'count'=>count($current),'mime'=>'application/zip']; $current=[]; $bytes=0;
        };
        foreach($items as $item) {
            $size=(int)filesize($item['path']);
            if($current&&$bytes+$size>$limit) $close();
            $current[]=$item; $bytes+=$size;
        }
        $close();
        $parts=count($archives);
        foreach($archives as $n=>$archive) $archives[$n]['name']=mb_substr($base,0,80).($parts>1?' ('.($n+1).')':'').'.zip';
        $ids=[]; $marked=false; $chunks=array_chunk($archives,(int)ceil($parts/max(1,(int)ceil($parts/10))));
        foreach($chunks as $n=>$chunk) {
            $last=$n===count($chunks)-1;
            $caption=$this->groupCaption($bot,'batch_zip_caption',['title'=>$title,'count'=>(string)count($items),'parts'=>(string)$parts],$items,$last?$this->failedNote($bot,$failed):'');
            $sent=$this->sendMediaGroup($job,$bot,'document',$chunk,$caption,$last?$back:[]);
            $ids=array_merge($ids,$sent['ids']); $marked=$sent['marked'];
        }
        foreach($archives as $archive) @unlink($archive['path']);
        return ['ids'=>$ids,'marked'=>$marked];
    }
    /**
     * The caption goes on the last item of a group (so it reads under the whole list). A single item is sent on its own,
     * since a group needs two. $markup rows are attached to the last message: Telegram takes them on a single message
     * directly and on a group through an edit. @return array{ids:list<int>,marked:bool}
     */
    private function sendMediaGroup(array $job,Bot $bot,string $type,array $items,string $caption,array $markup=[]): array
    {
        $topic=(int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0?['message_thread_id'=>(int)$job['thread_id']]:[];
        if(count($items)===1) {
            $item=$items[0]; $file=new \CURLFile($item['path'],$item['mime']??'application/octet-stream',$item['name']);
            $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$caption,'parse_mode'=>'HTML']+$topic+($markup?['reply_markup'=>['inline_keyboard'=>$markup]]:[]);
            if($type==='audio') $params+=['audio'=>$file,'title'=>mb_substr($item['title'],0,100),'performer'=>mb_substr($item['performer'],0,100),'duration'=>$item['duration']]+($item['thumbnail']!==null?['thumbnail'=>new \CURLFile($item['thumbnail'],'image/jpeg','cover.jpg')]:[]);
            else $params['document']=$file;
            $result=$this->api->call($type==='audio'?'sendAudio':'sendDocument',$params);
            return ['ids'=>[(int)($result['message_id']??0)],'marked'=>$markup!==[]];
        }
        $media=[]; $params=['chat_id'=>(int)$job['chat_id']]+$topic;
        foreach($items as $n=>$item) {
            $params['f'.$n]=new \CURLFile($item['path'],$item['mime']??'application/octet-stream',$item['name']);
            $entry=['type'=>$type,'media'=>'attach://f'.$n];
            if($type==='audio') {
                $entry+=['title'=>mb_substr($item['title'],0,100),'performer'=>mb_substr($item['performer'],0,100),'duration'=>$item['duration']];
                if($item['thumbnail']!==null) { $params['t'.$n]=new \CURLFile($item['thumbnail'],'image/jpeg','cover.jpg'); $entry['thumbnail']='attach://t'.$n; }
            }
            if($n===count($items)-1) $entry+=['caption'=>$caption,'parse_mode'=>'HTML'];
            $media[]=$entry;
        }
        $params['media']=$media;
        $result=$this->api->call('sendMediaGroup',$params);
        $ids=array_values(array_filter(array_map(fn($message)=>(int)($message['message_id']??0),is_array($result)?$result:[])));
        $marked=false;
        if($markup&&$ids) {
            try { $this->api->call('editMessageReplyMarkup',['chat_id'=>(int)$job['chat_id'],'message_id'=>end($ids),'reply_markup'=>['inline_keyboard'=>$markup]]); $marked=true; }
            catch(Throwable $e) { error_log('Music batch: the back button could not be attached: '.get_class($e)); }
        }
        return ['ids'=>$ids,'marked'=>$marked];
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
