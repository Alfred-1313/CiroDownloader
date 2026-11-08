<?php
declare(strict_types=1);
namespace Zero;

/**
 * A Spotify track link becomes an MP3 in three steps: read the public page of the track
 * (title, artists, length, cover), find the same recording on YouTube by name and length,
 * and download that audio with the existing YouTube pipeline. Spotify's own audio is
 * protected and is never touched, so no Spotify login or cookie is involved; the YouTube
 * step uses the YouTube cookies and proxy like any other YouTube download.
 */
final class Spotify
{
    private const MAX_PAGE=2097152, MAX_COVER=3145728, MATCH_MINIMUM=40, DEFAULT_BASE='https://open.spotify.com';
    /** $base is only changed by tests, which serve the track page from a local fixture. */
    public function __construct(private Config $config,private string $base=self::DEFAULT_BASE) {}

    /** The 22-character track ID of a canonical link, or null. */
    public static function id(string $url): ?string
    {
        return preg_match('~^https://open\.spotify\.com/track/([A-Za-z0-9]{22})$~D',$url,$match)===1?$match[1]:null;
    }

    private function fetchText(string $url,int $limit): string
    {
        $body=''; $ch=curl_init($url);
        $options=[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept-Language: en'],
            CURLOPT_USERAGENT=>'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_WRITEFUNCTION=>function($handle,string $chunk) use (&$body,$limit): int { $body.=$chunk; return strlen($body)>$limit?0:strlen($chunk); }];
        if($this->config->galleryProxy!=='') $options[CURLOPT_PROXY]=$this->config->galleryProxy;
        curl_setopt_array($ch,$options);
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $errno=curl_errno($ch); curl_close($ch);
        // Error 23 is the size guard stopping a download that is larger than any real page.
        if($ok===false||$status!==200) throw new MediaFailure($errno===23||$status===404?'format':(($errno!==0||$status>=500||$status===429?'network':'format')),'Spotify page unavailable: '.($status?:$errno));
        return $body;
    }

    /** @return array{title:string,artists:list<string>,duration:int,year:string,cover:string} */
    public function metadata(string $id): array
    {
        $html=$this->fetchText(rtrim($this->base,'/').'/embed/track/'.$id,self::MAX_PAGE);
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
     * must show the name or the artist. Negative means "not this recording".
     */
    public static function score(array $meta,array $candidate): int
    {
        $length=$candidate['duration']??null;
        if(!is_int($length)&&!is_float($length)) return -1000;
        $difference=abs((int)round($length)-$meta['duration']);
        if($difference>20) return -1000;
        $raw=(string)($candidate['title']??''); $channelRaw=(string)($candidate['channel']??$candidate['uploader']??'');
        $title=self::normalize($raw); $full=self::normalize($raw,false); $channel=self::normalize($channelRaw);
        $name=self::normalize($meta['title']); $artist=self::normalize($meta['artists'][0]);
        $nameHit=$name!==''&&str_contains($title,$name);
        $artistHit=$artist!==''&&str_contains($title,$artist);
        $topic=str_ends_with(mb_strtolower(trim($channelRaw)),'- topic')&&$artist!==''&&str_contains($channel,$artist);
        $channelHit=$artist!==''&&($channel===$artist||str_contains($channel,$artist));
        if(!$nameHit&&!$artistHit&&!$channelHit) return -1000;
        $score=70-6*$difference+($nameHit?30:0)+($artistHit?15:0)+($topic?40:($channelHit?30:0));
        // A different version of the song is not the track that was asked for.
        $original=self::normalize($meta['title'].' '.implode(' ',$meta['artists']),false);
        foreach(['live','cover','remix','karaoke','instrumental','nightcore','sped up','slowed','reverb','8d','acoustic','mashup','reaction','tutorial','piano','pianoforte','unplugged','demo','extended','orchestral'] as $word) {
            $pattern='/\b'.preg_quote($word,'/').'\b/u';
            if(preg_match($pattern,$full)&&!preg_match($pattern,$original)) $score-=30;
        }
        if(str_contains($full,'official audio')||str_contains($full,'audio only')) $score+=8;
        if(str_contains($full,'lyric')) $score-=5;
        // Videos often add an intro or outro, so the plain audio upload is preferred when both exist.
        if(preg_match('/\b(?:official video|music video|official music video)\b/u',$full)) $score-=4;
        return $score;
    }
    /** @return array<string,mixed>|null the best match above the minimum, or null */
    public static function best(array $meta,array $candidates): ?array
    {
        $chosen=null; $top=self::MATCH_MINIMUM-1;
        foreach($candidates as $candidate) {
            if(!is_array($candidate)||!preg_match('/^[A-Za-z0-9_-]{11}$/D',(string)($candidate['id']??''))) continue;
            $score=self::score($meta,$candidate);
            if($score>$top) { $top=$score; $chosen=$candidate; }
        }
        return $chosen;
    }

    private function cover(string $url,string $folder): ?string
    {
        if($url==='') return null;
        try {
            $raw=$folder.'/cover-source'; $jpg=$folder.'/cover.jpg';
            file_put_contents($raw,$this->fetchText($url,self::MAX_COVER));
            // Telegram wants a small JPEG for the thumbnail.
            (new Download($this->config))->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$raw,'-vf',"scale='min(320,iw)':'min(320,ih)':force_original_aspect_ratio=decrease",'-frames:v','1','-q:v','5',$jpg],$folder,60);
            return is_file($jpg)&&filesize($jpg)>0&&filesize($jpg)<=204800?$jpg:null;
        } catch(\Throwable) { return null; }
    }

