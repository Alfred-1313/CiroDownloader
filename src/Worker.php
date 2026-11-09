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
        $adult=pornhubUrl($job['url'])!==null;
        $label=$gallery?$service:($music??($collection!==null?'menu':($job['phase']==='batch'?'batch':($adult?'pornhub':'youtube'))));
        $started=hrtime(true); $timings=[];
        try {
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                if($gallery) (new GalleryPages($config,$store,$api,$service))->clean($job);
                $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
            }
            // Turned off, or no longer allowed for this person, since the link was accepted: nothing is fetched.
            if($adult&&!$bot->adultAllowed((int)$job['user_id'],(int)$job['chat_id'])) {
                $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->text('invalid')); return;
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
                    $sent=$pages->deliver($job,$bot);
                    $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                    $this->noteSpeed($service,$sent,$timings,0);
                    return;
                }
                $bot->jobMessage($job,$bot->text($service.'_downloading'));
                $inspectionStarted=hrtime(true);
                $media=GalleryMedia::forService($config,$service)->fetch($job['url'],$folder);
                $timings['prepare_ms']=(int)round((hrtime(true)-$inspectionStarted)/1e6);
                $stage='upload'; $uploadStarted=hrtime(true); $mediaBytes=self::bytesOf($media);
                if(count($media)>1&&$store->get($service.'.carousel_mode','pages')==='article') {
                    $bot->jobMessage($job,$bot->text($service.'_article_building'));
                    $url=($this->articles??new Articles($config))->publish($media,$job['url'],$bot->ui,fn()=>$bot->allowed((int)$job['user_id'],(int)$job['chat_id']));
                    $button=$bot->ui->button('open_'.$service.'_article'); $button['url']=$url;
                    $bot->jobMessage($job,$bot->text($service.'_article_ready',['count'=>count($media),'page_url'=>$url,'url'=>$job['url']]),[[$button]]);
                    $store->query("UPDATE jobs SET status='done',options=? WHERE id=?",[json_encode(['article_url'=>$url],JSON_THROW_ON_ERROR),$job['id']]);
                    $timings['publish_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                    // The upload here goes to the public file host, not to Telegram, so the article has its own line.
                    $this->noteSpeed('article',$mediaBytes,$timings,$mediaBytes,$timings['publish_ms']);
                    return;
                }
                if(count($media)>1) {
                    $job=$pages->prepare($job,$media);
                    $sent=$pages->deliver($job,$bot);
                    $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                    // Every slide was downloaded, but only the first one is uploaded now (the others when someone turns the page).
                    $this->noteSpeed($service,$sent,$timings,$mediaBytes);
                    return;
                }
                $this->galleryDelivery($job,$media,$bot,$service);
                $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                $this->noteSpeed($service,(int)@filesize((string)($media[0]['path']??'')),$timings);
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
                    if(!$adult) $store->set('youtube_probe_url',$job['url']);
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
            if($adult) {
                // Its own caption (title, people, categories, tags, views) and no link back to the site.
                $caption=$this->adultCaption($bot,$media,is_array($options['meta']??null)?$options['meta']:[],$audio,$bot->adultDeleteAfter((int)$job['chat_id']));
                $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$caption,'parse_mode'=>'HTML','duration'=>$media['duration']];
            } else {
                $caption=$bot->ui->mediaCaption($audio?'audio':'video',['title'=>mb_substr($media['title'],0,100),
                    'quality'=>$media['quality']??'','format'=>$media['format']??'','duration'=>Ui::duration($media['duration']),'url'=>$job['url']]);
                $link=$bot->ui->button($audio?'source_audio':'source_video'); $link['url']=$job['url'];
                $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$caption,'parse_mode'=>'HTML','duration'=>$media['duration'],
                    'reply_markup'=>['inline_keyboard'=>[[$link]]]];
            }
            if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
            $uploadStarted=hrtime(true);
            if($audio) $sent=$api->call('sendAudio',$params+['audio'=>new \CURLFile($media['path'],'audio/mpeg','audio.mp3'),
                'title'=>mb_substr($media['title'],0,100)]);
            else $sent=$api->call('sendVideo',$params+['video'=>new \CURLFile($media['path'],'video/mp4','video.mp4'),
                'supports_streaming'=>true,'width'=>$media['width'],'height'=>$media['height']]);
            // The filter for this kind of chat: the video is deleted again after a few seconds (kept in the database,
            // so a restart in between does not leave it in the chat).
            if($adult&&($after=$bot->adultDeleteAfter((int)$job['chat_id']))>0&&is_array($sent)) TimedDeletes::schedule($store,(int)$job['chat_id'],(int)($sent['message_id']??0),time()+$after);
            $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
            $this->noteSpeed($label,(int)@filesize($media['path']),$timings);
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
            $store->set('last_job_service',$gallery?$service:($music??($collection!==null?'menu':($adult?'pornhub':'youtube'))));
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            try { $store->pushError($label,$e instanceof MediaFailure?$e->reason:($stage==='upload'?'upload':'process'),$store->get('last_job_sources')); } catch(Throwable) {}
            $key=$e instanceof MediaFailure?'error_'.$e->reason:($stage==='upload'?'error_upload':'failed');
            if($gallery) $key=$service.'_'.$key;
            elseif($music!==null) $key=$music.'_'.$key;
            elseif($adult) $key=$bot->ui->definition('text','pornhub_'.$key)?'pornhub_'.$key:'failed';
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
    /** For the radio: the audio of one song prepared like any music download, from {"service":"spotify|soundcloud","u":ADDRESS} or {"service":..,"m":DETAILS}. */
    public function radioAudio(array $source,string $folder): array
    {
        $service=($source['service']??'')==='soundcloud'?'soundcloud':'spotify';
        if(isset($source['m'])&&is_array($source['m'])) return $this->prepareTrack($service,['m'=>$source['m']],$folder);
        if(!is_string($source['u']??null)) throw new MediaFailure('format','The radio song has no source');
        return $this->prepareTrack($service,['u'=>$source['u']],$folder);
    }
    /**
     * One audio message with the same caption as each track of a whole album (artist and title in bold, its length in a
     * quote). Rows: the source button when on, share (anyone, when inline mode is on), then $back (the way back to a
     * menu, its owner only). @return int the message ID
     */
    private function sendTrack(array $job,Bot $bot,string $service,array $media,string $source,string $album='',array $back=[],?array $radio=null): int
    {
        $config=$this->config;
        if($config->api==='http://ciro-telegram-api:8081') Download::ensureSpace('/telegram-api',(int)filesize($media['path'])+268435456);
        $title=mb_substr($media['title'],0,100);
        $caption=$bot->ui->fitCaption($bot->text($service.'_caption',['title'=>$title,'work'=>trim($album)!==''?mb_substr(trim($album),0,100):$title,'artist'=>mb_substr($media['performer'],0,150),
            'year'=>$media['year'],'duration'=>Ui::duration($media['length']),'url'=>$source]));
        $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$caption,'parse_mode'=>'HTML','duration'=>$media['duration'],
            'title'=>$title,'performer'=>mb_substr($media['performer'],0,100),
            'audio'=>new \CURLFile($media['path'],$media['mime']??'audio/mpeg',$media['name'])];
        if($media['thumbnail']!==null) $params['thumbnail']=new \CURLFile($media['thumbnail'],'image/jpeg','cover.jpg');
        $shares=new Shares($bot); $token=$shares->reserve();
        // Under the share button, the radio's play button when this chat (or this person) has a radio.
        $panel=new RadioPanel($bot); $play=$radio!==null&&$panel->available((int)$job['chat_id'],(int)$job['user_id'])?[$panel->row($radio,(string)$media['title'],(string)$media['performer'],(int)($media['length']??$media['duration']??0))]:[];
        $rows=array_merge($bot->ui->gallerySourceRows($service,$media['page']??$source),$token!==null?[$shares->row($token)]:[],$play,$back);
        if($rows) $params['reply_markup']=['inline_keyboard'=>$rows];
        if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
        $result=$this->api->call('sendAudio',$params);
        $shares->fill($token,$result,$caption);
        return (int)($result['message_id']??0);
    }
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
        $options=json_decode((string)($job['options']??'{}'),true)?:[];
        // A track chosen in a menu that closed gets the red way back to that menu, like a whole album.
        $menuId=(int)($options['menu']??0); $menus=new MusicMenu($bot,$this->catalog,$this->soundcloud);
        $back=$menuId>0&&$menus->owns($menuId,(int)$job['user_id'],(int)$job['chat_id'])?[[$bot->ui->button('batch_back','mm:'.$menuId.':r')]]:[];
        $audio=$this->sendTrack($job,$bot,$service,$media,$job['url'],(string)($options['album']??''),$back,['service'=>$service]+($track!==null?['m'=>$track]:['u'=>$job['url']]));
        $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
        $this->noteSpeed($service,(int)@filesize((string)($media['path']??'')),$timings);
        $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
        if($back) $menus->trackDelivered($menuId,(int)$job['user_id'],(int)$job['chat_id'],$audio);
        try { $bot->finishJob($job); }
        catch(Throwable) { error_log(ucfirst($service).' audio delivered; status cleanup unavailable'); }
    }

    /**
     * Prepares tracks in a pool: up to MUSIC_PARALLEL child processes run at once and the next track starts the moment
     * any of them ends (a slow track no longer holds the others back). $after runs when a child ends, $idle while it
     * waits (with the indexes still running); either returns false to stop starting new tracks; the running ones finish.
     */
    private function preparePool(array $indexes,array $tracks,string $service,string $dir,callable $after,?callable $idle=null): void
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
                        // The reason and what each source said ("youtube=blocked, soundcloud=format"), never the raw message.
                        $sources=preg_match('/\[((?:[a-z]+=[a-z]+)(?:, [a-z]+=[a-z]+){0,4})\]/',$e->getMessage(),$said)?$said[1]:'';
                        file_put_contents($dir.'/'.$index.'.fail',json_encode(['reason'=>$e instanceof MediaFailure?$e->reason:'process','sources'=>$sources]));
                    }
                    Download::clean($dir.'/work-'.$index);
                    exit(0);
                }
                if($pid>0) $running[$pid]=$index;
            }
            if(!$running) break;
            $ended=pcntl_wait($status,WNOHANG);
            if($ended===0) {
                // Nothing ended yet: $idle may refresh the progress (and say stop) with the tracks still being prepared.
                if($idle!==null&&$go&&$idle(array_values($running))===false) $go=false;
                usleep(200000); continue;
            }
            if($ended<0) break;
            unset($running[$ended]);
            if($go&&$after(array_values($running))===false) $go=false;
        }
    }

    /**
     * The caption of a Pornhub video: its title in bold, who is in it (the page's pornstars, else the channel), the length
     * and quality, and its categories and tags in a collapsed quote, cut to Telegram's limit.
     */
    private function adultCaption(Bot $bot,array $media,array $meta,bool $audio,int $deleteAfter=0): string
    {
        $ui=$bot->ui; $plain=fn(string $html)=>html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $people=array_filter((array)($meta['people']??[]),'is_string'); $channel=(string)($meta['channel']??'');
        $who=$people?implode('، ',array_map([Ui::class,'escape'],$people)):($channel!==''?Ui::escape($channel):'');
        $label=array_merge(array_filter((array)($meta['categories']??[]),'is_string'),array_filter((array)($meta['tags']??[]),'is_string'));
        $label=array_values(array_unique(array_map(fn($name)=>mb_strtolower($name),$label)));
        $views=(int)($meta['views']??0);
        $render=fn(string $tags)=>$ui->text('pornhub_caption',['title'=>mb_substr($media['title'],0,150),'quality'=>$audio?'MP3':($media['quality']??''),'duration'=>Ui::duration($media['duration']),
            'people'=>$who!==''?"\n".$ui->text('pornhub_people',['people'=>$who],['people']):'','views'=>$views>0?' · '.$ui->plainWith('pornhub_views',['n'=>number_format($views)]):'',
            'tags'=>$tags!==''?"\n".$ui->text('pornhub_tags',['tags'=>$tags],['tags']):'',
            'notice'=>$deleteAfter>0?$ui->text('pornhub_notice',['seconds'=>(string)$deleteAfter])."\n\n":''],['people','views','tags','notice']);
        $room=1000-TextFormat::length($plain($render('')));
        $kept=[]; $used=0;
        foreach($label as $name) {
            $tag='#'.preg_replace('/[^\p{L}\p{N}]+/u','_',$name); $cost=TextFormat::length($tag)+1+(int)(!$kept);
            if($used+$cost>$room-40) break;
            $kept[]=Ui::escape(trim($tag,'_')===''?$tag:rtrim($tag,'_')); $used+=$cost;
        }
        return $render(implode(' ',$kept));
    }

    /**
     * A whole album, playlist or set. Every track is prepared first (in a pool, kept in a folder so a stopped run
     * continues where it was), then all of them are sent one after another, one message each (or ZIP files); the last
     * carries the caption with the track list and the red way back, which deletes what was sent and brings the menu
     * back. Stopping sends nothing and returns to the menu with a "continue" button.
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
        $started=hrtime(true); $runStart=time(); $stopped=false; $denied=false; $shown=0.0; $checked=0.0;
        $pending=fn()=>array_values(array_filter(array_keys($tracks),fn($i)=>!is_file($dir.'/'.$i.'.json')&&!is_file($dir.'/'.$i.'.fail')));
        // The names of the tracks that failed so far, so the person sees which ones are missing.
        $failedNames=function() use($dir,$tracks,$bot): array {
            $names=[];
            foreach(glob($dir.'/*.fail')?:[] as $file) {
                $i=(int)basename($file,'.fail'); $name=(string)($tracks[$i]['n']??'');
                if($name!=='') $names[$i]=['name'=>$name,'why'=>$this->failureText($bot,self::failure($file))];
            }
            ksort($names); return array_values($names);
        };
        $progress=function(bool $force=false,array $working=[],float $every=1.2) use($bot,$job,$title,$total,$tracks,$stop,$pending,$failedNames,$dir,&$shown,$started): void {
            // Telegram allows about one edit a second per chat; the last state is always shown.
            if(!$force&&microtime(true)-$shown<$every) return;
            $shown=microtime(true); $failed=$failedNames();
            $names=array_values(array_filter(array_map(fn($i)=>(string)($tracks[$i]['n']??''),$working),fn($name)=>$name!==''));
            $now=$names?"\n".$bot->text('batch_working',['names'=>implode('، ',array_map(fn($name)=>mb_strlen($name)>40?mb_substr($name,0,39).'…':$name,array_slice($names,0,8)))]):'';
            $bot->jobMessage($job,$bot->text('batch_progress',['title'=>$title,'done'=>(string)($total-count($pending())),'total'=>(string)$total,'failed'=>(string)(count(glob($dir.'/*.fail')?:[])),
                'names'=>$this->failedBlock($bot,$failed),'working'=>$now,'elapsed'=>Ui::duration((int)round((hrtime(true)-$started)/1e9))],['names','working']),$stop);
        };
        $gate=function() use($store,$job,$bot,&$stopped,&$denied): bool {
            if($store->get('batch.stop.'.(int)$job['id'])==='1') { $stopped=true; return false; }
            if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) { $denied=true; return false; }
            return true;
        };
        $prepare=function(array $todo) use($tracks,$service,$dir,$gate,$progress,&$checked): void {
            $this->preparePool($todo,$tracks,$service,$dir,function(array $working) use($gate,$progress): bool { $progress(false,$working); return $gate(); },
                // While waiting: every three seconds the elapsed time and the tracks in work, and a quick answer to "stop".
                function(array $working) use($gate,$progress,&$checked): bool {
                    if(microtime(true)-$checked<1.0) return true;
                    $checked=microtime(true); $progress(false,$working,3.0); return $gate();
                });
        };
        if($todo=$pending()) {
            if($gate()) {
                Download::ensureSpace($config->storage.'/downloads');
                $progress(true);
                $this->probeYouTube($service,$folder);
                $prepare($todo);
                // A track that failed for a passing reason (the network, a crashed tool) gets one more try.
                $again=[];
                foreach(glob($dir.'/*.fail')?:[] as $file) if(in_array(self::failure($file)['reason'],['network','process'],true)) { $again[]=(int)basename($file,'.fail'); @unlink($file); }
                sort($again);
                if($again&&!$stopped&&!$denied) $prepare($again);
                // Each track that is still missing is reported to the administrators' list of recent errors, once.
                foreach(glob($dir.'/*.fail')?:[] as $file) {
                    $why=self::failure($file);
                    try { $store->pushError('batch',$why['reason'],$why['sources']); } catch(Throwable) {}
                }
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
        $sentShown=0.0;
        $sending=function(int $sent,bool $force=false) use($bot,$job,$good,$failedNames,&$sentShown): void {
            // Every edit counts against Telegram's per-chat limit too, so the count moves every few seconds only,
            // and a refused edit never stops the delivery itself.
            if(!$force&&microtime(true)-$sentShown<5.0) return;
            $sentShown=microtime(true);
            try { $bot->jobMessage($job,$bot->text('batch_sending',['sent'=>(string)$sent,'total'=>(string)count($good),'names'=>$this->failedBlock($bot,$failedNames())],['names'])); }
            catch(Throwable $e) { error_log('Batch sending status not updated: '.get_class($e)); }
        };
        $sending(0,true);
        $items=[];
        foreach($good as $position=>$i) {
            $meta=json_decode((string)file_get_contents($dir.'/'.$i.'.json'),true);
            $items[]=$meta+['path'=>$dir.'/'.$i.'.'.$meta['extension'],'thumbnail'=>$meta['cover']?$dir.'/'.$i.'.jpg':null,'order'=>$position+1,'radio'=>['service'=>$service]+array_intersect_key($tracks[$i],['u'=>1,'m'=>1])];
        }
        $back=[[$bot->ui->button('batch_back','mm:'.$menuId.':r')]];
        $ids=$zip?$this->sendZips($job,$bot,$items,$title,$folder,$back,$sending):$this->sendTracks($job,$bot,$items,$title,$back,$sending);
        $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
        // Only tracks prepared in this run count as downloaded, so a continued batch does not inflate the download speed.
        $fresh=array_values(array_filter($items,fn($item)=>(int)@filemtime((string)$item['path'])>=$runStart-1));
        $this->noteSpeed('batch',self::bytesOf($zip?$items:array_slice($items,0,max(1,count($ids)))),$timings,self::bytesOf($fresh));
        $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
        // The last track carries the caption and the way back (Telegram shows buttons only under a message of its own); the old menu is deleted.
        $menus->delivered($menuId,$batch,$ids,count($items),$failed,(int)end($ids));
    }

    /** What a .fail file says: the reason and the clean list of what each source said (older files hold only the reason). */
    private static function failure(string $file): array
    {
        $raw=trim((string)@file_get_contents($file)); $data=json_decode($raw,true);
        $reason=is_array($data)?(string)($data['reason']??''):$raw; $sources=is_array($data)?(string)($data['sources']??''):'';
        return ['reason'=>preg_match('/^[a-z]{2,20}$/D',$reason)?$reason:'process','sources'=>preg_match('/^[a-z]+=[a-z]+(?:, [a-z]+=[a-z]+){0,4}$/D',$sources)?$sources:''];
    }
    /** "YouTube: refused the server, SoundCloud: no safe version", or the reason alone. */
    private function failureText(Bot $bot,array $why): string
    {
        $ui=$bot->ui; $say=fn(string $reason)=>$ui->definition('text','batch_reason_'.$reason)?$ui->plain('batch_reason_'.$reason):$ui->plain('batch_reason_process');
        if($why['sources']==='') return $say($why['reason']);
        $parts=[];
        foreach(explode(', ',$why['sources']) as $pair) {
            [$source,$reason]=explode('=',$pair);
            $parts[]=($ui->definition('text','batch_source_'.$source)?$ui->plain('batch_source_'.$source):$source).': '.$say($reason);
        }
        return implode('، ',$parts);
    }
    /**
     * Before a whole list from Spotify's sources: one light YouTube request when nothing recent says how YouTube treats
     * this server. A refusal is remembered, so every track goes to SoundCloud first instead of each waiting for the same
     * refusal; a success fills the cache, so the tracks do not all solve YouTube's challenge at the same moment.
     */
    private function probeYouTube(string $service,string $folder): void
    {
        if($service!=='spotify'||Spotify::youtubeKnown($this->config)) return;
        $probe=$folder.'/probe'; @mkdir($probe,0700);
        try {
            (new Download($this->config))->probeYouTube($probe,$this->store->get('youtube_probe_url','https://www.youtube.com/watch?v=ybNMMK5Q318'));
            @touch($this->config->storage.'/.youtube-ok');
        } catch(MediaFailure $e) {
            if($e->reason==='blocked') { @touch($this->config->storage.'/.youtube-blocked'); @unlink($this->config->storage.'/.youtube-ok'); }
            error_log('YouTube probe before a batch: reason='.$e->reason);
        } catch(Throwable $e) { error_log('YouTube probe before a batch failed: '.get_class($e)); }
        finally { Download::clean($probe); }
    }
    /** A quote with the names of the tracks that could not be prepared and why (at most six), or nothing. */
    private function failedBlock(Bot $bot,array $names): string
    {
        if(!$names) return '';
        $shown=array_slice($names,0,6);
        $lines=array_map(fn($item)=>is_array($item)?'• <b>'.Ui::escape(mb_strlen($item['name'])>50?mb_substr($item['name'],0,49).'…':$item['name']).'</b> — '.Ui::escape($item['why'])
            :'• '.Ui::escape(mb_strlen($item)>60?mb_substr($item,0,59).'…':$item),$shown);
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
     * Sends the prepared tracks one message each, in order (Telegram shows buttons only under a message outside an
     * album). Every track has its own caption (artist and title in bold, its length in a quote) and, when the bot's
     * inline mode is on, a share button anyone may use; the last one also has the red way back, for its owner only.
     * $sending(int $sent) reports progress. @return list<int> message IDs in order
     */
    private function sendTracks(array $job,Bot $bot,array $items,string $title,array $back,callable $sending): array
    {
        $shares=new Shares($bot); $ids=[]; $last=null; $panel=new RadioPanel($bot); $radioOn=$panel->available((int)$job['chat_id'],(int)$job['user_id']);
        // Telegram allows about one message a second in a private chat and twenty a minute in a group; a long album is
        // paced to stay under that (the client also waits when Telegram asks it to).
        $gap=!$this->config->paceMessages?0.0:((int)$job['chat_id']<0?3.1:1.05); $previous=0.0;
        foreach($items as $n=>$item) {
            $caption=$bot->ui->fitCaption($bot->text('batch_caption',['artist'=>mb_substr((string)$item['performer'],0,150),'title'=>mb_substr((string)$item['title'],0,100),
                'duration'=>Ui::duration((int)($item['length']??$item['duration']??0)),'album'=>$title]));
            $token=$shares->reserve();
            $play=$radioOn&&is_array($item['radio']??null)?[$panel->row($item['radio'],(string)$item['title'],(string)$item['performer'],(int)($item['length']??$item['duration']??0))]:[];
            $rows=array_merge($token!==null?[$shares->row($token)]:[],$play,$n===count($items)-1?$back:[]);
            if(($pause=$gap-(microtime(true)-$previous))>0) usleep((int)($pause*1e6));
            $previous=microtime(true);
            try { $result=$this->sendOne($job,'audio',$item,$caption,$rows); }
            catch(Throwable $e) {
                if(!$ids) throw $e;
                // Part of the album is already there: keep it, put the way back under the last track that arrived and
                // tell the administrators what stopped the rest.
                error_log('Batch delivery stopped after '.count($ids).' tracks: '.get_class($e).' '.diagnosticMessage($e->getMessage()));
                try { $this->store->pushError('batch','upload',''); } catch(Throwable) {}
                try { $this->api->call('editMessageReplyMarkup',['chat_id'=>(int)$job['chat_id'],'message_id'=>end($ids),'reply_markup'=>['inline_keyboard'=>array_merge($last!==null?[$shares->row($last)]:[],$back)]]); }
                catch(Throwable) { error_log('Batch delivery: the way back could not be added to the last track'); }
                return $ids;
            }
            $shares->fill($token,$result,$caption); $last=$token;
            $ids[]=(int)($result['message_id']??0);
            $sending(count($ids));
        }
        return $ids;
    }
    /** ZIP files of the prepared tracks, each below the upload limit, one message each; the last carries the caption and the way back. @return list<int> */
    private function sendZips(array $job,Bot $bot,array $items,string $title,string $folder,array $back,callable $sending): array
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
        $parts=count($archives); $sent=0;
        foreach(array_keys($archives) as $n) $archives[$n]['name']=mb_substr($base,0,80).($parts>1?' ('.($n+1).')':'').'.zip';
        $caption=$this->listCaption($bot,'batch_zip_caption',['title'=>$title,'count'=>(string)count($items),'parts'=>(string)$parts],$items); $ids=[];
        foreach($archives as $n=>$archive) {
            $last=$n===$parts-1;
            $ids[]=(int)($this->sendOne($job,'document',$archive,$last?$caption:'',$last?$back:[])['message_id']??0);
            $sent+=$archive['count']; $sending($sent);
        }
        foreach($archives as $archive) @unlink($archive['path']);
        return $ids;
    }
    /** One audio or document message, with a caption and buttons when given. @return array Telegram's message */
    private function sendOne(array $job,string $type,array $item,string $caption,array $markup): array
    {
        $topic=(int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0?['message_thread_id'=>(int)$job['thread_id']]:[];
        $file=new \CURLFile($item['path'],$item['mime']??'application/octet-stream',$item['name']);
        $params=['chat_id'=>(int)$job['chat_id']]+$topic+($caption!==''?['caption'=>$caption,'parse_mode'=>'HTML']:[])+($markup?['reply_markup'=>['inline_keyboard'=>$markup]]:[]);
        if($type==='audio') $params+=['audio'=>$file,'title'=>mb_substr($item['title'],0,100),'performer'=>mb_substr($item['performer'],0,100),'duration'=>$item['duration']]+($item['thumbnail']!==null?['thumbnail'=>new \CURLFile($item['thumbnail'],'image/jpeg','cover.jpg')]:[]);
        else $params['document']=$file;
        $result=$this->api->call($type==='audio'?'sendAudio':'sendDocument',$params);
        return is_array($result)?$result:[];
    }

    /** One delivered file for the speed report; a failure to note it never touches the delivery itself. */
    private function noteSpeed(string $service,int $uploadBytes,array $timings,?int $downloadBytes=null,?int $uploadMs=null): void
    {
        try { $this->store->pushSpeed($service,$uploadBytes,(int)($timings['prepare_ms']??0),$uploadMs??(int)($timings['upload_ms']??0),$downloadBytes); } catch(Throwable) {}
    }
    /** The size on disk of prepared files, for the speed report. */
    private static function bytesOf(array $items): int
    {
        $sum=0; foreach($items as $item) $sum+=(int)@filesize((string)(is_array($item)?($item['path']??''):$item));
        return $sum;
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
