<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';

function phCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class PornhubApi implements Zero\Api {
    public array $calls=[]; private int $next=500;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='editMessageMedia') return ['message_id'=>(int)($params['message_id']??0),'photo'=>[['file_id'=>'SMALL'],['file_id'=>'BIG_POSTER']]];
        if(str_starts_with($method,'send')) return ['message_id'=>++$this->next];
        return true;
    }
    public function called(string $method): array { return array_values(array_filter($this->calls,fn($call)=>$call[0]===$method)); }
}
$link='https://www.pornhub.com/view_video.php?viewkey=ph5f8a9b2c3d4e5';

// 1. Only one video page, in every spelling, on the site's own hosts.
foreach(['https://www.pornhub.com/view_video.php?viewkey=ph5f8a9b2c3d4e5','pornhub.com/view_video.php?viewkey=ph5f8a9b2c3d4e5','https://de.pornhub.com/view_video.php?viewkey=ph5f8a9b2c3d4e5&t=10','https://m.pornhub.com/embed/ph5f8a9b2c3d4e5'] as $input)
    phCheck(Zero\pornhubUrl($input)===$link,'a video page is recognised: '.$input);
foreach(['https://www.pornhub.com/model/someone','https://www.pornhub.com/playlist/123','https://pornhub.com.evil.example/view_video.php?viewkey=ph5f8a9b2c3d4e5','https://www.pornhub.com:8443/view_video.php?viewkey=ph5f8a9b2c3d4e5','https://user@www.pornhub.com/view_video.php?viewkey=ph5f8a9b2c3d4e5','https://www.pornhub.com/view_video.php?viewkey=a<b','ftp://www.pornhub.com/view_video.php?viewkey=ph5f8a9b2c3d4e5'] as $input)
    phCheck(Zero\pornhubUrl($input)===null,'refused: '.$input);
phCheck(Zero\mediaUrl($link)===null,'the link is not one of the always-on services (so groups and the default never take it)');

// 2. Qualities: the YouTube menu's heights, only formats with sound, nothing above the upload limit.
// A real page: plain MP4s without codec or size (throttled by the CDN) and HLS streams with codec, bitrate and size.
$info=['title'=>'Fixture','duration'=>100,'uploader'=>'chan<nel>','cast'=>['Alice','Bob','Alice'],'categories'=>['Amateur','POV'],'tags'=>['homemade','pov'],'view_count'=>1234567,'like_count'=>99,
    'thumbnail'=>'https://ei.phncdn.com/videos/1/2/thumb.jpg','formats'=>[
    ['format_id'=>'240p','height'=>240,'protocol'=>'https','url'=>'https://ev.phncdn.com/x/240P_400K_1.mp4'],
    ['format_id'=>'hls-671','height'=>240,'width'=>427,'protocol'=>'m3u8_native','vcodec'=>'avc1.640015','acodec'=>'mp4a.40.2','tbr'=>672],
    ['format_id'=>'480p','height'=>480,'protocol'=>'https','url'=>'https://ev.phncdn.com/x/480P_2000K_1.mp4'],
    ['format_id'=>'hls-1815','height'=>480,'width'=>853,'protocol'=>'m3u8_native','vcodec'=>'avc1.64001f','acodec'=>'mp4a.40.2','tbr'=>1816],
    ['format_id'=>'hls-2898','height'=>720,'width'=>1280,'protocol'=>'m3u8_native','vcodec'=>'avc1.640020','acodec'=>'mp4a.40.2','tbr'=>2899],
    ['format_id'=>'hls-5209','height'=>1080,'width'=>1920,'protocol'=>'m3u8_native','vcodec'=>'avc1.640028','acodec'=>'mp4a.40.2','tbr'=>90000],
    ['format_id'=>'video-only','height'=>720,'acodec'=>'none','tbr'=>100],
    ['format_id'=>'bad id!','height'=>480,'tbr'=>100],
]];
$options=Zero\Download::sizedOptions($info,49000000);
phCheck(array_keys($options)===[240,480,720,'audio'],'the ladder starts at 240p; a size that does not fit (1080p at 90 Mbps), video without sound and odd IDs are left out');
phCheck(array_column($options[480],'id')===['hls-1815','480p']&&array_column($options[240],'id')===['hls-671','240p'],'the HLS stream (parallel pieces, known H.264) is tried before the plain MP4 that the CDN throttles');
phCheck($options['audio']===[['id'=>'hls-671']],'the audio comes from the smallest stream that has sound');
$meta=Zero\Download::siteMeta($info);
phCheck($meta['people']===['Alice','Bob']&&$meta['channel']==='chan nel'&&$meta['categories']===['Amateur','POV']&&$meta['tags']===['homemade','pov']&&$meta['views']===1234567,'people, channel, categories, tags and views are read, de-duplicated and cleaned');
// Pornhub states no size and no bitrate, but its file names carry the bitrate: 1002 s at 2000 kbps is ~250 MB, so 480p and up are left out.
$real=['duration'=>1002,'formats'=>[
    ['format_id'=>'240p','height'=>240,'url'=>'https://ev.phncdn.com/c1/videos/1/2/240P_1000K_67504295.mp4?x=1'],
    ['format_id'=>'480p','height'=>480,'url'=>'https://ev.phncdn.com/c1/videos/1/2/480P_2000K_67504295.mp4?x=1']]];
