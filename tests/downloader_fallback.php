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
    $metadata=['title'=>'Fixture','formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac']]];
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        '$mode=json_decode(file_get_contents('.var_export($mode,true).'),true); $cookie=in_array("--cookies",$argv,true);'.
        '$path=$cookie?$argv[array_search("--cookies",$argv,true)+1]:null; $out=in_array("-o",$argv,true)?$argv[array_search("-o",$argv,true)+1]:null;'.
        '$partials=$out?glob(dirname($out)."/".strtok(basename($out),".").".*"):[];'.
        'file_put_contents('.var_export($trace,true).',json_encode(["args"=>$argv,"cookie"=>$cookie,"mode"=>$path?(fileperms($path)&0777):null,"partials"=>count($partials)]).PHP_EOL,FILE_APPEND);'.
        '$error=$cookie?($mode["cookie_error"]??""):($mode["anonymous_error"]??"");'.
        'if($error!=="") { if($out&&!$cookie) file_put_contents(str_replace("%(ext)s","mp4.part",$out),"partial"); fwrite(STDERR,$error); exit(1); }'.
        'if($cookie&&($mode["rotate"]??false)) file_put_contents($path,str_replace("FAKE_COOKIE","FAKE_REFRESHED",file_get_contents($path)));'.
        'if($cookie&&($mode["remove"]??false)) unlink('.var_export($config->cookiePath(),true).');'.
        'if(in_array("--dump-single-json",$argv,true)) { echo '.var_export(json_encode($metadata),true).'; exit; }'.
        'if($cookie&&count($partials)>0) { fwrite(STDERR,"anonymous partial was not cleared"); exit(1); }'.
        'copy('.var_export($source,true).',str_replace("%(ext)s","mp4",$out)); echo json_encode("Fixture");');
    chmod($stub,0700); $config->ytDlp=$stub;
    $cookies="# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_COOKIE\n";
    $url='https://youtu.be/ybNMMK5Q318'; $counter=0;
    $work=function(array $settings=[]) use($mode,$trace,$temp,&$counter): string {
        file_put_contents($mode,json_encode($settings)); file_put_contents($trace,'');
        $directory=$temp.'/run-'.++$counter; mkdir($directory,0700); return $directory;
    };
    $calls=fn()=>array_map(fn($line)=>json_decode($line,true),file($trace,FILE_IGNORE_NEW_LINES));
    file_put_contents($config->cookiePath(),'broken saved jar');
    $directory=$work(); $info=$runner->inspect($url,$directory); $seen=$calls();
    fallbackCheck($info['title']==='Fixture'&&count($seen)===1&&!$seen[0]['cookie']&&!is_file($directory.'/cookies.txt'),'anonymous success does not read or copy an invalid saved cookie jar');
    Zero\saveYouTubeCookies($config,$cookies);
    $directory=$work(); $runner->fetch($url,$directory,360,[['id'=>'18','audio'=>true]]); $seen=$calls();
    fallbackCheck(count($seen)===1&&!$seen[0]['cookie']&&file_get_contents($config->cookiePath())===$cookies,'video first succeeds without sending or refreshing the saved login session');
    $directory=$work(['anonymous_error'=>'Sign in to confirm you are not a bot']); $runner->inspect($url,$directory); $seen=$calls();
    fallbackCheck(count($seen)===2&&!$seen[0]['cookie']&&$seen[1]['cookie']&&$seen[1]['mode']===0600,'inspection retries exactly once using a private cookie copy after anonymous rejection');
    foreach($seen as $call) {
        $args=$call['args'];
        fallbackCheck(in_array('--ignore-config',$args,true)&&$args[array_search('--proxy',$args,true)+1]===$config->youtubeProxy,'both attempts ignore inherited downloader configuration and preserve the configured proxy');
    }
    $directory=$work(['anonymous_error'=>'HTTP Error 403','rotate'=>true]); $media=$runner->fetch($url,$directory,360,[['id'=>'18','audio'=>true]]); $seen=$calls();
    fallbackCheck(count($seen)===2&&$seen[1]['cookie']&&$seen[1]['partials']===0&&$media['quality']==='360p','video retry discards anonymous partials and delivers the selected quality');
    foreach($seen as $call) fallbackCheck($call['args'][array_search('-f',$call['args'],true)+1]==='18','fallback cannot substitute a different selected format');
    fallbackCheck(file_get_contents($config->cookiePath())===str_replace('FAKE_COOKIE','FAKE_REFRESHED',$cookies),'only a successful authenticated attempt refreshes the active jar');
    Zero\saveYouTubeCookies($config,$cookies);
    $directory=$work(['anonymous_error'=>'HTTP Error 429']); $media=$runner->fetchAudio($url,$directory,[['id'=>'18']]); $seen=$calls();
    fallbackCheck(count($seen)===2&&!$seen[0]['cookie']&&$seen[1]['cookie']&&$seen[1]['partials']===0&&$media['format']==='MP3','audio also retries with cookies after clearing anonymous partials and prepares a real MP3');
    $directory=$work(['anonymous_error'=>'larger than max filesize']); $reason='';
    try { $runner->fetch($url,$directory,360,[['id'=>'18','audio'=>true]]); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    fallbackCheck($reason==='size'&&count($calls())===1,'a file-size failure does not waste another download with cookies');
    $directory=$work(['anonymous_error'=>'Network is unreachable','cookie_error'=>'cookies are no longer valid']); $reason='';
    try { $runner->inspect($url,$directory); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    fallbackCheck($reason==='cookies'&&count($calls())===2,'a failed authenticated attempt is reported without an infinite retry loop');
    $directory=$work(['cookie_error'=>'cookies are no longer valid']); $reason='';
    try { $runner->verifyCookies($cookies,$directory,$url); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    $seen=$calls();
    fallbackCheck($reason==='cookies'&&count($seen)===1&&$seen[0]['cookie'],'import verification actually tests the uploaded cookies even when anonymous access would work');
    $directory=$work(['anonymous_error'=>'Sign in to confirm you are not a bot','remove'=>true]); $runner->inspect($url,$directory);
    fallbackCheck(!is_file($config->cookiePath()),'a successful in-flight authenticated request cannot resurrect an administrator-deleted jar');
    $directory=$work(['anonymous_error'=>'Sign in to confirm you are not a bot']); $reason='';
    try { $runner->inspect($url,$directory); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    fallbackCheck($reason==='blocked'&&count($calls())===1,'a missing jar leaves the original failure intact without a duplicate anonymous attempt');
    $config->youtubeCookies=$temp.'/missing-configured.txt'; $directory=$work(); $runner->inspect($url,$directory);
    fallbackCheck(count($calls())===1&&!$calls()[0]['cookie'],'a missing configured cookie path does not prevent anonymous access');
} finally { Zero\Download::clean($temp); }
