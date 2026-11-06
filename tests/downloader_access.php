<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';
function accessCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$temp=sys_get_temp_dir().'/ciro-access-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp,youtubeProxy:'http://127.0.0.1:9999');
    $runner=new Zero\Download($config); $source=$temp.'/fixture.mp4';
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=640x360:r=1:d=1','-f','lavfi','-i','sine=frequency=440:duration=1','-shortest','-c:v','libx264','-threads','2','-pix_fmt','yuv420p','-c:a','aac',$source],$temp,30);
    $trace=$temp.'/trace.jsonl'; $mode=$temp.'/mode.json'; $stub=$temp.'/downloader';
    $metadata=['id'=>'ybNMMK5Q318','title'=>'Fixture','duration'=>1,'formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac']]];
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        '$mode=json_decode(file_get_contents('.var_export($mode,true).'),true); $cookie=in_array("--cookies",$argv,true);'.
        '$path=$cookie?$argv[array_search("--cookies",$argv,true)+1]:null;'.
        'file_put_contents('.var_export($trace,true).',json_encode(["args"=>$argv,"cookie"=>$cookie,"mode"=>$path?(fileperms($path)&0777):null]).PHP_EOL,FILE_APPEND);'.
        'if(($mode["error"]??"")!=="") { fwrite(STDERR,"ERROR: ".$mode["error"]); exit(1); }'.
        'if($cookie&&($mode["rotate"]??false)) file_put_contents($path,str_replace("FAKE_COOKIE","FAKE_REFRESHED",file_get_contents($path)));'.
        'if($cookie&&($mode["remove"]??false)) unlink('.var_export($config->cookiePath(),true).');'.
        'if(in_array("--dump-single-json",$argv,true)) { echo '.var_export(json_encode($metadata),true).'; exit; }'.
        '$output=str_replace("%(ext)s","mp4",$argv[array_search("-o",$argv,true)+1]); copy('.var_export($source,true).',$output); echo json_encode("Fixture").PHP_EOL;');
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
    accessCheck($info['title']==='Fixture'&&count($seen)===1&&$seen[0]['cookie']&&$seen[0]['mode']===0600,'inspection uses the saved cookie once through a private jar');
    $directory=$work(['rotate'=>true]); $media=$runner->fetch($url,$directory,360,[['id'=>'18','audio'=>true]]); $seen=$calls();
    accessCheck(count($seen)===1&&$seen[0]['cookie']&&$media['quality']==='360p'&&file_get_contents($config->cookiePath())===str_replace('FAKE_COOKIE','FAKE_REFRESHED',$cookies),'successful video delivery preserves quality and refreshes cookies');
    unlink($config->cookiePath());
    $directory=$work(); $info=$runner->inspect($url,$directory); $seen=$calls();
    accessCheck($info['title']==='Fixture'&&count($seen)===1&&!$seen[0]['cookie']&&in_array('--dump-single-json',$seen[0]['args'],true),'a missing cookie directly inspects with yt-dlp without a second provider');
    $directory=$work(); $media=$runner->fetch($url,$directory,360,[['id'=>'18','audio'=>true]]); $seen=$calls(); $args=$seen[0]['args'];
    accessCheck(count($seen)===1&&!$seen[0]['cookie']&&$media['quality']==='360p'&&$args[array_search('-f',$args,true)+1]==='18','cookie-free video delivery uses only the selected format in one download');
    accessCheck(in_array('--ignore-config',$args,true)&&$args[array_search('--proxy',$args,true)+1]===$config->youtubeProxy,'direct downloads preserve the proxy and ignore external configuration');
    $directory=$work(); $media=$runner->fetchAudio($url,$directory,[['id'=>'18']]); $seen=$calls();
    accessCheck(count($seen)===1&&!$seen[0]['cookie']&&$media['format']==='MP3','cookie-free audio uses a single direct download and prepares a complete MP3');
    Zero\saveYouTubeCookies($config,$cookies);
    foreach(['cookies are no longer valid'=>'cookies','Sign in to confirm you are not a bot'=>'blocked','larger than max filesize'=>'size','connection refused'=>'network'] as $detail=>$expected) {
        $directory=$work(['error'=>$detail]); $reason='';
        try { $runner->fetch($url,$directory,360,[['id'=>'18','audio'=>true]]); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
        accessCheck($reason===$expected&&count($calls())===1&&$calls()[0]['cookie'],'a '.$expected.' failure keeps its diagnosis without a second-provider retry');
        accessCheck(file_get_contents($config->cookiePath())===$cookies,'failed downloads preserve the saved jar');
    }
    file_put_contents($config->cookiePath(),'invalid saved jar');
    $directory=$work(); $reason='';
    try { $runner->inspect($url,$directory); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    accessCheck($reason==='cookies'&&$calls()===[],'a malformed saved jar is reported before launching the downloader');
    Zero\saveYouTubeCookies($config,$cookies);
    $directory=$work(['error'=>'cookies are no longer valid']); $reason='';
    try { $runner->verifyCookies($cookies,$directory,$url); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    accessCheck($reason==='cookies'&&count($calls())===1&&$calls()[0]['cookie'],'cookie import verification requires the uploaded session');
    $directory=$work(['remove'=>true]); $runner->inspect($url,$directory);
    accessCheck(!is_file($config->cookiePath()),'an in-flight request cannot resurrect an administrator-deleted jar');
    $directory=$work(['error'=>'Sign in to confirm you are not a bot']); $reason='';
    try { $runner->inspect($url,$directory); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    accessCheck($reason==='blocked'&&count($calls())===1&&!$calls()[0]['cookie'],'cookie-free authentication challenges remain visible without retries');
    $config->youtubeCookies=$temp.'/missing-configured.txt'; $directory=$work(); $runner->inspect($url,$directory);
    accessCheck(count($calls())===1&&!$calls()[0]['cookie'],'a missing configured cookie path uses direct cookie-free yt-dlp');
} finally { Zero\Download::clean($temp); }
