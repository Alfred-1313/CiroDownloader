<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';

function verify(bool $ok, string $message): void {
    if(!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}

$temp=sys_get_temp_dir().'/ciro-media-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
$server=null;

try {
    $config=new Zero\Config('123:fake-test-token',[42],$temp);
    $runner=new Zero\Download($config);
    $cookieExport="# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_TEST_VALUE\n";
    file_put_contents($config->cookiePath(),$cookieExport);
    $config->youtubeProxy='socks5://test-user:test-password@127.0.0.1:1080';
    foreach([
        ['landscape','640x360','libx264','aac','mp4'],
        ['shorts','480x854','libx264','aac','mp4'],
        ['fallback','600x1066','libvpx-vp9','libopus','webm'],
    ] as [$name,$size,$videoCodec,$audioCodec,$ext]) {
        $work=$temp.'/'.$name;
        mkdir($work,0700);
        $source=$temp.'/'.$name.'.'.$ext;
        $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y',
            '-f','lavfi','-i',"color=c=blue:s=$size:r=10:d=1",
            '-f','lavfi','-i','sine=frequency=440:sample_rate=48000',
            '-shortest','-c:v',$videoCodec,'-threads','2','-c:a',$audioCodec,$source],$work,30);

        // Replace only YouTube transport; exercise real probing, conversion and remuxing.
        $stub=$temp.'/download-'.$name;
        file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
            '$template=$argv[array_search("-o",$argv,true)+1];'."\n".
            'file_put_contents(dirname($template)."/arguments.json",json_encode($argv));'."\n".
            '$cookie=$argv[array_search("--cookies",$argv,true)+1]; file_put_contents($cookie,"# modified by downloader");'."\n".
            'copy('.var_export($source,true).',str_replace("%(ext)s",'.var_export($ext,true).',$template));'."\n".
            'echo json_encode("Test <title> & audio").PHP_EOL;');
        chmod($stub,0700);
        $config->ytDlp=$stub;
        $media=$runner->fetch('https://www.youtube.com/watch?v=ybNMMK5Q318',$work);
        $arguments=json_decode((string)file_get_contents($work.'/arguments.json'),true);
        verify($arguments[array_search('--cookies',$arguments,true)+1]===$work.'/cookies.txt',"$name uses a private cookie copy");
        verify(file_get_contents($config->cookiePath())===$cookieExport,"$name preserves shared cookies during download");
        verify($arguments[array_search('--proxy',$arguments,true)+1]===$config->youtubeProxy,"$name passes the configured YouTube proxy");
        $probe=json_decode($runner->run(['ffprobe','-v','error','-show_streams','-of','json',$media['path']],$work,10),true);
        $streams=array_column($probe['streams'],'codec_name','codec_type');
        verify(($streams['video']??'')==='h264'&&($streams['audio']??'')==='aac',"$name produces H.264 video and AAC audio");
        verify(min($media['width'],$media['height'])<=480,"$name respects 480p on the shorter edge");
        if($name==='shorts') verify($media['width']===480&&$media['height']===854,'portrait Shorts are not rejected or reduced to 240p');
        if($name==='fallback') verify($media['width']===480,'higher resolution WebM fallback is converted and scaled');
    }

    $router=$temp.'/router.php';
    file_put_contents($router,'<?php '.
        '$v=$_FILES["video"]??null;'.
        '$valid=$v&&$v["error"]===UPLOAD_ERR_OK&&($v["type"]??"")==="video/mp4";'.
        'if($valid) file_put_contents('.var_export($temp.'/upload.json',true).',json_encode(["hash"=>hash_file("sha256",$v["tmp_name"]),"chat"=>$_POST["chat_id"]??null]));'.
        'header("Content-Type: application/json"); echo json_encode(["ok"=>(bool)$valid,"result"=>["message_id"=>123],"error_code"=>400]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
    if(!$socket) throw new RuntimeException($errstr);
    $address=stream_socket_get_name($socket,false);
    fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],
        [0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    if(!is_resource($server)) throw new RuntimeException('Cannot start upload test server');
    $ready=false;
    for($attempt=0;$attempt<40;$attempt++) {
        $connection=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);
        if($connection) { fclose($connection); $ready=true; break; }
        usleep(50000);
    }
    verify($ready,'local upload endpoint ready');
    $config->api='http://'.$address;
    $result=(new Zero\Telegram($config))->call('sendVideo',[
        'chat_id'=>42,'video'=>new CURLFile($media['path'],'video/mp4','video.mp4'),
        'supports_streaming'=>true,'caption'=>'ویدیوی آزمایشی',
    ]);
    $received=json_decode((string)file_get_contents($temp.'/upload.json'),true);
    verify(($result['message_id']??0)===123&&($received['hash']??'')===hash_file('sha256',$media['path']),
        'sendVideo uploads the actual MP4 bytes as multipart video');
    verify(($received['chat']??'')==='42','video delivered to requested chat');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
