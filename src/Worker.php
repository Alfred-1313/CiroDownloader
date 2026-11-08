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

    /**
     * Prepares tracks in a pool: up to MUSIC_PARALLEL child processes run at once and the next track starts the moment
     * any of them ends (a slow track no longer holds the others back). $after runs when a child ends and returns false
     * to stop starting new tracks; the running ones always finish.
     */
    private function preparePool(array $indexes,array $tracks,string $service,string $dir,callable $after): void
    {
        $slots=max(1,$this->config->musicParallel); $running=[]; $go=true;
        while($indexes||$running) {
            while($go&&$indexes&&count($running)<$slots) {
                $index=array_shift($indexes); $pid=pcntl_fork();
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
                if($pid>0) $running[$pid]=$index;
            }
            if(!$running) break;
            $ended=pcntl_wait($status);
            if($ended>0) unset($running[$ended]);
            elseif($ended<0) break;
            if($go&&$after()===false) $go=false;
        }
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
        $started=hrtime(true); $stopped=false; $denied=false; $shown=0.0;
        $pending=fn()=>array_values(array_filter(array_keys($tracks),fn($i)=>!is_file($dir.'/'.$i.'.json')&&!is_file($dir.'/'.$i.'.fail')));
        // The names of the tracks that failed so far, so the person sees which ones are missing.
        $failedNames=function() use($dir,$tracks,$bot): array {
            $names=[]; foreach(glob($dir.'/*.fail')?:[] as $file) { $i=(int)basename($file,'.fail'); $names[$i]=(string)($tracks[$i]['n']??''); }
            ksort($names); return array_values(array_filter($names,fn($name)=>$name!==''));
        };
        $progress=function(bool $force=false) use($bot,$job,$title,$total,$stop,$pending,$failedNames,$dir,&$shown): void {
            // Telegram allows about one edit a second per chat; the last state is always shown.
            if(!$force&&microtime(true)-$shown<1.2) return;
            $shown=microtime(true); $failed=$failedNames();
            $bot->jobMessage($job,$bot->text('batch_progress',['title'=>$title,'done'=>(string)($total-count($pending())),'total'=>(string)$total,'failed'=>(string)(count(glob($dir.'/*.fail')?:[])),'names'=>$this->failedBlock($bot,$failed)],['names']),$stop);
        };
        $gate=function() use($store,$job,$bot,&$stopped,&$denied): bool {
            if($store->get('batch.stop.'.(int)$job['id'])==='1') { $stopped=true; return false; }
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) { $denied=true; return false; }
            return true;
        };
        if($todo=$pending()) {
            if($gate()) {
                Download::ensureSpace($config->storage.'/downloads');
                $progress(true);
                $this->preparePool($todo,$tracks,$service,$dir,function() use($gate,$progress): bool { $progress(); return $gate(); });
            }
        }
        if($denied) {
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
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
        $bot->jobMessage($job,$bot->text('batch_sending',['title'=>$title,'total'=>(string)count($good),'failed'=>(string)$failed,'names'=>$this->failedBlock($bot,$failedNames())],['names']));
        $items=[];
        foreach($good as $position=>$i) {
            $meta=json_decode((string)file_get_contents($dir.'/'.$i.'.json'),true);
            $items[]=$meta+['path'=>$dir.'/'.$i.'.'.$meta['extension'],'thumbnail'=>$meta['cover']?$dir.'/'.$i.'.jpg':null,'order'=>$position+1];
        }
        $back=[[$bot->ui->button('batch_back','mm:'.$menuId.':r')]];
        $ids=$zip?$this->sendZips($job,$bot,$items,$title,$folder,$back):$this->sendTracks($job,$bot,$items,$title,$back);
        $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
        $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
        // The last message (a message of its own, so its button always shows) carries the way back; the old menu is deleted.
        $menus->delivered($menuId,$batch,$ids,count($items),$failed,(int)end($ids));
    }

    /** A quote with the names of the tracks that could not be prepared (at most six), or nothing. */
    private function failedBlock(Bot $bot,array $names): string
    {
        if(!$names) return '';
        $shown=array_slice($names,0,6); $lines=array_map(fn($name)=>'• '.Ui::escape(mb_strlen($name)>60?mb_substr($name,0,59).'…':$name),$shown);
        if(count($names)>6) $lines[]='• '.Ui::escape($bot->ui->plainWith('batch_more',['n'=>(string)(count($names)-6)]));
        return "\n<blockquote>".implode("\n",$lines).'</blockquote>';
    }

    /**
     * The caption: the title in bold, an empty line, and a collapsed (expandable) quote with one "• title" line per track,
     * as many as fit Telegram's limit and then "and N more". Names only: no artist, no failures.
     */
    private function listCaption(Bot $bot,string $key,array $values,array $items): string
    {
        $ui=$bot->ui; $plain=fn(string $html)=>html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $render=fn(string $tracks)=>$ui->text($key,['tracks'=>$tracks]+$values,['tracks']);
        $room=1000-TextFormat::length($plain($render('')));
        $lines=[];
        foreach($items as $item) { $name=trim((string)$item['title']); $lines[]='• '.(mb_strlen($name)>80?mb_substr($name,0,79).'…':$name); }
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
    /**
     * Sends the prepared tracks. All but the last go out together as media groups of at most ten audios (sizes balanced);
     * the last one is a message of its own that carries the caption with the track list and the way back, because
     * Telegram shows buttons only under a message that is not part of a group. @return list<int> message IDs in order
     */
    private function sendTracks(array $job,Bot $bot,array $items,string $title,array $back): array
    {
        $final=array_pop($items); $ids=[];
        if(count($items)===1) $ids=array_merge($ids,$this->sendMediaGroup($job,$bot,'audio',$items,'',[]));
        elseif($items) {
            $parts=(int)ceil(count($items)/10);
            foreach(array_chunk($items,(int)ceil(count($items)/$parts)) as $chunk) $ids=array_merge($ids,$this->sendMediaGroup($job,$bot,'audio',$chunk,'',[]));
        }
        $all=array_merge($items,[$final]);
        $caption=$this->listCaption($bot,'batch_caption',['title'=>$title,'count'=>(string)count($all)],$all);
        return array_merge($ids,$this->sendMediaGroup($job,$bot,'audio',[$final],$caption,$back));
    }
    /** ZIP files of the prepared tracks, each below the upload limit. The last file is a message of its own with the caption and the way back. @return list<int> */
    private function sendZips(array $job,Bot $bot,array $items,string $title,string $folder,array $back): array
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
        foreach(array_keys($archives) as $n) $archives[$n]['name']=mb_substr($base,0,80).($parts>1?' ('.($n+1).')':'').'.zip';
        $final=array_pop($archives); $ids=[];
        if(count($archives)===1) $ids=array_merge($ids,$this->sendMediaGroup($job,$bot,'document',$archives,'',[]));
        elseif($archives) {
            $groups=(int)ceil(count($archives)/10);
            foreach(array_chunk($archives,(int)ceil(count($archives)/$groups)) as $chunk) $ids=array_merge($ids,$this->sendMediaGroup($job,$bot,'document',$chunk,'',[]));
        }
        $caption=$this->listCaption($bot,'batch_zip_caption',['title'=>$title,'count'=>(string)count($items),'parts'=>(string)$parts],$items);
        $ids=array_merge($ids,$this->sendMediaGroup($job,$bot,'document',[$final],$caption,$back));
        foreach(array_merge($archives,[$final]) as $archive) @unlink($archive['path']);
        return $ids;
    }
    /**
     * One message for a single item (with the caption and buttons when given), or one media group; a group takes no buttons
     * and no caption here. @return list<int> IDs of the messages Telegram created
     */
    private function sendMediaGroup(array $job,Bot $bot,string $type,array $items,string $caption,array $markup): array
    {
        $topic=(int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0?['message_thread_id'=>(int)$job['thread_id']]:[];
        if(count($items)===1) {
            $item=$items[0]; $file=new \CURLFile($item['path'],$item['mime']??'application/octet-stream',$item['name']);
            $params=['chat_id'=>(int)$job['chat_id']]+$topic+($caption!==''?['caption'=>$caption,'parse_mode'=>'HTML']:[])+($markup?['reply_markup'=>['inline_keyboard'=>$markup]]:[]);
            if($type==='audio') $params+=['audio'=>$file,'title'=>mb_substr($item['title'],0,100),'performer'=>mb_substr($item['performer'],0,100),'duration'=>$item['duration']]+($item['thumbnail']!==null?['thumbnail'=>new \CURLFile($item['thumbnail'],'image/jpeg','cover.jpg')]:[]);
            else $params['document']=$file;
            $result=$this->api->call($type==='audio'?'sendAudio':'sendDocument',$params);
            return [(int)($result['message_id']??0)];
        }
        $media=[]; $params=['chat_id'=>(int)$job['chat_id']]+$topic;
        foreach($items as $n=>$item) {
            $params['f'.$n]=new \CURLFile($item['path'],$item['mime']??'application/octet-stream',$item['name']);
            $entry=['type'=>$type,'media'=>'attach://f'.$n];
            if($type==='audio') {
                $entry+=['title'=>mb_substr($item['title'],0,100),'performer'=>mb_substr($item['performer'],0,100),'duration'=>$item['duration']];
                if($item['thumbnail']!==null) { $params['t'.$n]=new \CURLFile($item['thumbnail'],'image/jpeg','cover.jpg'); $entry['thumbnail']='attach://t'.$n; }
            }
            $media[]=$entry;
        }
        $params['media']=$media;
        $result=$this->api->call('sendMediaGroup',$params);
        return array_values(array_filter(array_map(fn($message)=>(int)($message['message_id']??0),is_array($result)?$result:[])));
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