try { Zero\Download::sizedOptions($real,49000000); $failure=null; } catch(Zero\MediaFailure $e) { $failure=$e->reason; }
phCheck($failure==='size','a long video is judged by the bitrate in its file names: nothing fits, so the person is told before anything is downloaded');
$short=$real; $short['duration']=100;
phCheck(array_keys(Zero\Download::sizedOptions($short,49000000))===[240,480,'audio'],'a short one (100 s at 1000 and 2000 kbps is 12 and 25 MB) fits');
$big=['duration'=>3600,'formats'=>[['format_id'=>'hls-480p','height'=>480,'tbr'=>2000]]];
try { Zero\Download::sizedOptions($big,49000000); $failure=null; } catch(Zero\MediaFailure $e) { $failure=$e->reason; }
phCheck($failure==='size','a video too large in every quality says so before anything is downloaded');
phCheck(Zero\Download::trustedPoster($info,'phncdn.com')==='https://ei.phncdn.com/videos/1/2/thumb.jpg'&&Zero\Download::trustedPoster(['thumbnail'=>'https://evil.example/a.jpg'],'phncdn.com')===''&&Zero\Download::trustedPoster(['thumbnail'=>'http://ei.phncdn.com/a.jpg'],'phncdn.com')==='','the poster is shown only from the site\'s own HTTPS image host');

