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
$info=['title'=>'Fixture','duration'=>100,'thumbnail'=>'https://ei.phncdn.com/videos/1/2/thumb.jpg','formats'=>[
    ['format_id'=>'hls-240p','height'=>240,'tbr'=>400],
    ['format_id'=>'hls-480p','height'=>480,'tbr'=>1000,'vcodec'=>'avc1.4d401e','acodec'=>'mp4a.40.2'],
    ['format_id'=>'480p','height'=>480,'width'=>854,'filesize'=>9000000],
    ['format_id'=>'hls-720p','height'=>720,'tbr'=>2000],
    ['format_id'=>'hls-1080p','height'=>1080,'tbr'=>9000],
    ['format_id'=>'video-only','height'=>720,'acodec'=>'none','tbr'=>100],
    ['format_id'=>'bad id!','height'=>480,'tbr'=>100],
]];
$options=Zero\Download::sizedOptions($info,49000000);
phCheck(array_keys($options)===[480,720]&&array_column($options[480],'id')===['480p','hls-480p']&&array_column($options[720],'id')===['hls-720p'],'the menu offers the heights that fit: 1080p (about 112 MB) and 240p are left out, and video without sound is never offered');
// Pornhub states no size and no bitrate, but its file names carry the bitrate: 1002 s at 2000 kbps is ~250 MB, so 480p and up are left out.
$real=['duration'=>1002,'formats'=>[
    ['format_id'=>'240p','height'=>240,'url'=>'https://ev.phncdn.com/c1/videos/1/2/240P_1000K_67504295.mp4?x=1'],
    ['format_id'=>'480p','height'=>480,'url'=>'https://ev.phncdn.com/c1/videos/1/2/480P_2000K_67504295.mp4?x=1']]];
try { Zero\Download::sizedOptions($real,49000000); $failure=null; } catch(Zero\MediaFailure $e) { $failure=$e->reason; }
phCheck($failure==='size','a long video is judged by the bitrate in its file names: nothing fits, so the person is told before anything is downloaded');
$short=$real; $short['duration']=100;
phCheck(array_keys(Zero\Download::sizedOptions($short,49000000))===[480],'a short one (100 s at 2000 kbps is 25 MB) fits');
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
    $trace=$temp.'/args.jsonl'; $stub=$temp.'/yt-dlp';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        'file_put_contents('.var_export($trace,true).',json_encode($argv).PHP_EOL,FILE_APPEND);'.
        'if(in_array("--dump-single-json",$argv,true)) { echo '.var_export(json_encode($info),true).'; exit; }'.
        '$template=$argv[array_search("-o",$argv,true)+1]; copy('.var_export($source,true).',str_replace("%(ext)s","mp4",$template)); echo json_encode("Fixture Video").PHP_EOL;');
    chmod($stub,0700); $config->ytDlp=$stub;
    Zero\saveYouTubeCookies($config,"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_COOKIE\n");
    $worker=new Zero\Worker($config,$store,$api);
    $send($link,42); $job=$store->claim('inspect'); $worker->process($job);
    $poster=$api->called('editMessageMedia'); $rows=end($poster)[1]['reply_markup']['inline_keyboard']??[];
    $data=array_column(array_merge(...$rows),'callback_data');
    phCheck(end($poster)[1]['media']['media']==='https://ei.phncdn.com/videos/1/2/thumb.jpg'&&in_array('quality:'.$job['id'].':480',$data,true)&&in_array('quality:'.$job['id'].':720',$data,true)&&!in_array('quality:'.$job['id'].':audio',$data,true)&&!in_array('quality:'.$job['id'].':1080',$data,true),'the status message becomes the poster with the qualities that fit, and no audio choice');
    $args=array_map(fn($line)=>json_decode($line,true),file($trace,FILE_IGNORE_NEW_LINES));
    phCheck(!in_array('--cookies',$args[0],true)&&end($args[0])===$link&&$store->get('youtube_probe_url')==='','the page is read without YouTube\'s cookies, and it never becomes YouTube\'s probe address');
    $update++; $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'q','from'=>['id'=>42,'is_bot'=>false],'message'=>['message_id'=>(int)$store->query('SELECT message_id FROM jobs WHERE id=?',[$job['id']])->fetchColumn(),'chat'=>['id'=>42,'type'=>'private']],'data'=>'quality:'.$job['id'].':480']]);
    $download=$store->claim('media'); phCheck($download!==null&&(int)$download['quality']===480,'choosing 480p queues the download');
    $worker->process($download);
    $video=$api->called('sendVideo'); $params=end($video)[1]??[];
    phCheck(count($video)===1&&$params['caption']==="<b>🔞 Fixture Video</b>\n<blockquote>⏱ 00:02 · 🎞 480p</blockquote>"&&!isset($params['reply_markup'])&&(int)$params['width']===854&&(int)$params['height']===480,'the video arrives with its own short caption and no link back to the site');
    $args=array_map(fn($line)=>json_decode($line,true),file($trace,FILE_IGNORE_NEW_LINES)); $downloadArgs=end($args);
    phCheck($downloadArgs[array_search('-f',$downloadArgs,true)+1]==='480p/hls-480p'&&!in_array('--cookies',$downloadArgs,true),'the saved 480p formats are downloaded, without cookies');
    phCheck((int)$store->query("SELECT ok FROM stats WHERE service='pornhub'")->fetchColumn()===1,'the status report counts it as Pornhub');

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
