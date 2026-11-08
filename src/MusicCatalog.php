<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Music.php';

/**
 * What a music menu shows, read from public sources that need no account or key:
 * Spotify's embed pages (the artist's popular tracks, an album's or playlist's tracks),
 * Deezer's public API (an artist's albums, singles, playlists and similar artists) and
 * SoundCloud's own listings through yt-dlp. Nothing here downloads audio.
 *
 * Items are small arrays so a whole menu fits in one database row:
 *  track:    k=t, n title, a artists text, d seconds, u address (Spotify or SoundCloud track) or m meta (Deezer)
 *  album:    k=a, id (Deezer), n title, y year, c cover
 *  playlist: k=p, id (Deezer), n title
 *  artist:   k=r, id (Deezer), n name
 *  set:      k=s, u address (SoundCloud), n title
 */
final class MusicCatalog
{
    public const PAGE_LIMIT=300, MAX_ARTISTS=20;
    public function __construct(private Config $config,private string $spotifyBase='https://open.spotify.com',private string $deezerBase='https://api.deezer.com') {}

    /** Lower case words only; text in brackets such as "(Remastered)" is dropped. */
    public static function plain(string $text): string
    {
        $text=mb_strtolower($text);
        $text=preg_replace('/[\(\[\{].*?[\)\]\}]/u',' ',$text)??$text;
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$text)??$text);
    }
    private static function clip(string $text,int $length): string { return mb_substr(trim($text),0,$length); }
    private static function hostOk(string $url,array $suffixes): bool
    {
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        if(!str_starts_with($url,'https://')) return false;
        foreach($suffixes as $suffix) if($host===$suffix||str_ends_with($host,'.'.$suffix)) return true;
        return false;
    }

    /** @return array{title:string,subtitle:string,tracks:list<array>} */
    public function spotifyPage(string $kind,string $id): array
    {
        if(!in_array($kind,['artist','album','playlist'],true)||!preg_match('/^[A-Za-z0-9]{22}$/D',$id)) throw new MediaFailure('format','Invalid Spotify page');
        $html=Music::get($this->config,rtrim($this->spotifyBase,'/').'/embed/'.$kind.'/'.$id,Music::MAX_PAGE,'Spotify page');
        if(!preg_match('~<script id="__NEXT_DATA__" type="application/json">(.*?)</script>~s',$html,$match)) throw new MediaFailure('format','Spotify page has no data');
        $entity=json_decode($match[1],true)['props']['pageProps']['state']['data']['entity']??null;
        if(!is_array($entity)||($entity['type']??'')!==$kind||($entity['id']??'')!==$id) throw new MediaFailure('format','Spotify returned a different item');
        $title=self::clip((string)($entity['name']??$entity['title']??''),120);
        if($title==='') throw new MediaFailure('format','Spotify page has no title');
        $tracks=[];
        foreach(is_array($entity['trackList']??null)?$entity['trackList']:[] as $row) {
            if(!is_array($row)||!preg_match('~^spotify:track:([A-Za-z0-9]{22})$~D',(string)($row['uri']??''),$uri)) continue;
            $name=self::clip((string)($row['title']??''),150);
            if($name===''||($row['isPlayable']??true)===false) continue;
            $tracks[]=['k'=>'t','n'=>$name,'a'=>self::clip((string)($row['subtitle']??''),150),'d'=>(int)round(((int)($row['duration']??0))/1000),'u'=>'https://open.spotify.com/track/'.$uri[1]];
        }
        return ['title'=>$title,'subtitle'=>$kind==='artist'?'':self::clip((string)($entity['subtitle']??''),120),'tracks'=>$tracks];
    }

    private function deezer(string $path): array
    {
        $body=Music::get($this->config,rtrim($this->deezerBase,'/').$path,Music::MAX_PAGE,'Deezer');
        $data=json_decode($body,true);
        if(!is_array($data)||isset($data['error'])) throw new MediaFailure('network','Deezer returned an error');
        return $data;
    }
    /** @return list<array> every page of a Deezer list, up to PAGE_LIMIT entries */
    private function deezerList(string $path): array
    {
        $result=[]; $index=0;
        do {
            $data=$this->deezer($path.(str_contains($path,'?')?'&':'?').'limit=100&index='.$index);
            $rows=is_array($data['data']??null)?$data['data']:[];
            array_push($result,...$rows);
            $index+=100;
        } while(count($rows)===100&&$index<self::PAGE_LIMIT&&isset($data['next']));
        return array_slice($result,0,self::PAGE_LIMIT);
    }
    private function deezerTrack(array $row,array $album=[]): ?array
    {
        $title=self::clip((string)($row['title']??''),150); $seconds=(int)($row['duration']??0);
        if($title===''||$seconds<1||!isset($row['id'])) return null;
        $artists=[];
        foreach(is_array($row['contributors']??null)?$row['contributors']:[] as $artist) if(is_string($artist['name']??null)&&trim($artist['name'])!=='') $artists[]=self::clip($artist['name'],100);
        if(!$artists&&is_string($row['artist']['name']??null)) $artists[]=self::clip($row['artist']['name'],100);
        if(!$artists) return null;
        $cover=(string)($row['album']['cover_big']??$album['c']??'');
        return ['k'=>'t','n'=>$title,'a'=>implode(', ',array_slice($artists,0,4)),'d'=>$seconds,
            'm'=>['artists'=>array_slice($artists,0,5),'year'=>(string)($album['y']??''),'cover'=>self::hostOk($cover,['dzcdn.net','deezer.com'])?$cover:'']];
    }

    /** The Deezer artist that is the same person as a Spotify artist, or null. Names are not enough, so the popular tracks must overlap. */
    public function matchArtist(string $name,array $spotifyTracks): ?array
    {
        $wanted=self::plain($name);
        $candidates=[];
        foreach($this->deezer('/search/artist?limit=10&q='.rawurlencode($name))['data']??[] as $row) {
            if(is_array($row)&&isset($row['id'])&&self::plain((string)($row['name']??''))===$wanted) $candidates[]=['id'=>(int)$row['id'],'fans'=>(int)($row['nb_fan']??0)];
        }
        usort($candidates,fn($a,$b)=>$b['fans']<=>$a['fans']);
        $known=array_filter(array_map(fn($track)=>self::plain($track['n']),$spotifyTracks));
        $best=null;
        foreach(array_slice($candidates,0,3) as $candidate) {
            $top=[];
            foreach(array_slice($this->deezerList('/artist/'.$candidate['id'].'/top'),0,50) as $row) if(is_array($row)&&($track=$this->deezerTrack($row))) $top[]=$track;
            $titles=array_map(fn($track)=>self::plain($track['n']),$top);
            $overlap=count(array_intersect($known,$titles));
            if($best===null||$overlap>$best['overlap']) $best=['id'=>$candidate['id'],'overlap'=>$overlap,'top'=>$top];
        }
        if($best===null) return null;
        // With few popular tracks to compare, one shared title is all there can be.
        return $best['overlap']>=min(2,max(1,count($known)))?$best:null;
    }

    /** @return array{title:string,tabs:array<string,array>} the Deezer discography of one artist */
    public function deezerArtist(int $id,?array $top=null): array
    {
        $artist=$this->deezer('/artist/'.$id);
        $title=self::clip((string)($artist['name']??''),120);
        if($title==='') throw new MediaFailure('format','Deezer artist has no name');
        $tabs=[];
        if($top===null) { $top=[]; foreach(array_slice($this->deezerList('/artist/'.$id.'/top'),0,50) as $row) if(is_array($row)&&($track=$this->deezerTrack($row))) $top[]=$track; }
        if($top) $tabs['popular']=['k'=>'t','items'=>$top];
        $groups=['album'=>'albums','single'=>'singles','ep'=>'eps','compile'=>'compilations']; $seen=[];
        $albums=array_filter($this->deezerList('/artist/'.$id.'/albums'),'is_array');
        usort($albums,fn($a,$b)=>strcmp((string)($b['release_date']??''),(string)($a['release_date']??'')));
        foreach($albums as $row) {
            $group=$groups[(string)($row['record_type']??'album')]??'albums';
            $name=self::clip((string)($row['title']??''),150);
            $key=$group.'|'.mb_strtolower($name);
            if($name===''||!isset($row['id'])||isset($seen[$key])) continue;
            $seen[$key]=true;
            $cover=(string)($row['cover_big']??'');
            $tabs[$group]['k']='a';
            $tabs[$group]['items'][]=['k'=>'a','id'=>(int)$row['id'],'n'=>$name,'y'=>substr((string)($row['release_date']??''),0,4),'c'=>self::hostOk($cover,['dzcdn.net','deezer.com'])?$cover:''];
        }
        $playlists=[];
        try {
            foreach(array_slice($this->deezerList('/artist/'.$id.'/playlists'),0,50) as $row) {
                $name=self::clip((string)($row['title']??''),150);
                if(is_array($row)&&isset($row['id'])&&$name!=='') $playlists[]=['k'=>'p','id'=>(int)$row['id'],'n'=>$name];
            }
        } catch(MediaFailure) { /* The other lists are still useful. */ }
        if($playlists) $tabs['playlists']=['k'=>'p','items'=>$playlists];
        $related=[];
        try {
            foreach(array_slice($this->deezer('/artist/'.$id.'/related?limit='.self::MAX_ARTISTS)['data']??[],0,self::MAX_ARTISTS) as $row) {
                $name=self::clip((string)($row['name']??''),100);
                if(is_array($row)&&isset($row['id'])&&$name!=='') $related[]=['k'=>'r','id'=>(int)$row['id'],'n'=>$name];
            }
        } catch(MediaFailure) {}
        if($related) $tabs['related']=['k'=>'r','items'=>$related];
        $ordered=[];
        foreach(['popular','albums','singles','eps','compilations','playlists','related'] as $key) if(isset($tabs[$key])) $ordered[$key]=$tabs[$key];
        return ['title'=>$title,'tabs'=>$ordered];
    }

    /** @return list<array> the tracks of a Deezer album ($album is its menu item) or playlist */
    public function deezerTracks(string $kind,int $id,array $album=[]): array
    {
        if(!in_array($kind,['album','playlist'],true)) throw new MediaFailure('format','Invalid Deezer list');
        $tracks=[];
        foreach($this->deezerList('/'.$kind.'/'.$id.'/tracks') as $row) if(is_array($row)&&($track=$this->deezerTrack($row,$album))) $tracks[]=$track;
        return $tracks;
    }

    /**
     * Everything a new menu needs. $listing reads a SoundCloud address with yt-dlp (injected so this class never spawns processes).
     * @param callable(string):array{title:string,items:list<array>} $listing
     * @return array{service:string,kind:string,title:string,subtitle:string,note:string,tabs:array<string,array>,views:array}
     */
    public function build(array $collection,callable $listing): array
    {
        ['service'=>$service,'kind'=>$kind,'id'=>$id]=$collection;
        $data=['service'=>$service,'kind'=>$kind,'title'=>'','subtitle'=>'','note'=>'','tabs'=>[],'views'=>[]];
        if($service==='deezer') {
            $artist=$this->deezerArtist((int)$id);
            return ['title'=>$artist['title'],'tabs'=>$artist['tabs']]+$data;
        }
        if($service==='spotify') {
            $page=$this->spotifyPage($kind,$id);
            $data['title']=$page['title']; $data['subtitle']=$page['subtitle'];
            if($kind!=='artist') {
                if(!$page['tracks']) throw new MediaFailure('format','The Spotify list has no playable tracks');
                $data['tabs']['tracks']=['k'=>'t','items'=>array_slice($page['tracks'],0,100)];
                return $data;
            }
            try { $match=$this->matchArtist($page['title'],$page['tracks']); }
            catch(MediaFailure) { $match=null; }
            if($match!==null) {
                try { $artist=$this->deezerArtist($match['id'],$match['top']); $data['tabs']=$artist['tabs']; }
                catch(MediaFailure) { $match=null; }
            }
            // Spotify's own popular tracks come first: they are exactly what the artist's page shows.
            if($page['tracks']) $data['tabs']=['popular'=>['k'=>'t','items'=>$page['tracks']]]+$data['tabs'];
            if($match===null) $data['note']='limited';
            if(!$data['tabs']) throw new MediaFailure('format','Nothing to show for this artist');
            return $data;
        }
        // SoundCloud: the lists are read one by one when they are opened, because each takes a moment.
        if($kind==='set') {
            $list=$listing($collection['url']);
            if(!$list['items']) throw new MediaFailure('format','The set has no tracks');
            $data['title']=$list['title']?:'SoundCloud'; $data['tabs']['tracks']=['k'=>'t','items'=>$list['items']];
            return $data;
        }
        [$user]=explode('/',$id); $first=str_contains($id,'/')?explode('/',$id)[1]:'tracks';
        $data['title']=$user;
        $list=$listing('https://soundcloud.com/'.$user.'/'.$first);
        if($list['title']!=='') $data['title']=$list['title'];
        $labels=['tracks'=>'t','albums'=>'s','sets'=>'s','reposts'=>'t','likes'=>'t'];
        foreach($labels as $tab=>$type) $data['tabs'][$tab]=['k'=>$type,'items'=>$tab===$first?$list['items']:null,'src'=>'https://soundcloud.com/'.$user.'/'.$tab];
        return $data;
    }
}
