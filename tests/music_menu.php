<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function menuCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }

$artistId='3TVXtAsR1Inumwj472S9r4'; $otherId='1Xyo4u8uXC1ZmMpatF05PJ'; $albumId='4yP0hdKOZPNshxUOjY0cZj';
// 1. Pages that list music are recognised, in every spelling, and kept apart from single tracks.
foreach([['https://open.spotify.com/artist/'.$artistId.'?si=P6Ll_riUSxWpp-5HJxH-lg','spotify','artist'],['open.spotify.com/intl-de/album/'.$albumId,'spotify','album'],['https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M','spotify','playlist'],
    ['https://soundcloud.com/fixture-artist','soundcloud','profile'],['https://m.soundcloud.com/fixture-artist/albums?si=x','soundcloud','profile'],['https://soundcloud.com/fixture-artist/sets/mixtape','soundcloud','set'],
    ['https://www.deezer.com/en/artist/246791','deezer','artist']] as [$input,$service,$kind]) {
    $found=Zero\collectionUrl($input);
    menuCheck($found!==null&&$found['service']===$service&&$found['kind']===$kind&&Zero\mediaUrl($input)===$found['url']&&Zero\musicService($input)===null,"a $service $kind page opens a menu: $input");
}
foreach(['https://open.spotify.com/artist/short','https://open.spotify.com/artist/'.$artistId.'/discography','https://open.spotify.com.evil.example/artist/'.$artistId,'https://soundcloud.com/discover','https://soundcloud.com/fixture-artist/popular-tracks',
    'https://soundcloud.com/a/sets/b/s-secret','https://www.deezer.com/en/album/302127','https://www.deezer.com/artist/abc','https://deezer.com.evil.example/artist/1'] as $input)
    menuCheck(Zero\collectionUrl($input)===null&&Zero\mediaUrl($input)===null,'not a menu page, refused: '.$input);
menuCheck(Zero\musicService('https://api.soundcloud.com/tracks/soundcloud%3Atracks%3A2321018903')==='soundcloud'&&Zero\soundcloudUrl('https://api-v2.soundcloud.com/tracks/2321018903')==='https://api.soundcloud.com/tracks/soundcloud%3Atracks%3A2321018903','a track a set lists without a title is reached through its API address');

