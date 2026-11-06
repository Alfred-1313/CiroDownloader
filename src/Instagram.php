<?php
declare(strict_types=1);
namespace Zero;

final class Instagram
{
    public const MAX_ITEMS=20;
    public function __construct(private Config $config) {}
    public static function validateCookies(string $content): void
    {
        if(strlen($content)>1048576||!preg_match('/^# (?:Netscape )?HTTP Cookie File/',$content)) throw new \RuntimeException('Expected a Netscape cookie export smaller than 1 MB');
        $session=false;
        foreach(preg_split('/\r?\n/',$content) as $line) {
            if(str_starts_with($line,'#HttpOnly_')) $line=substr($line,10);
            elseif($line===''||str_starts_with($line,'#')) continue;
            $fields=explode("\t",$line);
            if(count($fields)!==7||!ctype_digit($fields[4])||!in_array($fields[1],['TRUE','FALSE'],true)||!in_array($fields[3],['TRUE','FALSE'],true)) throw new \RuntimeException('Invalid Netscape cookie row');
            $domain=ltrim(strtolower($fields[0]),'.');
            if($domain!=='instagram.com'&&!str_ends_with($domain,'.instagram.com')) throw new \RuntimeException('Export only instagram.com cookies');
            if($fields[5]==='sessionid'&&$fields[6]!==''&&((int)$fields[4]===0||(int)$fields[4]>time())) $session=true;
        }
        if(!$session) throw new \RuntimeException('No unexpired Instagram session cookie');
    }
    public static function saveCookies(Config $config,string $content): void
    {
        self::validateCookies($content);
        $path=$config->instagramCookiePath(); $lock=fopen($path.'.lock','c');
        if(!$lock) throw new \RuntimeException('Cannot lock Instagram cookies');
        chmod($path.'.lock',0600); $temporary=dirname($path).'/.cookies-import-'.bin2hex(random_bytes(8));
        try {
            if(!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock Instagram cookies');
            if(file_put_contents($temporary,$content)!==strlen($content)||!chmod($temporary,0600)||!rename($temporary,$path)) throw new \RuntimeException('Cannot save Instagram cookies');
        } finally { if(is_file($temporary)) unlink($temporary); flock($lock,LOCK_UN); fclose($lock); }
    }
    public static function deleteCookies(Config $config,string $hash): bool
    {
        $path=$config->instagramCookiePath(); $lock=fopen($path.'.lock','c');
        if(!$lock) throw new \RuntimeException('Cannot lock Instagram cookies');
        chmod($path.'.lock',0600);
        try {
            if(!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock Instagram cookies');
            clearstatcache(true,$path);
            if(!is_file($path)||!hash_equals($hash,hash_file('sha256',$path))) return false;
            if(!unlink($path)) throw new \RuntimeException('Cannot delete Instagram cookies');
            return true;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public function fetch(string $url,string $folder): array
    {
        $url=instagramUrl($url);
        if(!$url) throw new MediaFailure('format','Invalid Instagram post URL');
        $runner=new Download($this->config);
        $python=dirname($this->config->galleryDl).'/python';
        $backend=basename($this->config->galleryDl)==='gallery-dl'&&is_executable($python)
            ?[$python,dirname(__DIR__).'/bin/instagram-download.py']:[$this->config->galleryDl];
        $command=array_merge($backend,['--ignore-config','--no-input','--quiet','--retries','2','--http-timeout','20',
            '--directory',$folder,'--filename','ig-{num:02d}.{extension}',
            '-o','extractor.instagram.videos=merged','-o','extractor.instagram.previews=false',
            '--range','1-21','--Print','after:CIRO|{num}|{count}|{_path}',
            '--Print','post:CIRO_META|{description!j}']);
        $path=$this->config->instagramCookiePath();
        if(is_file($path)) {
            if(!is_readable($path)||filesize($path)>1048576) throw new MediaFailure('cookies','Instagram cookie file is unreadable');
            $content=(string)file_get_contents($path);
            try { self::validateCookies($content); } catch(\RuntimeException $e) { throw new MediaFailure('cookies',$e->getMessage()); }
            $private=$folder.'/instagram-cookies.txt';
            if(file_put_contents($private,$content)!==strlen($content)||!chmod($private,0600)) throw new MediaFailure('cookies','Cannot create private Instagram cookie copy');
            $command=array_merge($command,['--cookies',$private]);
        }
        error_log('Instagram provider=gallery-dl cookies='.(is_file($path)?'yes':'no'));
        try { $output=$runner->run(array_merge($command,['--',$url]),$folder,$this->config->timeout,$this->config->maxBytes*(self::MAX_ITEMS+1),$this->config->maxBytes); }
        catch(MediaFailure $e) {
            if(preg_match('/login|authentication|checkpoint|challenge|private|rate.limit|429|403|not found/i',$e->getMessage())) throw new MediaFailure('blocked',$e->getMessage());
            throw $e;
        }
        $files=[]; $count=null; $description='';
        foreach(explode("\n",$output) as $line) {
            if(str_starts_with($line,'CIRO_META|')) {
                $value=json_decode(substr($line,10),true);
                if(is_string($value)) $description=mb_substr($value,0,20000);
                continue;
            }
            if(!str_starts_with($line,'CIRO|')) continue;
            $parts=explode('|',$line,4);
            if(count($parts)!==4||!ctype_digit($parts[1])||!ctype_digit($parts[2])) throw new MediaFailure('format','Invalid Instagram manifest');
            $index=(int)$parts[1]; $total=(int)$parts[2]; $file=realpath($parts[3]);
            if($index<1||$total<1||$total>self::MAX_ITEMS||isset($files[$index])||($count!==null&&$count!==$total)
                ||$file===false||dirname($file)!==realpath($folder)||is_link($parts[3])||!is_file($file)
                ||!preg_match('/^ig-\d+\.(jpg|jpeg|png|webp|mp4|mkv|mov)$/Di',basename($file))) throw new MediaFailure('format','Instagram media manifest is incomplete or invalid');
            if(filesize($file)<1||filesize($file)>$this->config->maxBytes) throw new MediaFailure('size','Instagram media exceeds file size limit');
            $files[$index]=$file; $count=$total;
        }
        ksort($files,SORT_NUMERIC);
        if(!$files) throw new MediaFailure('format','Instagram returned no downloadable visual media');
        if(count($files)!==$count||array_keys($files)!==range(1,$count)) throw new MediaFailure('format','Instagram did not return every slide of the post: expected='.$count.' received='.count($files).' indexes='.implode(',',array_keys($files)));
        $result=[];
        foreach($files as $index=>$file) {
            $work=$folder.'/item-'.$index; mkdir($work,0700);
            $source=$work.'/source.'.pathinfo($file,PATHINFO_EXTENSION); rename($file,$source);
            $result[]=$this->prepare($source,$work,$runner)+['index'=>$index,'count'=>$count,'description'=>$description];
        }
        return $result;
    }
    private function prepare(string $source,string $folder,Download $runner): array
    {
        $image=@getimagesize($source);
        if($image!==false) {
            if($image[0]<1||$image[1]<1) throw new MediaFailure('format','Invalid Instagram image');
            if($image[2]===IMAGETYPE_JPEG&&array_sum(array_slice($image,0,2))<=10000&&max($image[0],$image[1])/min($image[0],$image[1])<=20&&filesize($source)<=10000000) return ['type'=>'photo','path'=>$source];
            $ready=$folder.'/ready.jpg';
            $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$source,'-vf',"scale='min(4096,iw)':'min(4096,ih)':force_original_aspect_ratio=decrease",'-frames:v','1','-update','1','-q:v','2',$ready],$folder,120);
            if(!is_file($ready)||filesize($ready)>min(10000000,$this->config->maxBytes)) throw new MediaFailure('size','Instagram image exceeds Telegram photo limit');
            $size=getimagesize($ready);
            if($size===false||max($size[0],$size[1])/max(1,min($size[0],$size[1]))>20) throw new MediaFailure('format','Instagram image dimensions are unsupported');
            return ['type'=>'photo','path'=>$ready];
        }
        $probe=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$source],$folder,30),true,512,JSON_THROW_ON_ERROR);
        $video=null; $audio=null;
        foreach($probe['streams']??[] as $stream) {
            if(($stream['codec_type']??'')==='video') $video=$stream;
            if(($stream['codec_type']??'')==='audio') $audio=$stream;
        }
        $duration=(float)($probe['format']['duration']??0);
        if(!$video||$duration<=0) throw new MediaFailure('format','Instagram video is missing or invalid');
        $ready=$folder.'/ready.mp4';
        $args=['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$source,'-map','0:v:0','-map','0:a:0?'];
        $copy=($video['codec_name']??'')==='h264'&&($video['pix_fmt']??'')==='yuv420p';
        $args=array_merge($args,$copy?['-c:v','copy']:['-c:v','libx264','-preset','veryfast','-crf','23','-threads','2','-pix_fmt','yuv420p']);
        if($audio) $args=array_merge($args,($audio['codec_name']??'')==='aac'?['-c:a','copy']:['-c:a','aac','-b:a','128k']);
        $runner->run(array_merge($args,['-movflags','+faststart',$ready]),$folder,$this->config->timeout);
        if(!is_file($ready)||filesize($ready)<1||filesize($ready)>$this->config->maxBytes) throw new MediaFailure('size','Prepared Instagram video exceeds file size limit');
        return ['type'=>'video','path'=>$ready,'width'=>(int)$video['width'],'height'=>(int)$video['height'],'duration'=>(int)ceil($duration)];
    }
}
