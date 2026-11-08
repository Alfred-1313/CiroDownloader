<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Music.php';

/**
 * What a music menu shows, read from public sources that need no account or key:
 * Spotify's embed pages (the artist's popular tracks, an album's or playlist's tracks, pictures),
 * Deezer's public API and, when Deezer does not know the artist, Apple's iTunes Search API
 * (albums, singles, EPs; Deezer also playlists and similar artists), and SoundCloud's own pages
 * and listings. Nothing here downloads audio.
 *
 * Items are small arrays so a whole menu fits in one database row:
 *  track:    k=t, n title, a artists text, d seconds, u address (Spotify or SoundCloud track) or m details (Deezer, iTunes)
 *  album:    k=a, id, n title, y year, c cover, src "it" for iTunes (Deezer otherwise)
 *  playlist: k=p, id (Deezer), n title, c cover
 *  artist:   k=r, id (Deezer), n name
 *  set:      k=s, u address (SoundCloud), n title
 */
final class MusicCatalog
{
    public const PAGE_LIMIT=300, MAX_ARTISTS=20;
    private const IMAGE_HOSTS=['scdn.co','spotifycdn.com','dzcdn.net','deezer.com','mzstatic.com','sndcdn.com'];
    public function __construct(private Config $config,private string $spotifyBase='https://open.spotify.com',private string $deezerBase='https://api.deezer.com',
        private string $itunesBase='https://itunes.apple.com',private string $soundcloudBase='https://soundcloud.com') {}

