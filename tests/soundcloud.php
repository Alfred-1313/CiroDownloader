<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function soundcloudCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class SoundCloudUiApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed { $this->calls[]=[$method,$params]; return $method==='sendMessage'?['message_id'=>900]:true; }
}

$canonical='https://soundcloud.com/fixture-artist/fixture-song';
// 1. Only one track is accepted, on SoundCloud's own hosts.
foreach([$canonical,$canonical.'?si=abc&utm_source=clipboard','soundcloud.com/fixture-artist/fixture-song/','http://m.soundcloud.com/fixture-artist/fixture-song','https://www.soundcloud.com/fixture-artist/fixture-song#t=10'] as $input)
    soundcloudCheck(Zero\soundcloudUrl($input)===$canonical&&Zero\mediaUrl($input)===$canonical&&Zero\musicService($input)==='soundcloud','track link is normalized: '.$input);
soundcloudCheck(Zero\mediaUrl('https://on.soundcloud.com/AbCd1234')==='https://on.soundcloud.com/AbCd1234'&&Zero\musicService('https://on.soundcloud.com/AbCd1234')==='soundcloud','the share link of the mobile app is accepted as it is');
foreach(['https://soundcloud.com/fixture-artist','https://soundcloud.com/fixture-artist/sets/mixtape','https://soundcloud.com/fixture-artist/likes','https://soundcloud.com/fixture-artist/tracks','https://soundcloud.com/fixture-artist/albums'] as $input)
    soundcloudCheck(Zero\soundcloudUrl($input)===null&&Zero\collectionUrl($input)!==null&&Zero\mediaUrl($input)===Zero\collectionUrl($input)['url'],'a profile or set is not one track but opens a menu: '.$input);
foreach(['https://soundcloud.com/discover/sets/charts','https://soundcloud.com/discover','https://soundcloud.com/fixture-artist/fixture-song/s-PrivateToken','https://soundcloud.com.evil.example/a/b','https://evil.example/soundcloud.com/a/b','https://user@soundcloud.com/a/b',
    'https://soundcloud.com:8443/a/b','https://api.soundcloud.com/tracks/abc','https://api.evil.example/tracks/123','https://on.soundcloud.com/ab','https://on.soundcloud.com/AbCd1234/extra','ftp://soundcloud.com/a/b','https://soundcloud.com/a/b c'] as $input)
    soundcloudCheck(Zero\soundcloudUrl($input)===null&&Zero\mediaUrl($input)===null,'not a public track or a supported page, refused: '.$input);
soundcloudCheck(Zero\soundcloudUrl('https://api.soundcloud.com/tracks/123')==='https://api.soundcloud.com/tracks/soundcloud%3Atracks%3A123'&&Zero\musicService('https://api-v2.soundcloud.com/tracks/soundcloud:tracks:123')==='soundcloud','a bare API address of a track (as sets list untitled tracks) is accepted and normalized');
soundcloudCheck(Zero\musicService('https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT')==='spotify'&&Zero\musicService('https://youtu.be/ybNMMK5Q318')===null&&Zero\galleryService($canonical)===null,'the music services stay apart from each other, YouTube and the gallery services');

// 2. Track details come from the extractor's JSON.
$info=['title'=>'Fixture Song','artists'=>['Fixture Artist','Guest'],'uploader'=>'fixture uploader','duration'=>12.4,'timestamp'=>1620000000,'release_timestamp'=>1556841600,
    'thumbnails'=>[['id'=>'large','url'=>'https://i1.sndcdn.com/artworks-x-large.jpg'],['id'=>'t500x500','url'=>'https://i1.sndcdn.com/artworks-x-t500x500.jpg'],['id'=>'evil','url'=>'https://evil.example/huge.jpg','width'=>4000]]];
