<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function menuCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }

$artistId='3TVXtAsR1Inumwj472S9r4'; $bandId='1Xyo4u8uXC1ZmMpatF05PJ'; $nobodyId='2Xyo4u8uXC1ZmMpatF05PJ'; $albumId='4yP0hdKOZPNshxUOjY0cZj';
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
menuCheck(Zero\MusicCatalog::compact('Y.A.S.')===Zero\MusicCatalog::compact('yas')&&Zero\MusicCatalog::image('https://evil.example/a.jpg')===''&&Zero\MusicCatalog::image('http://i1.sndcdn.com/a.jpg')===''&&Zero\MusicCatalog::image('https://is1-ssl.mzstatic.com/a.jpg')!=='','names compare without punctuation; only HTTPS pictures on the music services\' own hosts are shown');

$temp=sys_get_temp_dir().'/ciro-menu-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); mkdir($config->storage,0700); mkdir($config->storage.'/downloads',0700); $runner=new Zero\Download($config);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','sine=frequency=440:sample_rate=44100:duration=12','-c:a','aac',$temp.'/source.m4a'],$temp,30);
    $log=$temp.'/http.jsonl'; $ytLog=$temp.'/yt.jsonl'; $mode=$temp.'/mode.json'; file_put_contents($log,''); file_put_contents($ytLog,''); file_put_contents($mode,'{}');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/fixtures/music_sites.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes,null,array_merge(getenv(),['FIXTURE_ROOT'=>$temp]));
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base;
    $stub=$temp.'/yt-dlp-fixture';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\nconst FIXTURE_ROOT=".var_export($temp,true).";\nrequire ".var_export(__DIR__.'/fixtures/music_ytdlp.php',true).";\n");
    chmod($stub,0700); $config->ytDlp=$stub;

    $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
    $catalog=new Zero\MusicCatalog($config,$base,$base.'/deezer',$base.'/itunes',$base.'/sc'); $soundcloud=new Zero\SoundCloud($config,$base);
    $bot->musicCatalog=$catalog; $bot->musicSoundCloud=$soundcloud;
    $worker=new Zero\Worker($config,$store,$api,null,new Zero\Spotify($config,$base),$soundcloud,$catalog);
    $records=fn(string $file)=>array_map(fn($line)=>json_decode($line,true),file($file,FILE_IGNORE_NEW_LINES));
    $calls=fn()=>$records($log); $yt=fn()=>$records($ytLog);
    $called=fn(string $method)=>array_values(array_filter($calls(),fn($call)=>$call['method']===$method));
    $update=0;
    $send=function(string $url,int $user,bool $group=false) use($bot,&$update) {
        $update++; $chat=$group?-100123:$user;
        $bot->handle(['update_id'=>$update,'message'=>['message_id'=>$update,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0,'text'=>$url]]);
    };
    $tap=function(string $data,int $user,?int $messageId=null,bool $group=false) use($bot,&$update,$store) {
        $update++; $chat=$group?-100123:$user;
        if($messageId===null) $messageId=(int)$store->query('SELECT message_id FROM music_menus WHERE user_id=? ORDER BY id DESC LIMIT 1',[$group?11:$user])->fetchColumn();
        $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'cb'.$update,'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$messageId,'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0],'data'=>$data]]);
    };
    // The last screen of any menu, whether it is a text message or a photo with a caption.
    $lastMenu=function() use($calls) {
        $found=array_values(array_filter($calls(),fn($call)=>in_array($call['method'],['editMessageText','sendMessage','editMessageCaption','editMessageMedia','sendPhoto'],true)&&str_contains((string)($call['params']['reply_markup']??''),'mm:')));
        if(!$found) return null; $last=end($found); $params=$last['params'];
        $media=isset($params['media'])?json_decode($params['media'],true):[];
        $rows=json_decode($params['reply_markup'],true)['inline_keyboard']; $buttons=array_merge(...$rows);
        return ['method'=>$last['method'],'text'=>(string)($params['text']??$params['caption']??$media['caption']??''),'photo'=>(string)($media['media']??$params['photo']??''),'rows'=>$rows,'buttons'=>$buttons,
            'data'=>array_column($buttons,'callback_data'),'labels'=>array_column($buttons,'text'),'message'=>(int)($params['message_id']??0)];
    };
    $answer=function() use($called) { $found=$called('answerCallbackQuery'); return $found?end($found)['params']:[]; };
    $run=function(string $lane) use($store,$worker) { $job=$store->claim($lane); if(!$job) throw new RuntimeException("No $lane job is waiting"); $worker->process($job); return $store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); };
    $button=function(array $menu,string $needle) { foreach($menu['buttons'] as $button) if(str_contains($button['text'],$needle)) return $button; throw new RuntimeException('No button with '.$needle); };
    $items=fn(array $menu)=>array_values(array_filter($menu['buttons'],fn($button)=>preg_match('/^mm:\d+:o:\d+$/D',$button['callback_data'])));
    $fresh=function() use($store,$log,$ytLog) { $store->query('DELETE FROM jobs'); $store->query('DELETE FROM users'); $store->query('DELETE FROM music_menus'); file_put_contents($log,''); file_put_contents($ytLog,''); };

    // 2. An artist page becomes a photo menu (the artist's picture) in the same status message, with the music reaction.
    $store->set('music.menu_close','0');
    $send('https://open.spotify.com/artist/'.$artistId.'?si=P6Ll_riUSxWpp-5HJxH-lg',11);
    menuCheck(str_contains($called('sendMessage')[0]['params']['text'],'لینک ثبت شد')&&$store->query('SELECT phase FROM jobs')->fetchColumn()==='inspect','the artist link is queued in the quick lane with its own "reading the list" message');
    menuCheck(json_decode($called('setMessageReaction')[0]['params']['reaction'],true)[0]['emoji']==='🔥','the link gets the music reaction');
    menuCheck($store->claim('media')===null,'the media lane does not take the menu job');
    $job=$run('inspect'); if($job['status']!=='done') throw new RuntimeException('Menu job failed: '.$store->get('last_job_error'));
    $menu=$lastMenu(); $row=$store->query('SELECT * FROM music_menus')->fetch(); $data=json_decode($row['data'],true);
    menuCheck($menu['method']==='editMessageMedia'&&$menu['photo']==='https://image-cdn-fa.spotifycdn.com/image/artist-'.$artistId&&$menu['message']===(int)$job['message_id']&&$data['photo']===true,'the status message becomes a photo of the artist (the biggest picture on a trusted host)');
    menuCheck(str_contains($menu['text'],'Fixture Artist')&&str_contains($menu['text'],'🎤')&&!str_contains($menu['text'],'ℹ️'),'the caption names the artist, with no "limited" note when the discography was found');
    menuCheck(!array_filter($called('deezer'),fn($call)=>$call['params']['path']==='/search/artist'),'the artist is recognised on Deezer by its own tracks, without guessing from the name');
    $tabs=array_values(array_filter($menu['labels'],fn($label)=>preg_match('/محبوب|آلبوم|سینگل|EP|پلی‌لیست|مشابه/u',$label)));
    menuCheck(count($tabs)===6&&str_contains($tabs[0],'(10)')&&str_contains($tabs[1],'(2)')&&str_contains($tabs[2],'(1)')&&str_contains($tabs[3],'(1)')&&str_contains($tabs[4],'(2)')&&str_contains($tabs[5],'(2)'),'tabs show popular tracks, albums, singles, EPs, playlists and similar artists with their counts');
    menuCheck(count(array_filter($menu['buttons'],fn($b)=>($b['style']??'')==='success'))===1&&!array_filter($menu['labels'],fn($label)=>str_contains($label,'دانلود همه')),'only the open tab is green, and an artist\'s popular list has no "download all"');
    menuCheck(count($items($menu))===8&&str_contains($items($menu)[0]['text'],'Hit Song 1 · 00:12'),'eight popular tracks fill the first page, with their length');
    foreach($menu['data'] as $value) menuCheck(strlen($value)<=64,'callback data fits Telegram\'s limit: '.$value);
    $menuId=(int)$row['id'];

    // 3. Opening an album shows its own cover in the same message, with a red Back that restores the artist.
    $tap('mm:'.$menuId.':t:albums',11); $menu=$lastMenu();
    menuCheck($menu['method']==='editMessageCaption'&&str_contains($items($menu)[0]['text'],'New Album · 2023'),'a tab change keeps the picture and only rewrites caption and buttons; albums are newest first');
    $tap('mm:'.$menuId.':o:0',11); $menu=$lastMenu();
    menuCheck($menu['method']==='editMessageMedia'&&$menu['photo']==='https://cdn-images.dzcdn.net/images/cover/52/1000x1000.jpg'&&str_contains($menu['text'],'💿 New Album'),'an album replaces the picture with its cover');
    $back=$button($menu,'بازگشت');
    menuCheck(($back['style']??'')==='danger'&&count($items($menu))===3&&in_array('mm:'.$menuId.':a:each',$menu['data'],true)&&in_array('mm:'.$menuId.':a:zip',$menu['data'],true),'inside the album: a red Back, its tracks, and "download all" one by one or as ZIP');
    $tap('mm:'.$menuId.':b',11); $menu=$lastMenu();
    menuCheck($menu['method']==='editMessageMedia'&&$menu['photo']==='https://image-cdn-fa.spotifycdn.com/image/artist-'.$artistId&&($button($menu,'آلبوم‌ها')['style']??'')==='success','Back brings the artist\'s picture and the albums tab back');
    $tap('mm:'.$menuId.':t:playlists',11); $tap('mm:'.$menuId.':o:0',11); $menu=$lastMenu();
    menuCheck(str_contains($menu['text'],'📻 Fixture Radio')&&$menu['photo']==='https://cdn-images.dzcdn.net/images/playlist/61/1000x1000.jpg'&&in_array('🎵 Somebody Else – Playlist Cut · 00:12',$menu['labels'],true),'a playlist shows its picture, its own icon and each track\'s artist');
    $tap('mm:'.$menuId.':b',11); $tap('mm:'.$menuId.':t:albums',11); $tap('mm:'.$menuId.':o:1',11);

    // 4. With the menu kept open: taps queue tracks, a repeat is refused, and no pause is needed between them.
    $tap('mm:'.$menuId.':o:1',11);
    menuCheck(str_contains($answer()['text']??'','ثبت شد')&&empty($answer()['show_alert']),'the tap is answered with a short confirmation');
    $job=$store->query("SELECT * FROM jobs WHERE phase='spotify' ORDER BY id DESC LIMIT 1")->fetch(); $options=json_decode($job['options'],true);
    menuCheck($job&&$options['track']['title']==='Album Cut Two'&&$options['track']['year']==='2020'&&$options['track']['cover']==='https://cdn-images.dzcdn.net/images/cover/51/1000x1000.jpg'&&(int)$job['message_id']!==$menuId,'a track listed by Deezer is queued with its details in a separate status message');
    $tap('mm:'.$menuId.':o:1',11);
    menuCheck(str_contains($answer()['text']??'','در راه است')&&$store->query("SELECT COUNT(*) FROM jobs WHERE phase='spotify'")->fetchColumn()==1,'tapping the same track again right away does not queue it twice');
    $tap('mm:'.$menuId.':o:0',11);
    menuCheck($store->query("SELECT COUNT(*) FROM jobs WHERE phase='spotify'")->fetchColumn()==2,'another track is queued at once');
    file_put_contents($log,''); file_put_contents($ytLog,'');
    $job=$run('media'); if($job['status']!=='done') throw new RuntimeException('Menu track failed: '.$store->get('last_job_error'));
    menuCheck(count($called('sendAudio'))===1&&!$called('page')&&str_ends_with(end($yt()[0]),' audio'),'the worker sends the track from its details, looked up by artist and title, without reading a Spotify page');
    $probe=json_decode($runner->run(['ffprobe','-v','error','-show_format','-of','json',$temp.'/delivered-audio'],$temp,30),true);
    menuCheck(str_starts_with($probe['format']['tags']['title']??'','Album Cut')&&($probe['format']['tags']['date']??'')==='2020','the MP3 is tagged with the title and the album\'s year');
    $store->query("UPDATE jobs SET status='done'");

    // 5. Closing on choice (the default): the menu turns into the download's status and is deleted after the audio.
    $store->set('music.menu_close','1'); file_put_contents($log,'');
    $menuMessage=(int)$store->query('SELECT message_id FROM music_menus WHERE id=?',[$menuId])->fetchColumn();
    $tap('mm:'.$menuId.':o:2',11);
    $job=$store->query("SELECT * FROM jobs ORDER BY id DESC LIMIT 1")->fetch();
    $status=$called('editMessageCaption');
    menuCheck((int)$job['message_id']===$menuMessage&&$job['message_kind']==='photo'&&$store->query('SELECT COUNT(*) FROM music_menus WHERE id=?',[$menuId])->fetchColumn()==0,'the chosen track takes over the menu message, and the menu is gone');
    menuCheck(count($status)===1&&str_contains($status[0]['params']['caption'],'اسپاتیفای')&&json_decode($status[0]['params']['reply_markup'],true)['inline_keyboard']===[]&&!$called('sendMessage'),'the photo\'s caption becomes the status, without buttons and without a new message');
    $run('media');
    $deleted=$called('deleteMessage');
    menuCheck(count($called('sendAudio'))===1&&count($deleted)===1&&(int)$deleted[0]['params']['message_id']===$menuMessage,'after the audio arrives the old menu message is deleted');
    $tap('mm:'.$menuId.':t:albums',11,$menuMessage);
    menuCheck(str_contains($answer()['text']??'','منقضی'),'a button of the closed menu says it has expired');

    // 6. "Download all": one audio message per track on one status message, or ZIP files.
    $fresh(); $send('https://open.spotify.com/artist/'.$artistId,12); $run('inspect');
    $id=(int)$store->query('SELECT id FROM music_menus')->fetchColumn(); $menuMessage=(int)$store->query('SELECT message_id FROM music_menus')->fetchColumn();
    $tap('mm:'.$id.':t:albums',12); $tap('mm:'.$id.':o:0',12); file_put_contents($log,'');
    $tap('mm:'.$id.':a:each',12);
    $job=$store->query("SELECT * FROM jobs ORDER BY id DESC LIMIT 1")->fetch(); $batch=json_decode($job['options'],true)['batch'];
    menuCheck($job['phase']==='batch'&&$batch['mode']==='each'&&count($batch['tracks'])===3&&$batch['title']==='New Album'&&(int)$job['message_id']===$menuMessage&&$job['url']==='https://www.deezer.com/album/52','"download all" queues the album\'s three tracks as one job on the menu message');
    file_put_contents($log,''); $job=$run('media');
    $progress=array_values(array_filter($called('editMessageCaption'),fn($call)=>str_contains($call['params']['caption'],'از 3')));
    menuCheck($job['status']==='done'&&count($called('sendAudio'))===3&&count($progress)===3&&str_contains((string)$progress[0]['params']['reply_markup'],'mb:'.$job['id']),'each track is sent as its own audio, with progress and a stop button on the status');
    menuCheck(count($called('deleteMessage'))===1,'when every track arrived the status message is removed');
    $fresh(); $send('https://open.spotify.com/album/'.$albumId,13); $run('inspect'); $album=$lastMenu();
    menuCheck($album['photo']==='https://image-cdn-fa.spotifycdn.com/image/album-'.$albumId&&!array_filter($album['data'],fn($value)=>str_contains($value,':t:'))&&in_array('mm:'.(int)$store->query('SELECT id FROM music_menus')->fetchColumn().':a:zip',$album['data'],true),'an album page opens with its cover, its tracks and "download all"');
    $id=(int)$store->query('SELECT id FROM music_menus')->fetchColumn();
    $config->maxBytes=2097152+650000; // room for about two short MP3s per ZIP file
    $tap('mm:'.$id.':a:zip',13); file_put_contents($log,''); $job=$run('media');
    $documents=$called('sendDocument'); $zip=new ZipArchive(); $zip->open($temp.'/delivered-0.zip'); $names=[];
    for($i=0;$i<$zip->numFiles;$i++) $names[]=$zip->getNameIndex($i);
    $method=$zip->statIndex(0)['comp_method']; $zip->close();
    menuCheck($job['status']==='done'&&count($documents)===2&&$documents[0]['uploads']['document']['mime']==='application/zip'&&str_ends_with($documents[0]['uploads']['document']['name'],'(1).zip')&&!$called('sendAudio'),'ZIP mode sends the tracks in ZIP files that each fit the upload limit');
    menuCheck($names===['01 - Fixture Artist - Hit Song 1.mp3','02 - Fixture Artist - Hit Song 2.mp3']&&$method===ZipArchive::CM_STORE&&str_contains($documents[1]['params']['caption'],'بخش 2'),'files keep the album order and their tagged names, stored without compression');
    $config->maxBytes=49000000;
    // A missing track is skipped and reported; a stop request ends the run early; only the owner can stop.
    $fresh(); $send('https://open.spotify.com/artist/'.$artistId,14); $run('inspect');
    $id=(int)$store->query('SELECT id FROM music_menus')->fetchColumn();
    $tap('mm:'.$id.':t:singles',14); $tap('mm:'.$id.':o:0',14); $tap('mm:'.$id.':a:each',14);
    file_put_contents($log,''); $job=$run('media'); $summary=($called('editMessageCaption')[count($called('editMessageCaption'))-1]['params']['caption']??'');
    menuCheck($job['status']==='done'&&count($called('sendAudio'))===1&&str_contains($summary,'1 از 2')&&str_contains($summary,'1 آهنگ')&&!$called('deleteMessage'),'a track that cannot be found is skipped and the status keeps a summary');
    $fresh(); $send('https://open.spotify.com/album/'.$albumId,15); $run('inspect');
    $id=(int)$store->query('SELECT id FROM music_menus')->fetchColumn(); $tap('mm:'.$id.':a:each',15);
    $batchJob=(int)$store->query("SELECT id FROM jobs WHERE phase='batch'")->fetchColumn(); $batchMessage=(int)$store->query('SELECT message_id FROM jobs WHERE id=?',[$batchJob])->fetchColumn();
    $tap('mb:'.$batchJob,99,$batchMessage); menuCheck(str_contains($answer()['text']??'','فرستندهٔ لینک')&&$store->get('batch.stop.'.$batchJob)==='','someone else cannot stop the download');
    $tap('mb:'.$batchJob,15,$batchMessage); menuCheck(str_contains($answer()['text']??'','متوقف')&&$store->get('batch.stop.'.$batchJob)==='1','the owner\'s stop is acknowledged');
    file_put_contents($log,''); $run('media');
    menuCheck(!$called('sendAudio')&&str_contains(($called('editMessageCaption')[count($called('editMessageCaption'))-1]['params']['caption']??''),'متوقف شد')&&$store->get('batch.stop.'.$batchJob)==='','a stopped download sends nothing more and says so');

    // 7. Artists Deezer does not know come from Apple's catalogue; without either only Spotify's popular tracks are shown.
    $fresh(); $send('https://open.spotify.com/artist/'.$bandId,16); $run('inspect'); $band=$lastMenu();
    menuCheck(str_contains($band['text'],'Unknown Band')&&!str_contains($band['text'],'ℹ️')&&str_contains(implode('|',$band['labels']),'آلبوم‌ها (1)')&&str_contains(implode('|',$band['labels']),'سینگل‌ها (1)'),'an artist missing on Deezer gets albums and singles from iTunes');
    $id=(int)$store->query('SELECT id FROM music_menus')->fetchColumn();
    $tap('mm:'.$id.':t:albums',16); $tap('mm:'.$id.':o:0',16); $bandAlbum=$lastMenu();
    menuCheck($bandAlbum['photo']==='https://is1-ssl.mzstatic.com/image/thumb/band/600x600bb.jpg'&&str_contains($items($bandAlbum)[0]['text'],'Band Track One')&&str_contains($items($bandAlbum)[1]['text'],'Band Track Two'),'an iTunes album opens with its large artwork and its songs in order');
    $store->set('music.menu_close','0'); $tap('mm:'.$id.':o:0',16);
    $track=json_decode($store->query("SELECT options FROM jobs WHERE phase='spotify'")->fetchColumn(),true)['track'];
    menuCheck($track['title']==='Band Track One'&&$track['artists']===['Unknown Band','Guest']&&$track['year']==='2019','an iTunes song is queued with its artists and year');
    $store->set('music.menu_close','1');
    $fresh(); $send('https://open.spotify.com/artist/'.$nobodyId,17); $run('inspect'); $limited=$lastMenu();
    menuCheck(str_contains($limited['text'],'ℹ️')&&!array_filter($limited['labels'],fn($label)=>str_contains($label,'آلبوم‌ها'))&&count($items($limited))===8,'an artist found nowhere shows only the popular tracks, with a note');
    file_put_contents($mode,json_encode(['deezer'=>'down'])); $fresh(); $send('https://open.spotify.com/artist/'.$artistId,18); $run('inspect');
    menuCheck(str_contains($lastMenu()['text'],'ℹ️')&&count($items($lastMenu()))===8,'when Deezer is unreachable the menu still opens with the popular tracks');
    file_put_contents($mode,'{}');

    // 8. SoundCloud: the profile picture, lists read when opened, a set with its artwork and untitled tracks.
    $fresh(); $send('https://soundcloud.com/fixture-artist',19); $run('inspect'); $profile=$lastMenu();
    menuCheck($profile['photo']==='https://i1.sndcdn.com/avatars-fixture-t500x500.jpg'&&str_contains(implode('|',$profile['labels']),'آهنگ‌ها (2)')&&count($yt())===1,'a SoundCloud profile opens with its picture and tracks; other lists are not read yet');
    $id=(int)$store->query('SELECT id FROM music_menus')->fetchColumn();
    $tap('mm:'.$id.':t:albums',19); menuCheck(count($yt())===2&&str_contains(implode('|',$lastMenu()['labels']),'Mixtape'),'a list is read the first time it is opened');
    $tap('mm:'.$id.':o:0',19); $set=$lastMenu();
    menuCheck($set['photo']==='https://i1.sndcdn.com/artworks-mix-t500x500.jpg'&&str_contains(implode('|',$set['labels']),'آهنگ 3')&&in_array('mm:'.$id.':a:zip',$set['data'],true),'a set shows its artwork, numbers untitled tracks and offers "download all"');
    $tap('mm:'.$id.':o:2',19);
    $job=$store->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch();
    menuCheck($job['url']==='https://api.soundcloud.com/tracks/soundcloud%3Atracks%3A333'&&$job['phase']==='soundcloud'&&$job['message_kind']==='photo','an untitled track is queued by its API address and takes over the menu');
    file_put_contents($log,''); $run('media');
    menuCheck(count($called('sendAudio'))===1&&$called('sendAudio')[0]['params']['title']==='Fixture Song','it is downloaded and tagged like a pasted SoundCloud link');

    // 9. Groups: the menu belongs to the sender and the topic; it expires after a day.
    $fresh(); $send('https://open.spotify.com/artist/'.$artistId,11,true); $run('inspect'); $groupMenu=$lastMenu();
    $gid=(int)$store->query('SELECT id FROM music_menus')->fetchColumn();
    menuCheck((int)$store->query('SELECT thread_id FROM music_menus')->fetchColumn()===321,'a group menu remembers its topic');
    $tap('mm:'.$gid.':t:albums',99,$groupMenu['message'],true);
    menuCheck(str_contains($answer()['text']??'','فرستندهٔ لینک'),'another member of the group cannot use it');
    $tap('mm:'.$gid.':t:albums',11,$groupMenu['message'],true); $tap('mm:'.$gid.':o:0',11,$groupMenu['message'],true); $tap('mm:'.$gid.':o:0',11,$groupMenu['message'],true);
    $job=$store->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch();
    menuCheck((int)$job['chat_id']===-100123&&(int)$job['thread_id']===321&&(int)$job['message_id']===$groupMenu['message'],'a track chosen in a group stays in the same topic and message');
    $fresh(); $send('https://open.spotify.com/artist/'.$artistId,11,true); $run('inspect'); $groupMenu=$lastMenu(); $gid=(int)$store->query('SELECT id FROM music_menus')->fetchColumn();
    $store->query('UPDATE music_menus SET expires=?',[time()-1]); $tap('mm:'.$gid.':t:albums',11,$groupMenu['message'],true);
    menuCheck(str_contains($answer()['text']??'','منقضی')&&$store->query('SELECT COUNT(*) FROM music_menus')->fetchColumn()==0,'a day-old menu expires and is removed');

    // 10. A page that cannot be read is reported, and nothing is left behind.
    $fresh(); $send('https://open.spotify.com/artist/'.str_repeat('0',22),20); $job=$run('inspect');
    $edits=$called('editMessageText');
    menuCheck($job['status']==='failed'&&str_contains(end($edits)['params']['text'],'انجام نشد')&&$store->query('SELECT COUNT(*) FROM music_menus')->fetchColumn()==0&&$store->get('last_job_service')==='menu','a page that cannot be read ends with a clear message and leaves no menu behind');
    menuCheck(!is_dir($config->storage.'/downloads')||!glob($config->storage.'/downloads/job-*'),'temporary job folders are removed');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    if(is_dir($temp)) Zero\Download::clean($temp);
}
echo "All tests passed.\n";