$temp=sys_get_temp_dir().'/ciro-menu-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); mkdir($config->storage,0700); mkdir($config->storage.'/downloads',0700); $runner=new Zero\Download($config);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','sine=frequency=440:sample_rate=44100:duration=12','-c:a','aac',$temp.'/source.m4a'],$temp,30);
    $log=$temp.'/http.jsonl'; $ytLog=$temp.'/yt.jsonl'; $mode=$temp.'/mode.json'; file_put_contents($log,''); file_put_contents($ytLog,''); file_put_contents($mode,'{}');
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php $root='.var_export($temp,true).';$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);parse_str((string)parse_url($_SERVER["REQUEST_URI"],PHP_URL_QUERY),$query);$mode=json_decode((string)file_get_contents('.var_export($mode,true).'),true)?:[];'.
        'function out($data) { header("Content-Type: application/json");echo json_encode($data);return; }'.
        'function track($id,$title,$artist="Fixture Artist") { return ["id"=>$id,"title"=>$title,"duration"=>12,"artist"=>["name"=>$artist],"contributors"=>[["name"=>$artist]],"album"=>["cover_big"=>"http://127.0.0.1/cover.jpg"]]; }'.
        'function embed($entity) { echo "<html><script id=\"__NEXT_DATA__\" type=\"application/json\">".json_encode(["props"=>["pageProps"=>["state"=>["data"=>["entity"=>$entity]]]]])."</script></html>"; }'.
        'function spotifyTracks($prefix,$count,$artist) { $rows=[];for($i=1;$i<=$count;$i++) $rows[]=["uri"=>"spotify:track:".str_pad($prefix.$i,22,"x"),"title"=>"Hit Song $i","subtitle"=>$artist,"duration"=>12000,"isPlayable"=>true]; return $rows; }'.
        // Spotify embed pages
        'if(preg_match("~^/embed/(artist|album|playlist|track)/([A-Za-z0-9]{22})$~",$path,$m)) { file_put_contents('.var_export($log,true).',json_encode(["method"=>"page","params"=>["path"=>$path]]).PHP_EOL,FILE_APPEND);'.
        'if(str_starts_with($m[2],"0000")) { http_response_code(404);echo "not found";return; }'.
        'if($m[1]==="track") { $name=preg_match("~^t(\d+)x*$~",$m[2],$n)?"Hit Song ".$n[1]:"Other Song";embed(["type"=>"track","id"=>$m[2],"name"=>$name,"artists"=>[["name"=>"Fixture Artist"]],"duration"=>12000,"releaseDate"=>["isoString"=>"2020-01-01T00:00:00Z"]]);return; }'.
        '$names=["artist"=>($m[2]==="'.$artistId.'"?"Fixture Artist":"Unknown Band"),"album"=>"Fixture Album","playlist"=>"Fixture Mix"];'.
        'embed(["type"=>$m[1],"id"=>$m[2],"name"=>$names[$m[1]],"title"=>$names[$m[1]],"subtitle"=>$m[1]==="album"?"Fixture Artist":"Top tracks","trackList"=>spotifyTracks("t",$m[1]==="artist"?10:3,"Fixture Artist")]);return; }'.
        // Deezer
        'if(str_starts_with($path,"/deezer/")) { $p=substr($path,7);file_put_contents('.var_export($log,true).',json_encode(["method"=>"deezer","params"=>["path"=>$p]]).PHP_EOL,FILE_APPEND);'.
        'if(($mode["deezer"]??"ok")==="down") { out(["error"=>["type"=>"Exception","code"=>4]]);return; }'.
        'if($p==="/search/artist") { out(["data"=>[["id"=>112,"name"=>"Fixture Artist","nb_fan"=>3],["id"=>111,"name"=>"Fixture Artist","nb_fan"=>5000],["id"=>113,"name"=>"Fixture Artist Band","nb_fan"=>9000]]]);return; }'.
        'if($p==="/artist/111") { out(["id"=>111,"name"=>"Fixture Artist"]);return; }'.
        'if($p==="/artist/222") { out(["id"=>222,"name"=>"Other Artist"]);return; }'.
        'if($p==="/artist/112") { out(["id"=>112,"name"=>"Fixture Artist"]);return; }'.
        'if($p==="/artist/111/top") { $rows=[];for($i=1;$i<=12;$i++) $rows[]=track(700+$i,"Hit Song $i");out(["data"=>$rows,"total"=>12]);return; }'.
        'if($p==="/artist/112/top") { out(["data"=>[track(801,"Something Else Entirely")],"total"=>1]);return; }'.
        'if($p==="/artist/222/top") { out(["data"=>[track(901,"Other Hit","Other Artist")],"total"=>1]);return; }'.
        'if($p==="/artist/111/albums") { out(["data"=>[["id"=>51,"title"=>"Old Album","record_type"=>"album","release_date"=>"2020-02-02","cover_big"=>"http://127.0.0.1/c.jpg"],'.
        '["id"=>52,"title"=>"New Album","record_type"=>"album","release_date"=>"2023-03-03","cover_big"=>"http://127.0.0.1/c.jpg"],["id"=>53,"title"=>"Lone Single","record_type"=>"single","release_date"=>"2024-04-04"],'.
        '["id"=>54,"title"=>"Small EP","record_type"=>"ep","release_date"=>"2021-01-01"],["id"=>55,"title"=>"Old Album","record_type"=>"album","release_date"=>"2020-02-02"]],"total"=>5]);return; }'.
        'if(preg_match("~^/artist/(111|222)/(albums|playlists|related)$~",$p,$d)) { if($d[2]==="playlists"&&$d[1]==="111") out(["data"=>[["id"=>61,"title"=>"Fixture Radio"],["id"=>62,"title"=>"Road Trip"]]]);'.
        'elseif($d[2]==="related"&&$d[1]==="111") out(["data"=>[["id"=>222,"name"=>"Other Artist"],["id"=>223,"name"=>"Third Artist"]]]); else out(["data"=>[]]);return; }'.
        'if(preg_match("~^/album/(\d+)/tracks$~",$p,$d)) { out(["data"=>[track($d[1]."1","Album Cut One"),track($d[1]."2","Album Cut Two"),track($d[1]."3","Album Cut Three")]]);return; }'.
        'if(preg_match("~^/playlist/(\d+)/tracks$~",$p,$d)) { out(["data"=>[track($d[1]."1","Playlist Cut","Somebody Else")]]);return; }'.
        'http_response_code(404);out(["error"=>["type"=>"DataException","code"=>800]]);return; }'.
        // Telegram
        '$method=basename($path);$uploads=[];foreach($_FILES as $key=>$file) { $uploads[$key]=["hash"=>hash_file("sha256",$file["tmp_name"]),"mime"=>$file["type"],"name"=>$file["name"],"size"=>filesize($file["tmp_name"])];'.
        'if($method==="sendAudio") copy($file["tmp_name"],$root."/delivered-".$key); }'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        '$counter=$root."/counter";$next=(int)@file_get_contents($counter)+1;file_put_contents($counter,(string)$next);'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>str_starts_with($method,"send")?["message_id"=>900+$next]:true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base;
    // A stand-in for yt-dlp: YouTube search and audio, SoundCloud lists and tracks. Every call is recorded.
    $stub=$temp.'/yt-dlp-fixture';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        '$args=array_slice($argv,1);file_put_contents('.var_export($ytLog,true).',json_encode($args).PHP_EOL,FILE_APPEND);$target=end($args);'.
        'if(str_starts_with($target,"ytsearch")) { $text=substr($target,strpos($target,":")+1);$name=preg_replace("/ audio$/","",$text);'.
        'echo json_encode(["entries"=>[["id"=>"goodMatch01","title"=>$name." (Official Audio)","duration"=>12.0,"channel"=>"Fixture Artist"]]]);exit(0); }'.
        'if(in_array("--flat-playlist",$args,true)) { $path=parse_url($target,PHP_URL_PATH);'.
        'if(str_contains($path,"/sets/")) { echo json_encode(["title"=>"Mixtape","entries"=>[["id"=>"1","url"=>"https://soundcloud.com/fixture-artist/first-cut","title"=>"First Cut"],["id"=>"2","url"=>"https://soundcloud.com/fixture-artist/second-cut-1"],'.
        '["id"=>"3","url"=>"https://api-v2.soundcloud.com/tracks/333"]]]);exit(0); }'.
        'if(str_ends_with($path,"/albums")||str_ends_with($path,"/sets")) { echo json_encode(["title"=>"fixture-artist (Albums)","entries"=>[["id"=>"9","url"=>"https://soundcloud.com/fixture-artist/sets/mixtape","title"=>"Mixtape"]]]);exit(0); }'.
        'echo json_encode(["title"=>"Fixture Artist (Tracks)","entries"=>[["id"=>"5","url"=>"https://soundcloud.com/fixture-artist/fixture-song","title"=>"Fixture Song"],["id"=>"6","url"=>"https://soundcloud.com/fixture-artist/other-song","title"=>"Other Song"]]]);exit(0); }'.
        '$template=$args[array_search("-o",$args,true)+1];copy('.var_export($temp.'/source.m4a',true).',str_replace("%(ext)s","m4a",$template));'.
        'if(in_array("--write-info-json",$args,true)) file_put_contents(str_replace("%(ext)s","info.json",$template),json_encode(["title"=>"Fixture Song","artists"=>["Fixture Artist"],"duration"=>12.4,"timestamp"=>1556841600,"webpage_url"=>"https://soundcloud.com/fixture-artist/fixture-song"]));'.
        'echo json_encode("Fixture Artist - Fixture Song").PHP_EOL;');
    chmod($stub,0700); $config->ytDlp=$stub;

    $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
    $catalog=new Zero\MusicCatalog($config,$base,$base.'/deezer'); $bot->musicCatalog=$catalog; $bot->musicSoundCloud=new Zero\SoundCloud($config,$base);
    $worker=new Zero\Worker($config,$store,$api,null,new Zero\Spotify($config,$base),new Zero\SoundCloud($config,$base),$catalog);
    $records=fn(string $file)=>array_map(fn($line)=>json_decode($line,true),file($file,FILE_IGNORE_NEW_LINES));
    $calls=fn()=>$records($log); $yt=fn()=>$records($ytLog);
    $update=0;
    $send=function(string $url,int $user,bool $group=false) use($bot,&$update) {
        $update++; $chat=$group?-100123:$user;
        $bot->handle(['update_id'=>$update,'message'=>['message_id'=>$update,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0,'text'=>$url]]);
    };
    // $messageId: the menu message Telegram says the button is on; $user taps it.
    $tap=function(string $data,int $user,?int $messageId=null,bool $group=false) use($bot,&$update,$store) {
        $update++; $chat=$group?-100123:$user;
        if($messageId===null) $messageId=(int)$store->query('SELECT message_id FROM music_menus WHERE user_id=? ORDER BY id DESC LIMIT 1',[$group?11:$user])->fetchColumn();
        $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'cb'.$update,'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$messageId,'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0],'data'=>$data]]);
    };
    $lastMenu=function() use($calls) {
        $found=array_values(array_filter($calls(),fn($call)=>in_array($call['method'],['editMessageText','sendMessage'],true)&&isset($call['params']['reply_markup'])&&str_contains($call['params']['reply_markup'],'mm:')));
        if(!$found) return null; $last=end($found);
        $rows=json_decode($last['params']['reply_markup'],true)['inline_keyboard']; $buttons=array_merge(...$rows);
        return ['text'=>$last['params']['text'],'rows'=>$rows,'buttons'=>$buttons,'data'=>array_column($buttons,'callback_data'),'labels'=>array_column($buttons,'text'),'message'=>(int)($last['params']['message_id']??0)];
    };
    $answer=function() use($calls) { $found=array_values(array_filter($calls(),fn($call)=>$call['method']==='answerCallbackQuery')); return $found?end($found)['params']:[]; };
    $run=function(string $lane) use($store,$worker) { $job=$store->claim($lane); if(!$job) throw new RuntimeException("No $lane job is waiting"); $worker->process($job); return $store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); };
    $button=function(array $menu,string $needle) { foreach($menu['buttons'] as $button) if(str_contains($button['text'],$needle)) return $button; throw new RuntimeException('No button with '.$needle); };

    // 2. An artist page becomes a menu in the same status message, with the user's reaction.
    file_put_contents($log,'');
    $send('https://open.spotify.com/artist/'.$artistId.'?si=P6Ll_riUSxWpp-5HJxH-lg',11);
    $queued=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendMessage'));
    menuCheck(str_contains($queued[0]['params']['text'],'لینک ثبت شد')&&$store->query('SELECT phase FROM jobs')->fetchColumn()==='inspect','the artist link is queued in the quick lane with its own "reading the list" message');
    $reaction=array_values(array_filter($calls(),fn($call)=>$call['method']==='setMessageReaction'));
    menuCheck(count($reaction)===1&&json_decode($reaction[0]['params']['reaction'],true)[0]['emoji']==='🔥','the link gets the music reaction');
    menuCheck($store->claim('media')===null,'the media lane does not take the menu job');
    $job=$run('inspect'); if($job['status']!=='done') throw new RuntimeException('Menu job failed: '.$store->get('last_job_error'));
    $menu=$lastMenu();
    menuCheck($menu['message']===(int)$job['message_id']&&str_contains($menu['text'],'Fixture Artist')&&str_contains($menu['text'],'🎤')&&!str_contains($menu['text'],'ℹ️'),'the status message is edited into the menu, with no "limited" note when the discography was found');
    $tabs=array_values(array_filter($menu['labels'],fn($label)=>preg_match('/محبوب|آلبوم|سینگل|EP|پلی‌لیست|مشابه/u',$label)));
    menuCheck(count($tabs)===6&&str_contains($tabs[0],'(10)')&&str_contains($tabs[1],'(2)')&&str_contains($tabs[2],'(1)')&&str_contains($tabs[3],'(1)')&&str_contains($tabs[4],'(2)')&&str_contains($tabs[5],'(2)'),'tabs show popular tracks, albums, singles, EPs, playlists and similar artists with their counts (a repeated album is listed once)');
    $green=array_values(array_filter($menu['buttons'],fn($button)=>($button['style']??'')==='success'));
    menuCheck(count($green)===1&&str_contains($green[0]['text'],'محبوب'),'only the open tab is green');
    $items=array_values(array_filter($menu['buttons'],fn($button)=>preg_match('/^mm:\d+:o:\d+$/D',$button['callback_data'])));
    menuCheck(count($items)===8&&str_contains($items[0]['text'],'Hit Song 1')&&str_contains($items[0]['text'],'00:12')&&in_array('next',array_map(fn($label)=>str_contains($label,'بعدی')?'next':'',$menu['labels']),true),'eight popular tracks fill the first page, with their length, and there is a next page');
    $titleButtons=array_filter($menu['buttons'],fn($button)=>str_contains($button['text'],'صفحه 1 از 2'));
    menuCheck(count($titleButtons)===1&&!isset(array_values($titleButtons)[0]['style']),'the page indicator is a white title');
    foreach($menu['data'] as $data) menuCheck(strlen($data)<=64,'callback data fits Telegram\'s limit: '.$data);

    // 3. Tabs, pages and opening an album.
    $menuId=(int)$store->query('SELECT id FROM music_menus')->fetchColumn();
    $tap('mm:'.$menuId.':p:1',11); $menu=$lastMenu();
    $items=array_values(array_filter($menu['buttons'],fn($button)=>preg_match('/^mm:\d+:o:\d+$/D',$button['callback_data'])));
    menuCheck(count($items)===2&&$items[0]['callback_data']==='mm:'.$menuId.':o:8'&&str_contains($menu['text'],'صفحه 2 از 2'),'the next page shows the remaining tracks in the same message');
    $tap('mm:'.$menuId.':t:albums',11); $menu=$lastMenu();
    $items=array_values(array_filter($menu['buttons'],fn($button)=>preg_match('/^mm:\d+:o:\d+$/D',$button['callback_data'])));
    menuCheck(count($items)===2&&str_contains($items[0]['text'],'New Album · 2023')&&str_contains($items[1]['text'],'Old Album · 2020'),'the albums tab lists newest first');
    $tap('mm:'.$menuId.':o:0',11); $menu=$lastMenu();
    $items=array_values(array_filter($menu['buttons'],fn($button)=>preg_match('/^mm:\d+:o:\d+$/D',$button['callback_data'])));
    menuCheck(count($items)===3&&str_contains($menu['text'],'New Album')&&str_contains($items[0]['text'],'Album Cut One')&&in_array('mm:'.$menuId.':b',$menu['data'],true)&&!array_filter($menu['data'],fn($data)=>str_contains($data,':t:')),'an album opens its tracks with Back, and no tabs inside it');
    $tap('mm:'.$menuId.':b',11); $menu=$lastMenu();
    menuCheck(str_contains($button($menu,'آلبوم‌ها')['text'],'(2)')&&($button($menu,'آلبوم‌ها')['style']??'')==='success','Back returns to the albums tab');

    // 4. A tap on a listed track queues it like a pasted link, with no pause between taps.
    $tap('mm:'.$menuId.':o:1',11); $menu=$lastMenu();   // Old Album
    $tap('mm:'.$menuId.':o:1',11); $before=count($calls());
    $answered=$answer();
    menuCheck(str_contains($answered['text']??'','ثبت شد')&&empty($answered['show_alert']),'the tap is answered with a short confirmation');
    $job=$store->query("SELECT * FROM jobs WHERE phase='spotify' ORDER BY id DESC LIMIT 1")->fetch(); $options=json_decode($job['options'],true);
    menuCheck($job&&$job['status']==='queued'&&$options['track']['title']==='Album Cut Two'&&$options['track']['artists']===['Fixture Artist']&&$options['track']['year']==='2020'&&str_starts_with($job['url'],'https://open.spotify.com/search/'),'a track listed by Deezer is queued with its own details');
    $status=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendMessage'));
    menuCheck(str_contains(end($status)['params']['text'],'اسپاتیفای'),'it gets its own status message while the menu stays');
    $tap('mm:'.$menuId.':o:1',11);
    menuCheck(str_contains($answer()['text']??'','در راه است')&&$store->query("SELECT COUNT(*) FROM jobs WHERE phase='spotify'")->fetchColumn()==1,'tapping the same track again right away does not queue it twice');
    $tap('mm:'.$menuId.':o:0',11); $store->query("UPDATE music_menus SET sent='{}'");
    menuCheck($store->query("SELECT COUNT(*) FROM jobs WHERE phase='spotify'")->fetchColumn()==2,'another track is queued at once, without the pause between separate links');
    file_put_contents($log,''); file_put_contents($ytLog,'');
    $job=$run('media'); if($job['status']!=='done') throw new RuntimeException('Menu track failed: '.$store->get('last_job_error'));
    $audio=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendAudio'));
    menuCheck(count($audio)===1&&$audio[0]['params']['title']==='Album Cut Three'||$audio[0]['params']['title']==='Album Cut Two','the worker sends the chosen track as audio from its details, without reading a Spotify page');
    menuCheck(!array_filter($calls(),fn($call)=>$call['method']==='page'),'no Spotify page is read for a track whose details are known');
    $search=$yt()[0]; menuCheck(str_contains(end($search),'Fixture Artist Album Cut')&&str_ends_with(end($search),' audio'),'the recording is looked up by artist and title');
    $probe=json_decode($runner->run(['ffprobe','-v','error','-show_format','-of','json',$temp.'/delivered-audio'],$temp,30),true);
    menuCheck(str_starts_with($probe['format']['tags']['title']??'','Album Cut')&&($probe['format']['tags']['artist']??'')==='Fixture Artist'&&($probe['format']['tags']['date']??'')==='2020','the MP3 is tagged with the title, artist and the album\'s year');

    // 5. A popular track from Spotify itself is a normal track link.
    $tap('mm:'.$menuId.':t:popular',11);
    $store->query("UPDATE music_menus SET sent='{}'"); $tap('mm:'.$menuId.':o:0',11);
    $job=$store->query("SELECT * FROM jobs ORDER BY id DESC LIMIT 1")->fetch();
    menuCheck($job['url']==='https://open.spotify.com/track/'.str_pad('t1',22,'x')&&json_decode($job['options'],true)===[]&&$job['phase']==='spotify','a popular track is queued by its Spotify address');
    file_put_contents($log,''); $store->query("UPDATE jobs SET status='done' WHERE id<".(int)$job['id']); $run('media');
    menuCheck(count(array_filter($calls(),fn($call)=>$call['method']==='sendAudio'))===1&&count(array_filter($calls(),fn($call)=>$call['method']==='page'))===1,'it is delivered like any pasted track link');

    // 6. Similar artists open a new menu; other people cannot use yours; closing removes the menu.
    $tap('mm:'.$menuId.':t:related',11);
    $tap('mm:'.$menuId.':o:0',11);
    $related=$store->query("SELECT * FROM jobs ORDER BY id DESC LIMIT 1")->fetch();
    menuCheck($related['url']==='https://www.deezer.com/artist/222'&&$related['phase']==='inspect','a similar artist is opened as a new menu job');
    file_put_contents($log,''); $store->query("UPDATE jobs SET status='done' WHERE id<".(int)$related['id']); $run('inspect');
    $second=$lastMenu();
    menuCheck(str_contains($second['text'],'Other Artist')&&$second['message']!==$menu['message']&&$store->query('SELECT COUNT(*) FROM music_menus')->fetchColumn()==2,'it appears as a second menu and the first one stays');
    $tap('mm:'.$menuId.':o:0',99,$menu['message']);
    menuCheck(str_contains($answer()['text']??'','فرستندهٔ لینک')&&!empty($answer()['show_alert']),'someone else\'s tap is refused with an alert');
    $tap('mm:'.$menuId.':x',11,$menu['message']);
    menuCheck($store->query('SELECT COUNT(*) FROM music_menus WHERE id=?',[$menuId])->fetchColumn()==0&&count(array_filter($calls(),fn($call)=>$call['method']==='deleteMessage'))>=1,'Close deletes the menu message and forgets the menu');
    $tap('mm:'.$menuId.':t:albums',11,$menu['message']);
    menuCheck(str_contains($answer()['text']??'','منقضی'),'a button of a closed menu says it has expired');

    // 7. Without a matching Deezer artist only Spotify\'s popular tracks are shown, with a note; an album page has one list.
    file_put_contents($log,''); $store->query('DELETE FROM jobs'); $store->query('DELETE FROM users'); $store->query('DELETE FROM music_menus');
    $send('https://open.spotify.com/artist/'.$otherId,12); $run('inspect'); $limited=$lastMenu();
    menuCheck(str_contains($limited['text'],'ℹ️')&&str_contains($limited['text'],'Unknown Band')&&!array_filter($limited['labels'],fn($label)=>str_contains($label,'آلبوم‌ها'))&&count(array_filter($limited['data'],fn($data)=>str_contains($data,':o:')))===8,'an artist Deezer cannot confirm shows only the popular tracks, with a note');
    file_put_contents($mode,json_encode(['deezer'=>'down'])); $store->query('DELETE FROM jobs'); $store->query('DELETE FROM users'); $store->query('DELETE FROM music_menus');
    $send('https://open.spotify.com/artist/'.$artistId,13); $run('inspect'); $down=$lastMenu();
    menuCheck(str_contains($down['text'],'ℹ️')&&count(array_filter($down['data'],fn($data)=>str_contains($data,':o:')))===8,'when Deezer is unreachable the menu still opens with the popular tracks');
    file_put_contents($mode,'{}'); $store->query('DELETE FROM jobs'); $store->query('DELETE FROM users');
    $send('https://open.spotify.com/album/'.$albumId,14); $run('inspect'); $album=$lastMenu();
    menuCheck(str_contains($album['text'],'💿')&&str_contains($album['text'],'Fixture Album')&&!array_filter($album['data'],fn($data)=>str_contains($data,':t:'))&&count(array_filter($album['data'],fn($data)=>str_contains($data,':o:')))===3,'an album page opens its tracks without tabs');

    // 8. SoundCloud: a profile, lists read when opened, sets with untitled tracks, and a track chosen from them.
    $store->query('DELETE FROM jobs'); $store->query('DELETE FROM users'); $store->query('DELETE FROM music_menus'); file_put_contents($ytLog,'');
    $send('https://soundcloud.com/fixture-artist',15); $run('inspect'); $profile=$lastMenu();
    $labels=implode('|',$profile['labels']);
    menuCheck(str_contains($profile['text'],'Fixture Artist')&&str_contains($labels,'آهنگ‌ها (2)')&&str_contains($labels,'ریپست‌ها')&&str_contains($labels,'لایک‌ها')&&count($yt())===1,'a SoundCloud profile opens with its tracks, and the other lists are not read yet');
    $id15=(int)$store->query('SELECT id FROM music_menus')->fetchColumn();
    $tap('mm:'.$id15.':t:albums',15); $albums=$lastMenu();
    menuCheck(count($yt())===2&&str_contains(implode('|',$albums['labels']),'Mixtape')&&str_contains(implode('|',$albums['labels']),'آلبوم‌ها (1)'),'a list is read the first time it is opened');
    $tap('mm:'.$id15.':t:albums',15); menuCheck(count($yt())===2,'and kept for the next time');
    $tap('mm:'.$id15.':o:0',15); $set=$lastMenu();
    menuCheck(str_contains(implode('|',$set['labels']),'First Cut')&&str_contains(implode('|',$set['labels']),'Second cut')&&str_contains(implode('|',$set['labels']),'آهنگ 3'),'a set lists its tracks, naming those SoundCloud gave no title by their number');
    $tap('mm:'.$id15.':o:2',15);
    $job=$store->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch();
    menuCheck($job['url']==='https://api.soundcloud.com/tracks/soundcloud%3Atracks%3A333'&&$job['phase']==='soundcloud','an untitled track is queued by its API address as a SoundCloud job');
    file_put_contents($log,''); $store->query("UPDATE jobs SET status='done' WHERE id<".(int)$job['id']); $run('media');
    $audio=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendAudio'));
    menuCheck(count($audio)===1&&$audio[0]['params']['title']==='Fixture Song','it is downloaded and tagged like a pasted SoundCloud link');

    // 9. Groups: the menu belongs to the sender and the topic.
    $store->query('DELETE FROM jobs'); $store->query('DELETE FROM users'); $store->query('DELETE FROM music_menus'); file_put_contents($log,'');
    $send('https://open.spotify.com/artist/'.$artistId,11,true); $run('inspect'); $groupMenu=$lastMenu();
    $gid=(int)$store->query('SELECT id FROM music_menus')->fetchColumn();
    menuCheck((int)$store->query('SELECT thread_id FROM music_menus')->fetchColumn()===321&&(int)$store->query('SELECT chat_id FROM music_menus')->fetchColumn()===-100123,'a group menu remembers its chat and topic');
    $tap('mm:'.$gid.':t:albums',99,$groupMenu['message'],true);
    menuCheck(str_contains($answer()['text']??'','فرستندهٔ لینک'),'another member of the group cannot use it');
    $tap('mm:'.$gid.':t:albums',11,$groupMenu['message'],true); $store->query("UPDATE music_menus SET sent='{}'");
    $tap('mm:'.$gid.':o:0',11,$groupMenu['message'],true); $tap('mm:'.$gid.':o:0',11,$groupMenu['message'],true);
    $tap('mm:'.$gid.':o:0',11,$groupMenu['message'],true);
    $job=$store->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch();
    menuCheck((int)$job['chat_id']===-100123&&(int)$job['thread_id']===321,'a track chosen in a group is delivered to the same topic');
    $store->query('UPDATE music_menus SET expires=?',[time()-1]); $tap('mm:'.$gid.':t:albums',11,$groupMenu['message'],true);
    menuCheck(str_contains($answer()['text']??'','منقضی')&&$store->query('SELECT COUNT(*) FROM music_menus')->fetchColumn()==0,'a day-old menu expires and is removed');

    // 10. A busy bot and a page that cannot be read are reported, not ignored.
    $store->query('DELETE FROM jobs'); $store->query('DELETE FROM users'); file_put_contents($log,'');
    $send('https://open.spotify.com/artist/'.str_repeat('0',22),16); $job=$run('inspect');
    $edits=array_values(array_filter($calls(),fn($call)=>$call['method']==='editMessageText'));
    menuCheck($job['status']==='failed'&&str_contains(end($edits)['params']['text'],'انجام نشد')&&$store->query('SELECT COUNT(*) FROM music_menus')->fetchColumn()==0&&$store->get('last_job_service')==='menu','a page that cannot be read ends with a clear message and leaves no menu behind');
    menuCheck(!is_dir($config->storage.'/downloads')||!glob($config->storage.'/downloads/job-*'),'temporary job folders are removed');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    if(is_dir($temp)) Zero\Download::clean($temp);
}
echo "All tests passed.\n";
