<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function spotifyCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class SpotifyUiApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed { $this->calls[]=[$method,$params]; return $method==='sendMessage'?['message_id'=>900]:true; }
}

$id='4cOdK2wGLETKBW3PvgPWqT'; $canonical='https://open.spotify.com/track/'.$id;
// 1. Only one track link is accepted; everything else is refused before it reaches the queue.
foreach([$canonical,$canonical.'?si=abc123&utm_source=copy-link','open.spotify.com/intl-de/track/'.$id,'http://open.spotify.com/track/'.$id.'/','https://open.spotify.com/embed/track/'.$id,'https://open.spotify.com/intl-pt-BR/track/'.$id.'#x'] as $input)
    spotifyCheck(Zero\spotifyUrl($input)===$canonical&&Zero\mediaUrl($input)===$canonical,'track link is normalized: '.$input);
foreach(['https://open.spotify.com/album/'.$id,'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M','https://open.spotify.com/artist/'.$id,'https://open.spotify.com/episode/'.$id,
    'https://open.spotify.com.evil.example/track/'.$id,'https://evil.example/open.spotify.com/track/'.$id,'https://user@open.spotify.com/track/'.$id,'https://open.spotify.com:8443/track/'.$id,
    'https://open.spotify.com/track/short','https://open.spotify.com/track/'.$id.'/extra','https://spotify.link/AbCdEf','ftp://open.spotify.com/track/'.$id,"https://open.spotify.com/track/$id evil",'spotify:track:'.$id] as $input)
    spotifyCheck(Zero\spotifyUrl($input)===null&&Zero\mediaUrl($input)===null,'not a single track, refused: '.$input);
spotifyCheck(Zero\mediaUrl('https://youtu.be/ybNMMK5Q318')==='https://www.youtube.com/watch?v=ybNMMK5Q318'&&Zero\galleryService($canonical)===null,'Spotify does not disturb YouTube or the gallery services');

// 2. Matching: length and text decide, other versions lose.
$meta=['title'=>'Fixture Song (Remastered)','artists'=>['Fixture Artist','Guest Singer'],'duration'=>200,'year'=>'2019','cover'=>''];
$good=['id'=>'goodMatch01','title'=>'Fixture Artist - Fixture Song (Official Audio)','duration'=>201,'channel'=>'Fixture Artist'];
$live=['id'=>'liveCopy001','title'=>'Fixture Artist - Fixture Song (Live)','duration'=>200,'channel'=>'Fixture Artist'];
$video=['id'=>'otherSong01','title'=>'Totally Different Video','duration'=>200,'channel'=>'Somebody'];
$long=['id'=>'longVideo01','title'=>'Fixture Artist - Fixture Song','duration'=>420,'channel'=>'Fixture Artist'];
$topic=['id'=>'topicUpl001','title'=>'Fixture Song','duration'=>200,'channel'=>'Fixture Artist - Topic'];
spotifyCheck(Zero\Spotify::best($meta,[$video,$long,$live,$good])['id']==='goodMatch01','the official audio beats a live version, an unrelated video and a longer upload');
$fan=['id'=>'fanUpload01','title'=>'Fixture Artist - Fixture Song','duration'=>200,'channel'=>'Fan Channel'];
spotifyCheck(Zero\Spotify::best($meta,[$fan,$topic])['id']==='topicUpl001','a generated Topic upload of the same length is preferred over a fan upload');
spotifyCheck(Zero\Spotify::best($meta,[$video,$long])===null&&Zero\Spotify::best($meta,[])===null,'no result is chosen when nothing matches in length and text');
spotifyCheck(Zero\Spotify::best($meta,[['id'=>'bad id','title'=>'Fixture Artist - Fixture Song','duration'=>200,'channel'=>'Fixture Artist']])===null&&Zero\Spotify::score($meta,['id'=>'noLength001','title'=>'Fixture Song','channel'=>'Fixture Artist'])<0,'invalid IDs and results without a length are never chosen');
$liveMeta=['title'=>'Fixture Song - Live','artists'=>['Fixture Artist'],'duration'=>200,'year'=>'','cover'=>''];
spotifyCheck(Zero\Spotify::best($liveMeta,[$live])['id']==='liveCopy001','a live recording is accepted when the track itself is live');

