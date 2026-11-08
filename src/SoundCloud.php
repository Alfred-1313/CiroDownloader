<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Music.php';
require_once __DIR__.'/Spotify.php';

/**
 * A SoundCloud track link becomes an MP3 with the track's own title, artist and cover. Public tracks need
 * no account or cookie. Tracks that SoundCloud offers only as a 30 s preview, private tracks and anything
 * that is not a single track are refused.
 */
final class SoundCloud
{
    private const HOPS=3;
    /** $shortBase is only changed by tests, which serve the share-link redirect from a local fixture. */
    public function __construct(private Config $config,private ?string $shortBase=null) {}

    /** The canonical track address; an on.soundcloud.com share link is followed through at most three validated hops. */
    public function resolve(string $url): string
    {
        for($hop=0;$hop<=self::HOPS;$hop++) {
            $canonical=soundcloudUrl($url);
            if($canonical===null) throw new MediaFailure('format','Not a SoundCloud track address');
            if(!str_starts_with($canonical,'https://on.soundcloud.com/')) return $canonical;
            $target=$this->shortBase!==null?rtrim($this->shortBase,'/').'/'.basename($canonical):$canonical;
            $url=$this->location($target);
        }
        throw new MediaFailure('format','Too many redirects from a SoundCloud share link');
    }

    private function location(string $url): string
    {
        $location=''; $ch=curl_init($url);
        $options=[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_USERAGENT=>'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HEADERFUNCTION=>function($handle,string $line) use (&$location): int { if(stripos($line,'location:')===0) $location=trim(substr($line,9)); return strlen($line); },
            // Only the redirect matters; the body is dropped.
            CURLOPT_WRITEFUNCTION=>fn($handle,string $chunk): int => 0];
        if($this->config->galleryProxy!=='') $options[CURLOPT_PROXY]=$this->config->galleryProxy;
        curl_setopt_array($ch,$options);
        curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $errno=curl_errno($ch); curl_close($ch);
        if($location===''||$status<300||$status>399) throw new MediaFailure($errno!==0&&$errno!==23||$status>=500?'network':'format','SoundCloud share link did not redirect: '.($status?:$errno));
        if(str_starts_with($location,'/')) $location='https://soundcloud.com'.$location;
        return $location;
    }

    /** Turns the last part of an address into a readable title, for lists where SoundCloud gives none. */
    private static function titleFromSlug(string $url): string
    {
        $slug=basename((string)parse_url($url,PHP_URL_PATH));
        $slug=preg_replace('/-\d+$/','',$slug)??$slug;
        $text=trim(str_replace(['-','_'],' ',$slug));
        return $text===''?'SoundCloud':mb_strtoupper(mb_substr($text,0,1)).mb_substr($text,1);
    }
    /**
     * The tracks and sets of a profile list or set, as menu items.
     * @return array{title:string,items:list<array>}
     */
    public function listing(string $url,string $folder,int $limit=40): array
    {
        $data=(new Download($this->config))->listSoundCloud($url,$folder,$limit);
        $title=trim(preg_replace('/\s*\((?:All|Tracks|Albums|Sets|Reposts|Likes|Spotlight)\)\s*$/u','',(string)($data['title']??''))??'');
        $items=[];
        foreach(is_array($data['entries']??null)?$data['entries']:[] as $entry) {
            if(!is_array($entry)) continue;
            $address=(string)($entry['url']??$entry['webpage_url']??'');
            $name=mb_substr(trim((string)($entry['title']??'')),0,150);
            $set=collectionUrl($address);
            if($set!==null&&$set['service']==='soundcloud'&&$set['kind']==='set') { $items[]=['k'=>'s','n'=>$name!==''?$name:self::titleFromSlug($address),'u'=>$set['url']]; continue; }
            $track=soundcloudUrl($address);
            if($track===null||str_starts_with($track,'https://on.')) continue;
            // A bare API address has no readable name; the menu numbers such tracks.
            $items[]=['k'=>'t','n'=>$name!==''?$name:(str_contains($track,'api.soundcloud.com')?'':self::titleFromSlug($track)),'a'=>'','d'=>(int)round((float)($entry['duration']??0)),'u'=>$track];
        }
        return ['title'=>mb_substr($title,0,120),'items'=>array_slice($items,0,$limit)];
    }

