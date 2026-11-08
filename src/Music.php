<?php
declare(strict_types=1);
namespace Zero;

/**
 * Shared by the music services (Spotify, SoundCloud): a bounded text/image fetch and the last step
 * of every track, which puts the real title, artists, year and cover into the MP3.
 */
final class Music
{
    public const MAX_PAGE=2097152, MAX_COVER=3145728;

    /** One GET without redirects, never larger than $limit bytes. Cover and page hosts are checked by the caller. */
    public static function get(Config $config,string $url,int $limit,string $label): string
    {
        $body=''; $ch=curl_init($url);
        $options=[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept-Language: en'],
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_USERAGENT=>'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_WRITEFUNCTION=>function($handle,string $chunk) use (&$body,$limit): int { $body.=$chunk; return strlen($body)>$limit?0:strlen($chunk); }];
        if($config->galleryProxy!=='') $options[CURLOPT_PROXY]=$config->galleryProxy;
        curl_setopt_array($ch,$options);
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $errno=curl_errno($ch); curl_close($ch);
        // Error 23 is the size guard stopping a download that is larger than any real page.
        if($ok===false||$status!==200) throw new MediaFailure($errno===23||$status===404?'format':($errno!==0||$status>=500||$status===429?'network':'format'),$label.' unavailable: '.($status?:$errno));
        return $body;
    }

    /** A small JPEG thumbnail (at most 200 KB) from an address the caller already trusts, or null. */
    public static function cover(Config $config,string $url,string $folder): ?string
    {
        if($url==='') return null;
        try {
            $raw=$folder.'/cover-source'; $jpg=$folder.'/cover.jpg';
            file_put_contents($raw,self::get($config,$url,self::MAX_COVER,'Cover'));
            (new Download($config))->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$raw,'-vf',"scale='min(320,iw)':'min(320,ih)':force_original_aspect_ratio=decrease",'-frames:v','1','-q:v','5',$jpg],$folder,60);
            return is_file($jpg)&&filesize($jpg)>0&&filesize($jpg)<=204800?$jpg:null;
        } catch(\Throwable) { return null; }
    }

    /**
     * Tags the downloaded MP3 without re-encoding it and names the file.
     * @param array{path:string,duration:int} $audio the prepared MP3
     * @param array{title:string,artists:list<string>,duration:int,year:string,cover:string} $meta
     * @return array{path:string,title:string,performer:string,duration:int,length:int,year:string,thumbnail:?string,name:string,mime:string} duration is the file's, length the track's own
     */
    public static function finish(Config $config,array $audio,string $folder,array $meta,string $fallbackName): array
    {
        $runner=new Download($config);
        $performer=implode(', ',$meta['artists']);
        $cover=self::cover($config,$meta['cover'],$folder);
        $extension=strtolower(pathinfo($audio['path'],PATHINFO_EXTENSION))==='m4a'?'m4a':'mp3';
        $tagged=$folder.'/tagged.'.$extension;
        $command=['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$audio['path']];
        if($cover!==null) {
            array_push($command,'-i',$cover,'-map','0:a','-map','1:v','-c','copy');
            // MP3 carries the cover as an ID3 picture; M4A as an attached picture stream.
            if($extension==='mp3') array_push($command,'-id3v2_version','3','-metadata:s:v','title=Cover','-metadata:s:v','comment=Cover (front)');
            else array_push($command,'-disposition:v:0','attached_pic');
        } else {
            array_push($command,'-map','0:a','-c','copy');
            if($extension==='mp3') array_push($command,'-id3v2_version','3');
        }
        array_push($command,'-map_metadata','-1','-metadata','title='.$meta['title'],'-metadata','artist='.$performer);
        if($meta['year']!=='') array_push($command,'-metadata','date='.$meta['year']);
        $command[]=$tagged;
        try { $runner->run($command,$folder,120); }
        catch(MediaFailure) { $tagged=$audio['path']; }
        clearstatcache(true,$tagged);
        if(!is_file($tagged)||filesize($tagged)<1||filesize($tagged)>$config->maxBytes) $tagged=$audio['path'];
        // A title that already starts with the artist ("Artist - Song") is not repeated in the file name.
        $plain=fn(string $text)=>trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($text))??'');
        $label=$plain($meta['title'])!==''&&str_contains($plain($meta['title']),$plain($meta['artists'][0]))?$meta['title']:$performer.' - '.$meta['title'];
        $name=trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u',' ',$label)??'');
        return ['path'=>$tagged,'title'=>$meta['title'],'performer'=>$performer,'duration'=>$audio['duration'],'length'=>$meta['duration'],'year'=>$meta['year'],'thumbnail'=>$cover,
            'name'=>mb_substr($name!==''?$name:$fallbackName,0,120).'.'.$extension,'mime'=>$extension==='m4a'?'audio/mp4':'audio/mpeg'];
    }
}
