<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function srCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }

// 1. A search is a text of 2 to 80 characters; it travels as a Spotify search address and comes back out unchanged.
srCheck(Zero\searchQuery("  pishro-separ \n")==='pishro-separ'&&Zero\searchQuery("پیشرو  سپر")==='پیشرو سپر'&&Zero\searchQuery("shayea\u{202E}")==='shayea','a search text is trimmed and cleaned of control and direction marks');
foreach(['','a','---',str_repeat('x',81)] as $text) srCheck(Zero\searchQuery($text)===null,'not a usable search: '.mb_substr($text,0,12));
foreach([['tracks','pishro-separ'],['artists','shayea'],['tracks','پیشرو سپر'],['artists','AC/DC']] as [$kind,$query]) {
    $found=Zero\collectionUrl(Zero\searchAddress($kind,$query));
    srCheck($found!==null&&$found['service']==='search'&&$found['kind']===$kind&&$found['id']===$query&&Zero\mediaUrl(Zero\searchAddress($kind,$query))!==null&&Zero\musicService(Zero\searchAddress($kind,$query))===null,"the address of a $kind search for «{$query}» is read back");
}
srCheck(Zero\collectionUrl('https://open.spotify.com/search/x/tracks')===null&&Zero\collectionUrl('https://open.spotify.com/search/shayea')===null&&Zero\collectionUrl('https://open.spotify.com/search/AC%2FDC%20Back')===null&&Zero\collectionUrl('https://evil.example/search/shayea/artists')===null,'a one-letter search, a plain search page (also the one used inside menus) and other hosts are not searches');
foreach([['/music pishro-separ','music','pishro-separ'],["/spotify   shayea  ",'spotify','shayea'],['/music@fixture_bot a b','music','a b'],['/music','music','']] as [$text,$command,$argument]) {
    $parsed=Zero\MusicSearch::parse($text); srCheck($parsed!==null&&$parsed['command']===$command&&$parsed['argument']===$argument,"command read: $text");
}
foreach(['/musicbox x','music pishro','/start','https://open.spotify.com/search/x/tracks'] as $text) srCheck(Zero\MusicSearch::parse($text)===null,'not a search command: '.$text);

