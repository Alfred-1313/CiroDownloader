<?php
declare(strict_types=1);
namespace Zero;

final class MediaFailure extends \RuntimeException
{
    public function __construct(public string $reason, string $detail)
    {
        parent::__construct(diagnosticMessage($detail));
    }

    public function userMessage(): string
    {
        return match($this->reason) {
            'blocked'=>'یوتیوب دسترسی این سرور را محدود کرده است. مدیر باید کوکی ورود یا اتصال خروجی سرور را تنظیم و آزمایش کند.',
            'cookies'=>'کوکی ورود یوتیوب معتبر یا قابل خواندن نیست؛ مدیر باید آن را به‌روز کند.',
            'size'=>'حجم این ویدیو از سقف ارسال ربات بیشتر است؛ یک ویدیوی کوتاه‌تر بفرست.',
            'storage'=>'فضای خالی سرور برای آماده‌سازی یا ارسال فایل کافی نیست؛ مدیر باید فضای سرور را بررسی کند.',
            'restricted'=>'این ویدیو خصوصی، حذف‌شده یا دارای محدودیت دسترسی است و قابل دانلود نیست.',
            'duration'=>'پخش زنده یا ویدیوی بدون مدت مشخص پشتیبانی نمی‌شود.',
            'network'=>'اتصال سرور به یوتیوب برقرار نشد؛ کمی بعد دوباره تلاش کن.',
            'format'=>'فرمت قابل دانلود این ویدیو در دسترس نیست؛ جزئیات برای مدیر ثبت شد.',
            default=>'دانلود یا آماده‌سازی ویدیو انجام نشد؛ جزئیات برای مدیر ثبت شد.',
        };
    }

    public static function process(int $exit, string $detail): self
    {
        $reason=match(true) {
            preg_match('/cookies are no longer valid|cookies.*expired|cookies.*rotated|cookie file.*(?:unreadable|does not exist)|invalid Netscape cookie|no youtube.com cookies|cannot create private cookie/i',$detail)===1=>'cookies',
            preg_match('/not a bot|sign in to confirm|HTTP Error (403|429)/i',$detail)===1=>'blocked',
            preg_match('/max.filesize|larger than|too large|size limit/i',$detail)===1=>'size',
            preg_match('/private video|video unavailable|removed|age.restricted|not available in your country/i',$detail)===1=>'restricted',
            preg_match('/does not pass filter|live stream|duration limit/i',$detail)===1=>'duration',
            // Official uploads of major labels are encrypted; they are never decrypted, so there is no version to take.
            preg_match('/DRM.protected|has DRM|\bDRM\b/i',$detail)===1=>'format',
            $exit===124||preg_match('/timed out|unable to download webpage|network is unreachable|name resolution|connection refused/i',$detail)===1=>'network',
            preg_match('/requested format.*not available/i',$detail)===1=>'format',
            default=>'process',
        };
        return new self($reason,"Media process exit $exit: $detail");
    }
}

