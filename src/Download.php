<?php
declare(strict_types=1);
namespace Zero;

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
                if ($bytes>$this->config->maxBytes*3) throw new \RuntimeException('Temporary size limit exceeded');
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
        if ($exit!==0) throw new \RuntimeException('Media process failed');
        return file_get_contents($stdout)?:'';
    }
    public function fetch(string $url, string $folder): array
    {
        $canonical=youtubeUrl($url);
        if (!$canonical) throw new \RuntimeException('Invalid YouTube URL');
        $title=$this->run([$this->config->ytDlp,'--ignore-config','--no-playlist','--no-progress','--quiet','--no-warnings',
            '--no-cache-dir','--js-runtimes','node','--socket-timeout','20','--retries','2','--fragment-retries','2',
            '--abort-on-unavailable-fragments','--max-filesize',(string)$this->config->maxBytes,
            '--match-filter','!is_live & duration <= 1200',
            '-f','bv[height<=480][ext=mp4][vcodec^=avc1]+ba[ext=m4a]/b[height<=480][ext=mp4]',
            '--merge-output-format','mp4','--print','after_move:%(title)j',
            '-o',$folder.'/video.%(ext)s','--',$canonical],$folder,$this->config->timeout);
        $file=$folder.'/video.mp4'; clearstatcache(true,$file);
        if(!is_file($file)||filesize($file)<1||filesize($file)>$this->config->maxBytes) throw new \RuntimeException('Missing or oversized video');
        $probe=$this->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$file],$folder,30);
        $info=json_decode($probe,true,512,JSON_THROW_ON_ERROR); $video=null; $audio=false;
        foreach($info['streams']??[] as $s) {
            if(($s['codec_type']??'')==='video') $video=$s;
            if(($s['codec_type']??'')==='audio') $audio=true;
        }
        if(!$video||!$audio||(int)$video['height']>480) throw new \RuntimeException('Invalid video streams');
        return ['path'=>$file,'title'=>is_string($decoded=json_decode(trim($title),true))?$decoded:'YouTube',
            'height'=>(int)$video['height'],'width'=>(int)$video['width'],'duration'=>(int)($info['format']['duration']??0)];
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
