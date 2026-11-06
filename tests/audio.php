<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';
function audioCheck(bool $ok,string $text): void { if(!$ok) throw new RuntimeException($text); echo "PASS $text\n"; }
$temp=sys_get_temp_dir().'/ciro-audio-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fake-token',[42],$temp);
    $runner=new Zero\Download($config);
    $cookies="# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_VALUE\n";
    file_put_contents($config->cookiePath(),$cookies);
    $config->youtubeProxy='socks5://test-user:test-password@127.0.0.1:1080';
    foreach([['aac','m4a',false],['libopus','webm',false],['aac','mp4',true]] as [$codec,$extension,$video]) {
        $work=$temp.'/'.$extension; mkdir($work,0700);
        $source=$temp.'/fixture.'.$extension;
        $args=['ffmpeg','-hide_banner','-loglevel','error','-y'];
        if($video) $args=array_merge($args,['-f','lavfi','-i','color=c=blue:s=64x64:r=1:d=3']);
        $args=array_merge($args,['-f','lavfi','-i','sine=frequency=440:sample_rate=44100:duration=3','-c:a',$codec]);
        if($video) $args=array_merge($args,['-c:v','libx264','-threads','2','-shortest']);
        $runner->run(array_merge($args,[$source]),$work,30);
        $stub=$temp.'/download-'.$extension;
        file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
            '$template=$argv[array_search("-o",$argv,true)+1];'.
            'file_put_contents(dirname($template)."/arguments.json",json_encode($argv));'.
            'copy('.var_export($source,true).',str_replace("%(ext)s",'.var_export($extension,true).',$template));'.
            'echo json_encode("Audio <title>").PHP_EOL;');
        chmod($stub,0700); $config->ytDlp=$stub;
        $media=$runner->fetchAudio('https://youtu.be/ybNMMK5Q318',$work,[['id'=>'140'],['id'=>'251']]);
        $arguments=json_decode((string)file_get_contents($work.'/arguments.json'),true);
        audioCheck($arguments[array_search('--concurrent-fragments',$arguments,true)+1]==='4',"$extension uses bounded parallel fragment downloads");
        audioCheck($arguments[array_search('-f',$arguments,true)+1]==='140/251',"$extension uses only offered audio formats");
        audioCheck($arguments[array_search('--cookies',$arguments,true)+1]===$work.'/cookies.txt'&&file_get_contents($config->cookiePath())===$cookies,"$extension uses an isolated cookie copy");
        audioCheck($arguments[array_search('--proxy',$arguments,true)+1]===$config->youtubeProxy,"$extension uses the configured YouTube proxy");
        audioCheck($arguments[array_search('--match-filter',$arguments,true)+1]==='!is_live',"$extension does not impose a 20-minute filter");
        $probe=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$media['path']],$work,10),true);
        $streams=array_column($probe['streams'],'codec_name','codec_type');
        audioCheck(($streams['audio']??'')==='mp3'&&!isset($streams['video']),"$extension becomes a genuine MP3 without video");
        audioCheck(abs((float)$probe['format']['duration']-3)<0.2,"$extension preserves the complete audio duration");
        audioCheck($media['title']==='Audio <title>'&&$media['format']==='MP3',"$extension preserves the title and output format");
    }
    $bad=false;
    try { $runner->fetchAudio('https://youtu.be/ybNMMK5Q318',$work,[['id'=>'140/../../bad']]); }
    catch(Zero\MediaFailure $e) { $bad=$e->reason==='format'; }
    audioCheck($bad,'audio format selectors cannot inject options or arbitrary paths');
    $empty=false;
    try { $runner->fetchAudio('https://youtu.be/ybNMMK5Q318',$work,[]); }
    catch(Zero\MediaFailure $e) { $empty=$e->reason==='format'; }
    audioCheck($empty,'missing saved formats cannot trigger a fallback video download');
    $limited=$temp.'/limited'; mkdir($limited,0700); $config->maxBytes=10000;
    $tooLarge=false;
    try { $runner->fetchAudio('https://youtu.be/ybNMMK5Q318',$limited,[['id'=>'140']]); }
    catch(Zero\MediaFailure $e) { $tooLarge=$e->reason==='size'; }
    audioCheck($tooLarge,'audio preparation respects the configured byte limit');
} finally { Zero\Download::clean($temp); }
