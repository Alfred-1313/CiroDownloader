<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Music.php';

/**
 * A Spotify track link becomes an MP3 in three steps: read the public page of the track
 * (title, artists, length, cover), find the same recording on YouTube by name and length,
 * and download that audio with the existing YouTube pipeline. Spotify's own audio is
 * protected and is never touched, so no Spotify login or cookie is involved; the YouTube
 * step uses the YouTube cookies and proxy like any other YouTube download.
 */
final class Spotify
{
    private const MATCH_MINIMUM=40, DEFAULT_BASE='https://open.spotify.com';
    /** $base is only changed by tests, which serve the track page from a local fixture. */
    public function __construct(private Config $config,private string $base=self::DEFAULT_BASE) {}

    /** The 22-character track ID of a canonical link, or null. */
    public static function id(string $url): ?string
    {
        return preg_match('~^https://open\.spotify\.com/track/([A-Za-z0-9]{22})$~D',$url,$match)===1?$match[1]:null;
    }

    /** @return array{title:string,artists:list<string>,duration:int,year:string,cover:string} */
    public function metadata(string $id): array
    {
        $html=Music::get($this->config,rtrim($this->base,'/').'/embed/track/'.$id,Music::MAX_PAGE,'Spotify page');
        if(!preg_match('~<script id="__NEXT_DATA__" type="application/json">(.*?)</script>~s',$html,$match)) throw new MediaFailure('format','Spotify page has no track data');
        $data=json_decode($match[1],true);
        $entity=$data['props']['pageProps']['state']['data']['entity']??null;
        if(!is_array($entity)||($entity['type']??'')!=='track'||($entity['id']??'')!==$id) throw new MediaFailure('format','Spotify returned a different item');
        $title=trim((string)($entity['name']??$entity['title']??''));
        $artists=[];
        foreach(is_array($entity['artists']??null)?$entity['artists']:[] as $artist) if(is_string($artist['name']??null)&&trim($artist['name'])!=='') $artists[]=mb_substr(trim($artist['name']),0,100);
        $duration=(int)round(((int)($entity['duration']??0))/1000);
        if($title===''||!$artists||$duration<1||$duration>14400) throw new MediaFailure('format','Spotify track data is incomplete');
        $cover=''; $best=0;
        foreach(is_array($entity['visualIdentity']['image']??null)?$entity['visualIdentity']['image']:[] as $image) {
            $url=(string)($image['url']??''); $size=(int)($image['maxHeight']??0);
            $host=strtolower((string)parse_url($url,PHP_URL_HOST));
            $trusted=str_starts_with($url,'https://')&&($host==='scdn.co'||str_ends_with($host,'.scdn.co')||str_ends_with($host,'.spotifycdn.com'));
            if(($trusted||($this->base!==self::DEFAULT_BASE&&str_starts_with($url,rtrim($this->base,'/').'/')))&&$size>=$best) { $cover=$url; $best=$size; }
        }
        return ['title'=>mb_substr($title,0,200),'artists'=>array_slice($artists,0,5),'duration'=>$duration,
            'year'=>preg_match('/^(\d{4})/',(string)($entity['releaseDate']['isoString']??''),$year)?$year[1]:'','cover'=>$cover];
    }

