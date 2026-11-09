<?php
declare(strict_types=1);
// Local stand-in for every site the music menus read and for Telegram. Started with `php -S` by tests/music_menu.php;
// FIXTURE_ROOT names the test folder, where requests are logged (http.jsonl) and a mode file switches failures on.
$root=(string)getenv('FIXTURE_ROOT');
$path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_QUERY),$query);
$mode=json_decode((string)@file_get_contents($root.'/mode.json'),true)?:[];
$record=function(string $method,array $params,array $uploads=[]) use($root): void {
    file_put_contents($root.'/http.jsonl',json_encode(['method'=>$method,'params'=>$params,'uploads'=>$uploads],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);
};
$json=function(array $data): void { header('Content-Type: application/json'); echo json_encode($data); };
$track=fn($id,string $title,string $artist='Fixture Artist')=>['id'=>$id,'title'=>$title,'duration'=>12,'artist'=>['id'=>111,'name'=>$artist],'contributors'=>[['name'=>$artist]],'album'=>['cover_big'=>'https://cdn-images.dzcdn.net/images/cover/top/500x500.jpg']];
$embed=function(array $entity): void { echo '<html><script id="__NEXT_DATA__" type="application/json">'.json_encode(['props'=>['pageProps'=>['state'=>['data'=>['entity'=>$entity]]]]]).'</script></html>'; };
$spotifyTracks=function(int $count,string $artist): array {
    $rows=[]; for($i=1;$i<=$count;$i++) $rows[]=['uri'=>'spotify:track:'.str_pad('t'.$i,22,'x'),'title'=>"Hit Song $i",'subtitle'=>$artist,'duration'=>12000,'isPlayable'=>true];
    return $rows;
};

// Spotify embed pages
if(preg_match('~^/embed/(artist|album|playlist|track)/([A-Za-z0-9]{22})$~',$path,$m)) {
    $record('page',['path'=>$path]);
    if(str_starts_with($m[2],'0000')) { http_response_code(404); echo 'not found'; return; }
    if($m[1]==='track') {
        $name=preg_match('~^t(\d+)x*$~',$m[2],$n)?'Hit Song '.$n[1]:'Other Song';
        $embed(['type'=>'track','id'=>$m[2],'name'=>$name,'artists'=>[['name'=>'Fixture Artist']],'duration'=>12000,'releaseDate'=>['isoString'=>'2020-01-01T00:00:00Z']]); return;
    }
    $names=['3TVXtAsR1Inumwj472S9r4'=>'Fixture Artist','1Xyo4u8uXC1ZmMpatF05PJ'=>'Unknown Band','2Xyo4u8uXC1ZmMpatF05PJ'=>'Nobody Known','5StarSearchArtistIdAb1'=>'Search Star'];
    $title=$m[1]==='artist'?($names[$m[2]]??'Nobody Known'):($m[1]==='album'?'Fixture Album':'Fixture Mix');
    $artist=$m[1]==='artist'?$title:'Fixture Artist';
    $embed(['type'=>$m[1],'id'=>$m[2],'name'=>$title,'title'=>$title,'subtitle'=>$m[1]==='album'?'Fixture Artist':'Top tracks',
        'visualIdentity'=>['image'=>[['url'=>'https://image-cdn-fa.spotifycdn.com/image/small-'.$m[2],'maxHeight'=>64],['url'=>'https://image-cdn-fa.spotifycdn.com/image/'.$m[1].'-'.$m[2],'maxHeight'=>640],['url'=>'https://evil.example/huge.jpg','maxHeight'=>2000]]],
        'trackList'=>$spotifyTracks($m[1]==='artist'?10:3,$artist)]);
    return;
}

// Deezer's public API
if(str_starts_with($path,'/deezer/')) {
    $p=substr($path,7); $record('deezer',['path'=>$p,'q'=>$query['q']??'']);
    if(($mode['deezer']??'ok')==='down') { $json(['error'=>['type'=>'Exception','code'=>4]]); return; }
    // Searches typed as /music and /spotify commands (tests/music_search.php)
    if($p==='/search'&&str_contains(strtolower((string)($query['q']??'')),'pishro')) {
        $strict=str_contains((string)$query['q'],'artist:"');
        $json(['data'=>$strict?[]:[['id'=>801,'title'=>'SEPAR (Shield)','duration'=>433,'artist'=>['id'=>5303898,'name'=>'Reza Pishro'],'album'=>['cover_big'=>'https://cdn-images.dzcdn.net/images/cover/801/1000x1000.jpg']],
            ['id'=>802,'title'=>'Pishro','duration'=>304,'artist'=>['id'=>1,'name'=>'Mahsa Azimi']],['id'=>803,'title'=>'Leha Dodi','duration'=>153,'artist'=>['id'=>2,'name'=>'Sezen Aksu']]]]); return;
    }
    if($p==='/search/artist'&&str_contains((string)($query['q']??''),'Search Star')) { $json(['data'=>[['id'=>601,'name'=>'Search Star Tribute','nb_fan'=>5],['id'=>600,'name'=>'Search Star','nb_fan'=>900]]]); return; }
    if($p==='/search/artist'&&str_contains((string)($query['q']??''),'Mismatch Act')) { $json(['data'=>[['id'=>700,'name'=>'Mismatch Act','nb_fan'=>50]]]); return; }
    if($p==='/search/artist'&&str_contains((string)($query['q']??''),'Reza')) { $json(['data'=>[['id'=>5303898,'name'=>'Reza Pishro','nb_fan'=>3712],['id'=>9,'name'=>'Someone Else','nb_fan'=>999999]]]); return; }
    if($p==='/search') { $json(['data'=>str_contains((string)($query['q']??''),'artist:"Fixture Artist"')?[['id'=>701,'title'=>'Hit Song 1','duration'=>12,'artist'=>['id'=>111,'name'=>'Fixture Artist']]]:[]]); return; }
    if($p==='/search/artist') { $json(['data'=>str_contains((string)($query['q']??''),'Unknown Band')?[['id'=>400,'name'=>'Unknown Band','nb_fan'=>10]]:[]]); return; }
    if(preg_match('~^/artist/(111|222|400)$~',$p,$d)) { $json(['id'=>(int)$d[1],'name'=>['111'=>'Fixture Artist','222'=>'Other Artist','400'=>'Unknown Band'][$d[1]],'picture_xl'=>'https://cdn-images.dzcdn.net/images/artist/'.$d[1].'/1000x1000.jpg']); return; }
    if($p==='/artist/111/top') { $rows=[]; for($i=1;$i<=12;$i++) $rows[]=$track(700+$i,"Hit Song $i"); $json(['data'=>$rows,'total'=>12]); return; }
    if($p==='/artist/400/top') { $json(['data'=>[['id'=>401,'title'=>'Unrelated Song','duration'=>99,'artist'=>['name'=>'Unknown Band']]]]); return; }
    if($p==='/artist/222/top') { $json(['data'=>[$track(901,'Other Hit','Other Artist')]]); return; }
    if($p==='/artist/111/albums') {
        $cover=fn($id)=>'https://cdn-images.dzcdn.net/images/cover/'.$id.'/1000x1000.jpg';
        $json(['data'=>[['id'=>51,'title'=>'Old Album','record_type'=>'album','release_date'=>'2020-02-02','cover_xl'=>$cover(51)],
            ['id'=>52,'title'=>'New Album','record_type'=>'album','release_date'=>'2023-03-03','cover_xl'=>$cover(52)],['id'=>53,'title'=>'Lone Single','record_type'=>'single','release_date'=>'2024-04-04','cover_xl'=>$cover(53)],
            ['id'=>54,'title'=>'Small EP','record_type'=>'ep','release_date'=>'2021-01-01'],['id'=>55,'title'=>'Old Album','record_type'=>'album','release_date'=>'2020-02-02']],'total'=>5]);
        return;
    }
    if($p==='/artist/111/playlists') { $json(['data'=>[['id'=>61,'title'=>'Fixture Radio','picture_xl'=>'https://cdn-images.dzcdn.net/images/playlist/61/1000x1000.jpg'],['id'=>62,'title'=>'Road Trip']]]); return; }
    if($p==='/artist/111/related') { $json(['data'=>[['id'=>222,'name'=>'Other Artist'],['id'=>223,'name'=>'Third Artist']]]); return; }
    if(preg_match('~^/artist/(222|400)/(albums|playlists|related)$~',$p)) { $json(['data'=>[]]); return; }
    if(preg_match('~^/album/(\d+)/tracks$~',$p,$d)) {
        $cuts=[['id'=>$d[1].'1','title'=>'Album Cut One','duration'=>12,'artist'=>['name'=>'Fixture Artist']],['id'=>$d[1].'2','title'=>'Album Cut Two','duration'=>12,'artist'=>['name'=>'Fixture Artist']],['id'=>$d[1].'3','title'=>'Album Cut Three','duration'=>12,'artist'=>['name'=>'Fixture Artist']]];
        // The old album has a fourth track nobody can find.
        if($d[1]==='51') $cuts[]=['id'=>514,'title'=>'Missing Song','duration'=>12,'artist'=>['name'=>'Fixture Artist']];
        $json(['data'=>$d[1]==='53'?[['id'=>531,'title'=>'Single Cut','duration'=>12,'artist'=>['name'=>'Fixture Artist']],['id'=>532,'title'=>'Missing Song','duration'=>12,'artist'=>['name'=>'Fixture Artist']]]:$cuts]);
        return;
    }
    if(preg_match('~^/playlist/(\d+)/tracks$~',$p,$d)) { $json(['data'=>[$track($d[1].'1','Playlist Cut','Somebody Else')]]); return; }
    http_response_code(404); $json(['error'=>['type'=>'DataException','code'=>800]]); return;
}

// Apple's iTunes Search API
if(str_starts_with($path,'/itunes/')) {
    $p=substr($path,7); $record('itunes',['path'=>$p,'term'=>$query['term']??'','id'=>$query['id']??'']);
    if($p==='/search'&&($query['entity']??'')==='musicArtist') {
        $json(['results'=>str_contains((string)($query['term']??''),'Apple Only')?[['wrapperType'=>'artist','artistId'=>555,'artistName'=>'Apple Only'],['wrapperType'=>'artist','artistId'=>556,'artistName'=>'Apple Only Tribute']]:[]]); return;
    }
    if($p==='/search'&&($query['entity']??'')==='song'&&str_contains(strtolower((string)($query['term']??'')),'pishro')) {
        $json(['results'=>[['wrapperType'=>'track','artistId'=>5,'artistName'=>'Reza Pishro','trackName'=>'Separ','trackTimeMillis'=>433000,'artworkUrl100'=>'https://is1-ssl.mzstatic.com/image/thumb/sep/100x100bb.jpg','releaseDate'=>'2020-01-01T08:00:00Z']]]); return;
    }
    if($p==='/search') { $json(['results'=>str_contains((string)($query['term']??''),'Unknown Band')?[['wrapperType'=>'track','artistId'=>555,'artistName'=>'Unknown Band','trackName'=>'Hit Song 1','trackTimeMillis'=>12000]]:[]]); return; }
    if($p==='/lookup'&&($query['id']??'')==='555') {
        $json(['results'=>[['wrapperType'=>'artist','artistId'=>555],
            ['wrapperType'=>'collection','collectionId'=>9001,'collectionName'=>'Band Album','releaseDate'=>'2019-05-05T07:00:00Z','artworkUrl100'=>'https://is1-ssl.mzstatic.com/image/thumb/band/100x100bb.jpg'],
            ['wrapperType'=>'collection','collectionId'=>9002,'collectionName'=>'Band Song - Single','releaseDate'=>'2021-06-06T07:00:00Z','artworkUrl100'=>'https://is1-ssl.mzstatic.com/image/thumb/single/100x100bb.jpg']]]);
        return;
    }
    if($p==='/lookup'&&($query['id']??'')==='9001') {
        $json(['results'=>[['wrapperType'=>'collection','collectionId'=>9001],['wrapperType'=>'track','trackName'=>'Band Track Two','artistName'=>'Unknown Band','trackTimeMillis'=>12000,'trackNumber'=>2,'discNumber'=>1],
            ['wrapperType'=>'track','trackName'=>'Band Track One','artistName'=>'Unknown Band & Guest','trackTimeMillis'=>12000,'trackNumber'=>1,'discNumber'=>1]]]);
        return;
    }
    $json(['results'=>[]]); return;
}

// MusicBrainz (artist links, for /spotify)
if(str_starts_with($path,'/mb/')) {
    $p=substr($path,3); $record('musicbrainz',['path'=>$p,'q'=>$query['query']??'','agent'=>$_SERVER['HTTP_USER_AGENT']??'']);
    if(($mode['musicbrainz']??'ok')==='down') { http_response_code(503); echo '{}'; return; }
    $rel=fn(string $url)=>['type'=>'free streaming','url'=>['resource'=>$url]];
    if($p==='/ws/2/artist/') {
        $q=(string)($query['query']??'');
        $json(['artists'=>str_contains($q,'"Search Star"')?[['id'=>'11111111-1111-1111-1111-111111111111','name'=>'Search Star','score'=>100]]
            :(str_contains($q,'"Unknown Band"')?[['id'=>'22222222-2222-2222-2222-222222222222','name'=>'Unknown Band','score'=>100]]
            :(str_contains($q,'"Mismatch Act"')?[['id'=>'33333333-3333-3333-3333-333333333333','name'=>'Mismatch Act','score'=>100]]:[]))]); return;
    }
    if($p==='/ws/2/artist/11111111-1111-1111-1111-111111111111') { $json(['relations'=>[$rel('https://open.spotify.com/artist/5StarSearchArtistIdAb1'),$rel('https://www.deezer.com/artist/600')]]); return; }
    // The Spotify page of this one is gone (404), so the menu falls back to the Deezer artist.
    if($p==='/ws/2/artist/22222222-2222-2222-2222-222222222222') { $json(['relations'=>[$rel('https://open.spotify.com/artist/0000BrokenPageArtistId1'),$rel('https://www.deezer.com/artist/400')]]); return; }
    // MusicBrainz names another Deezer artist: it is somebody else, so the Spotify link is not trusted.
    if($p==='/ws/2/artist/33333333-3333-3333-3333-333333333333') { $json(['relations'=>[$rel('https://open.spotify.com/artist/5StarSearchArtistIdAb1'),$rel('https://www.deezer.com/artist/999')]]); return; }
    http_response_code(404); $json(['error'=>'Not Found']); return;
}

// SoundCloud's public pages (only their picture is read)
if(str_starts_with($path,'/sc/')) {
    $record('scpage',['path'=>$path]);
    $image=str_contains($path,'/sets/')?'https://i1.sndcdn.com/artworks-mix-t500x500.jpg':'https://i1.sndcdn.com/avatars-fixture-t500x500.jpg';
    echo '<html><head><meta property="og:image" content="'.$image.'"><meta property="og:title" content="x"></head></html>'; return;
}

// Telegram
$method=basename($path); $uploads=[];
foreach($_FILES as $key=>$file) {
    $uploads[$key]=['hash'=>hash_file('sha256',$file['tmp_name']),'mime'=>$file['type'],'name'=>$file['name'],'size'=>filesize($file['tmp_name'])];
    if($method==='sendAudio'&&$key==='audio') copy($file['tmp_name'],$root.'/delivered-audio');
    if($method==='sendDocument') copy($file['tmp_name'],$root.'/delivered-'.count(glob($root.'/delivered-*.zip')).'.zip');
}
foreach($_FILES as $key=>$file) {
    if($method==='sendMediaGroup'&&str_ends_with((string)$file['name'],'.zip')) copy($file['tmp_name'],$root.'/delivered-'.count(glob($root.'/delivered-*.zip')).'.zip');
}
$record($method,$_POST,$uploads);
$next=(int)@file_get_contents($root.'/counter')+1; file_put_contents($root.'/counter',(string)$next);
if($method==='getMe') { $json(['ok'=>true,'result'=>['id'=>1,'is_bot'=>true,'username'=>'fixture_bot','supports_inline_queries'=>!empty($mode['inline'])]]); return; }
// A refused upload from the second track on (to test a delivery that stops halfway).
if($method==='sendAudio'&&!empty($mode['refuse_after'])) { $n=(int)@file_get_contents($root.'/refuse.count')+1; file_put_contents($root.'/refuse.count',(string)$n); if($n>(int)$mode['refuse_after']) { $json(['ok'=>false,'error_code'=>400,'description'=>'Bad Request: file is too big']); return; } }
// Telegram's flood control, once: "retry after 1 second".
if($method==='sendAudio'&&!empty($mode['flood'])&&!is_file($root.'/flood.done')) { touch($root.'/flood.done'); $json(['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests: retry after 1','parameters'=>['retry_after'=>1]]); return; }
if($method==='sendAudio') { $json(['ok'=>true,'result'=>['message_id'=>900+$next,'audio'=>['file_id'=>'AUDIO'.$next]]]); return; }
if($method==='sendMediaGroup') {
    // One message per item, with consecutive IDs.
    $count=count(json_decode((string)($_POST['media']??'[]'),true)?:[]); file_put_contents($root.'/counter',(string)($next+$count-1));
    $messages=[]; for($i=0;$i<$count;$i++) $messages[]=['message_id'=>900+$next+$i];
    $json(['ok'=>true,'result'=>$messages]); return;
}
$json(['ok'=>true,'result'=>str_starts_with($method,'send')?['message_id'=>900+$next]:true]);