    /**
     * @return array{path:string,title:string,performer:string,duration:int,length:int,year:string,thumbnail:?string,name:string} duration is the file's, length the track's own
     */
    public function fetch(string $url,string $folder): array
    {
        $id=self::id($url); if($id===null) throw new MediaFailure('format','Invalid Spotify track URL');
        $meta=$this->metadata($id);
        $runner=new Download($this->config);
        // Adding "audio" makes the plain audio upload (often the official one) appear before music videos.
        $query=$meta['artists'][0].' '.$meta['title'].' audio';
        $match=self::best($meta,$runner->search($query,$folder));
        if($match===null) throw new MediaFailure('format','No matching recording was found');
        $video='https://www.youtube.com/watch?v='.$match['id'];
        $audio=$runner->fetchBestAudio($video,$folder);
        // The search already compared lengths; this guards against a different file being served.
        if(abs($audio['duration']-$meta['duration'])>30) throw new MediaFailure('format','Downloaded length does not match the track');
        $performer=implode(', ',$meta['artists']);
        $cover=$this->cover($meta['cover'],$folder);
        // Put the real names (and the cover) in the file, without re-encoding the audio.
        $tagged=$folder.'/tagged.mp3';
        $command=['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$audio['path']];
        if($cover!==null) array_push($command,'-i',$cover,'-map','0:a','-map','1:v','-c','copy','-id3v2_version','3','-metadata:s:v','title=Cover','-metadata:s:v','comment=Cover (front)');
        else array_push($command,'-map','0:a','-c','copy','-id3v2_version','3');
        array_push($command,'-map_metadata','-1','-metadata','title='.$meta['title'],'-metadata','artist='.$performer);
        if($meta['year']!=='') array_push($command,'-metadata','date='.$meta['year']);
        $command[]=$tagged;
        try { $runner->run($command,$folder,120); }
        catch(MediaFailure) { $tagged=$audio['path']; }
        clearstatcache(true,$tagged);
        if(!is_file($tagged)||filesize($tagged)<1||filesize($tagged)>$this->config->maxBytes) $tagged=$audio['path'];
        $name=trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u',' ',$performer.' - '.$meta['title'])??'');
        return ['path'=>$tagged,'title'=>$meta['title'],'performer'=>$performer,'duration'=>$audio['duration'],'length'=>$meta['duration'],'year'=>$meta['year'],'thumbnail'=>$cover,
            'name'=>mb_substr($name!==''?$name:'spotify',0,120).'.mp3'];
    }
}