    /** Lower case words only. Text in brackets is kept unless $dropBrackets, which removes "(Remastered)"-style notes. */
    private static function normalize(string $text,bool $dropBrackets=true): string
    {
        $text=mb_strtolower($text);
        if($dropBrackets) $text=preg_replace('/[\(\[\{].*?[\)\]\}]/u',' ',$text)??$text;
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$text)??$text);
    }
    /**
     * How well a YouTube result matches the track: its length must be close and its title or channel
     * must show the name or the artist. Negative means "not this recording". With $strict (uploads by
     * anyone, as on SoundCloud) both the name and the artist must show.
     */
    public static function score(array $meta,array $candidate,bool $strict=false): int
    {
        $length=$candidate['duration']??null;
        if(!is_int($length)&&!is_float($length)) return -1000;
        $difference=abs((int)round($length)-$meta['duration']);
        // Anyone can upload to SoundCloud, so there the length must be almost exact.
        if($difference>($strict?5:20)) return -1000;
        $raw=(string)($candidate['title']??''); $channelRaw=(string)($candidate['channel']??$candidate['uploader']??'');
        $title=self::normalize($raw); $full=self::normalize($raw,false); $channel=self::normalize($channelRaw);
        $name=self::normalize($meta['title']); $artist=self::normalize($meta['artists'][0]);
        $nameHit=$name!==''&&str_contains($title,$name);
        $artistHit=$artist!==''&&str_contains($title,$artist);
        $topic=str_ends_with(mb_strtolower(trim($channelRaw)),'- topic')&&$artist!==''&&str_contains($channel,$artist);
        $channelHit=$artist!==''&&($channel===$artist||str_contains($channel,$artist));
        if(!$nameHit&&!$artistHit&&!$channelHit) return -1000;
        if($strict&&!($nameHit&&($artistHit||$channelHit))) return -1000;
        $score=70-6*$difference+($nameHit?30:0)+($artistHit?15:0)+($topic?40:($channelHit?30:0));
        // A different version of the song is not the track that was asked for.
        $original=self::normalize($meta['title'].' '.implode(' ',$meta['artists']),false);
        foreach(['live','cover','remix','karaoke','instrumental','nightcore','sped up','slowed','reverb','8d','acoustic','mashup','reaction','tutorial','piano','pianoforte','unplugged','demo','extended','orchestral'] as $word) {
            $pattern='/\b'.preg_quote($word,'/').'\b/u';
            if(preg_match($pattern,$full)&&!preg_match($pattern,$original)) { if($strict) return -1000; $score-=30; }
        }
        if(str_contains($full,'official audio')||str_contains($full,'audio only')) $score+=8;
        if(str_contains($full,'lyric')) $score-=5;
        // Videos often add an intro or outro, so the plain audio upload is preferred when both exist.
        if(preg_match('/\b(?:official video|music video|official music video)\b/u',$full)) $score-=4;
        return $score;
    }
    /** @return list<array<string,mixed>> the results that match well enough, best first */
    public static function ranked(array $meta,array $candidates,bool $strict=false): array
    {
        $scored=[];
        foreach($candidates as $candidate) {
            if(!is_array($candidate)||!preg_match($strict?'/^[0-9]{1,20}$/D':'/^[A-Za-z0-9_-]{11}$/D',(string)($candidate['id']??''))) continue;
            $score=self::score($meta,$candidate,$strict);
            if($score>=self::MATCH_MINIMUM) $scored[]=[$score,$candidate];
        }
        // usort is stable, so equal scores keep the order of the site's own ranking.
        usort($scored,fn($a,$b)=>$b[0]<=>$a[0]);
        return array_column($scored,1);
    }
    /** @return array<string,mixed>|null the best match above the minimum, or null */
    public static function best(array $meta,array $candidates,bool $strict=false): ?array
    {
        return self::ranked($meta,$candidates,$strict)[0]??null;
    }
    /**
     * Download the best match; if that one cannot be fetched, the next two. A refusal of the whole site
     * (blocked, cookies, network) or a size/disk problem is the same for every result, so it stops at once.
     * @param list<array<string,mixed>> $ranked
     * @param callable(array):array $get
     */
    private static function firstAudio(array $ranked,callable $get,string $folder): array
    {
        $last=null;
        foreach(array_slice($ranked,0,3) as $candidate) {
            try { return $get($candidate); }
            catch(MediaFailure $e) {
                if(in_array($e->reason,['blocked','cookies','network','size','storage'],true)) throw $e;
                $last=$e;
                foreach(glob($folder.'/{source.*,ready.mp3}',GLOB_BRACE)?:[] as $leftover) @unlink($leftover);
            }
        }
        throw $last??new MediaFailure('format','No matching recording was found');
    }

    /**
     * @return array{path:string,title:string,performer:string,duration:int,length:int,year:string,thumbnail:?string,name:string} see Music::finish
     */
    public function fetch(string $url,string $folder): array
    {
        $id=self::id($url); if($id===null) throw new MediaFailure('format','Invalid Spotify track URL');
        return $this->fromDetails($this->metadata($id),$folder);
    }

    /**
     * A track whose title, artists, length, year and cover are already known (from a Spotify page or a menu).
     * @param array{title:string,artists:list<string>,duration:int,year:string,cover:string} $meta
     * @return array{path:string,title:string,performer:string,duration:int,length:int,year:string,thumbnail:?string,name:string} see Music::finish
     */
    /** @param list<string> $only the sources to try, in order (SoundCloud tracks that cannot be taken directly use YouTube only) */
    public function fromDetails(array $meta,string $folder,array $only=['youtube','soundcloud']): array
    {
        $title=trim((string)($meta['title']??'')); $artists=array_values(array_filter((array)($meta['artists']??[]),fn($name)=>is_string($name)&&trim($name)!==''));
        $duration=(int)($meta['duration']??0);
        if($title===''||!$artists||$duration<1||$duration>14400) throw new MediaFailure('format','Track details are incomplete');
        $cover=(string)($meta['cover']??'');
        $host=strtolower((string)parse_url($cover,PHP_URL_HOST));
        $trusted=str_starts_with($cover,'https://')&&($host==='scdn.co'||str_ends_with($host,'.scdn.co')||str_ends_with($host,'.spotifycdn.com')||$host==='dzcdn.net'||str_ends_with($host,'.dzcdn.net')||str_ends_with($host,'.deezer.com'));
        $meta=['title'=>mb_substr($title,0,200),'artists'=>array_slice(array_map(fn($name)=>mb_substr(trim($name),0,100),$artists),0,5),'duration'=>$duration,
            'year'=>preg_match('/^\d{4}$/D',(string)($meta['year']??''))?(string)$meta['year']:'','cover'=>$trusted||($this->base!==self::DEFAULT_BASE&&str_starts_with($cover,rtrim($this->base,'/').'/'))?$cover:''];
        $runner=new Download($this->config);
        // YouTube has the official uploads, so it goes first. When it refuses this server (its bot check) or has
        // no good match, SoundCloud is tried, so Spotify does not depend on YouTube cookies alone.
        $sources=[
            'youtube'=>function() use($runner,$meta,$folder) {
                // Adding "audio" makes the plain audio upload (often the official one) appear before music videos.
                $ranked=self::ranked($meta,$runner->search($meta['artists'][0].' '.$meta['title'].' audio',$folder));
                if(!$ranked) throw new MediaFailure('format','No matching recording was found on YouTube');
                return self::firstAudio($ranked,fn($match)=>$runner->fetchBestAudio('https://www.youtube.com/watch?v='.$match['id'],$folder),$folder);
            },
            'soundcloud'=>function() use($runner,$meta,$folder) {
                $ranked=self::ranked($meta,$runner->searchSoundCloud($meta['artists'][0].' '.$meta['title'],$folder),true);
                if(!$ranked) throw new MediaFailure('format','No matching recording was found on SoundCloud');
                return self::firstAudio($ranked,fn($match)=>$runner->fetchSoundCloudAudio((string)$match['id'],$folder),$folder);
            },
        ];
        $sources=array_intersect_key($sources,array_flip($only));
        $audio=null; $failure=null; $tried=[]; $rank=['blocked'=>3,'cookies'=>2,'network'=>1];
        foreach($sources as $source=>$find) {
            try {
                $audio=$find();
                // The search already compared lengths; this guards against a different file being served.
                if(abs($audio['duration']-$meta['duration'])>30) throw new MediaFailure('format','Downloaded length does not match the track');
                error_log('Spotify source='.$source);
                break;
            } catch(MediaFailure $e) {
                // Too big or no disk space is the same everywhere; anything else may work from another source.
                if(in_array($e->reason,['size','storage'],true)) throw $e;
                error_log('Spotify source='.$source.' failed reason='.$e->reason);
                $tried[$source]=$e->reason; $audio=null;
                foreach(glob($folder.'/{source.*,ready.mp3}',GLOB_BRACE)?:[] as $leftover) @unlink($leftover);
                // Report the most useful cause: a refusal says more than "not found".
                if($failure===null||($rank[$e->reason]??0)>($rank[$failure->reason]??0)) $failure=$e;
            }
        }
        if($audio===null) {
            // The administrator sees both reasons (youtube=blocked, soundcloud=format), not only the most telling one.
            $summary=implode(', ',array_map(fn($source,$reason)=>$source.'='.$reason,array_keys($tried),$tried));
            throw new MediaFailure($failure?->reason??'format','Spotify audio sources failed ['.$summary.']: '.mb_substr($failure?->getMessage()??'no source',0,240));
        }
        return Music::finish($this->config,$audio,$folder,$meta,'spotify');
    }
}