$temp=sys_get_temp_dir().'/ciro-spotify-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); mkdir($config->storage,0700); mkdir($config->storage.'/downloads',0700); $runner=new Zero\Download($config);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','sine=frequency=440:sample_rate=44100:duration=12','-c:a','aac',$temp.'/source.m4a'],$temp,30);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=purple:s=640x640','-frames:v','1','-update','1',$temp.'/cover.jpg'],$temp,30);
    $log=$temp.'/http.jsonl'; $ytLog=$temp.'/yt.jsonl'; $mode=$temp.'/mode.json'; file_put_contents($log,''); file_put_contents($ytLog,''); file_put_contents($mode,'{}');
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php $root='.var_export($temp,true).';$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);$mode=json_decode((string)file_get_contents('.var_export($mode,true).'),true)?:[];'.
        'if(str_starts_with($path,"/image/")) { header("Content-Type: image/jpeg");readfile($root."/cover.jpg");return; }'.
        'if(str_starts_with($path,"/embed/track/")) { file_put_contents('.var_export($log,true).',json_encode(["method"=>"page","params"=>["id"=>basename($path)]]).PHP_EOL,FILE_APPEND);'.
        'if(($mode["page"]??"ok")==="missing") { http_response_code(404);echo "not found";return; }'.
        'if(($mode["page"]??"ok")==="empty") { echo "<html><body>nothing here</body></html>";return; }'.
        '$base="http://".$_SERVER["HTTP_HOST"];$entity=["type"=>"track","id"=>(($mode["page"]??"ok")==="wrong"?"0000000000000000000000":basename($path)),"name"=>"Fixture Song","title"=>"Fixture Song",'.
        '"artists"=>[["name"=>"Fixture Artist"],["name"=>"Guest Singer"]],"duration"=>12000,"releaseDate"=>["isoString"=>"2019-05-03T00:00:00Z"],'.
        '"visualIdentity"=>["image"=>[["url"=>$base."/image/small.jpg","maxHeight"=>64],["url"=>$base."/image/cover.jpg","maxHeight"=>640],["url"=>"https://evil.example/image/huge.jpg","maxHeight"=>2000]]]];'.
        'echo "<html><script id=\"__NEXT_DATA__\" type=\"application/json\">".json_encode(["props"=>["pageProps"=>["state"=>["data"=>["entity"=>$entity]]]]])."</script></html>";return; }'.
        '$method=basename($path);$uploads=[];foreach($_FILES as $key=>$file) { $uploads[$key]=["hash"=>hash_file("sha256",$file["tmp_name"]),"mime"=>$file["type"],"name"=>$file["name"],"size"=>filesize($file["tmp_name"])];'.
        'if($method==="sendAudio") copy($file["tmp_name"],$root."/delivered-".$key); }'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        '$counter=$root."/counter";$next=(int)@file_get_contents($counter)+1;file_put_contents($counter,(string)$next);'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>str_starts_with($method,"send")?["message_id"=>900+$next]:true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base;
    // A stand-in for yt-dlp: it lists search results, serves one video's audio and records every call.
    $stub=$temp.'/yt-dlp-fixture';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        '$args=array_slice($argv,1);file_put_contents('.var_export($ytLog,true).',json_encode($args).PHP_EOL,FILE_APPEND);'.
        '$mode=json_decode((string)file_get_contents('.var_export($mode,true).'),true)?:[];$target=end($args);'.
        'if(($mode["youtube"]??"ok")==="blocked"&&(str_starts_with($target,"ytsearch")||str_contains($target,"youtube.com"))) { fwrite(STDERR,"ERROR: [youtube] x: Sign in to confirm you’re not a bot. Use --cookies for the authentication.");exit(1); }'.
        'if(str_starts_with($target,"scsearch")) { $found=($mode["soundcloud"]??"ok")==="empty"?[]:['.
        '["id"=>"111000111","title"=>"Fixture Song","duration"=>30.0,"uploader"=>"Fixture Artist"],'.
        '["id"=>"222000222","title"=>"Fixture Song","duration"=>12.0,"uploader"=>"Random Upload"],'.
        '["id"=>"333000333","title"=>"Fixture Artist - Fixture Song","duration"=>12.4,"uploader"=>"Some Fan"]];'.
        'echo json_encode(["entries"=>$found]);exit(0); }'.
        'if(str_starts_with($target,"ytsearch")) { $found=match($mode["search"]??"ok") {'.
        '"empty"=>[],"far"=>[["id"=>"longVideo01","title"=>"Fixture Artist - Fixture Song","duration"=>420.0,"channel"=>"Fixture Artist"]],'.
        'default=>[["id"=>"otherSong01","title"=>"Totally Different Video","duration"=>12.0,"channel"=>"Somebody"],'.
        '["id"=>"liveCopy001","title"=>"Fixture Artist - Fixture Song (Live)","duration"=>12.0,"channel"=>"Fixture Artist"],'.
        '["id"=>"goodMatch01","title"=>"Fixture Artist - Fixture Song (Official Audio)","duration"=>13.0,"channel"=>"Fixture Artist"]]};'.
        'echo json_encode(["entries"=>$found]);exit(0); }'.
        '$template=$args[array_search("-o",$args,true)+1];copy('.var_export($temp.'/source.m4a',true).',str_replace("%(ext)s","m4a",$template));echo json_encode("Fixture Artist - Fixture Song (Official Audio)").PHP_EOL;');
    chmod($stub,0700); $config->ytDlp=$stub;

    $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
    $worker=new Zero\Worker($config,$store,$api,null,new Zero\Spotify($config,$base));
    $records=fn(string $file)=>array_map(fn($line)=>json_decode($line,true),file($file,FILE_IGNORE_NEW_LINES));
    $calls=fn()=>$records($log); $searches=fn()=>$records($ytLog);

    // 3. The public page is read and validated.
    $spotify=new Zero\Spotify($config,$base);
    $meta=$spotify->metadata($id);
    spotifyCheck($meta['title']==='Fixture Song'&&$meta['artists']===['Fixture Artist','Guest Singer']&&$meta['duration']===12&&$meta['year']==='2019','title, artists, length and year come from the public track page');
    spotifyCheck($meta['cover']===$base.'/image/cover.jpg','the biggest cover on a trusted address is chosen and foreign cover addresses are ignored');
    foreach([['missing','a missing track page'],['empty','a page without track data'],['wrong','a page for a different item']] as [$page,$label]) {
        file_put_contents($mode,json_encode(['page'=>$page]));
        try { $spotify->metadata($id); $failed=false; } catch(Zero\MediaFailure $e) { $failed=$e->reason==='format'; }
        spotifyCheck($failed,'metadata refuses '.$label);
    }
    file_put_contents($mode,'{}');

    // 4. A link in a private chat is queued in the media lane, with a reaction, and delivered as one tagged audio message.
    $send=function(string $url,int $update,bool $group=false) use($bot,$store) {
        $user=10+$update; $chat=$group?-100123:$user;
        $bot->handle(['update_id'=>$update,'message'=>['message_id'=>$update,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0,'text'=>$url]]);
    };
    $run=function() use($store,$worker) {
        $job=$store->claim('media'); if(!$job) throw new RuntimeException('Spotify job was not queued');
        $worker->process($job); return $store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    };
    $send('Look: '.$canonical.'?si=share',1);
    $notice=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendMessage'));
    spotifyCheck($store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()==0&&count($notice)===1&&str_contains($notice[0]['params']['text'],'اسپاتیفای'),'a message with extra words is not queued and the reply lists Spotify among the supported links');
    $store->query('DELETE FROM users'); file_put_contents($log,'');
    $send($canonical.'?si=share',2);
    $queued=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendMessage'));
    spotifyCheck(str_contains($queued[0]['params']['text'],'اسپاتیفای')&&$store->query('SELECT phase FROM jobs')->fetchColumn()==='spotify','the status message names Spotify and the job waits in the media lane');
    spotifyCheck($store->claim('inspect')===null,'the YouTube inspection lane does not take a Spotify job');
    $reaction=array_values(array_filter($calls(),fn($call)=>$call['method']==='setMessageReaction'));
    spotifyCheck(count($reaction)===1&&json_decode($reaction[0]['params']['reaction'],true)[0]['emoji']==='👾','the accepted link receives the configured reaction');
    $store->query("UPDATE jobs SET status='queued'");
    $job=$run();
    if($job['status']!=='done') throw new RuntimeException('Spotify job failed: '.$store->get('last_job_error'));
    $audio=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendAudio'));
    spotifyCheck(count($audio)===1&&$audio[0]['uploads']['audio']['mime']==='audio/mpeg'&&$audio[0]['uploads']['audio']['name']==='Fixture Artist, Guest Singer - Fixture Song.mp3','one MP3 audio message is sent with the artist and title as its file name');
    $params=$audio[0]['params'];
    spotifyCheck($params['title']==='Fixture Song'&&$params['performer']==='Fixture Artist, Guest Singer'&&(int)$params['duration']>=12&&(int)$params['duration']<=13,'Telegram receives the real title, performers and length');
    spotifyCheck(isset($audio[0]['uploads']['thumbnail'])&&$audio[0]['uploads']['thumbnail']['mime']==='image/jpeg'&&$audio[0]['uploads']['thumbnail']['size']<=204800,'the cover is attached as a small thumbnail');
    spotifyCheck(str_contains($params['caption'],'Fixture Song')&&str_contains($params['caption'],'Fixture Artist, Guest Singer')&&str_contains($params['caption'],'00:12')&&!str_contains($params['caption'],'href=')&&!str_contains($params['caption'],'open.spotify.com'),'the default caption shows title, artists and length without a link to the original');
    spotifyCheck(!isset($params['reply_markup'])&&(int)$params['chat_id']===12&&!isset($params['message_thread_id']),'the source button is off by default and a private chat has no topic');
    $probe=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$temp.'/delivered-audio'],$temp,30),true);
    $types=array_column($probe['streams'],'codec_name','codec_type');
    spotifyCheck(($types['audio']??'')==='mp3'&&($types['video']??'')==='mjpeg'&&($probe['format']['tags']['title']??'')==='Fixture Song'&&($probe['format']['tags']['artist']??'')==='Fixture Artist, Guest Singer'&&($probe['format']['tags']['date']??'')==='2019','the MP3 carries the cover and the real title, artists and year');
    $yt=$searches();
    spotifyCheck(str_starts_with($yt[0][count($yt[0])-1],'ytsearch')&&str_contains($yt[0][count($yt[0])-1],'Fixture Artist Fixture Song audio')&&in_array('--flat-playlist',$yt[0],true),'the matching video is looked up with the artist and the title');
    $downloads=array_values(array_filter($yt,fn($args)=>in_array('-o',$args,true)));
    spotifyCheck(count($yt)===2&&count($downloads)===1&&end($downloads[0])==='https://www.youtube.com/watch?v=goodMatch01'&&!in_array('--cookies',$downloads[0],true),'only the best match is downloaded, in two downloader runs and without cookies');
    $edits=array_values(array_filter($calls(),fn($call)=>in_array($call['method'],['editMessageText','deleteMessage'],true)));
    spotifyCheck(count($edits)>=2,'progress and completion use the one status message');

    // 5. Group delivery keeps the topic, and the administrator can turn the source button on.
    file_put_contents($log,'');
    $admin=new Zero\Bot($config,$store,new SpotifyUiApi()); $uiApi=$admin->api;
    $admin->handle(['callback_query'=>['id'=>'c','from'=>['id'=>42],'message'=>['message_id'=>900,'chat'=>['id'=>42,'type'=>'private']],'data'=>'custom:section:spotify']]);
    $labels=array_column(array_merge(...end($uiApi->calls)[1]['reply_markup']['inline_keyboard']),'callback_data');
    $admin->handle(['callback_query'=>['id'=>'c','from'=>['id'=>42],'message'=>['message_id'=>900,'chat'=>['id'=>42,'type'=>'private']],'data'=>'custom:section:spotify:1']]);
    $second=array_column(array_merge(...end($uiApi->calls)[1]['reply_markup']['inline_keyboard']),'callback_data');
    spotifyCheck(in_array('custom:spsource',$labels,true)&&in_array('custom:item:text:spotify_caption',$labels,true)&&in_array('custom:item:text:spotify_queued',$labels,true)&&in_array('custom:item:text:spotify_error_format',$labels,true)
        &&in_array('custom:item:text:spotify_failed',$second,true)&&in_array('custom:item:button:source_spotify',$second,true)&&in_array('custom:section:spotify',$second,true),'Personalization has a paged Spotify screen with its texts, caption, button and switch');
    $admin->handle(['callback_query'=>['id'=>'c','from'=>['id'=>42],'message'=>['message_id'=>900,'chat'=>['id'=>42,'type'=>'private']],'data'=>'custom:spsource']]);
    spotifyCheck($store->get('spotify.source_button')==='1','the switch turns the source button on');
    $admin->handle(['callback_query'=>['id'=>'c','from'=>['id'=>7],'message'=>['message_id'=>900,'chat'=>['id'=>7,'type'=>'private']],'data'=>'custom:spsource']]);
    spotifyCheck($store->get('spotify.source_button')==='1','an ordinary user cannot flip the switch');
    $store->query('DELETE FROM users');
    $send($canonical,3,true);
    $job=$run(); if($job['status']!=='done') throw new RuntimeException('Group Spotify job failed: '.$store->get('last_job_error'));
    $audio=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendAudio'));
    $markup=json_decode($audio[0]['params']['reply_markup']??'{}',true)['inline_keyboard']??[];
    spotifyCheck((int)$audio[0]['params']['chat_id']===-100123&&(int)$audio[0]['params']['message_thread_id']===321,'a group request is answered in its own forum topic');
    spotifyCheck(($markup[0][0]['url']??'')===$canonical&&str_contains($markup[0][0]['text'],'اسپاتیفای'),'the source button opens the track once it is turned on');

    // 6. Failures: a missing track page, no matching recording and a revoked permission all end with one clear status.
    $failure=function(array $modes,string $expected,string $label) use($send,$run,$store,$mode,$calls,$log) {
        static $update=100; $update++; file_put_contents($mode,json_encode($modes)); file_put_contents($log,''); $store->query('DELETE FROM users');
        $send('https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',$update);
        $job=$run(); $texts=array_column(array_column(array_filter($calls(),fn($call)=>$call['method']==='editMessageText'),'params'),'text');
        spotifyCheck($job['status']==='failed'&&str_contains(end($texts),$expected)&&$store->get('last_job_service')==='spotify',$label);
    };
    $failure(['page'=>'missing'],'پیدا نشد','a missing track page is reported as "not found"');
    $failure(['search'=>'empty','soundcloud'=>'empty'],'پیدا نشد','a search without results is reported as "not found"');
    $failure(['search'=>'far','soundcloud'=>'empty'],'پیدا نشد','a result of the wrong length is not sent');
    $failure(['youtube'=>'blocked','soundcloud'=>'empty'],'یوتیوب و منبع جایگزین','when YouTube refuses the server and SoundCloud has nothing, the status says so');

    // 7. YouTube refuses the server (its bot check): the track still arrives from SoundCloud.
    file_put_contents($mode,json_encode(['youtube'=>'blocked'])); file_put_contents($log,''); file_put_contents($ytLog,''); $store->query('DELETE FROM users'); $store->query('DELETE FROM jobs');
    $send($canonical,300);
    $job=$run(); if($job['status']!=='done') throw new RuntimeException('Fallback job failed: '.$store->get('last_job_error'));
    $audio=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendAudio')); $yt=$searches();
    $downloads=array_values(array_filter($yt,fn($args)=>in_array('-o',$args,true)));
    spotifyCheck(count($audio)===1&&$audio[0]['params']['title']==='Fixture Song'&&$audio[0]['params']['performer']==='Fixture Artist, Guest Singer','a blocked YouTube still ends with the tagged audio message');
    spotifyCheck(str_starts_with(end($yt[0]),'ytsearch')&&count($downloads)===1&&end($downloads[0])==='https://api.soundcloud.com/tracks/soundcloud%3Atracks%3A333000333','after YouTube refuses, the upload with the artist and title in its text is downloaded, not the 30 s preview or the unrelated upload');
    spotifyCheck(!in_array('--cookies',$downloads[0],true)&&!str_contains(implode(' ',$downloads[0]),'youtube'),'the second source is reached without YouTube cookies or YouTube options');
    $probe=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$temp.'/delivered-audio'],$temp,30),true);
    spotifyCheck(($probe['format']['tags']['title']??'')==='Fixture Song'&&in_array('mjpeg',array_column($probe['streams'],'codec_name'),true),'the fallback audio is tagged with the Spotify title and cover too');
    // YouTube has no good match: SoundCloud is tried as well.
    file_put_contents($mode,json_encode(['search'=>'empty'])); file_put_contents($log,''); file_put_contents($ytLog,''); $store->query('DELETE FROM users');
    $send($canonical,301);
    $job=$run();
    spotifyCheck($job['status']==='done'&&count(array_filter($calls(),fn($call)=>$call['method']==='sendAudio'))===1,'no good YouTube match falls back to SoundCloud');
    // Strict matching and the social-media proxy.
    $strictMeta=['title'=>'Fixture Song','artists'=>['Fixture Artist'],'duration'=>12,'year'=>'','cover'=>''];
    $nameOnly=['id'=>'222000222','title'=>'Fixture Song','duration'=>12.0,'channel'=>'Random Upload'];
    spotifyCheck(Zero\Spotify::best($strictMeta,[$nameOnly],true)===null&&Zero\Spotify::best($strictMeta,[['id'=>'abcdefghijk']+$nameOnly])!==null,'uploads by anyone need both the name and the artist, official channels only one');
    $proxied=clone $config; $proxied->galleryProxy='socks5://proxy-user:proxy-pass@127.0.0.1:1080'; file_put_contents($ytLog,''); file_put_contents($mode,'{}');
    (new Zero\Download($proxied))->searchSoundCloud('Fixture Artist Fixture Song',$temp);
    $sc=$searches()[0];
    spotifyCheck($sc[array_search('--proxy',$sc,true)+1]==='socks5://proxy-user:proxy-pass@127.0.0.1:1080','the social-media proxy is used for SoundCloud');
    $store->query('DELETE FROM jobs'); $store->query('DELETE FROM users');
    $store->set('admins_only','1'); file_put_contents($mode,'{}'); file_put_contents($log,''); file_put_contents($ytLog,'');
    $store->set('admins_only','0'); $send($canonical,200); $store->set('admins_only','1');
    $job=$run();
    spotifyCheck($job['status']==='failed'&&!array_filter($calls(),fn($call)=>$call['method']==='sendAudio')&&filesize($ytLog)===0,'when access closes after queueing, nothing is downloaded or sent');
    $store->set('admins_only','0');
    spotifyCheck(!is_dir($config->storage.'/downloads')||!glob($config->storage.'/downloads/job-*'),'temporary job folders are removed after success and failure');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    if(is_dir($temp)) Zero\Download::clean($temp);
}
echo "All tests passed.\n";
