<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';
function fallbackCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$temp=sys_get_temp_dir().'/ciro-fallback-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp,youtubeProxy:'http://127.0.0.1:9999');
    $runner=new Zero\Download($config); $source=$temp.'/fixture.mp4';
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=640x360:r=1:d=1','-f','lavfi','-i','sine=frequency=440:duration=1','-shortest','-c:v','libx264','-threads','2','-pix_fmt','yuv420p','-c:a','aac',$source],$temp,30);
    $trace=$temp.'/trace.jsonl'; $mode=$temp.'/mode.json'; $stub=$temp.'/downloader';
    $metadata=['id'=>'ybNMMK5Q318','title'=>'Fixture','duration'=>1,'formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac']]];
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        '$mode=json_decode(file_get_contents('.var_export($mode,true).'),true); $cookie=in_array("--cookies",$argv,true);'.
        '$backend=getenv("CIRO_YTDLP_BIN")?"yoinks":"yt-dlp";'.
        '$path=$cookie?$argv[array_search("--cookies",$argv,true)+1]:null; $out=in_array("-o",$argv,true)?$argv[array_search("-o",$argv,true)+1]:null;'.
        'file_put_contents('.var_export($trace,true).',json_encode(["args"=>$argv,"backend"=>$backend,"cookie"=>$cookie,"mode"=>$path?(fileperms($path)&0777):null,"partials"=>count(glob(getcwd()."/video.*"))]).PHP_EOL,FILE_APPEND);'.
        '$error=$cookie?($mode["cookie_error"]??""):($mode["anonymous_error"]??"");'.
        'if($error!=="") { if($out) file_put_contents(str_replace(["%(title).60s","%(ext)s"],["Fixture","mp4.part"],$out),"partial"); fwrite(STDERR,"ERROR: ".$error); exit(1); }'.
        'if($cookie&&($mode["rotate"]??false)) file_put_contents($path,str_replace("FAKE_COOKIE","FAKE_REFRESHED",file_get_contents($path)));'.
        'if($cookie&&($mode["remove"]??false)) unlink('.var_export($config->cookiePath(),true).');'.
        'if(in_array("--dump-single-json",$argv,true)||in_array("-J",$argv,true)) { echo '.var_export(json_encode($metadata),true).'; exit; }'.
        '$output=str_replace(["%(title).60s","%(ext)s"],["Fixture","mp4"],$out); copy('.var_export($source,true).',$output);'.
        'echo ($backend==="yoinks"?$output:json_encode("Fixture")).PHP_EOL;');
    chmod($stub,0700); $config->ytDlp=$stub;
    $cookies="# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_COOKIE\n";
    $url='https://youtu.be/ybNMMK5Q318'; $counter=0;
    $work=function(array $settings=[]) use($mode,$trace,$temp,&$counter): string {
        file_put_contents($mode,json_encode($settings)); file_put_contents($trace,'');
        $directory=$temp.'/run-'.++$counter; mkdir($directory,0700); return $directory;
    };
    $calls=fn()=>array_map(fn($line)=>json_decode($line,true),file($trace,FILE_IGNORE_NEW_LINES));
    Zero\saveYouTubeCookies($config,$cookies);
    $directory=$work(); $info=$runner->inspect($url,$directory); $seen=$calls();
    fallbackCheck($info['title']==='Fixture'&&count($seen)===1&&$seen[0]['backend']==='yt-dlp'&&$seen[0]['cookie']&&$seen[0]['mode']===0600,'a working cookie uses the primary yt-dlp provider with a private jar and no Yoinks invocation');
    $directory=$work(['rotate'=>true]); $media=$runner->fetch($url,$directory,360,[['id'=>'18','audio'=>true]]); $seen=$calls();
    fallbackCheck(count($seen)===1&&$seen[0]['cookie']&&$media['quality']==='360p'&&file_get_contents($config->cookiePath())===str_replace('FAKE_COOKIE','FAKE_REFRESHED',$cookies),'successful primary video delivery preserves selected quality and refreshes cookies');
    unlink($config->cookiePath());
    $directory=$work(); $runner->inspect($url,$directory); $seen=$calls();
    fallbackCheck(count($seen)===1&&$seen[0]['backend']==='yoinks'&&!$seen[0]['cookie']&&in_array('-J',$seen[0]['args'],true),'a missing cookie executes the actual Yoinks probe through its isolated shim');
    fallbackCheck(glob($directory.'/yoinks-info-*')===[]&&!is_file($directory.'/yoinks-request.json'),'Yoinks metadata and adapter request files are removed after inspection');
    $directory=$work(); $media=$runner->fetch($url,$directory,360,[['id'=>'18','audio'=>true]]); $seen=$calls();
    fallbackCheck(count($seen)===2&&array_unique(array_column($seen,'backend'))===['yoinks']&&!in_array(true,array_column($seen,'cookie'),true)&&$media['quality']==='360p','Yoinks probe and download deliver a real video without any saved cookie');
    fallbackCheck(in_array('--load-info-json',$seen[1]['args'],true)&&$seen[1]['args'][array_search('-f',$seen[1]['args'],true)+1]==='18','the upstream download reuses its probe metadata and only the owner-selected format');
    foreach($seen as $call) {
        $args=$call['args'];
        fallbackCheck(in_array('--ignore-config',$args,true)&&$args[array_search('--proxy',$args,true)+1]===$config->youtubeProxy,'Yoinks preserves proxy settings and cannot load external downloader configuration');
    }
    $directory=$work(); $media=$runner->fetchAudio($url,$directory,[['id'=>'18']]); $seen=$calls();
    fallbackCheck(count($seen)===2&&!in_array(true,array_column($seen,'cookie'),true)&&$media['format']==='MP3','the real Yoinks download path also prepares a complete MP3');
    Zero\saveYouTubeCookies($config,$cookies);
    $directory=$work(['cookie_error'=>'cookies are no longer valid']); $runner->inspect($url,$directory); $seen=$calls();
    fallbackCheck(count($seen)===2&&$seen[0]['backend']==='yt-dlp'&&$seen[0]['cookie']&&$seen[1]['backend']==='yoinks'&&!$seen[1]['cookie'],'an expired-cookie primary failure switches to the actual Yoinks provider');
    $directory=$work(['cookie_error'=>'cookies are no longer valid']); $media=$runner->fetch($url,$directory,360,[['id'=>'18','audio'=>true]]); $seen=$calls();
    fallbackCheck(count($seen)===3&&$seen[0]['cookie']&&$seen[1]['partials']===0&&$seen[2]['backend']==='yoinks'&&$media['quality']==='360p','a failed primary download clears partials before Yoinks inspects and downloads the same quality');
    fallbackCheck(file_get_contents($config->cookiePath())===$cookies,'a cookie-free Yoinks success never refreshes or rewrites the failed primary jar');
    file_put_contents($config->cookiePath(),'invalid saved jar');
    $directory=$work(); $runner->inspect($url,$directory); $seen=$calls();
    fallbackCheck(count($seen)===1&&$seen[0]['backend']==='yoinks'&&!$seen[0]['cookie'],'an unreadable-format cookie is bypassed by Yoinks without sending its values');
    Zero\saveYouTubeCookies($config,$cookies);
    $directory=$work(['cookie_error'=>'larger than max filesize']); $reason='';
    try { $runner->fetch($url,$directory,360,[['id'=>'18','audio'=>true]]); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    fallbackCheck($reason==='size'&&count($calls())===1,'a size failure does not trigger an irrelevant second-provider download');
    $directory=$work(['cookie_error'=>'cookies are no longer valid']); $reason='';
    try { $runner->verifyCookies($cookies,$directory,$url); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    fallbackCheck($reason==='cookies'&&count($calls())===1&&$calls()[0]['backend']==='yt-dlp','cookie import verification cannot accidentally succeed through the cookie-free Yoinks fallback');
    $directory=$work(['cookie_error'=>'cookies are no longer valid','anonymous_error'=>'Sign in to confirm you are not a bot']); $reason='';
    try { $runner->inspect($url,$directory); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    fallbackCheck($reason==='blocked'&&count($calls())===2,'if YouTube rejects both providers the real failure is reported with no retry loop');
    $directory=$work(['remove'=>true]); $runner->inspect($url,$directory);
    fallbackCheck(!is_file($config->cookiePath()),'a successful in-flight primary request cannot resurrect an administrator-deleted jar');
    $directory=$work(['anonymous_error'=>'Sign in to confirm you are not a bot']); $reason='';
    try { $runner->inspect($url,$directory); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    fallbackCheck($reason==='blocked'&&count($calls())===1&&$calls()[0]['backend']==='yoinks','Yoinks authentication challenges remain visible instead of claiming cookie-free success');
    $config->youtubeCookies=$temp.'/missing-configured.txt'; $directory=$work(); $runner->inspect($url,$directory);
    fallbackCheck(count($calls())===1&&$calls()[0]['backend']==='yoinks','a missing configured cookie path goes directly to Yoinks');
} finally { Zero\Download::clean($temp); }
