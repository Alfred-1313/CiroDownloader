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
            'blocked'=>'یوتیوب دسترسی این سرور را محدود کرده و تأیید ورود می‌خواهد. مدیر باید اتصال سرور به یوتیوب را بررسی کند.',
            'size'=>'حجم این ویدیو از سقف ارسال ربات بیشتر است؛ یک ویدیوی کوتاه‌تر بفرست.',
            'restricted'=>'این ویدیو خصوصی، حذف‌شده یا دارای محدودیت دسترسی است و قابل دانلود نیست.',
            'duration'=>'پخش زنده یا ویدیوی بیشتر از ۲۰ دقیقه پشتیبانی نمی‌شود.',
            'network'=>'اتصال سرور به یوتیوب برقرار نشد؛ کمی بعد دوباره تلاش کن.',
            'format'=>'فرمت قابل دانلود این ویدیو در دسترس نیست؛ جزئیات برای مدیر ثبت شد.',
            default=>'دانلود یا آماده‌سازی ویدیو انجام نشد؛ جزئیات برای مدیر ثبت شد.',
        };
    }

    public static function process(int $exit, string $detail): self
    {
        $reason=match(true) {
            preg_match('/not a bot|sign in to confirm|po.?token|HTTP Error (403|429)/i',$detail)===1=>'blocked',
            preg_match('/max.filesize|larger than|too large|size limit/i',$detail)===1=>'size',
            preg_match('/private video|video unavailable|removed|age.restricted|not available in your country/i',$detail)===1=>'restricted',
            preg_match('/does not pass filter|live stream|duration limit/i',$detail)===1=>'duration',
            $exit===124||preg_match('/timed out|unable to download webpage|network is unreachable|name resolution|connection refused/i',$detail)===1=>'network',
            preg_match('/requested format.*not available/i',$detail)===1=>'format',
            default=>'process',
        };
        return new self($reason,"Media process exit $exit: $detail");
    }
}

final class Download
{
    public function __construct(private Config $config) {}
    public function run(array $command, string $folder, int $seconds): string
    {
        $stdout=$folder.'/stdout.log'; $stderr=$folder.'/stderr.log';
        // GNU timeout manages the child process group. No URL is interpreted by a shell.
        $command=array_merge(['timeout','--signal=TERM','--kill-after=5',(string)$seconds],$command);
        $proc=proc_open($command,[0=>['file','/dev/null','r'],1=>['file',$stdout,'w'],2=>['file',$stderr,'w']],$pipes,$folder,
            ['PATH'=>getenv('PATH')?:'/usr/local/bin:/usr/bin:/bin','HOME'=>$folder,'LANG'=>'C.UTF-8']);
        if (!is_resource($proc)) throw new \RuntimeException('Cannot start downloader');
        $exit=-1;
        try {
            do {
                $status=proc_get_status($proc);
                if (!$status['running']) { $exit=$status['exitcode']; break; }
                $bytes=0;
                foreach (glob($folder.'/*')?:[] as $p) { clearstatcache(true,$p); if(is_file($p)) $bytes+=filesize($p); }
                if ($bytes>$this->config->maxBytes*3) throw new MediaFailure('size','Temporary size limit exceeded');
                usleep(250000);
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
    public function fetch(string $url, string $folder): array
    {
        $canonical=youtubeUrl($url);
        if (!$canonical) throw new \RuntimeException('Invalid YouTube URL');
        $title=$this->run([$this->config->ytDlp,'--ignore-config','--no-playlist','--no-progress','--quiet',
            '--no-cache-dir','--js-runtimes','node','--socket-timeout','20','--retries','2','--fragment-retries','2',
            '--abort-on-unavailable-fragments','--max-filesize',(string)$this->config->maxBytes,
            '--match-filter','!is_live & duration <= 1200','--break-on-reject',
            // res uses the shorter edge: 480x854 Shorts are valid 480p videos.
            '-f','bv*+ba/b','-S','res:480,vcodec:h264,acodec:aac',
            '--merge-output-format','mkv','--print','after_move:%(title)j',
            '-o',$folder.'/video.%(ext)s','--',$canonical],$folder,$this->config->timeout);
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
        if($duration<=0||$duration>1200) throw new MediaFailure('duration','Video exceeds duration limit or has no duration');

        // Telegram clients need H.264/AAC MP4; accept other source codecs and convert them.
        $ready=$folder.'/ready.mp4';
        $compatible=($video['codec_name']??'')==='h264'&&($audio['codec_name']??'')==='aac'
            &&min((int)$video['width'],(int)$video['height'])<=480;
        $args=['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$file,'-map','0:v:0','-map','0:a:0'];
        if($compatible) {
            $args=array_merge($args,['-c','copy']);
        } else {
            $args=array_merge($args,['-vf',"scale='if(gte(iw,ih),-2,min(480,iw))':'if(gte(iw,ih),min(480,ih),-2)'",
                '-c:v','libx264','-preset','veryfast','-crf','25','-threads','2','-pix_fmt','yuv420p','-c:a','aac','-b:a','96k']);
        }
        $this->run(array_merge($args,['-movflags','+faststart',$ready]),$folder,$this->config->timeout);
        clearstatcache(true,$ready);
        if(!is_file($ready)||filesize($ready)<1) throw new MediaFailure('format','Prepared MP4 is missing');
        if(filesize($ready)>$this->config->maxBytes) throw new MediaFailure('size','Prepared MP4 exceeds file size limit');
        $info=$this->probe($ready,$folder); $video=null;
        foreach($info['streams']??[] as $s) if(($s['codec_type']??'')==='video') $video=$s;
        if(!$video||min((int)$video['width'],(int)$video['height'])>480) throw new MediaFailure('format','Prepared resolution exceeds 480p');
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