    /** @return array{title:string,artists:list<string>,duration:int,year:string,cover:string} */
    public static function metadata(array $info): array
    {
        $title=trim((string)($info['title']??''));
        $artists=[];
        foreach(is_array($info['artists']??null)?$info['artists']:[] as $artist) if(is_string($artist)&&trim($artist)!=='') $artists[]=mb_substr(trim($artist),0,100);
        if(!$artists&&is_string($info['uploader']??null)&&trim($info['uploader'])!=='') $artists[]=mb_substr(trim($info['uploader']),0,100);
        $duration=(int)round((float)($info['duration']??0));
        if($title===''||!$artists||$duration<1||$duration>43200) throw new MediaFailure('format','SoundCloud track data is incomplete');
        $cover=''; $best=0;
        foreach(is_array($info['thumbnails']??null)?$info['thumbnails']:[] as $thumbnail) {
            $url=(string)($thumbnail['url']??''); $host=strtolower((string)parse_url($url,PHP_URL_HOST));
            $size=(int)($thumbnail['width']??0)?:(preg_match('/^t(\d+)x\d+$/',(string)($thumbnail['id']??''),$m)?(int)$m[1]:0);
            if(str_starts_with($url,'https://')&&($host==='sndcdn.com'||str_ends_with($host,'.sndcdn.com'))&&$size>=$best) { $cover=$url; $best=$size; }
        }
        $stamp=(int)($info['release_timestamp']??$info['timestamp']??0);
        return ['title'=>mb_substr($title,0,200),'artists'=>array_slice($artists,0,5),'duration'=>$duration,'year'=>$stamp>0?gmdate('Y',$stamp):'','cover'=>$cover];
    }

    /**
     * @return array{path:string,title:string,performer:string,duration:int,length:int,year:string,thumbnail:?string,name:string,page:string} see Music::finish
     */
    public function fetch(string $url,string $folder): array
    {
        $track=$this->resolve($url);
        $runner=new Download($this->config);
        try {
            $result=$runner->fetchSoundCloudTrack($track,$folder);
            $meta=self::metadata($result['info']);
            // A 30 s preview of a longer track is not the track, even if a format slipped through.
            if($result['audio']['duration']+30<$meta['duration']) throw new MediaFailure('format','Only a preview of the track was available');
        } catch(MediaFailure $direct) {
            if(in_array($direct->reason,['size','storage'],true)) throw $direct;
            // The file itself cannot be taken here (DRM on an official upload, a preview only, or SoundCloud refused):
            // read its details and find the same recording on YouTube, exactly as for a Spotify track.
            foreach(glob($folder.'/{source.*,ready.mp3}',GLOB_BRACE)?:[] as $leftover) @unlink($leftover);
            error_log('SoundCloud direct download failed reason='.$direct->reason.'; trying the same recording on YouTube');
            try {
                $info=$runner->soundCloudInfo($track,$folder);
                $meta=self::metadata($info);
                $meta['artists']=array_values(array_filter(array_map('trim',explode(',',implode(',',$meta['artists'])))));
                $media=(new Spotify($this->config))->fromDetails($meta,$folder,['youtube']);
            } catch(MediaFailure $other) {
                $reason=in_array($other->reason,['blocked','cookies','network'],true)?$other->reason:$direct->reason;
                throw new MediaFailure($reason,'SoundCloud audio sources failed [soundcloud='.$direct->reason.', youtube='.$other->reason.']: '.mb_substr($other->getMessage(),0,240));
            }
            $page=soundcloudUrl((string)($info['webpage_url']??''));
            return $media+['page'=>$page!==null&&!str_contains($page,'api.soundcloud.com')?$page:$track];
        }
        $media=Music::finish($this->config,$result['audio'],$folder,$meta,'soundcloud');
        // The track's own page, for the source button, when it was reached through a bare API address.
        $page=soundcloudUrl((string)($result['info']['webpage_url']??''));
        return $media+['page'=>$page!==null&&!str_contains($page,'api.soundcloud.com')?$page:$track];
    }
}
