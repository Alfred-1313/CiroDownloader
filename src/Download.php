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
        $stdout=$folder.'/stdout.log'; $stderr=$folder.'/stderr.log';
        // GNU timeout manages the child process group. No URL is interpreted by a shell.
        $command=array_merge(['timeout','--signal=TERM','--kill-after=5',(string)$seconds],$command);
        $proc=proc_open($command,[0=>['file','/dev/null','r'],1=>['file',$stdout,'w'],2=>['file',$stderr,'w']],$pipes,$folder,
            ['PATH'=>getenv('PATH')?:'/usr/local/bin:/usr/bin:/bin','HOME'=>$folder,'LANG'=>'C.UTF-8']);
        if (!is_resource($proc)) throw new \RuntimeException('Cannot start downloader');
        $exit=-1;
        $nextStorageCheck=0.0;
        try {
            do {
                $status=proc_get_status($proc);
                if (!$status['running']) { $exit=$status['exitcode']; break; }
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
            proc_close($proc);
        }
        if ($exit!==0) {
            $detail=is_file($stderr)?(string)file_get_contents($stderr):'';
            throw MediaFailure::process($exit,substr($detail,-6000));
        }
        return file_get_contents($stdout)?:'';
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
        return [$this->config->ytDlp,'--ignore-config','--no-playlist','--no-progress','--quiet',
            '--no-cache-dir','--js-runtimes','node','--socket-timeout','20','--retries','2','--fragment-retries','2',
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
    public function inspect(string $url,string $folder,bool $forceCookies=false): array
    {
        $canonical=youtubeUrl($url);
        if(!$canonical) throw new \RuntimeException('Invalid YouTube URL');
        $json=$this->youtube($canonical,['--dump-single-json','--skip-download'],$folder,min(120,$this->config->timeout),$forceCookies);
        try { $info=json_decode($json,true,512,JSON_THROW_ON_ERROR); }
        catch(\JsonException) { throw new MediaFailure('format','Downloader metadata is invalid'); }
        if(!is_array($info)) throw new MediaFailure('format','Downloader metadata is missing');
        $options=self::qualityOptions($info);
        $audio=self::audioOptions($info);
        if($audio) $options['audio']=$audio;
        if(!$options) throw new MediaFailure('format','No downloadable media formats found');
        return ['title'=>mb_substr((string)($info['title']??'YouTube'),0,180),'options'=>$options,'poster'=>self::posterUrl($info,$canonical)];
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
        return $this->audioDownload($url,$folder,self::FULL_AUDIO,fn(array $options)=>$this->plain($url,$options,$folder,$this->config->timeout));
    }
    /**
     * One SoundCloud track page: its audio as MP3 plus the track's own details (title, artists, length, cover, date).
     * Only the full track is taken; the 30 s previews SoundCloud offers for some tracks are never chosen.
     * @return array{audio:array,info:array}
     */
    public function fetchSoundCloudTrack(string $url,string $folder): array
    {
        if(!preg_match('~^https://(?:soundcloud\.com/[A-Za-z0-9_-]{1,64}/[A-Za-z0-9_-]{1,128}|api\.soundcloud\.com/tracks/soundcloud%3Atracks%3A[0-9]{1,20})$~D',$url)) throw new MediaFailure('format','Invalid SoundCloud track address');
        $audio=$this->audioDownload($url,$folder,self::FULL_AUDIO,fn(array $options)=>$this->plain($url,$options,$folder,$this->config->timeout),['--write-info-json']);
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
        $canonical=youtubeUrl($url);
        if(!$canonical) throw new \RuntimeException('Invalid YouTube URL');
        $selector=[];
        foreach($formats as $format) {
            if(!is_array($format)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)($format['id']??''))) throw new MediaFailure('format','Invalid audio format identifier');
            $selector[]=$format['id'];
        }
        if(!$selector) throw new MediaFailure('format','No saved audio formats are available');
        return $this->audioDownload($canonical,$folder,implode('/',$selector));
    }
    /** The best audio of a video without listing its formats first; one request fewer than inspect + fetchAudio. */
    public function fetchBestAudio(string $url,string $folder): array
    {
        $canonical=youtubeUrl($url);
        if(!$canonical) throw new \RuntimeException('Invalid YouTube URL');
        return $this->audioDownload($canonical,$folder,'ba/b');
    }
    /** @param ?callable(list<string>):string $runner downloads with the given options; null means YouTube with its cookies */
    private function audioDownload(string $canonical,string $folder,string $selector,?callable $runner=null,array $extra=[]): array
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
        return ['path'=>$ready,'title'=>is_string($decoded=json_decode(trim($title),true))?$decoded:'YouTube',
            'format'=>'MP3','duration'=>(int)ceil($duration)];
    }
    public function fetch(string $url, string $folder, ?int $quality=null, array $formats=[]): array
    {
        $canonical=youtubeUrl($url);
        if(!$canonical) throw new \RuntimeException('Invalid YouTube URL');
        $target=$quality??480;
        if($target<72||$target>8640) throw new MediaFailure('format','Invalid selected resolution');
        $selector=[];
        foreach($formats as $format) {
            if(!is_array($format)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)($format['id']??''))) throw new MediaFailure('format','Invalid format identifier');
            $selector[]=$format['id'].(!empty($format['audio'])?'':'+ba');
        }
        if($quality!==null&&!$selector) throw new MediaFailure('format','The selected quality has no saved formats');
        $title=$this->youtube($canonical,[
            '--concurrent-fragments','4',
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
        return ['path'=>$ready,'title'=>is_string($decoded=json_decode(trim($title),true))?$decoded:'YouTube',
            'height'=>(int)$video['height'],'width'=>(int)$video['width'],
            'quality'=>min((int)$video['height'],(int)$video['width']).'p','duration'=>(int)ceil($duration)];
    }

    private function probe(string $file, string $folder): array
    {
        $probe=$this->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$file],$folder,30);
        return json_decode($probe,true,512,JSON_THROW_ON_ERROR);
    }
    public static function clean(string $folder): void
    {
        if (!is_dir($folder)) return;
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $p) {
            if($p->isDir()&&!$p->isLink()) rmdir($p->getPathname()); else unlink($p->getPathname());
        }
        rmdir($folder);
    }
}