$meta=Zero\SoundCloud::metadata($info);
soundcloudCheck($meta['title']==='Fixture Song'&&$meta['artists']===['Fixture Artist','Guest']&&$meta['duration']===12&&$meta['year']==='2019','title, artists, length and the release year are read');
soundcloudCheck($meta['cover']==='https://i1.sndcdn.com/artworks-x-t500x500.jpg','the largest cover on SoundCloud\'s own image host is chosen and foreign hosts are ignored');
$meta=Zero\SoundCloud::metadata(['title'=>'Only Uploader','uploader'=>'Some Fan','duration'=>200,'timestamp'=>1620000000]);
soundcloudCheck($meta['artists']===['Some Fan']&&$meta['year']==='2021'&&$meta['cover']==='','the uploader stands in when no artist is credited');
foreach([['title'=>'','uploader'=>'x','duration'=>9],['title'=>'No artist','duration'=>9],['title'=>'No length','uploader'=>'x'],['title'=>'Too long','uploader'=>'x','duration'=>999999]] as $bad) {
    try { Zero\SoundCloud::metadata($bad); $failed=false; } catch(Zero\MediaFailure $e) { $failed=$e->reason==='format'; }
    soundcloudCheck($failed,'incomplete track data is refused: '.($bad['title']?:'empty title'));
}

