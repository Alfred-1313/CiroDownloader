<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

umask(0077);

$config=Zero\Config::load();
$store=new Zero\Store($config->storage);
$api=new Zero\Telegram($config);
$downloader=new Zero\Download($config);
$folder=$config->storage.'/diagnostic-'.bin2hex(random_bytes(4));
$url=trim((string)(getenv('TEST_VIDEO_URL')?:''));

mkdir($folder,0700,true);
$exitCode=0;

try {
    $me=$api->call('getMe');
    $webhook=$api->call('getWebhookInfo');
    if(!empty($webhook['url'])) throw new RuntimeException('A webhook is configured; polling cannot receive updates.');
    if(!is_executable($config->ytDlp)) throw new RuntimeException('yt-dlp is not executable.');
    $store->set('diagnostic_at',(string)time());

    if($url!=='') {
        echo "Testing the real YouTube download pipeline…\n";
        $media=$downloader->fetch($url,$folder);
        $caption='Ciro Downloader diagnostic: YouTube download and Telegram upload succeeded.';
    } else {
        echo "Generating a local diagnostic video…\n";
        $path=$folder.'/diagnostic.mp4';
        $downloader->run([
            'ffmpeg','-hide_banner','-loglevel','error',
            '-f','lavfi','-i','color=c=0x020617:s=640x360:r=25:d=2',
            '-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=44100',
            '-shortest','-c:v','libx264','-preset','veryfast','-pix_fmt','yuv420p',
            '-c:a','aac','-movflags','+faststart','-y',$path,
        ],$folder,60);
        if(!is_file($path)||filesize($path)<1) throw new RuntimeException('FFmpeg did not create the diagnostic video.');
        $media=['path'=>$path,'width'=>640,'height'=>360,'duration'=>2];
        $caption='Ciro Downloader diagnostic: Telegram video upload succeeded. Add a YouTube URL in the manager to test the full download pipeline.';
    }

    $api->call('sendVideo',[
        'chat_id'=>$config->admins[0],
        'video'=>new CURLFile($media['path'],'video/mp4','ciro-diagnostic.mp4'),
        'caption'=>$caption,
        'supports_streaming'=>true,
        'width'=>$media['width'],
        'height'=>$media['height'],
        'duration'=>$media['duration'],
    ]);

    echo 'Connected bot: @'.($me['username']??'unknown').PHP_EOL;
    echo 'Diagnostic video sent to admin ID '.$config->admins[0].PHP_EOL;
    echo "All diagnostic checks passed.\n";
} catch(Throwable $e) {
    fwrite(STDERR,'Diagnostic failed: '.$e->getMessage().PHP_EOL);
    $exitCode=1;
} finally {
    Zero\Download::clean($folder);
}

exit($exitCode);