$temp=sys_get_temp_dir().'/ciro-pornhub-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $storage=$temp.'/data'; mkdir($storage,0700); mkdir($storage.'/downloads',0700);
    $config=new Zero\Config('123:fixture',[42],$storage); $store=new Zero\Store($storage); $api=new PornhubApi(); $bot=new Zero\Bot($config,$store,$api);
    $update=0;
    $send=function(string $text,int $user,int $chat=0) use($bot,&$update) {
        $update++; $chat=$chat?:$user;
        $bot->handle(['update_id'=>$update,'message'=>['message_id'=>$update,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'text'=>$text]]);
    };
    $jobs=fn()=>(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    $lastText=function() use($api): string { $sent=$api->called('sendMessage'); return (string)(end($sent)[1]['text']??''); };

    // 3. Who may use it.
    $send($link,42);
    phCheck($jobs()===0&&$lastText()===$bot->text('invalid'),'off by default: even an administrator gets the usual "send a supported link"');
    $store->set('adult.pornhub','1');
    $send($link,7);
    phCheck($jobs()===0&&$lastText()===$bot->text('invalid'),'turned on, it is for administrators only by default; others see nothing about the site');
    $send($link,42);
    $job=$store->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch();
    phCheck($job&&$job['url']===$link&&$job['phase']==='inspect'&&(int)$job['user_id']===42,'an administrator\'s link is queued for the quality choice, like YouTube');
    $store->query('DELETE FROM jobs'); $store->query('UPDATE users SET last_request=0');
    $before=count($api->calls); $send($link,42,-100555);
    phCheck($jobs()===0,'never in a group');
    $store->set('adult.admins_only','0'); $send($link,7);
    phCheck($jobs()===1,'with "administrators only" off, anyone may use it in a private chat');
    $store->query('DELETE FROM jobs'); $store->query('UPDATE users SET last_request=0'); $store->set('adult.admins_only','1');

    // 4. The switches on the features page, administrators only.
    $click=function(string $data,int $user) use($bot,&$update) { $update++; $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'c'.$update,'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>900,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]); };
    $store->set('adult.pornhub','0');
    $click('custom:features',42);
    $edit=$api->called('editMessageText'); $buttons=array_merge(...(end($edit)[1]['reply_markup']['inline_keyboard']??[[]]));
    $switch=array_values(array_filter($buttons,fn($b)=>($b['callback_data']??'')==='custom:adult'))[0]??null;
    phCheck($switch!==null&&($switch['style']??'')==='danger'&&in_array('custom:adultadmins',array_column($buttons,'callback_data'),true),'the features page has the Pornhub switch (red while off) and "administrators only"');
    $click('custom:adult',42); phCheck($store->get('adult.pornhub')==='1','one tap turns it on');
    $click('custom:adultadmins',42); phCheck($store->get('adult.admins_only')==='0','and "administrators only" off');
    $click('custom:adult',7); $click('custom:adultadmins',7);
    phCheck($store->get('adult.pornhub')==='1'&&$store->get('adult.admins_only')==='0','an ordinary user cannot flip either');
    $store->set('adult.admins_only','1');

    // 5. The whole flow with a stand-in downloader: quality menu with the poster, a choice, then the video.
    $runner=new Zero\Download($config); $source=$temp.'/fixture.mp4';
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=red:s=854x480:r=5:d=2','-f','lavfi','-i','sine=frequency=440:sample_rate=44100:duration=2','-shortest','-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac',$source],$temp,60);
    $posterImage=$temp.'/poster.png'; $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=640x360','-frames:v','1',$posterImage],$temp,30);
    $trace=$temp.'/args.jsonl'; $stub=$temp.'/yt-dlp';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        'file_put_contents('.var_export($trace,true).',json_encode($argv).PHP_EOL,FILE_APPEND);'.
        'if(in_array("--dump-single-json",$argv,true)) { if(in_array("--write-thumbnail",$argv,true)) copy('.var_export($posterImage,true).',str_replace("%(ext)s","png",$argv[array_search("-o",$argv,true)+1])); echo '.var_export(json_encode($info),true).'; exit; }'.
        '$template=$argv[array_search("-o",$argv,true)+1]; copy('.var_export($source,true).',str_replace("%(ext)s","mp4",$template)); echo json_encode("Fixture Video").PHP_EOL;');
    chmod($stub,0700); $config->ytDlp=$stub;
    Zero\saveYouTubeCookies($config,"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_COOKIE\n");
    $worker=new Zero\Worker($config,$store,$api);
    $send($link,42); $job=$store->claim('inspect'); $worker->process($job);
    $poster=$api->called('editMessageMedia'); $rows=end($poster)[1]['reply_markup']['inline_keyboard']??[];
    $data=array_column(array_merge(...$rows),'callback_data'); $sent=end($poster)[1];
    phCheck(($sent['media']['media']??'')==='attach://poster'&&($sent['poster']??null) instanceof CURLFile&&$sent['poster']->getMimeType()==='image/png'&&(int)$store->query('SELECT COUNT(*) FROM jobs WHERE id=? AND poster=?',[$job['id'],'BIG_POSTER'])->fetchColumn()===1,'the cover is uploaded as a file (Telegram cannot always fetch the site\'s CDN) and its file ID is kept');
    phCheck($data===['quality:'.$job['id'].':240','quality:'.$job['id'].':480','quality:'.$job['id'].':720','quality:'.$job['id'].':audio','quality:'.$job['id'].':cancel']||array_values(array_filter($data,fn($d)=>$d!=='quality:'.$job['id'].':cancel'))===['quality:'.$job['id'].':240','quality:'.$job['id'].':480','quality:'.$job['id'].':720','quality:'.$job['id'].':audio'],'the menu has the same buttons as YouTube\'s: 240p, 480p, 720p (1080p does not fit) and the audio');
    $labels=array_column(array_merge(...$rows),'text');
    phCheck(str_contains($labels[0],'240p')&&str_contains(implode('|',$labels),'Audio'),'the lowest quality leads and the audio button is the usual one');
    $args=array_map(fn($line)=>json_decode($line,true),file($trace,FILE_IGNORE_NEW_LINES));
    phCheck(!in_array('--cookies',$args[0],true)&&end($args[0])===$link&&$store->get('youtube_probe_url')==='','the page is read without YouTube\'s cookies, and it never becomes YouTube\'s probe address');
    $update++; $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'q','from'=>['id'=>42,'is_bot'=>false],'message'=>['message_id'=>(int)$store->query('SELECT message_id FROM jobs WHERE id=?',[$job['id']])->fetchColumn(),'chat'=>['id'=>42,'type'=>'private']],'data'=>'quality:'.$job['id'].':480']]);
    $download=$store->claim('media'); phCheck($download!==null&&(int)$download['quality']===480,'choosing 480p queues the download');
    $worker->process($download);
    $video=$api->called('sendVideo'); $params=end($video)[1]??[];
    phCheck(count($video)===1&&$params['caption']==="<b>🔞 Fixture Video</b>\n👤 <b>Alice، Bob</b>\n<blockquote>⏱ 00:02 · 🎞 480p · 👁 1,234,567</blockquote>\n<blockquote expandable>🏷 #amateur #pov #homemade</blockquote>"&&!isset($params['reply_markup'])&&(int)$params['width']===854&&(int)$params['height']===480,'the video arrives with its title, the people in it, length, quality, views and the page\'s categories and tags, and no link back to the site');
    $args=array_map(fn($line)=>json_decode($line,true),file($trace,FILE_IGNORE_NEW_LINES)); $downloadArgs=end($args);
    phCheck($downloadArgs[array_search('-f',$downloadArgs,true)+1]==='hls-1815/480p'&&$downloadArgs[array_search('--concurrent-fragments',$downloadArgs,true)+1]==='8'&&!in_array('--cookies',$downloadArgs,true),'the HLS stream is tried first, with eight pieces at once, without cookies');
    phCheck((int)$store->query("SELECT ok FROM stats WHERE service='pornhub'")->fetchColumn()===1,'the status report counts it as Pornhub');

    // The audio choice: the smallest stream is downloaded and its sound becomes an MP3 with the same caption.
    $store->query('UPDATE users SET last_request=0'); $send($link,42); $job=$store->claim('inspect'); $worker->process($job);
    $menuId=(int)$store->query('SELECT message_id FROM jobs WHERE id=?',[$job['id']])->fetchColumn(); $update++;
    $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'qa','from'=>['id'=>42,'is_bot'=>false],'message'=>['message_id'=>$menuId,'chat'=>['id'=>42,'type'=>'private']],'data'=>'quality:'.$job['id'].':audio']]);
    $download=$store->claim('media'); $worker->process($download);
    $songs=$api->called('sendAudio'); $args=array_map(fn($line)=>json_decode($line,true),file($trace,FILE_IGNORE_NEW_LINES)); $audioArgs=end($args);
    phCheck(count($songs)===1&&str_contains($songs[0][1]['caption'],'🎞 MP3')&&str_contains($songs[0][1]['caption'],'Alice، Bob')&&!isset($songs[0][1]['reply_markup'])&&$audioArgs[array_search('-f',$audioArgs,true)+1]==='hls-671','the audio button gives an MP3 from the smallest stream, with the same caption and no link');

    // The administrator is told WHY a page could not be reached (one clean word), never the raw output.
    $broken=$temp.'/yt-broken';
    file_put_contents($broken,"#!/usr/bin/env php\n<?php\nfwrite(STDERR,'ERROR: [PornHub] x: Unable to download webpage: [SSL: CERTIFICATE_VERIFY_FAILED] certificate verify failed: self-signed certificate in certificate chain (https://secret.example/token=ABC)');exit(1);");
    chmod($broken,0700); $config->ytDlp=$broken; $store->set('adult.pornhub','1'); $store->query('UPDATE users SET last_request=0'); $send($link,42);
    $job=$store->claim('inspect'); $worker->process($job);
    $texts=array_map(fn($call)=>(string)($call[1]['text']??$call[1]['caption']??''),array_filter($api->calls,fn($call)=>str_starts_with($call[0],'edit')));
    phCheck($store->get('last_job_sources')==='pornhub=certificate'&&$store->get('last_job_service')==='pornhub'&&str_contains(end($texts),'اتصال سرور به سایت برقرار نشد'),'an unreachable site shows the person the network message and records "pornhub=certificate" for the administrator');
    $recent=json_decode($store->get('recent_errors','[]'),true);
    phCheck($recent[0]['s']==='pornhub'&&$recent[0]['r']==='network'&&$recent[0]['c']==='pornhub=certificate'&&!str_contains(json_encode($recent),'secret.example'),'the recent-errors list keeps the service, the reason and that word, and nothing from the downloader output');
    $api->calls=[]; $bot->handle(['update_id'=>9999,'message'=>['message_id'=>1,'from'=>['id'=>42,'is_bot'=>false],'chat'=>['id'=>42,'type'=>'private'],'text'=>'/health']]);
    $health=(string)end($api->calls)[1]['text'];
    phCheck(str_contains($health,'اتصال سرور به سایت برقرار نشد')&&str_contains($health,'pornhub=certificate')&&!str_contains($health,'secret.example'),'the administrator\'s status message shows the Pornhub text and the cause');
    foreach(['<urlopen error [Errno -2] Name or service not known>'=>'dns','<urlopen error [Errno 111] Connection refused>'=>'refused','<urlopen error timed out>'=>'timeout','HTTP Error 451: Unavailable For Legal Reasons'=>'region','HTTP Error 503: Service Unavailable'=>'unavailable','something else'=>'unknown'] as $text=>$word)
        phCheck(Zero\Download::siteCause($text)===$word,'the cause of "'.$text.'" is "'.$word.'"');
    $config->ytDlp=$stub;
    // 6. Turned off after the link was accepted: nothing is fetched.
    $store->query('UPDATE users SET last_request=0'); $send($link,42); $store->set('adult.pornhub','0'); $lines=count(file($trace));
    $job=$store->claim('inspect'); $worker->process($job);
    phCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='failed'&&count(file($trace))===$lines,'switching it off stops a queued link before anything is downloaded');
} finally { exec('rm -rf '.escapeshellarg($temp)); }