final class Download
{
    /** SoundCloud also lists 30 s previews as formats; they are skipped, so a track that has only a preview fails cleanly. */
    private const FULL_AUDIO='ba[format_id!*=preview]/b[format_id!*=preview]';
    public function __construct(private Config $config) {}
    public static function ensureSpace(string $folder,int $required=268435456): void
    {
        $free=@disk_free_space($folder);
        if($free===false||$free<$required) throw new MediaFailure('storage','Insufficient free disk space');
    }
    public function run(array $command, string $folder, int $seconds,?int $maxTemporaryBytes=null,?int $maxFileBytes=null): string
    {
        // Drain both streams without writing unbounded process logs to disk.
        $stdout=''; $stderr='';
        // GNU timeout manages the child process group. No URL is interpreted by a shell.
        $command=array_merge(['timeout','--signal=TERM','--kill-after=5',(string)$seconds],$command);
        $proc=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$folder,
            ['PATH'=>getenv('PATH')?:'/usr/local/bin:/usr/bin:/bin','HOME'=>$folder,'LANG'=>'C.UTF-8']);
        if (!is_resource($proc)) throw new \RuntimeException('Cannot start downloader');
        stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $drain=static function() use (&$stdout,&$stderr,$pipes): void {
            $stdout.=(string)stream_get_contents($pipes[1],1048576);
            $stderr=substr($stderr.(string)stream_get_contents($pipes[2],1048576),-65536);
            if(strlen($stdout)>16777216) throw new MediaFailure('format','Downloader output exceeds metadata limit');
        };
        $exit=-1;
        $nextStorageCheck=0.0;
        try {
            do {
                $drain();
                $status=proc_get_status($proc);
                if (!$status['running']) { $exit=$status['exitcode']; $drain(); break; }
                if(microtime(true)>=$nextStorageCheck) {
                    self::ensureSpace($folder);
                    $bytes=0;
                    foreach (glob($folder.'/*')?:[] as $p) {
                        clearstatcache(true,$p);
                        if(is_file($p)) {
                            $size=filesize($p); $bytes+=$size;
                            if($maxFileBytes!==null&&$size>$maxFileBytes) throw new MediaFailure('size','Downloaded file exceeds size limit');
                        }
                    }
                    if ($bytes>($maxTemporaryBytes??$this->config->maxBytes*3)) throw new MediaFailure('size','Temporary size limit exceeded');
                    $nextStorageCheck=microtime(true)+0.25;
                }
                // Notice finished probes/remuxes promptly without scanning the
                // disk more often while a long download or encode is running.
                usleep(50000);
            } while (true);
        } finally {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc,15); // timeout forwards TERM to yt-dlp and FFmpeg.
                $deadline=microtime(true)+6;
                while(proc_get_status($proc)['running']&&microtime(true)<$deadline) usleep(100000);
                if(proc_get_status($proc)['running']) proc_terminate($proc,9);
            }
            fclose($pipes[1]); fclose($pipes[2]);
            proc_close($proc);
        }
        if ($exit!==0) {
            throw MediaFailure::process($exit,substr($stderr,-6000));
        }
        return $stdout;
    }
    /** Arguments for one attempt. A null jar means an anonymous request. */
    private function access(string $folder,?array $jar): array
    {
        $access=[]; $snapshot=null;
        if($jar!==null) {
            $cookies=$jar['path'];
            clearstatcache(true,$cookies);
            if(!is_file($cookies)) throw new MediaFailure('cookies','Configured YouTube cookie file does not exist');
            if(!is_readable($cookies)||filesize($cookies)>1048576) throw new MediaFailure('cookies','YouTube cookie file is unreadable or too large');
            $content=(string)file_get_contents($cookies);
            try { validateYouTubeCookies($content); }
            catch(\RuntimeException $e) { throw new MediaFailure('cookies',$e->getMessage()); }
            // yt-dlp saves cookies at exit. A private job copy avoids concurrent writers.
            $private=$folder.'/cookies.txt';
            if(file_put_contents($private,$content)===false) throw new MediaFailure('cookies','Cannot create private cookie copy');
            chmod($private,0600);
            $access=['--cookies',$private];
            $snapshot=['path'=>$private,'hash'=>hash('sha256',$content),'jar'=>$jar];
        }
        if($this->config->youtubeProxy!=='') $access=array_merge($access,['--proxy',$this->config->youtubeProxy]);
        // Only with the optional provider container: yt-dlp then asks it for a PO Token.
        if($this->config->youtubePotUrl!=='') $access=array_merge($access,['--extractor-args','youtubepot-bgutilhttp:base_url='.$this->config->youtubePotUrl]);
        return ['args'=>$access,'snapshot'=>$snapshot];
    }
    private function youtube(string $url,array $options,string $folder,int $seconds,bool $forceCookies=false): string
    {
        $pool=youtubeCookiePool($this->config);
        $jars=$pool->order(3);
        $context=preg_match('/^job-(\d+)-/',basename($folder),$match)?' job='.$match[1]:'';
        if(!$jars&&$forceCookies) throw new MediaFailure('cookies','Configured YouTube cookie file does not exist');
        // Anonymous first when the administrator prefers cookies only as a last resort.
        $attempts=!$jars?[null]:($this->config->cookieFallback&&!$forceCookies?array_merge([null],$jars):$jars);
        $failure=null; $refused=[];
        foreach($attempts as $jar) {
            try {
                $access=$this->access($folder,$jar);
                error_log('YouTube provider=yt-dlp cookies='.($jar?'yes':'no').($jar?' jar='.$jar['id']:'').$context);
                $result=$this->run(array_merge($this->base(),$options,$access['args'],['--',$url]),$folder,$seconds);
            } catch(MediaFailure $e) {
                $failure=$e;
                // Only a refusal that a login could fix is retried with the next cookie jar.
                if(!in_array($e->reason,$jar===null?['blocked','restricted']:['blocked','cookies'],true)) throw $e;
                // An unreadable or expired jar is its own fault. A plain refusal may be the server's IP, so
                // it only counts against a jar once another jar got through where it did not.
                if($jar!==null) { if($e->reason==='cookies') $pool->markFailed($jar['id']); else $refused[]=$jar['id']; }
                continue;
            }
            if($jar!==null) { $pool->markWorking($jar['id']); foreach($refused as $id) $pool->markFailed($id); $this->refresh($access); }
            return $result;
        }
        throw $failure??new MediaFailure('process','YouTube download did not start');
    }
    /**
     * One light YouTube request through the usual cookie order, before a whole album is prepared: it fills the cache
     * once (instead of every track solving the same challenge at the same time) and tells whether YouTube refuses this
     * server right now. Throws MediaFailure like any YouTube request.
     */
    public function probeYouTube(string $folder,string $url): void
    {
        $canonical=youtubeUrl($url);
        if(!$canonical) throw new \RuntimeException('Invalid YouTube URL');
        $this->youtube($canonical,['--skip-download','--dump-single-json','--no-warnings'],$folder,min(90,$this->config->timeout));
    }
    /**
     * One light request with one jar, so YouTube's rotated session cookies are saved even on days nobody downloads.
     * Throws MediaFailure when the request fails; the reason says whether the cookie itself or the server is at fault.
     */
    public function keepAlive(string $folder,array $jar,string $url): void
    {
        $canonical=youtubeUrl($url);
        if(!$canonical) throw new \RuntimeException('Invalid YouTube URL');
        $access=$this->access($folder,$jar);
        $this->run(array_merge($this->base(),['--skip-download','--dump-single-json','--no-warnings'],$access['args'],['--',$canonical]),$folder,min(120,$this->config->timeout));
        $this->refresh($access);
    }
    private function refresh(array $access): void
    {
        $snapshot=$access['snapshot'];
        if($snapshot&&is_file($snapshot['path'])) {
            try { saveYouTubeCookies($this->config,(string)file_get_contents($snapshot['path']),$snapshot['hash'],$snapshot['jar']['path']); }
            catch(\Throwable) { error_log('YouTube cookie refresh skipped: invalid jar or unavailable storage'); }
        }
    }
    private function base(): array
    {
        // A private cache in storage (the image itself is read-only) keeps YouTube's player code and solved challenges, so
        // every call does not download the player again and run its JavaScript challenge through node, which is slow on
        // a small server and slower still when several tracks are prepared at once.
        $cache=$this->config->storage.'/yt-cache';
        if(!is_dir($cache)) @mkdir($cache,0700,true);
        return [$this->config->ytDlp,'--ignore-config','--no-playlist','--no-progress','--quiet',
            ...(is_dir($cache)&&is_writable($cache)?['--cache-dir',$cache]:['--no-cache-dir']),'--js-runtimes','node','--socket-timeout','20','--retries','2','--fragment-retries','2',
        ];
    }
    public static function qualityOptions(array $info): array
    {
        if(!empty($info['is_live'])||($info['live_status']??'')==='is_live') throw new MediaFailure('duration','Live streams are not supported');
        $formats=$info['formats']??[]; $audio=false;
        foreach($formats as $format) if(($format['acodec']??'none')!=='none'&&empty($format['has_drm'])) $audio=true;
        if(!$audio) throw new MediaFailure('format','No audio stream is available');
        usort($formats,fn($a,$b)=>[
            str_starts_with($b['vcodec']??'','avc1')?1:0,(float)($b['tbr']??0)
        ]<=>[str_starts_with($a['vcodec']??'','avc1')?1:0,(float)($a['tbr']??0)]);
        $options=[];
        foreach($formats as $format) {
            $height=min((int)($format['width']??0),(int)($format['height']??0));
            $id=(string)($format['format_id']??'');
            if(!in_array($height,Config::VIDEO_QUALITIES,true)||in_array($format['vcodec']??'none',['none','images'],true)||($format['protocol']??'')==='mhtml'||!empty($format['has_drm'])||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)) continue;
            if(count($options[$height]??[])<8) $options[$height][]=['id'=>$id,'audio'=>($format['acodec']??'none')!=='none'];
        }
        ksort($options,SORT_NUMERIC);
        return $options;
    }
    public static function audioOptions(array $info): array
    {
        $formats=array_values(array_filter($info['formats']??[],fn($format)=>
            !empty($format['acodec'])&&$format['acodec']!=='none'&&empty($format['has_drm'])
            &&($format['protocol']??'')!=='mhtml'
            &&preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)($format['format_id']??''))));
        // Prefer audio-only sources; use a combined stream when no separate audio exists.
        usort($formats,fn($a,$b)=>[
            ($b['vcodec']??'none')==='none'?1:0,(float)($b['abr']??$b['tbr']??0)
        ]<=>[($a['vcodec']??'none')==='none'?1:0,(float)($a['abr']??$a['tbr']??0)]);
        return array_map(fn($format)=>['id'=>(string)$format['format_id']],array_slice($formats,0,8));
    }
    /** A video page this class downloads with a quality choice: YouTube (with its cookies) or an enabled adult site (no YouTube cookies). */
    private static function videoPage(string $url): array
    {
        if($canonical=youtubeUrl($url)) return [$canonical,'youtube'];
        if(($canonical=adultUrl($url))!==null) return [$canonical,adultService($canonical)];
        throw new \RuntimeException('Invalid video URL');
    }
    /** Runs yt-dlp for a video page: YouTube through its cookie jars, anything else plainly (with the social proxy when set). */
    private function videoRun(string $site,string $canonical,array $options,string $folder,int $seconds,bool $forceCookies=false): string
    {
        if($site==='youtube') return $this->youtube($canonical,$options,$folder,$seconds,$forceCookies);
        // The pinned Beeg extractor still uses the retired API shape. Load our scoped compatibility plugin.
        if($site==='beeg') $options=array_merge(['--plugin-dirs',__DIR__.'/ytdlp'],$options);
        try { return $this->plain($canonical,$options,$folder,$seconds); }
        catch(MediaFailure $e) {
            // The administrator sees WHY ("pornhub=dns", "pornhub=certificate"), never the raw downloader output.
            throw new MediaFailure($e->reason,$e->getMessage().' ['.$site.'='.self::siteCause($e->getMessage()).']');
        }
    }
    /** A one-word cause of a failed request to a site, from the downloader's error text (letters only, safe to show). */
    public static function siteCause(string $detail): string
    {
        return match(true) {
            preg_match('/CERTIFICATE_VERIFY_FAILED|certificate verify|self.signed|hostname.*mismatch|SSL:|TLS|handshake/i',$detail)===1=>'certificate',
            preg_match('/name resolution|Temporary failure in name|nodename nor servname|Name or service not known|getaddrinfo|No address associated/i',$detail)===1=>'dns',
            preg_match('/connection refused|Errno 111/i',$detail)===1=>'refused',
            preg_match('/network is unreachable|no route to host|Errno 101|Errno 113/i',$detail)===1=>'unreachable',
            preg_match('/connection reset|Errno 104|remote end closed|IncompleteRead|EOF occurred/i',$detail)===1=>'reset',
            preg_match('/timed out|timeout|exit 124/i',$detail)===1=>'timeout',
            preg_match('/HTTP Error 451|legal reasons|not available in your (?:country|region)|geo.?(?:restrict|block)|blocked in your/i',$detail)===1=>'region',
            preg_match('/HTTP Error 429|too many requests/i',$detail)===1=>'ratelimit',
            preg_match('/HTTP Error 40[13]|forbidden|cloudflare|just a moment|captcha/i',$detail)===1=>'forbidden',
            preg_match('/HTTP Error 4(?:04|10)|not found|\bgone\b|removed|deleted/i',$detail)===1=>'gone',
            preg_match('/HTTP Error 5\d\d|bad gateway|service unavailable|gateway time/i',$detail)===1=>'unavailable',
            preg_match('/HTTP Error 30\d|too many redirects|redirect/i',$detail)===1=>'redirect',
            preg_match('/Unable to extract|unsupported url|no video formats|age.?verif/i',$detail)===1=>'page',
            default=>'unknown',
        };
    }
    public function inspect(string $url,string $folder,bool $forceCookies=false): array
    {
        [$canonical,$site]=self::videoPage($url);
        // The page's picture is written by the same request (its own network path and headers), then uploaded to Telegram
        // as a file: Telegram's servers cannot always fetch a picture from the site's CDN themselves.
        $extra=$site!=='youtube'?['--no-simulate','--write-thumbnail','-o',$folder.'/poster.%(ext)s']:[];
        $json=$this->videoRun($site,$canonical,array_merge(['--dump-single-json','--skip-download'],$extra),$folder,min(120,$this->config->timeout),$forceCookies);
        try { $info=json_decode($json,true,512,JSON_THROW_ON_ERROR); }
        catch(\JsonException) { throw new MediaFailure('format','Downloader metadata is invalid'); }
        if(!is_array($info)) throw new MediaFailure('format','Downloader metadata is missing');
        if($site!=='youtube') {
            // The same quality menu as YouTube (plus 240p and the audio choice); qualities that cannot be sent are not offered.
            $options=self::sizedOptions($info,$this->config->maxBytes);
            $options['meta']=self::siteMeta($info);
            return ['title'=>mb_substr((string)($info['title']??'Video'),0,180),'options'=>$options,'poster'=>'','poster_file'=>self::posterFile($folder),'duration'=>max(0,(int)ceil((float)($info['duration']??0)))];
        }
        $options=self::qualityOptions($info);
        $audio=self::audioOptions($info);
        if($audio) $options['audio']=$audio;
        if(!$options) throw new MediaFailure('format','No downloadable media formats found');
        return ['title'=>mb_substr((string)($info['title']??'YouTube'),0,180),'options'=>$options,'poster'=>self::posterUrl($info,$canonical)];
    }
    /**
     * Qualities of a site whose formats carry picture and sound together (HLS or MP4), keyed like YouTube's, plus an
     * "audio" entry (the smallest format, whose sound is extracted). A format whose size (stated, or estimated from its
     * bitrate and the length) is above the upload limit is left out; when that leaves nothing, the video is too large for
     * this bot. HLS streams with a known H.264 codec come first: they download in parallel pieces, while the plain MP4
     * of the same height is throttled by the CDN to a few hundred KB/s.
     */
    public static function sizedOptions(array $info,int $maxBytes): array
    {
        if(!empty($info['is_live'])||($info['live_status']??'')==='is_live') throw new MediaFailure('duration','Live streams are not supported');
        $duration=(float)($info['duration']??0); $formats=array_values(array_filter(is_array($info['formats']??null)?$info['formats']:[],'is_array')); $tooBig=false; $options=[]; $smallest=null;
        $rank=function(array $format): int {
            $h264=str_starts_with((string)($format['vcodec']??''),'avc1');
            return str_starts_with((string)($format['protocol']??''),'m3u8')&&$h264?0:($h264?1:2);
        };
        usort($formats,fn($a,$b)=>[$rank($a),(float)($a['tbr']??0)]<=>[$rank($b),(float)($b['tbr']??0)]);
        foreach($formats as $format) {
            $id=(string)($format['format_id']??'');
            if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)||in_array($format['vcodec']??'',['none','images'],true)||($format['acodec']??'')==='none'||!empty($format['has_drm'])||($format['protocol']??'')==='mhtml') continue;
            $height=(int)($format['height']??0); $width=(int)($format['width']??0); $height=$width>0?min($width,$height):$height;
            if(!in_array($height,Config::SITE_QUALITIES,true)) continue;
            // Stated size, else bitrate x length. Pornhub's plain MP4s state neither, but their file names carry the bitrate (480P_2000K_...).
            $kbps=(float)($format['tbr']??0);
            if($kbps<=0&&preg_match('~/\d+P_(\d{3,5})K_~',(string)($format['url']??''),$bitrate)) $kbps=(float)$bitrate[1];
            $size=is_numeric($format['filesize']??null)?(float)$format['filesize']:(is_numeric($format['filesize_approx']??null)?(float)$format['filesize_approx']:($kbps>0&&$duration>0?$kbps*125*$duration:null));
            if($size!==null&&$size>$maxBytes*0.95) { $tooBig=true; continue; }
            if(count($options[$height]??[])<8) $options[$height][]=['id'=>$id,'audio'=>true];
            if($smallest===null||($rank($format)<=$smallest[0]&&$height<$smallest[1])||($rank($format)<$smallest[0])) $smallest=[$rank($format),$height,$id];
        }
        if(!$options) throw $tooBig?new MediaFailure('size','Every quality is larger than the upload limit'):new MediaFailure('format','No downloadable media formats found');
        ksort($options,SORT_NUMERIC);
        // The sound alone: the lowest picture that has it, converted to MP3 after the download.
        if($smallest!==null) $options['audio']=[['id'=>$smallest[2]]];
        return $options;
    }
    /** What the page says about the video, cut to what a caption can carry: people, channel, categories, tags, views. */
    public static function siteMeta(array $info): array
    {
        $clean=fn(mixed $list,int $count,int $length)=>array_slice(array_values(array_unique(array_filter(array_map(fn($value)=>is_string($value)?mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>]+|\s+/u',' ',$value)??''),0,$length):'',is_array($list)?$list:[]),fn($value)=>$value!==''))),0,$count);
        $uploader=is_string($info['uploader']??null)?mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>]+/u',' ',$info['uploader'])??''),0,60):'';
        return ['people'=>$clean($info['cast']??[],6,40),'channel'=>$uploader,'categories'=>$clean($info['categories']??[],10,30),'tags'=>$clean($info['tags']??[],12,30),
            'views'=>max(0,(int)($info['view_count']??0)),'likes'=>max(0,(int)($info['like_count']??0))];
    }
    /** The picture yt-dlp wrote next to the metadata, when it is a real image of a sensible size. */
    public static function posterFile(string $folder): ?string
    {
        foreach(glob($folder.'/poster.*')?:[] as $file) {
            if(!is_file($file)||filesize($file)<200||filesize($file)>8388608) continue;
            $head=(string)file_get_contents($file,false,null,0,12);
            if(str_starts_with($head,"\xFF\xD8\xFF")||str_starts_with($head,"\x89PNG")||(str_starts_with($head,'RIFF')&&substr($head,8,4)==='WEBP')) return $file;
        }
        return null;
    }
    /** The page's picture when it is HTTPS on the site's own image host, otherwise none. */
    public static function trustedPoster(array $info,string $host): string
    {
        $thumbnail=(string)($info['thumbnail']??''); $parts=parse_url($thumbnail); $name=strtolower((string)($parts['host']??''));
        return $parts&&($parts['scheme']??'')==='https'&&($name===$host||str_ends_with($name,'.'.$host))&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['port'])&&strlen($thumbnail)<=2048?$thumbnail:'';
    }
    /**
     * The first YouTube search results for a text, as {id,title,duration,channel}. Only what a
     * flat listing offers is read, so one search is a single quick request.
     * @return list<array{id:string,title:string,duration:?float,channel:string}>
     */
    public function search(string $query,string $folder,int $count=6): array
    {
        $query=trim(preg_replace('/[\x00-\x1F\x7F]+|\s+/u',' ',$query)??'');
        if($query===''||mb_strlen($query)>300) throw new MediaFailure('format','Invalid search text');
        $json=$this->youtube('ytsearch'.max(1,min(10,$count)).':'.$query,['--flat-playlist','--dump-single-json','--skip-download'],$folder,min(60,$this->config->timeout));
        try { $data=json_decode($json,true,512,JSON_THROW_ON_ERROR); }
        catch(\JsonException) { throw new MediaFailure('format','Search result is invalid'); }
        $result=[];
        foreach(is_array($data['entries']??null)?$data['entries']:[] as $entry) {
            if(!is_array($entry)||!preg_match('/^[A-Za-z0-9_-]{11}$/D',(string)($entry['id']??''))) continue;
            $result[]=['id'=>(string)$entry['id'],'title'=>mb_substr((string)($entry['title']??''),0,300),
                'duration'=>is_numeric($entry['duration']??null)?(float)$entry['duration']:null,
                'channel'=>mb_substr((string)($entry['channel']??$entry['uploader']??''),0,200)];
        }
        return $result;
    }
    /** The details of a SoundCloud track even when it has nothing to download (DRM, preview only). */
    public function soundCloudInfo(string $url,string $folder): array
    {
        if(!preg_match('~^https://(?:soundcloud\.com/[A-Za-z0-9_-]{1,64}/[A-Za-z0-9_-]{1,128}|api\.soundcloud\.com/tracks/soundcloud%3Atracks%3A[0-9]{1,20})$~D',$url)) throw new MediaFailure('format','Invalid SoundCloud track address');
        $json=$this->plain($url,['--dump-single-json','--skip-download','--ignore-no-formats-error'],$folder,min(60,$this->config->timeout));
        try { $data=json_decode($json,true,512,JSON_THROW_ON_ERROR); }
        catch(\JsonException) { throw new MediaFailure('format','SoundCloud details are invalid'); }
        if(!is_array($data)) throw new MediaFailure('format','SoundCloud details are missing');
        return $data;
    }
    /** A SoundCloud profile, list or set as yt-dlp's flat listing (no per-track requests), at most $limit entries. */
    public function listSoundCloud(string $url,string $folder,int $limit=40): array
    {
        if(!preg_match('~^https://soundcloud\.com/[A-Za-z0-9_-]{1,64}(?:/(?:tracks|albums|sets|reposts|likes)|/sets/[A-Za-z0-9_-]{1,128})?$~D',$url)) throw new MediaFailure('format','Invalid SoundCloud list address');
        $json=$this->plain($url,['--flat-playlist','--dump-single-json','--skip-download','--playlist-end',(string)max(1,min(100,$limit))],$folder,min(40,$this->config->timeout));
        try { $data=json_decode($json,true,512,JSON_THROW_ON_ERROR); }
        catch(\JsonException) { throw new MediaFailure('format','SoundCloud list is invalid'); }
        if(!is_array($data)) throw new MediaFailure('format','SoundCloud list is missing');
        return $data;
    }
    private function soundCloudSelector(): string
    {
        $full='[format_id!*=preview]';
        return $this->config->musicCopy?'ba'.$full.'[ext=mp3]/ba'.$full.'[ext=m4a]/'.self::FULL_AUDIO:self::FULL_AUDIO;
    }
    private static function soundCloudUrl(string $id): string { return 'https://api.soundcloud.com/tracks/soundcloud%3Atracks%3A'.$id; }
    /** A downloader run for a site that is not YouTube: no YouTube cookies, the social-media proxy when one is set. */
    private function plain(string $url,array $options,string $folder,int $seconds): string
    {
        $proxy=$this->config->galleryProxy!==''?['--proxy',$this->config->galleryProxy]:[];
        return $this->run(array_merge($this->base(),$options,$proxy,['--',$url]),$folder,$seconds);
    }
    /**
     * The first SoundCloud search results for a text. Used as a second source when YouTube refuses the server.
     * @return list<array{id:string,title:string,duration:?float,channel:string,url:string}>
     */
    public function searchSoundCloud(string $query,string $folder,int $count=8): array
    {
        $query=trim(preg_replace('/[\x00-\x1F\x7F]+|\s+/u',' ',$query)??'');
        if($query===''||mb_strlen($query)>300) throw new MediaFailure('format','Invalid search text');
        $json=$this->plain('scsearch'.max(1,min(10,$count)).':'.$query,['--flat-playlist','--dump-single-json','--skip-download'],$folder,min(60,$this->config->timeout));
        try { $data=json_decode($json,true,512,JSON_THROW_ON_ERROR); }
        catch(\JsonException) { throw new MediaFailure('format','Search result is invalid'); }
        $result=[];
        foreach(is_array($data['entries']??null)?$data['entries']:[] as $entry) {
            if(!is_array($entry)||!preg_match('/^[0-9]{1,20}$/D',(string)($entry['id']??''))) continue;
            $result[]=['id'=>(string)$entry['id'],'title'=>mb_substr((string)($entry['title']??''),0,300),
                'duration'=>is_numeric($entry['duration']??null)?(float)$entry['duration']:null,
                'channel'=>mb_substr((string)($entry['uploader']??$entry['channel']??''),0,200),'url'=>self::soundCloudUrl((string)$entry['id'])];
        }
        return $result;
    }
    /** The audio of one SoundCloud track found with searchSoundCloud, as a tagged-ready MP3. */
    public function fetchSoundCloudAudio(string $id,string $folder): array
    {
        if(!preg_match('/^[0-9]{1,20}$/D',$id)) throw new MediaFailure('format','Invalid SoundCloud track');
        $url=self::soundCloudUrl($id);
        return $this->audioDownload($url,$folder,$this->soundCloudSelector(),fn(array $options)=>$this->plain($url,$options,$folder,$this->config->timeout),[],$this->config->musicCopy);
    }
    /**
     * One SoundCloud track page: its audio as MP3 plus the track's own details (title, artists, length, cover, date).
     * Only the full track is taken; the 30 s previews SoundCloud offers for some tracks are never chosen.
     * @return array{audio:array,info:array}
     */
    public function fetchSoundCloudTrack(string $url,string $folder): array
    {
        if(!preg_match('~^https://(?:soundcloud\.com/[A-Za-z0-9_-]{1,64}/[A-Za-z0-9_-]{1,128}|api\.soundcloud\.com/tracks/soundcloud%3Atracks%3A[0-9]{1,20})$~D',$url)) throw new MediaFailure('format','Invalid SoundCloud track address');
        $audio=$this->audioDownload($url,$folder,$this->soundCloudSelector(),fn(array $options)=>$this->plain($url,$options,$folder,$this->config->timeout),['--write-info-json'],$this->config->musicCopy);
        $file=$folder.'/source.info.json'; $info=[];
        if(is_file($file)&&filesize($file)<=4194304) {
            $decoded=json_decode((string)file_get_contents($file),true);
            if(is_array($decoded)) $info=$decoded;
        }
        return ['audio'=>$audio,'info'=>$info];
    }
    public static function posterUrl(array $info,string $url): string
    {
        $canonical=youtubeUrl($url);
        if(!$canonical) return '';
        parse_str((string)parse_url($canonical,PHP_URL_QUERY),$query); $id=$query['v'];
        $thumbnail=(string)($info['thumbnail']??'');
        $parts=parse_url($thumbnail);
        if($parts&&($parts['scheme']??'')==='https'&&($parts['host']??'')==='i.ytimg.com'
            &&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['port'])&&strlen($thumbnail)<=2048
            &&preg_match('~^/vi/'.preg_quote($id,'~').'/[A-Za-z0-9_-]+\.jpg$~D',$parts['path']??'')) return $thumbnail;
        return 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg';
    }
    public function verifyCookies(string $content,string $folder,string $url): string
    {
        validateYouTubeCookies($content);
        $usable=false;
        foreach(preg_split('/\r?\n/',$content) as $line) {
            if(str_starts_with($line,'#HttpOnly_')) $line=substr($line,10);
            elseif($line===''||str_starts_with($line,'#')) continue;
            $fields=explode("\t",$line); $domain=ltrim(strtolower($fields[0]),'.');
            if(($domain==='youtube.com'||str_ends_with($domain,'.youtube.com'))&&$fields[6]!==''&&((int)$fields[4]===0||(int)$fields[4]>time())) $usable=true;
        }
        if(!$usable) throw new MediaFailure('cookies','All YouTube cookies have expired or are empty');
        $candidate=clone $this->config; $candidate->youtubeCookies=$folder.'/candidate.txt'; $candidate->timeout=30;
        if(file_put_contents($candidate->youtubeCookies,$content)!==strlen($content)) throw new \RuntimeException('Cannot write private candidate cookie file');
        chmod($candidate->youtubeCookies,0600);
        // Verification must exercise the uploaded session even if this server
        // can access a public video anonymously.
        (new self($candidate))->inspect($url,$folder,true);
        return (string)file_get_contents($candidate->youtubeCookies);
    }
    public function fetchAudio(string $url,string $folder,array $formats): array
    {
        [$canonical,$site]=self::videoPage($url);
        $selector=[];
        foreach($formats as $format) {
            if(!is_array($format)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)($format['id']??''))) throw new MediaFailure('format','Invalid audio format identifier');
            $selector[]=$format['id'];
        }
        if(!$selector) throw new MediaFailure('format','No saved audio formats are available');
        return $this->audioDownload($canonical,$folder,implode('/',$selector),$site==='youtube'?null:fn(array $options)=>$this->videoRun($site,$canonical,$options,$folder,$this->config->timeout));
    }
    /** The best audio of a video without listing its formats first; one request fewer than inspect + fetchAudio. */
    public function fetchBestAudio(string $url,string $folder): array
    {
        $canonical=youtubeUrl($url);
        if(!$canonical) throw new \RuntimeException('Invalid YouTube URL');
        // M4A (AAC) exists for every YouTube video and needs no re-encoding; Opus would have to be converted.
        return $this->audioDownload($canonical,$folder,$this->config->musicCopy?'ba[ext=m4a]/ba/b':'ba/b',null,[],$this->config->musicCopy);
    }
    /** @param ?callable(list<string>):string $runner downloads with the given options; null means YouTube with its cookies */
    /** @param bool $copy music mode: keep MP3 and AAC (M4A) sources as they are instead of re-encoding them to MP3 */
    private function audioDownload(string $canonical,string $folder,string $selector,?callable $runner=null,array $extra=[],bool $copy=false): array
    {
        $options=array_merge([
            '--concurrent-fragments','4',
            '--abort-on-unavailable-fragments','--max-filesize',(string)$this->config->maxBytes,
            '--match-filter','!is_live','--break-on-reject','-f',$selector,
            '--print','after_move:%(title)j','-o',$folder.'/source.%(ext)s'],$extra);
        $title=$runner?$runner($options):$this->youtube($canonical,$options,$folder,$this->config->timeout);
        $files=array_values(array_filter(glob($folder.'/source.*')?:[],fn($file)=>
            in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['m4a','webm','mp4','mkv','opus','ogg','mp3','aac','wav','flac'],true)));
        if(count($files)!==1) throw new MediaFailure('format','Downloader did not produce one complete audio source');
        $title=is_string($decoded=json_decode(trim($title),true))?$decoded:'YouTube';
        if($copy) return $this->keepOrConvert($files[0],$folder,$title);
        $ready=$folder.'/ready.mp3';
        $this->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$files[0],
            '-map','0:a:0','-vn','-c:a','libmp3lame','-b:a','192k','-ac','2','-threads','2',$ready],$folder,$this->config->timeout);
        clearstatcache(true,$ready);
        if(!is_file($ready)||filesize($ready)<1) throw new MediaFailure('format','Prepared MP3 is missing');
        if(filesize($ready)>$this->config->maxBytes) throw new MediaFailure('size','Prepared MP3 exceeds file size limit');
        $info=$this->probe($ready,$folder);
        $streams=array_column($info['streams']??[],'codec_name','codec_type');
        if(($streams['audio']??'')!=='mp3'||isset($streams['video'])) throw new MediaFailure('format','Prepared MP3 has invalid streams');
        $duration=(float)($info['format']['duration']??0);
        if($duration<=0) throw new MediaFailure('duration','Audio has no duration');
        return ['path'=>$ready,'title'=>$title,'format'=>'MP3','duration'=>(int)ceil($duration)];
    }
    /**
     * Telegram plays MP3 and M4A (AAC) as music. A source that already is one of them is only copied into its own
     * container: no quality is lost, it takes a fraction of a second and the file is the smaller one. Anything else
     * (Opus, Vorbis) becomes MP3 at 160 kbps, which is all such sources hold.
     */
    private function keepOrConvert(string $source,string $folder,string $title): array
    {
        $codec=''; $info=$this->probe($source,$folder);
        foreach($info['streams']??[] as $stream) if(($stream['codec_type']??'')==='audio') { $codec=(string)($stream['codec_name']??''); break; }
        [$ready,$arguments,$format,$expected]=match($codec) {
            'mp3'=>[$folder.'/ready.mp3',['-c:a','copy'],'MP3','mp3'],
            'aac'=>[$folder.'/ready.m4a',['-c:a','copy','-movflags','+faststart'],'M4A','aac'],
            default=>[$folder.'/ready.mp3',['-c:a','libmp3lame','-b:a','160k','-ac','2','-threads','2'],'MP3','mp3'],
        };
        $this->run(array_merge(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$source,'-map','0:a:0','-vn'],$arguments,[$ready]),$folder,$this->config->timeout);
        clearstatcache(true,$ready);
        if(!is_file($ready)||filesize($ready)<1) throw new MediaFailure('format','Prepared audio is missing');
        if(filesize($ready)>$this->config->maxBytes) throw new MediaFailure('size','Prepared audio exceeds file size limit');
        $info=$this->probe($ready,$folder);
        $streams=array_column($info['streams']??[],'codec_name','codec_type');
        if(($streams['audio']??'')!==$expected||isset($streams['video'])) throw new MediaFailure('format','Prepared audio has invalid streams');
        $duration=(float)($info['format']['duration']??0);
        if($duration<=0) throw new MediaFailure('duration','Audio has no duration');
        return ['path'=>$ready,'title'=>$title,'format'=>$format,'duration'=>(int)ceil($duration)];
    }
    public function fetch(string $url, string $folder, ?int $quality=null, array $formats=[]): array
    {
        [$canonical,$site]=self::videoPage($url);
        $target=$quality??480;
        if($target<72||$target>8640) throw new MediaFailure('format','Invalid selected resolution');
        $selector=[];
        foreach($formats as $format) {
            if(!is_array($format)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)($format['id']??''))) throw new MediaFailure('format','Invalid format identifier');
            $selector[]=$format['id'].(!empty($format['audio'])?'':'+ba');
        }
        if($quality!==null&&!$selector) throw new MediaFailure('format','The selected quality has no saved formats');
        $title=$this->videoRun($site,$canonical,[
            '--concurrent-fragments',$site==='youtube'?'4':'8',
            '--abort-on-unavailable-fragments','--max-filesize',(string)$this->config->maxBytes,
            '--match-filter','!is_live','--break-on-reject',
            // Explicit IDs prevent silently substituting a different quality.
            '-f',$selector?implode('/',$selector):'bv*+ba/b','-S','res:'.$target.',vcodec:h264,acodec:aac',
            '--merge-output-format','mkv','--print','after_move:%(title)j',
            '-o',$folder.'/video.%(ext)s'],$folder,$this->config->timeout);
        $files=array_values(array_filter(glob($folder.'/video.*')?:[],
            fn($file)=>in_array(pathinfo($file,PATHINFO_EXTENSION),['mp4','mkv','webm','mov'],true)));
        if(count($files)!==1) throw new MediaFailure('format','Downloader did not produce one complete video');
        $file=$files[0];
        $info=$this->probe($file,$folder);
        $video=null; $audio=null;
        foreach($info['streams']??[] as $s) {
            if(($s['codec_type']??'')==='video') $video=$s;
            if(($s['codec_type']??'')==='audio') $audio=$s;
        }
        $duration=(float)($info['format']['duration']??0);
        if(!$video||!$audio) throw new MediaFailure('format','Downloaded video or audio stream is missing');
        if($duration<=0) throw new MediaFailure('duration','Video has no duration');
        if($quality!==null&&min((int)$video['width'],(int)$video['height'])!==$quality) throw new MediaFailure('format','Downloaded resolution does not match the selected quality');

        // Telegram clients need H.264/AAC MP4; accept other source codecs and convert them.
        $ready=$folder.'/ready.mp4';
        $copyVideo=($video['codec_name']??'')==='h264'&&($video['pix_fmt']??'')==='yuv420p'
            &&min((int)$video['width'],(int)$video['height'])<=$target;
        $copyAudio=($audio['codec_name']??'')==='aac';
        $args=['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$file,'-map','0:v:0','-map','0:a:0'];
        // A non-AAC soundtrack must not force re-encoding an already suitable
        // H.264 picture. Likewise preserve AAC when only video needs conversion.
        if($copyVideo) {
            $args=array_merge($args,['-c:v','copy']);
        } else {
            $args=array_merge($args,['-vf',"scale='if(gte(iw,ih),-2,min($target,iw))':'if(gte(iw,ih),min($target,ih),-2)'",
                '-c:v','libx264','-preset','veryfast','-crf','25','-threads','2','-pix_fmt','yuv420p']);
        }
        $args=array_merge($args,$copyAudio?['-c:a','copy']:['-c:a','aac','-b:a','96k']);
        $this->run(array_merge($args,['-movflags','+faststart',$ready]),$folder,$this->config->timeout);
        clearstatcache(true,$ready);
        if(!is_file($ready)||filesize($ready)<1) throw new MediaFailure('format','Prepared MP4 is missing');
        if(filesize($ready)>$this->config->maxBytes) throw new MediaFailure('size','Prepared MP4 exceeds file size limit');
        $info=$this->probe($ready,$folder); $video=null;
        foreach($info['streams']??[] as $s) if(($s['codec_type']??'')==='video') $video=$s;
        if(!$video||min((int)$video['width'],(int)$video['height'])>$target||($quality!==null&&min((int)$video['width'],(int)$video['height'])!==$quality)) throw new MediaFailure('format','Prepared resolution does not match the selected quality');
        // The selected file, not an estimated playlist label, supplies the final caption's resolution.
        $thumbnail=in_array($site,['beeg','xvideos'],true)?$this->videoThumbnail($ready,$folder):null;
        @unlink($file); // The validated MP4 is the only full video needed during upload.
        return ['path'=>$ready,'title'=>is_string($decoded=json_decode(trim($title),true))?$decoded:'YouTube','thumbnail'=>$thumbnail,
            'height'=>(int)$video['height'],'width'=>(int)$video['width'],
            'quality'=>min((int)$video['height'],(int)$video['width']).'p','duration'=>(int)ceil($duration)];
    }

    /** A small JPEG from the actual video; optional, never another media download. */
    private function videoThumbnail(string $file,string $folder): ?string
    {
        $path=$folder.'/cover.jpg';
        try {
            $this->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$file,
                '-frames:v','1','-vf','scale=320:320:force_original_aspect_ratio=decrease','-q:v','6','-threads','1',$path],$folder,15);
            if(is_file($path)&&filesize($path)>0&&filesize($path)<200000) return $path;
        } catch(MediaFailure) {}
        if(is_file($path)) @unlink($path);
        return null;
    }

    private function probe(string $file, string $folder): array
    {
        $probe=$this->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$file],$folder,30);
        return json_decode($probe,true,512,JSON_THROW_ON_ERROR);
    }
    /** Old entries of yt-dlp's cache (player code of past YouTube versions) are removed; the cache rebuilds itself. */
    public static function pruneCache(Config $config,int $days=7): void
    {
        $root=$config->storage.'/yt-cache';
        if(!is_dir($root)) return;
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if($file->isLink()) { @unlink($file->getPathname()); continue; }
            if($file->isFile()&&$file->getMTime()<time()-$days*86400) @unlink($file->getPathname());
            elseif($file->isDir()) @rmdir($file->getPathname());
        }
    }
    public static function clean(string $folder): void
    {
        if(is_link($folder)) { unlink($folder); return; }
        if (!is_dir($folder)) return;
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $p) {
            if($p->isDir()&&!$p->isLink()) rmdir($p->getPathname()); else unlink($p->getPathname());
        }
        rmdir($folder);
    }
}

