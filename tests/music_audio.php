<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';
function musicAudioCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-music-audio-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp); $runner=new Zero\Download($config);
    // Sources as YouTube and SoundCloud serve them: AAC in M4A, MP3, and Opus in WebM.
    $sources=['m4a'=>['aac',['-c:a','aac','-b:a','128k']],'mp3'=>['mp3',['-c:a','libmp3lame','-b:a','128k']],'webm'=>['opus',['-c:a','libopus','-b:a','160k']]];
    foreach($sources as $extension=>[$codec,$arguments]) {
        $work=$temp.'/make-'.$extension; mkdir($work,0700);
        $runner->run(array_merge(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','sine=frequency=440:sample_rate=44100:duration=20'],$arguments,[$temp.'/source.'.$extension]),$work,60);
    }
    $stub=$temp.'/yt-dlp-fixture';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n\$args=array_slice(\$argv,1);file_put_contents(".var_export($temp.'/args.json',true).",json_encode(\$args));".
        '$extension=trim((string)file_get_contents('.var_export($temp.'/which',true).'));$template=$args[array_search("-o",$args,true)+1];'.
        'copy('.var_export($temp.'/source.',true).'.$extension,str_replace("%(ext)s",$extension,$template));echo json_encode("T").PHP_EOL;');
    chmod($stub,0700); $config->ytDlp=$stub;
    $take=function(string $extension,bool $copy) use($config,$temp): array {
        static $n=0; $work=$temp.'/job-'.++$n; mkdir($work,0700); file_put_contents($temp.'/which',$extension); $config->musicCopy=$copy;
        $audio=(new Zero\Download($config))->fetchBestAudio('https://youtu.be/ybNMMK5Q318',$work);
        $probe=json_decode((new Zero\Download($config))->run(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$audio['path']],$work,30),true);
        return [$audio,$probe,$work];
    };
    $started=microtime(true);
    [$audio,$probe]=$take('m4a',true);
    musicAudioCheck(str_ends_with($audio['path'],'.m4a')&&$audio['format']==='M4A'&&$probe['streams'][0]['codec_name']==='aac'&&$audio['duration']>=20&&$audio['duration']<=21,'an AAC source is only copied into an M4A file: no re-encoding');
    [$audio,$probe]=$take('mp3',true);
    musicAudioCheck(str_ends_with($audio['path'],'.mp3')&&$probe['streams'][0]['codec_name']==='mp3','an MP3 source is only copied');
    [$audio,$probe]=$take('webm',true);
    musicAudioCheck(str_ends_with($audio['path'],'.mp3')&&$probe['streams'][0]['codec_name']==='mp3'&&(int)$probe['format']['bit_rate']<175000,'an Opus source (which Telegram cannot play as music) becomes MP3 at 160 kbps');
    [$audio,$probe]=$take('m4a',false);
    musicAudioCheck(str_ends_with($audio['path'],'.mp3')&&$probe['streams'][0]['codec_name']==='mp3','with "always MP3" every source is converted');
    $args=json_decode((string)file_get_contents($temp.'/args.json'),true);
    musicAudioCheck($args[array_search('-f',$args,true)+1]==='ba/b','and the format choice is the old one then');
    $config->musicCopy=true; file_put_contents($temp.'/which','m4a'); $work=$temp.'/job-x'; mkdir($work,0700);
    (new Zero\Download($config))->fetchBestAudio('https://youtu.be/ybNMMK5Q318',$work);
    $args=json_decode((string)file_get_contents($temp.'/args.json'),true);
    musicAudioCheck($args[array_search('-f',$args,true)+1]==='ba[ext=m4a]/ba/b','the fast mode asks YouTube for M4A first');
    // The YouTube quality menu is untouched: its audio is always an MP3 at 192 kbps.
    $work=$temp.'/job-menu'; mkdir($work,0700); file_put_contents($temp.'/which','m4a');
    $menu=(new Zero\Download($config))->fetchAudio('https://youtu.be/ybNMMK5Q318',$work,[['id'=>'140']]);
    musicAudioCheck(str_ends_with($menu['path'],'.mp3')&&$menu['format']==='MP3','the "Audio" button of a YouTube link still gives an MP3');
} finally { Zero\Download::clean($temp); }
echo "All tests passed.\n";