    /** Lower case words only; text in brackets such as "(Remastered)" is dropped. */
    public static function plain(string $text): string
    {
        $text=mb_strtolower($text);
        $text=preg_replace('/[\(\[\{].*?[\)\]\}]/u',' ',$text)??$text;
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$text)??$text);
    }
    /** Letters and digits only, so "Y.A.S." and "yas" are the same name. */
    public static function compact(string $text): string { return preg_replace('/[^\p{L}\p{N}]+/u','',mb_strtolower($text))??''; }
    private static function clip(string $text,int $length): string { return mb_substr(trim($text),0,$length); }
    /** A picture Telegram may fetch itself: HTTPS on the music services' own image hosts only. */
    public static function image(string $url): string
    {
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        if(!str_starts_with($url,'https://')||strlen($url)>500) return '';
        foreach(self::IMAGE_HOSTS as $suffix) if($host===$suffix||str_ends_with($host,'.'.$suffix)) return $url;
        return '';
    }
    private static function firstArtist(string $artists): string { return trim(explode(',',$artists)[0]); }

    /** @return array{title:string,subtitle:string,image:string,tracks:list<array>} */
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
        // The biggest picture of the artist, album or playlist.
        $image=''; $best=0;
        $pictures=array_merge(is_array($entity['visualIdentity']['image']??null)?$entity['visualIdentity']['image']:[],is_array($entity['coverArt']['sources']??null)?$entity['coverArt']['sources']:[]);
        foreach($pictures as $picture) {
            $size=(int)($picture['maxHeight']??$picture['height']??0); $url=self::image((string)($picture['url']??''));
            if($url!==''&&$size>=$best) { $image=$url; $best=$size; }
        }
        return ['title'=>$title,'subtitle'=>$kind==='artist'?'':self::clip((string)($entity['subtitle']??''),120),'image'=>$image,'tracks'=>$tracks];
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
        $cover=self::image((string)($row['album']['cover_big']??$album['c']??''));
        return ['k'=>'t','n'=>$title,'a'=>implode(', ',array_slice($artists,0,4)),'d'=>$seconds,
            'm'=>['artists'=>array_slice($artists,0,5),'year'=>(string)($album['y']??''),'cover'=>$cover]];
    }
    private function deezerTop(int $id): array
    {
        $top=[];
        foreach(array_slice($this->deezerList('/artist/'.$id.'/top'),0,50) as $row) if(is_array($row)&&($track=$this->deezerTrack($row))) $top[]=$track;
        return $top;
    }

    /** The Spotify artist's own popular tracks (not the ones they only feature on), to recognise them elsewhere. */
    private static function ownTracks(string $name,array $spotifyTracks): array
    {
        $own=array_values(array_filter($spotifyTracks,fn($track)=>self::compact(self::firstArtist((string)($track['a']??'')))===self::compact($name)));
        return $own?:$spotifyTracks;
    }
    /** How many popular tracks two lists share, by title or, across alphabets, by exact length. */
    private static function overlap(array $spotifyTracks,array $other): array
    {
        $titles=array_intersect(array_filter(array_map(fn($track)=>self::plain($track['n']),$spotifyTracks)),array_map(fn($track)=>self::plain($track['n']),$other));
        $lengths=array_map(fn($track)=>(int)$track['d'],$other); $same=0;
        foreach($spotifyTracks as $track) {
            foreach($lengths as $index=>$length) if((int)$track['d']>0&&abs($length-(int)$track['d'])<=1) { $same++; unset($lengths[$index]); break; }
        }
        return ['titles'=>count($titles),'lengths'=>$same];
    }

    /**
     * The Deezer artist that is the same person as a Spotify artist, or null. Names are not enough:
     * first the artist's own popular tracks are looked up by name and length, then same-name artists
     * must share popular tracks (by title, or by exact length for names written in another alphabet).
     */
    public function matchArtist(string $name,array $spotifyTracks): ?array
    {
        $votes=[];
        foreach(array_slice(self::ownTracks($name,$spotifyTracks),0,4) as $track) {
            $title=trim(preg_replace('/\s*[\(\[].*$/u','',$track['n'])??$track['n']);
            foreach($this->deezer('/search?limit=10&q='.rawurlencode('artist:"'.$name.'" track:"'.$title.'"'))['data']??[] as $row) {
                if(is_array($row)&&isset($row['artist']['id'])&&self::compact((string)($row['artist']['name']??''))===self::compact($name)&&abs((int)($row['duration']??0)-(int)$track['d'])<=2)
                    $votes[(int)$row['artist']['id']]=($votes[(int)$row['artist']['id']]??0)+1;
            }
        }
        if($votes) { arsort($votes); return ['id'=>(int)array_key_first($votes),'top'=>null]; }
        $candidates=[];
        foreach($this->deezer('/search/artist?limit=10&q='.rawurlencode($name))['data']??[] as $row) {
            if(is_array($row)&&isset($row['id'])&&self::compact((string)($row['name']??''))===self::compact($name)) $candidates[]=['id'=>(int)$row['id'],'fans'=>(int)($row['nb_fan']??0)];
        }
        usort($candidates,fn($a,$b)=>$b['fans']<=>$a['fans']);
        foreach(array_slice($candidates,0,3) as $candidate) {
            $top=$this->deezerTop($candidate['id']);
            $shared=self::overlap($spotifyTracks,$top);
            // With few popular tracks to compare, one shared title is all there can be.
            if($shared['titles']>=min(2,max(1,count($spotifyTracks)))||$shared['lengths']>=3) return ['id'=>$candidate['id'],'top'=>$top];
        }
        return null;
    }

    /** @return array{title:string,image:string,tabs:array<string,array>} the Deezer discography of one artist */
    public function deezerArtist(int $id,?array $top=null): array
    {
        $artist=$this->deezer('/artist/'.$id);
        $title=self::clip((string)($artist['name']??''),120);
        if($title==='') throw new MediaFailure('format','Deezer artist has no name');
        $tabs=[];
        $top??=$this->deezerTop($id);
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
            $tabs[$group]['k']='a';
            $tabs[$group]['items'][]=['k'=>'a','id'=>(int)$row['id'],'n'=>$name,'y'=>substr((string)($row['release_date']??''),0,4),'c'=>self::image((string)($row['cover_xl']??$row['cover_big']??''))];
        }
        $playlists=[];
        try {
            foreach(array_slice($this->deezerList('/artist/'.$id.'/playlists'),0,50) as $row) {
                $name=self::clip((string)($row['title']??''),150);
                if(is_array($row)&&isset($row['id'])&&$name!=='') $playlists[]=['k'=>'p','id'=>(int)$row['id'],'n'=>$name,'c'=>self::image((string)($row['picture_xl']??$row['picture_big']??''))];
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
        return ['title'=>$title,'image'=>self::image((string)($artist['picture_xl']??$artist['picture_big']??'')),'tabs'=>$ordered];
    }

    /** @return list<array> the tracks of a Deezer album ($album is its menu item) or playlist */
    public function deezerTracks(string $kind,int $id,array $album=[]): array
    {
        if(!in_array($kind,['album','playlist'],true)) throw new MediaFailure('format','Invalid Deezer list');
        $tracks=[];
        foreach($this->deezerList('/'.$kind.'/'.$id.'/tracks') as $row) if(is_array($row)&&($track=$this->deezerTrack($row,$album))) $tracks[]=$track;
        return $tracks;
    }

    private function itunes(string $path): array
    {
        $data=json_decode(Music::get($this->config,rtrim($this->itunesBase,'/').$path,Music::MAX_PAGE,'iTunes'),true);
        if(!is_array($data)||!is_array($data['results']??null)) throw new MediaFailure('network','iTunes returned an error');
        return $data['results'];
    }
    private static function artwork(string $url): string { return self::image(preg_replace('~/\d+x\d+bb\.(jpg|png)$~','/600x600bb.$1',$url)??''); }
    /** The iTunes artist whose songs have the same titles and lengths as the Spotify artist's own popular tracks, or null. */
    public function matchItunes(string $name,array $spotifyTracks): ?int
    {
        $votes=[];
        foreach(array_slice(self::ownTracks($name,$spotifyTracks),0,4) as $track) {
            foreach($this->itunes('/search?entity=song&limit=25&term='.rawurlencode($name.' '.$track['n'])) as $row) {
                if(($row['wrapperType']??'')==='track'&&isset($row['artistId'])&&self::compact((string)($row['artistName']??''))===self::compact($name)
                    &&abs((int)round(((int)($row['trackTimeMillis']??0))/1000)-(int)$track['d'])<=2) $votes[(int)$row['artistId']]=($votes[(int)$row['artistId']]??0)+1;
            }
        }
        if(!$votes) return null;
        arsort($votes);
        return (int)array_key_first($votes);
    }
    /** @return array{tabs:array<string,array>} albums, singles and EPs of an iTunes artist */
    public function itunesArtist(int $id): array
    {
        $tabs=[]; $seen=[];
        $rows=array_filter($this->itunes('/lookup?entity=album&limit=200&id='.$id),fn($row)=>is_array($row)&&($row['wrapperType']??'')==='collection'&&isset($row['collectionId']));
        usort($rows,fn($a,$b)=>strcmp((string)($b['releaseDate']??''),(string)($a['releaseDate']??'')));
        foreach($rows as $row) {
            $name=self::clip((string)($row['collectionName']??''),150);
            $group='albums';
            if(preg_match('/\s+-\s+Single$/u',$name)) { $group='singles'; $name=(string)preg_replace('/\s+-\s+Single$/u','',$name); }
            elseif(preg_match('/\s+-\s+EP$/u',$name)) { $group='eps'; $name=(string)preg_replace('/\s+-\s+EP$/u','',$name); }
            $key=$group.'|'.mb_strtolower($name);
            if($name===''||isset($seen[$key])) continue;
            $seen[$key]=true;
            $tabs[$group]['k']='a';
            $tabs[$group]['items'][]=['k'=>'a','src'=>'it','id'=>(int)$row['collectionId'],'n'=>$name,'y'=>substr((string)($row['releaseDate']??''),0,4),'c'=>self::artwork((string)($row['artworkUrl100']??''))];
        }
        $ordered=[];
        foreach(['albums','singles','eps'] as $key) if(isset($tabs[$key])) $ordered[$key]=$tabs[$key];
        return ['tabs'=>$ordered];
    }
    /** @return list<array> the songs of an iTunes album, in disc and track order */
    public function itunesTracks(int $id,array $album=[]): array
    {
        $rows=array_values(array_filter($this->itunes('/lookup?entity=song&limit=200&id='.$id),fn($row)=>is_array($row)&&($row['wrapperType']??'')==='track'));
        usort($rows,fn($a,$b)=>[(int)($a['discNumber']??1),(int)($a['trackNumber']??0)]<=>[(int)($b['discNumber']??1),(int)($b['trackNumber']??0)]);
        $tracks=[];
        foreach($rows as $row) {
            $title=self::clip((string)($row['trackName']??''),150); $seconds=(int)round(((int)($row['trackTimeMillis']??0))/1000);
            $artists=array_values(array_filter(array_map('trim',preg_split('/\s*(?:,|&)\s*/u',(string)($row['artistName']??''))?:[])));
            if($title===''||$seconds<1||!$artists) continue;
            $tracks[]=['k'=>'t','n'=>$title,'a'=>implode(', ',array_slice($artists,0,4)),'d'=>$seconds,
                'm'=>['artists'=>array_slice($artists,0,5),'year'=>(string)($album['y']??substr((string)($row['releaseDate']??''),0,4)),'cover'=>(string)($album['c']??self::artwork((string)($row['artworkUrl100']??'')))]];
        }
        return $tracks;
    }

    /** The picture SoundCloud shows for a profile or set (its og:image), or an empty text. */
    public function soundcloudImage(string $url): string
    {
        $path=(string)parse_url($url,PHP_URL_PATH);
        try { $html=Music::get($this->config,rtrim($this->soundcloudBase,'/').$path,Music::MAX_PAGE,'SoundCloud page'); }
        catch(MediaFailure) { return ''; }
        return preg_match('~<meta property="og:image" content="([^"]+)"~',$html,$match)?self::image(html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8')):'';
    }

    /**
     * Everything a new menu needs. $listing reads a SoundCloud address with yt-dlp (injected so this class never spawns processes).
     * @param callable(string):array{title:string,items:list<array>} $listing
     * @return array{service:string,kind:string,url:string,title:string,subtitle:string,image:string,note:string,tabs:array<string,array>,views:array}
     */
    public function build(array $collection,callable $listing): array
    {
        ['service'=>$service,'kind'=>$kind,'id'=>$id]=$collection;
        $data=['service'=>$service,'kind'=>$kind,'url'=>$collection['url'],'title'=>'','subtitle'=>'','image'=>'','note'=>'','tabs'=>[],'views'=>[]];
        if($service==='deezer') {
            $artist=$this->deezerArtist((int)$id);
            return ['title'=>$artist['title'],'image'=>$artist['image'],'tabs'=>$artist['tabs']]+$data;
        }
        if($service==='spotify') {
            $page=$this->spotifyPage($kind,$id);
            $data['title']=$page['title']; $data['subtitle']=$page['subtitle']; $data['image']=$page['image'];
            if($kind!=='artist') {
                if(!$page['tracks']) throw new MediaFailure('format','The Spotify list has no playable tracks');
                $data['tabs']['tracks']=['k'=>'t','items'=>array_slice($page['tracks'],0,100)];
                return $data;
            }
            $found=false;
            try {
                $match=$this->matchArtist($page['title'],$page['tracks']);
                if($match!==null) { $data['tabs']=$this->deezerArtist($match['id'],$match['top'])['tabs']; $found=true; }
            } catch(MediaFailure) {}
            if(!$found) {
                // Deezer does not know every artist (many Persian artists, for one); Apple's catalogue often does.
                try {
                    $match=$this->matchItunes($page['title'],$page['tracks']);
                    if($match!==null) { $data['tabs']=$this->itunesArtist($match)['tabs']; $found=$data['tabs']!==[]; }
                } catch(MediaFailure) {}
            }
            // Spotify's own popular tracks come first: they are exactly what the artist's page shows.
            if($page['tracks']) $data['tabs']=['popular'=>['k'=>'t','items'=>$page['tracks']]]+$data['tabs'];
            if(!$found) $data['note']='limited';
            if(!$data['tabs']) throw new MediaFailure('format','Nothing to show for this artist');
            return $data;
        }
        // SoundCloud: the lists are read one by one when they are opened, because each takes a moment.
        $data['image']=$this->soundcloudImage($collection['url']);
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
