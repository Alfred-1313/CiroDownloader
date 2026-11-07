<?php
declare(strict_types=1);
namespace Zero;

abstract class GalleryMedia
{
    public const MAX_ITEMS=20;
    protected const SERVICE='instagram', NAME='Instagram', PREFIX='ig', SESSION_COOKIE='sessionid', COOKIE_DOMAIN='instagram.com';
    protected const SESSION_ALIASES=[];
    public static function service(): string { return static::SERVICE; }
    private static function type(string $service): string { return match($service) {'instagram'=>Instagram::class,'pinterest'=>Pinterest::class,'tiktok'=>TikTok::class,'twitter'=>Twitter::class,default=>throw new \InvalidArgumentException('Unknown media service')}; }
    public static function forService(Config $config,string $service): self { $class=self::type($service); return new $class($config); }
    public static function validateFor(string $service,string $content): void { $class=self::type($service); $class::validateCookies($content); }
    public static function poolFor(Config $config,string $service): CookiePool { $class=self::type($service); return $class::pool($config); }
    public static function maxItems(string $service): int { $class=self::type($service); return $class::MAX_ITEMS; }
    protected static function cookiePath(Config $config): string { return $config->galleryCookiePath(static::SERVICE); }
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
            if($domain!==static::COOKIE_DOMAIN&&!str_ends_with($domain,'.'.static::COOKIE_DOMAIN)) throw new \RuntimeException('Export only '.static::COOKIE_DOMAIN.' cookies');
            if(in_array($fields[5],[static::SESSION_COOKIE,...static::SESSION_ALIASES],true)&&$fields[6]!==''&&((int)$fields[4]===0||(int)$fields[4]>time())) $session=true;
        }
        if(!$session) throw new \RuntimeException('No unexpired '.static::NAME.' session cookie');
    }
    /** The session cookie value identifies the account, so one account is never stored twice. */
    public static function cookieIdentity(string $content): string
    {
        $found=[];
        foreach(preg_split('/\r?\n/',$content) as $line) {
            if(str_starts_with($line,'#HttpOnly_')) $line=substr($line,10);
            elseif($line===''||str_starts_with($line,'#')) continue;
            $fields=explode("\t",$line);
            if(count($fields)===7&&$fields[6]!==''&&in_array($fields[5],[static::SESSION_COOKIE,...static::SESSION_ALIASES],true)) $found[$fields[5]]=$fields[6];
        }
        foreach([static::SESSION_COOKIE,...static::SESSION_ALIASES] as $name) if(isset($found[$name])) return $name.'='.$found[$name];
        return '';
    }
    /** All cookie jars of this service only; other services keep separate pools. */
    public static function pool(Config $config): CookiePool
    {
        return new CookiePool(static::cookiePath($config),[static::class,'validateCookies'],[static::class,'cookieIdentity'],static::COOKIE_DOMAIN,[static::SESSION_COOKIE,...static::SESSION_ALIASES]);
    }
    /** @return array{status:string,id:string} */
    public static function saveCookies(Config $config,string $content): array { return static::pool($config)->add($content); }
    public static function deleteCookies(Config $config,string $digest): bool { return static::pool($config)->clear($digest); }
    public function fetch(string $url,string $folder): array
    {
        $url=galleryUrl(static::SERVICE,$url);
        if(!$url) throw new MediaFailure('format','Invalid '.static::NAME.' post URL');
        $runner=new Download($this->config);
        $python=dirname($this->config->galleryDl).'/python';
        $backend=basename($this->config->galleryDl)==='gallery-dl'&&is_executable($python)
            ?[$python,dirname(__DIR__).'/bin/'.static::SERVICE.'-download.py']:[$this->config->galleryDl];
        $command=array_merge($backend,['--ignore-config','--no-input','--quiet','--retries','2','--http-timeout','20',
            '--directory',$folder,'--filename',static::PREFIX.'-{num:02d}.{extension}',
            '-o','extractor.'.static::SERVICE.'.videos='.(static::SERVICE==='instagram'?'merged':'true'),
            '-o','extractor.'.static::SERVICE.'.previews=false','-o','extractor.tiktok.audio=false','-o','extractor.tiktok.covers=false',
            '-o','extractor.twitter.quoted=false','-o','extractor.twitter.conversations=false','-o','extractor.twitter.cards=false','-o','extractor.twitter.articles=false',
            '--range','1-'.(static::MAX_ITEMS+1),'--Print','after:CIRO|{num}|{count}|{_path}',
            '--Print','post:CIRO_META|{description!j}']);
        // Instagram, Pinterest, TikTok and X may need a cleaner exit address than the server's.
        if($this->config->galleryProxy!=='') $command=array_merge($command,['--proxy',$this->config->galleryProxy]);
        $pool=static::pool($this->config); $jars=$pool->order(3);
        // Anonymous first when cookies are only a last resort; otherwise rotate through the jars.
        $attempts=!$jars?[null]:($this->config->cookieFallback?array_merge([null],$jars):$jars);
        $output=''; $failure=null;
        foreach($attempts as $jar) {
            $arguments=[];
            if($jar!==null) {
                $content=(string)file_get_contents($jar['path']);
                try { static::validateCookies($content); }
                catch(\RuntimeException $e) { $pool->markFailed($jar['id']); $failure=new MediaFailure('cookies',$e->getMessage()); continue; }
                $private=$folder.'/'.static::SERVICE.'-cookies.txt';
                if(file_put_contents($private,$content)!==strlen($content)||!chmod($private,0600)) throw new MediaFailure('cookies','Cannot create private '.static::NAME.' cookie copy');
                $arguments=['--cookies',$private];
            }
            error_log(static::NAME.' provider=gallery-dl cookies='.($jar?'yes jar='.$jar['id']:'no'));
            // Partial files of an earlier refused attempt must not be mistaken for this one's result.
            foreach(glob($folder.'/'.static::PREFIX.'-*')?:[] as $stale) if(is_file($stale)) unlink($stale);
            try { $output=$runner->run(array_merge($command,$arguments,['--',$url]),$folder,$this->config->timeout,$this->config->maxBytes*(static::MAX_ITEMS+1),$this->config->maxBytes); $failure=null; }
            catch(MediaFailure $e) {
                if(preg_match('/login|authentication|authorization|unauthorized|forbidden|checkpoint|challenge|private|rate.limit|429|403|not found/i',$e->getMessage())) $e=new MediaFailure('blocked',$e->getMessage());
                $failure=$e;
                if($e->reason!=='blocked') throw $e;
                if($jar!==null) $pool->markFailed($jar['id']);
                continue;
            }
            if($jar!==null) $pool->markWorking($jar['id']);
            break;
        }
        if($failure!==null) throw $failure;
        $files=[]; $count=null; $description='';
        foreach(explode("\n",$output) as $line) {
            if(str_starts_with($line,'CIRO_META|')) {
                $value=json_decode(substr($line,10),true);
                if(is_string($value)) $description=mb_substr($value,0,20000);
                continue;
            }
            if(!str_starts_with($line,'CIRO|')) continue;
            $parts=explode('|',$line,4);
            if(count($parts)!==4||!ctype_digit($parts[1])||!ctype_digit($parts[2])) throw new MediaFailure('format','Invalid '.static::NAME.' manifest');
            $index=(int)$parts[1]; $total=(int)$parts[2]; $file=realpath($parts[3]);
            if($index<1||$total<1||$total>static::MAX_ITEMS||isset($files[$index])||($count!==null&&$count!==$total)
                ||$file===false||dirname($file)!==realpath($folder)||is_link($parts[3])||!is_file($file)
                ||!preg_match('/^'.static::PREFIX.'-\d+\.(jpg|jpeg|png|webp|mp4|mkv|mov)$/Di',basename($file))) throw new MediaFailure('format',static::NAME.' media manifest is incomplete or invalid');
            if(filesize($file)<1||filesize($file)>$this->config->maxBytes) throw new MediaFailure('size',static::NAME.' media exceeds file size limit');
            $files[$index]=$file; $count=$total;
        }
        ksort($files,SORT_NUMERIC);
        if(!$files) throw new MediaFailure('format',static::NAME.' returned no downloadable visual media');
        if(count($files)!==$count||array_keys($files)!==range(1,$count)) throw new MediaFailure('format',static::NAME.' did not return every slide of the post: expected='.$count.' received='.count($files).' indexes='.implode(',',array_keys($files)));
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
            if($image[0]<1||$image[1]<1) throw new MediaFailure('format','Invalid '.static::NAME.' image');
            if($image[2]===IMAGETYPE_JPEG&&array_sum(array_slice($image,0,2))<=10000&&max($image[0],$image[1])/min($image[0],$image[1])<=20&&filesize($source)<=10000000) return ['type'=>'photo','path'=>$source];
            $ready=$folder.'/ready.jpg';
            $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$source,'-vf',"scale='min(4096,iw)':'min(4096,ih)':force_original_aspect_ratio=decrease",'-frames:v','1','-update','1','-q:v','2',$ready],$folder,120);
            if(!is_file($ready)||filesize($ready)>min(10000000,$this->config->maxBytes)) throw new MediaFailure('size',static::NAME.' image exceeds Telegram photo limit');
            $size=getimagesize($ready);
            if($size===false||max($size[0],$size[1])/max(1,min($size[0],$size[1]))>20) throw new MediaFailure('format',static::NAME.' image dimensions are unsupported');
            return ['type'=>'photo','path'=>$ready];
        }
        $probe=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$source],$folder,30),true,512,JSON_THROW_ON_ERROR);
        $video=null; $audio=null;
        foreach($probe['streams']??[] as $stream) {
            if(($stream['codec_type']??'')==='video') $video=$stream;
            if(($stream['codec_type']??'')==='audio') $audio=$stream;
        }
        $duration=(float)($probe['format']['duration']??0);
        if(!$video||$duration<=0) throw new MediaFailure('format',static::NAME.' video is missing or invalid');
        $ready=$folder.'/ready.mp4';
        $args=['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$source,'-map','0:v:0','-map','0:a:0?'];
        $copy=($video['codec_name']??'')==='h264'&&($video['pix_fmt']??'')==='yuv420p';
        $args=array_merge($args,$copy?['-c:v','copy']:['-c:v','libx264','-preset','veryfast','-crf','23','-threads','2','-pix_fmt','yuv420p']);
        if($audio) $args=array_merge($args,($audio['codec_name']??'')==='aac'?['-c:a','copy']:['-c:a','aac','-b:a','128k']);
        $runner->run(array_merge($args,['-movflags','+faststart',$ready]),$folder,$this->config->timeout);
        if(!is_file($ready)||filesize($ready)<1||filesize($ready)>$this->config->maxBytes) throw new MediaFailure('size','Prepared '.static::NAME.' video exceeds file size limit');
        return ['type'=>'video','path'=>$ready,'width'=>(int)$video['width'],'height'=>(int)$video['height'],'duration'=>(int)ceil($duration)];
    }
}
