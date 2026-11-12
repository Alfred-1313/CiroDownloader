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
        // Track embeds use coverArt.sources; older/artist-style payloads use visualIdentity.image.
        $images=array_merge(is_array($entity['coverArt']['sources']??null)?$entity['coverArt']['sources']:[],
            is_array($entity['visualIdentity']['image']??null)?$entity['visualIdentity']['image']:[]);
        foreach($images as $image) {
            if(!is_array($image)) continue;
            $url=(string)($image['url']??''); $size=(int)($image['height']??$image['maxHeight']??0);
            $host=strtolower((string)parse_url($url,PHP_URL_HOST));
            $trusted=str_starts_with($url,'https://')&&($host==='scdn.co'||str_ends_with($host,'.scdn.co')||str_ends_with($host,'.spotifycdn.com'));
            if(($trusted||($this->base!==self::DEFAULT_BASE&&str_starts_with($url,rtrim($this->base,'/').'/')))&&$size>=$best) { $cover=$url; $best=$size; }
        }
        return ['title'=>mb_substr($title,0,200),'artists'=>array_slice($artists,0,5),'duration'=>$duration,
            'year'=>preg_match('/^(\d{4})/',(string)($entity['releaseDate']['isoString']??''),$year)?$year[1]:'','cover'=>$cover];
    }

    /** How long (seconds) YouTube is asked second after it refused the server. */
    public const REFUSED_SECONDS=900;
    /** True while YouTube's last answer to this server was its bot check (shared by every worker process through a file). */
    /** True when YouTube's state was learned in the last minutes (refused, or a download worked), so no probe is needed. */
    public static function youtubeKnown(Config $config): bool
    {
        $ok=$config->storage.'/.youtube-ok'; clearstatcache(true,$ok);
        return self::youtubeRefused($config)||(is_file($ok)&&time()-(int)filemtime($ok)<self::REFUSED_SECONDS);
    }
    public static function youtubeRefused(Config $config): bool
    {
        $flag=$config->storage.'/.youtube-blocked'; clearstatcache(true,$flag);
        return is_file($flag)&&time()-(int)filemtime($flag)<self::REFUSED_SECONDS;
    }
    /** Lower case words only. Text in brackets is kept unless $dropBrackets, which removes "(Remastered)"-style notes. */
    private static function normalize(string $text,bool $dropBrackets=true): string
    {
        $text=mb_strtolower($text);
        if($dropBrackets) $text=preg_replace('/[\(\[\{].*?[\)\]\}]/u',' ',$text)??$text;
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$text)??$text);
    }
    /**
     * The consonants of a name, the same for its Latin spellings and its Persian script: "Ahle Naa Ahli", "Ahle Na Ahli"
     * and «اهل نا اهلی» all become "hlnhl". Vowels, y/w/v and doubled letters are dropped and sh/kh/gh/ch/zh, q and the
     * Persian letters map to one form each, because Persian names are written in many ways on these sites.
     */
    public static function skeleton(string $text): string
    {
        $text=mb_strtolower($text);
        $text=strtr($text,['ب'=>'b','پ'=>'p','ت'=>'t','ث'=>'s','ج'=>'j','چ'=>'c','ح'=>'h','خ'=>'x','د'=>'d','ذ'=>'z','ر'=>'r','ز'=>'z','ژ'=>'Z','س'=>'s','ش'=>'S','ص'=>'s','ض'=>'z',
            'ط'=>'t','ظ'=>'z','غ'=>'q','ف'=>'f','ق'=>'q','ک'=>'k','ك'=>'k','گ'=>'g','ل'=>'l','م'=>'m','ن'=>'n','ه'=>'h','ة'=>'h','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        $text=strtr($text,['sh'=>'S','kh'=>'x','gh'=>'q','ch'=>'c','zh'=>'Z','ph'=>'f','ck'=>'k','th'=>'t']);
        // Vowels in both scripts, and the letters that are vowels in one spelling and consonants in another.
        $text=preg_replace('/[aeiouyvw\x{0627}\x{0622}\x{0623}\x{0625}\x{0621}\x{0626}\x{0624}\x{06CC}\x{064A}\x{0648}\x{0639}]|[^\p{L}\p{N}]/u','',$text)??'';
        return preg_replace('/(.)\1+/u','$1',$text)??$text;
    }
    /** Whether $needle shows in $haystack, as written or by its consonants (long enough to mean something). */
    private static function shows(string $needle,string $haystack,int $least): bool
    {
        if($needle==='') return false;
        if(str_contains(self::normalize($haystack),self::normalize($needle))) return true;
        $bones=self::skeleton(self::normalize($needle));
        return mb_strlen($bones)>=$least&&str_contains(self::skeleton(self::normalize($haystack,false)),$bones);
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
        // Written the same, or with the same consonants (another transliteration, or Persian script).
        $nameHit=$name!==''&&(str_contains($title,$name)||self::shows($meta['title'],$raw,4));
        $artistHit=$artist!==''&&(str_contains($title,$artist)||self::shows($meta['artists'][0],$raw,3));
        $topic=str_ends_with(mb_strtolower(trim($channelRaw)),'- topic')&&$artist!==''&&str_contains($channel,$artist);
        $channelHit=$artist!==''&&($channel===$artist||str_contains($channel,$artist)||self::shows($meta['artists'][0],$channelRaw,3));
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
    /**
     * @param list<string> $only the sources to try, in order
     * @param list<string> $skipSoundCloud SoundCloud tracks not to take (a locked original that sent us here)
     */
    public function fromDetails(array $meta,string $folder,array $only=['youtube','soundcloud'],array $skipSoundCloud=[]): array
    {
        $title=trim((string)($meta['title']??'')); $artists=array_values(array_filter((array)($meta['artists']??[]),fn($name)=>is_string($name)&&trim($name)!==''));
        $duration=(int)($meta['duration']??0);
        if($title===''||!$artists||$duration<1||$duration>14400) throw new MediaFailure('format','Track details are incomplete');
        $cover=(string)($meta['cover']??'');
        $trusted=Music::imageUrl($cover)!=='';
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
            'soundcloud'=>function() use($runner,$meta,$folder,$skipSoundCloud) {
                $found=array_filter($runner->searchSoundCloud($meta['artists'][0].' '.$meta['title'],$folder),fn($result)=>!in_array((string)$result['id'],$skipSoundCloud,true));
                $ranked=self::ranked($meta,array_values($found),true);
                if(!$ranked) throw new MediaFailure('format','No matching recording was found on SoundCloud');
                return self::firstAudio($ranked,fn($match)=>$runner->fetchSoundCloudAudio((string)$match['id'],$folder),$folder);
            },
        ];
        $sources=array_intersect_key($sources,array_flip($only));
        // When YouTube refused this server a moment ago (its bot check), asking it again first costs every track the
        // same slow failure. For a while SoundCloud goes first; YouTube is still tried when SoundCloud has no match.
        $flag=$this->config->storage.'/.youtube-blocked';
        if(isset($sources['youtube'],$sources['soundcloud'])&&self::youtubeRefused($this->config)) $sources=['soundcloud'=>$sources['soundcloud'],'youtube'=>$sources['youtube']];
        $clock=hrtime(true); $audio=null; $failure=null; $tried=[]; $rank=['blocked'=>3,'cookies'=>2,'network'=>1];
        foreach($sources as $source=>$find) {
            try {
                $audio=$find();
                // The search already compared lengths; this guards against a different file being served.
                if(abs($audio['duration']-$meta['duration'])>30) throw new MediaFailure('format','Downloaded length does not match the track');
                if($source==='youtube') { if(is_file($flag)) @unlink($flag); @touch($this->config->storage.'/.youtube-ok'); }
                break;
            } catch(MediaFailure $e) {
                // Too big or no disk space is the same everywhere; anything else may work from another source.
                if(in_array($e->reason,['size','storage'],true)) throw $e;
                if($source==='youtube'&&$e->reason==='blocked') { @touch($flag); @unlink($this->config->storage.'/.youtube-ok'); }
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
        $found=(int)round((hrtime(true)-$clock)/1e6);
        $media=Music::finish($this->config,$audio,$folder,$meta,'spotify');
        // Where the time goes: finding and downloading the audio, then the cover and the tags.
        return $media;
    }
}