soundcloudCheck(Zero\MediaFailure::process(1,'ERROR: [soundcloud] 431099817: This video is DRM protected')->reason==='format','an encrypted (DRM) official upload is reported as having no version to take, never decrypted');
$temp=sys_get_temp_dir().'/ciro-soundcloud-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); mkdir($config->storage,0700); mkdir($config->storage.'/downloads',0700); $runner=new Zero\Download($config);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','sine=frequency=440:sample_rate=44100:duration=12','-c:a','aac',$temp.'/source.m4a'],$temp,30);
    $log=$temp.'/http.jsonl'; $ytLog=$temp.'/yt.jsonl'; $mode=$temp.'/mode.json'; file_put_contents($log,''); file_put_contents($ytLog,''); file_put_contents($mode,'{}');
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php $root='.var_export($temp,true).';$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);'.
        'if(in_array($path,["/AbCd1234","/Loop0001","/Loop0002","/Loop0003","/Loop0004","/Loop0005","/EvilHost1","/NoRedir001"],true)) {'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>"short","params"=>["path"=>$path]]).PHP_EOL,FILE_APPEND);'.
        '$next=match($path) {"/AbCd1234"=>"https://soundcloud.com/fixture-artist/fixture-song?utm_source=mobi&si=x","/EvilHost1"=>"http://169.254.169.254/latest","/NoRedir001"=>null,'.
        'default=>"https://on.soundcloud.com/Loop000".(((int)substr($path,-1))+1)};'.
        'if($next===null) { echo "no redirect";return; } http_response_code(302);header("Location: ".$next);echo str_repeat("x",100);return; }'.
        '$method=basename($path);$uploads=[];foreach($_FILES as $key=>$file) { $uploads[$key]=["hash"=>hash_file("sha256",$file["tmp_name"]),"mime"=>$file["type"],"name"=>$file["name"],"size"=>filesize($file["tmp_name"])];'.
        'if($method==="sendAudio") copy($file["tmp_name"],$root."/delivered-".$key); }'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST,"uploads"=>$uploads],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        '$counter=$root."/counter";$next=(int)@file_get_contents($counter)+1;file_put_contents($counter,(string)$next);'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>str_starts_with($method,"send")?["message_id"=>900+$next]:true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $base='http://'.$address; $config->api=$base;
    // A stand-in for yt-dlp: it writes one track's audio and its info JSON, and records every call.
    $stub=$temp.'/yt-dlp-fixture';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        '$args=array_slice($argv,1);file_put_contents('.var_export($ytLog,true).',json_encode($args).PHP_EOL,FILE_APPEND);'.
        '$mode=json_decode((string)file_get_contents('.var_export($mode,true).'),true)?:[];$target=end($args);'.
        'if(($mode["download"]??"ok")==="unavailable") { fwrite(STDERR,"ERROR: [soundcloud] fixture: Requested format is not available");exit(1); }'.
        '$template=$args[array_search("-o",$args,true)+1];copy('.var_export($temp.'/source.m4a',true).',str_replace("%(ext)s","m4a",$template));'.
        'if(in_array("--write-info-json",$args,true)) file_put_contents(str_replace("%(ext)s","info.json",$template),json_encode(['.
        '"title"=>"Fixture Song","artists"=>["Fixture Artist"],"uploader"=>"fixture uploader","duration"=>(($mode["download"]??"ok")==="preview"?200.0:12.4),"timestamp"=>1556841600,'.
        '"thumbnails"=>[["id"=>"t500x500","url"=>"https://evil.example/cover.jpg"]]]));'.
        'echo json_encode("Fixture Song").PHP_EOL;');
    chmod($stub,0700); $config->ytDlp=$stub;

    $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
    $worker=new Zero\Worker($config,$store,$api,null,null,new Zero\SoundCloud($config,$base));
    $records=fn(string $file)=>array_map(fn($line)=>json_decode($line,true),file($file,FILE_IGNORE_NEW_LINES));
    $calls=fn()=>$records($log); $searches=fn()=>$records($ytLog);

    // 3. Share links are followed only through SoundCloud's own track addresses.
    $soundcloud=new Zero\SoundCloud($config,$base);
    soundcloudCheck($soundcloud->resolve($canonical.'?x=1')===$canonical&&$soundcloud->resolve('https://on.soundcloud.com/AbCd1234')===$canonical,'a share link resolves to the plain track address and a normal link needs no request');
    foreach([['https://on.soundcloud.com/EvilHost1','a redirect to another host'],['https://on.soundcloud.com/NoRedir001','a share link that does not redirect'],['https://on.soundcloud.com/Loop0001','a redirect loop']] as [$link,$label]) {
        try { $soundcloud->resolve($link); $failed=false; } catch(Zero\MediaFailure $e) { $failed=true; }
        soundcloudCheck($failed,'the bot refuses '.$label);
    }
    $hops=count(array_filter($calls(),fn($call)=>$call['method']==='short'));
    soundcloudCheck($hops<=1+1+1+4,'a redirect loop is cut off after a few hops');

    // 4. A link in a private chat is queued in the media lane, reacts with the music reaction and is delivered as one tagged audio message.
    $send=function(string $url,int $update,bool $group=false) use($bot) {
        $user=10+$update; $chat=$group?-100123:$user;
        $bot->handle(['update_id'=>$update,'message'=>['message_id'=>$update,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$group?'supergroup':'private'],'message_thread_id'=>$group?321:0,'text'=>$url]]);
    };
    $run=function() use($store,$worker) {
        $job=$store->claim('media'); if(!$job) throw new RuntimeException('SoundCloud job was not queued');
        $worker->process($job); return $store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
    };
    file_put_contents($log,'');
    $send('https://soundcloud.com/discover',1);
    $notice=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendMessage'));
    soundcloudCheck($store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()==0&&count($notice)===1&&str_contains($notice[0]['params']['text'],'ساوندکلاود'),'a link that is neither a track nor a supported page is not queued and the reply lists SoundCloud among the supported links');
    $store->query('DELETE FROM users'); file_put_contents($log,'');
    $send($canonical.'?si=share',2);
    $queued=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendMessage'));
    soundcloudCheck(str_contains($queued[0]['params']['text'],'ساوندکلاود')&&$store->query('SELECT phase FROM jobs')->fetchColumn()==='soundcloud','the status message names SoundCloud and the job waits in the media lane');
    soundcloudCheck($store->claim('inspect')===null,'the YouTube inspection lane does not take a SoundCloud job');
    $reaction=array_values(array_filter($calls(),fn($call)=>$call['method']==='setMessageReaction'));
    soundcloudCheck(count($reaction)===1&&json_decode($reaction[0]['params']['reaction'],true)[0]['emoji']==='🔥','the link gets the music reaction, not the one for other links');
    $store->query("UPDATE jobs SET status='queued'");
    $job=$run(); if($job['status']!=='done') throw new RuntimeException('SoundCloud job failed: '.$store->get('last_job_error'));
    $audio=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendAudio')); $params=$audio[0]['params'];
    soundcloudCheck(count($audio)===1&&$audio[0]['uploads']['audio']['mime']==='audio/mpeg'&&$audio[0]['uploads']['audio']['name']==='Fixture Artist - Fixture Song.mp3','one MP3 audio message is sent with the artist and title as its file name');
    soundcloudCheck($params['title']==='Fixture Song'&&$params['performer']==='Fixture Artist'&&(int)$params['duration']>=12&&(int)$params['duration']<=13&&!isset($audio[0]['uploads']['thumbnail']),'Telegram receives the real title, artist and length; a cover from a foreign host is never fetched');
    soundcloudCheck(str_contains($params['caption'],'Fixture Song')&&str_contains($params['caption'],'Fixture Artist')&&str_contains($params['caption'],'00:12')&&!str_contains($params['caption'],'href=')&&!isset($params['reply_markup']),'the default caption has title, artist and length, without a link or a source button');
    $probe=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$temp.'/delivered-audio'],$temp,30),true);
    soundcloudCheck(array_column($probe['streams'],'codec_name','codec_type')===['audio'=>'mp3']&&($probe['format']['tags']['title']??'')==='Fixture Song'&&($probe['format']['tags']['artist']??'')==='Fixture Artist'&&($probe['format']['tags']['date']??'')==='2019','the MP3 carries the title, artist and release year');
    $yt=$searches();
    soundcloudCheck(count($yt)>=1&&end($yt[0])===$canonical&&in_array('--write-info-json',$yt[0],true)&&!in_array('--cookies',$yt[0],true),'the track page is downloaded once with its details and without YouTube cookies');
    soundcloudCheck(in_array('ba[format_id!*=preview]/b[format_id!*=preview]',$yt[0],true),'the 30 s previews SoundCloud lists are never chosen');

    // 5. Group delivery keeps the topic; the administrator controls the source button and the music reaction.
    file_put_contents($log,''); $uiApi=new SoundCloudUiApi(); $admin=new Zero\Bot($config,$store,$uiApi);
    $click=fn(string $data,int $user=42)=>$admin->handle(['callback_query'=>['id'=>'c','from'=>['id'=>$user],'message'=>['message_id'=>900,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]);
    $click('custom:section:soundcloud');
    $actions=array_column(array_merge(...end($uiApi->calls)[1]['reply_markup']['inline_keyboard']),'callback_data');
    soundcloudCheck(in_array('custom:scsource',$actions,true)&&in_array('custom:item:text:soundcloud_caption',$actions,true)&&in_array('custom:item:text:soundcloud_queued',$actions,true),'Personalization has a SoundCloud screen with its texts, caption and source switch');
    $click('custom:section:soundcloud:1');
    $actions=array_column(array_merge(...end($uiApi->calls)[1]['reply_markup']['inline_keyboard']),'callback_data');
    soundcloudCheck(in_array('custom:item:button:source_soundcloud',$actions,true)&&in_array('custom:item:text:soundcloud_failed',$actions,true),'its error texts and source button are on the next page');
    $click('custom:scsource'); soundcloudCheck($store->get('soundcloud.source_button')==='1'&&$store->get('spotify.source_button')==='','the switch turns on only the SoundCloud source button');
    $click('custom:scsource',7); soundcloudCheck($store->get('soundcloud.source_button')==='1','an ordinary user cannot flip the switch');
    $store->query('DELETE FROM users'); $send($canonical,3,true);
    $job=$run(); if($job['status']!=='done') throw new RuntimeException('Group SoundCloud job failed: '.$store->get('last_job_error'));
    $audio=array_values(array_filter($calls(),fn($call)=>$call['method']==='sendAudio'));
    $markup=json_decode($audio[0]['params']['reply_markup']??'{}',true)['inline_keyboard']??[];
    soundcloudCheck((int)$audio[0]['params']['chat_id']===-100123&&(int)$audio[0]['params']['message_thread_id']===321,'a group request is answered in its own forum topic');
    soundcloudCheck(($markup[0][0]['url']??'')===$canonical&&str_contains($markup[0][0]['text'],'ساوندکلاود'),'the source button opens the track once it is turned on');

    // 6. The music reaction is configurable and follows the general switch.
    $reactionFor=function(string $url,int $update) use($send,$calls,$store,$log) {
        file_put_contents($log,''); $store->query('DELETE FROM users'); $store->query('DELETE FROM jobs'); $send($url,$update);
        $found=array_values(array_filter($calls(),fn($call)=>$call['method']==='setMessageReaction'));
        return $found?json_decode($found[0]['params']['reaction'],true)[0]['emoji']:'';
    };
    $click('custom:reaction'); $labels=array_column(array_merge(...end($uiApi->calls)[1]['reply_markup']['inline_keyboard']),'callback_data');
    soundcloudCheck(in_array('custom:reaction:music:0',$labels,true)&&in_array('custom:reaction:music:same',$labels,true)&&str_contains(end($uiApi->calls)[1]['text'],'🔥'),'the reaction screen has a separate row of choices for music links');
    $click('custom:reaction:music:4');
    soundcloudCheck($reactionFor($canonical,40)==='👀'&&$reactionFor('https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',41)==='👀'&&$reactionFor('https://youtu.be/ybNMMK5Q318',42)==='👾','a chosen music reaction applies to Spotify and SoundCloud but not to other links');
    $click('custom:reaction:music:same');
    soundcloudCheck($reactionFor($canonical,43)==='👾','"same as other links" gives music links the general reaction');
    $click('custom:reaction:music:99'); $click('custom:reaction:music:x',7);
    soundcloudCheck($reactionFor($canonical,44)==='👾'&&$store->get('link_reaction.music')==='same','an unknown choice or a request from an ordinary user changes nothing');
    $click('custom:reaction:off'); $click('custom:reaction:music:3');
    soundcloudCheck($reactionFor($canonical,45)==='','when reactions are turned off, music links get none either');
    $click('custom:reaction:0'); $store->set('link_reaction.music','');
    soundcloudCheck($reactionFor($canonical,46)==='🔥','without a choice, music links use the default 🔥');

    // 7. Failures end with one clear status; a preview-only track is not sent as if it were the song.
    $failure=function(array $modes,string $expected,string $label) use($send,$run,$store,$mode,$calls,$log,$canonical) {
        static $update=100; $update++; file_put_contents($mode,json_encode($modes)); file_put_contents($log,''); $store->query('DELETE FROM users'); $store->query('DELETE FROM jobs');
        $send($canonical,$update);
        $job=$run(); $texts=array_column(array_column(array_filter($calls(),fn($call)=>$call['method']==='editMessageText'),'params'),'text');
        soundcloudCheck($job['status']==='failed'&&str_contains(end($texts),$expected)&&$store->get('last_job_service')==='soundcloud'&&!array_filter($calls(),fn($call)=>$call['method']==='sendAudio'),$label);
    };
    $failure(['download'=>'unavailable'],'در دسترس نیست','a track without a public full version is reported as unavailable');
    $failure(['download'=>'preview'],'در دسترس نیست','a 30 s preview of a longer track is never sent');
    file_put_contents($mode,'{}'); file_put_contents($log,''); file_put_contents($ytLog,''); $store->query('DELETE FROM users'); $store->query('DELETE FROM jobs');
    $store->set('admins_only','0'); $send($canonical,200); $store->set('admins_only','1');
    $job=$run();
    soundcloudCheck($job['status']==='failed'&&!array_filter($calls(),fn($call)=>$call['method']==='sendAudio')&&filesize($ytLog)===0,'when access closes after queueing, nothing is downloaded or sent');
    $store->set('admins_only','0');
    soundcloudCheck(!is_dir($config->storage.'/downloads')||!glob($config->storage.'/downloads/job-*'),'temporary job folders are removed after success and failure');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    if(is_dir($temp)) Zero\Download::clean($temp);
}
echo "All tests passed.\n";