$temp=sys_get_temp_dir().'/ciro-search-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); mkdir($config->storage,0700); mkdir($config->storage.'/downloads',0700);
    $log=$temp.'/http.jsonl'; $ytLog=$temp.'/yt.jsonl'; $mode=$temp.'/mode.json'; file_put_contents($log,''); file_put_contents($ytLog,''); file_put_contents($mode,'{}');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/fixtures/music_sites.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes,null,array_merge(getenv(),['FIXTURE_ROOT'=>$temp]));
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base; $config->paceMessages=false;
    $stub=$temp.'/yt-dlp-fixture';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\nconst FIXTURE_ROOT=".var_export($temp,true).";\nrequire ".var_export(__DIR__.'/fixtures/music_ytdlp.php',true).";\n");
    chmod($stub,0700); $config->ytDlp=$stub;
    $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
    $catalog=new Zero\MusicCatalog($config,$base,$base.'/deezer',$base.'/itunes',$base.'/sc',$base.'/mb'); $catalog->pause=0; $soundcloud=new Zero\SoundCloud($config,$base);
    $bot->musicCatalog=$catalog; $bot->musicSoundCloud=$soundcloud;
    $worker=new Zero\Worker($config,$store,$api,null,new Zero\Spotify($config,$base),$soundcloud,$catalog);
    $records=fn(string $file)=>array_map(fn($line)=>json_decode($line,true),file($file,FILE_IGNORE_NEW_LINES));
    $calls=fn()=>$records($log);
    $called=fn(string $method)=>array_values(array_filter($calls(),fn($call)=>$call['method']===$method));
    $setMode=fn(array $values)=>file_put_contents($mode,json_encode($values));
    $update=0;
    $send=function(string $text,int $user=7,bool $group=false) use($bot,&$update,$store) {
        $update++; $chat=$group?-100123:$user; $store->query('UPDATE users SET last_request=0');
        $bot->handle(['update_id'=>$update,'message'=>['message_id'=>$update,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0,'text'=>$text]]);
    };
    $tap=function(string $data,int $user=7) use($bot,&$update,$store) {
        $update++; $messageId=(int)$store->query('SELECT message_id FROM music_menus WHERE user_id=? ORDER BY id DESC LIMIT 1',[$user])->fetchColumn(); $store->query('UPDATE users SET last_request=0');
        $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'cb'.$update,'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$messageId,'chat'=>['id'=>$user,'type'=>'private'],'message_thread_id'=>0],'data'=>$data]]);
    };
    $lastMenu=function() use($calls) {
        $found=array_values(array_filter($calls(),fn($call)=>in_array($call['method'],['editMessageText','sendMessage','editMessageCaption','editMessageMedia','sendPhoto'],true)&&str_contains((string)($call['params']['reply_markup']??''),'mm:')));
        if(!$found) return null; $params=end($found)['params'];
        $media=isset($params['media'])?json_decode($params['media'],true):[];
        $buttons=array_merge(...json_decode($params['reply_markup'],true)['inline_keyboard']);
        return ['text'=>(string)($params['text']??$params['caption']??$media['caption']??''),'data'=>array_column($buttons,'callback_data'),'labels'=>array_column($buttons,'text')];
    };
    $lastText=function() use($calls) {
        $found=array_values(array_filter($calls(),fn($call)=>in_array($call['method'],['editMessageText','sendMessage'],true)));
        return $found?(string)end($found)['params']['text']:'';
    };
    $run=function() use($store,$worker) { $job=$store->claim('inspect'); if($job) $worker->process($job); return $job; };
    $sentJobs=fn()=>$store->query('SELECT * FROM jobs ORDER BY id')->fetchAll();

    // 2. /music artist-title: a list of songs to tap.
    $send('/music pishro-separ');
    $job=$store->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch();
    srCheck($job&&$job['url']===Zero\searchAddress('tracks','pishro-separ')&&$job['phase']==='inspect'&&str_contains($lastText(),'جست‌وجو ثبت شد'),'/music queues a search and says so in its status message');
    $run(); $menu=$lastMenu();
    srCheck($menu!==null&&str_contains($menu['text'],'🔎 pishro-separ'),'the result is a menu titled with the search');
    $songs=array_values(array_filter($menu['labels'],fn($label)=>str_contains($label,'·')));
    srCheck(count($songs)===2&&str_starts_with($songs[0],'🎵 SEPAR (Shield) · 07:13')&&str_contains($songs[1],'Pishro'),'the best song comes first, the song both catalogues know is listed once and the unrelated one is left out');
    srCheck(!array_filter($menu['data'],fn($data)=>str_contains($data,':a:')||str_contains($data,':m:1')),'search results offer no "download all" or "choose tracks"');
    $queries=array_values(array_map(fn($call)=>$call['params']['q'],array_filter($called('deezer'),fn($call)=>$call['params']['path']==='/search')));
    srCheck($queries[0]==='artist:"pishro" track:"separ"'&&$queries[1]==='pishro separ','the exact artist and track are tried first, then the same words loosely');
    $tap('mm:'.$store->query('SELECT id FROM music_menus ORDER BY id DESC LIMIT 1')->fetchColumn().':o:0');
    $track=$store->query("SELECT * FROM jobs WHERE phase='spotify' ORDER BY id DESC LIMIT 1")->fetch(); $options=json_decode((string)$track['options'],true);
    srCheck($track&&$options['track']['title']==='SEPAR (Shield)'&&$options['track']['artists']===['Reza Pishro']&&$options['track']['duration']===433&&str_starts_with($options['track']['cover'],'https://cdn-images.dzcdn.net/'),'tapping a song queues it like a Spotify track, with the catalogue\'s title, artist, length and cover');

    // 3. Names in another alphabet fall back to SoundCloud's own search.
    $setMode(['scresults'=>'پیشرو']); $send('/music پیشرو سپر'); $run(); $menu=$lastMenu();
    srCheck($menu!==null&&count(array_filter($menu['labels'],fn($label)=>str_contains($label,'Pishro - Separ (live)')))===1,'a search the catalogues do not know is answered from SoundCloud');
    $tap('mm:'.$store->query('SELECT id FROM music_menus ORDER BY id DESC LIMIT 1')->fetchColumn().':o:0');
    $job=$store->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch();
    srCheck($job['url']==='https://api.soundcloud.com/tracks/soundcloud%3Atracks%3A777'&&$job['phase']==='soundcloud','tapping it queues that SoundCloud track');
    $setMode([]);

    // 4. Nothing found is said plainly, not as an error.
    $before=(int)$store->query('SELECT COUNT(*) FROM music_menus')->fetchColumn(); $send('/music zzqq xxvv'); $job=$run();
    srCheck(str_contains($lastText(),'چیزی پیدا نشد')&&str_contains($lastText(),'zzqq xxvv')&&(int)$store->query('SELECT COUNT(*) FROM music_menus')->fetchColumn()===$before&&$store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='done','a search without result says so and leaves no menu behind');
    srCheck($store->get('recent_errors','[]')==='[]','and it is not counted as an error');

    // 5. /spotify name: the artist's menu.
    $send('/spotify Search Star'); $job=$store->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch();
    srCheck($job['url']===Zero\searchAddress('artists','Search Star'),'/spotify queues an artist search');
    $run(); $menu=$lastMenu(); $row=$store->query('SELECT * FROM music_menus ORDER BY id DESC LIMIT 1')->fetch();
    srCheck($menu!==null&&str_contains($menu['text'],'🎤 Search Star')&&$row['service']==='spotify'&&$row['kind']==='artist'&&count(array_filter($menu['labels'],fn($label)=>str_contains($label,'Hit Song')))>=1,'the artist is found through Deezer and MusicBrainz and opens as the Spotify artist menu with its popular songs');
    $mb=$called('musicbrainz');
    srCheck(count($mb)===2&&str_contains($mb[0]['params']['agent'],'CiroDownloader')&&str_contains($mb[0]['params']['q'],'artist:"Search Star"'),'MusicBrainz is asked twice (search, links) and is told which program asks');
    $pages=array_values(array_unique(array_map(fn($call)=>$call['params']['path'],$called('page'))));
    srCheck($pages===['/embed/artist/5StarSearchArtistIdAb1'],'only the identified artist\'s Spotify page is read');

    // 6. When the Spotify page cannot be read, the Deezer artist is used; Apple's catalogue is the last resort.
    $send('/spotify Unknown Band'); $run(); $row=$store->query('SELECT * FROM music_menus ORDER BY id DESC LIMIT 1')->fetch(); $menu=$lastMenu();
    srCheck($row['service']==='deezer'&&str_contains($menu['text'],'Unknown Band'),'an artist whose Spotify page is gone opens from Deezer');
    $send('/spotify Apple Only'); $run(); $row=$store->query('SELECT * FROM music_menus ORDER BY id DESC LIMIT 1')->fetch(); $menu=$lastMenu();
    srCheck($row['service']==='itunes'&&str_contains($menu['text'],'Apple Only')&&count(array_filter($menu['labels'],fn($label)=>str_contains($label,'Band Album')))===1,'an artist only Apple knows opens from Apple\'s catalogue, with its albums and singles');
    $send('/spotify Nobody Here'); $run();
    srCheck(str_contains($lastText(),'چیزی پیدا نشد'),'an unknown artist is answered plainly');

    // 7. A name is not enough to trust a link: MusicBrainz must name the same Deezer artist.
    $found=$catalog->artistCandidates('Mismatch Act'); $services=array_column($found['candidates'],'service');
    srCheck($services===['deezer'],'a MusicBrainz entry that names another Deezer artist is not trusted');
    $found=$catalog->artistCandidates('Search Star'); srCheck(array_column($found['candidates'],'service')===['spotify','deezer']&&$found['candidates'][1]['id']==='600','the exact name beats a longer one and gives the Spotify page first, then Deezer');
    $found=$catalog->artistCandidates('Reza'); srCheck(array_column($found['candidates'],'id')===['5303898'],'a name inside a longer one is found, and a famous artist whose name does not fit is not taken instead');
    $setMode(['musicbrainz'=>'down']); $found=$catalog->artistCandidates('Search Star'); $setMode([]);
    srCheck(array_column($found['candidates'],'service')===['deezer'],'when MusicBrainz is down the Deezer artist is still found');

    // 8. Without text the commands explain themselves; nothing is queued.
    $count=count($sentJobs()); $send('/music'); $usageMusic=$lastText(); $send('/spotify   '); $usageSpotify=$lastText();
    srCheck(str_contains($usageMusic,'/music pishro-separ')&&str_contains($usageSpotify,'/spotify shayea')&&count($sentJobs())===$count,'the commands without text show an example and queue nothing');

    // 9. Groups: only for this bot, with the topic kept.
    $count=count($sentJobs()); $send('/music@other_bot pishro-separ',7,true); $send('/music@fixture_bot pishro-separ',7,true);
    $jobs=$sentJobs(); $last=end($jobs);
    srCheck(count($jobs)===$count+1&&$last['url']===Zero\searchAddress('tracks','pishro-separ')&&(int)$last['chat_id']===-100123&&(int)$last['thread_id']===321,'in a group a command for another bot is ignored and one for this bot keeps the chat and topic');
    $store->set('groups_enabled','0'); $count=count($sentJobs()); $send('/spotify shayea',7,true); $store->set('groups_enabled','1');
    srCheck(count($sentJobs())===$count,'with group use off nothing is queued in a group');

    // 10. The "/" menus: everyone gets the commands, administrators keep them next to their own.
    $names=array_column(Zero\AdminCommands::menu($bot->ui),'command');
    srCheck(in_array('music',$names,true)&&in_array('spotify',$names,true)&&array_search('admin',$names,true)<array_search('music',$names,true),'the administrator\'s menu also lists /music and /spotify');
    $before=count($called('setMyCommands')); Zero\MusicSearch::register($api,$store,$bot->ui); $first=$called('setMyCommands');
    $sent=json_decode(end($first)['params']['commands'],true); $scope=json_decode(end($first)['params']['scope'],true);
    srCheck(count($first)===$before+1&&$scope['type']==='default'&&array_column($sent,'command')===['start','help','music','spotify']&&str_contains($sent[2]['description'],'/music'),'the public menu is registered for everyone');
    Zero\MusicSearch::register($api,$store,$bot->ui); srCheck(count($called('setMyCommands'))===$before+1,'and not sent again while it is unchanged');
    $store->set('text.command_music','Search songs'); Zero\MusicSearch::register($api,$store,$bot->ui); srCheck(count($called('setMyCommands'))===$before+1,'a changed description waits for the hourly retry window');
    $store->set('commands.public_tried','0'); Zero\MusicSearch::register($api,$store,$bot->ui); srCheck(count($called('setMyCommands'))===$before+2,'and is sent once the window has passed');

    // 11. Everything a user sees can be reworded in Personalization.
    foreach(['search_queued','search_loading','search_empty','music_usage','spotify_usage','command_music','command_spotify','command_start','command_help'] as $key)
        srCheck($bot->ui->customizable('text',$key)&&($bot->ui->definition('text',$key)['audience']??'')==='user',"editable by users' wording: $key");
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    exec('rm -rf '.escapeshellarg($temp));
}
