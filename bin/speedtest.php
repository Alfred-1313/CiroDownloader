<?php
declare(strict_types=1);

/**
 * Where does the time go? Measures the server, the link to Telegram and, with a link,
 * the real download-and-prepare path, and prints a short report that is safe to share:
 * it never prints the bot token, administrator IDs, cookies or full links.
 *
 *   docker compose run --rm --no-deps -T worker php bin/speedtest.php [LINK]
 *   SPEED_UPLOAD_MB=20 sets the size of the upload test (1 to 40, default 10).
 *
 * The test sends a few temporary messages to the first administrator and deletes them.
 */
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require_once dirname(__DIR__).'/src/Spotify.php';
require_once dirname(__DIR__).'/src/SoundCloud.php';
umask(0077);

function line(string $label,string $value): void { printf("%-34s %s\n",$label,$value); }
function ms(float $started): int { return (int)round((microtime(true)-$started)*1000); }
function section(string $title): void { echo "\n== $title ==\n"; }
function attempt(string $label,callable $work): void
{
    try { $work(); }
    catch(Throwable $e) { line($label,'failed ('.preg_replace('/[A-Za-z0-9_-]{25,}/','…',Zero\diagnosticMessage($e->getMessage())).')'); }
}
function mb(int $bytes): string { return number_format($bytes/1048576,1).' MB'; }
function rate(int $bytes,int $milliseconds): string { return $milliseconds>0?number_format($bytes/1048576/($milliseconds/1000),2).' MB/s':'n/a'; }

$config=Zero\Config::load(); $api=new Zero\Telegram($config); $runner=new Zero\Download($config);
$admin=$config->admins[0];
$megabytes=max(1,min(40,(int)(getenv('SPEED_UPLOAD_MB')?:10)));
$link=trim((string)(getenv('SPEED_URL')?:($argv[1]??'')));
$folder=$config->storage.'/speedtest-'.bin2hex(random_bytes(4)); mkdir($folder,0700,true);
$sent=[]; $summary=[];
try {
    echo "Ciro Downloader speed report\n";
    section('Server');
    line('Version',trim((string)@file_get_contents(dirname(__DIR__).'/VERSION')));
    $cpus=preg_match_all('/^processor\s*:/m',(string)@file_get_contents('/proc/cpuinfo'));
    $limit=trim((string)@file_get_contents('/sys/fs/cgroup/cpu.max'));
    $quota='no limit';
    if(preg_match('/^(\d+)\s+(\d+)$/',$limit,$m)&&(int)$m[2]>0) $quota=number_format((int)$m[1]/(int)$m[2],2).' CPU';
    line('CPU cores seen / allowed',$cpus.' / '.$quota);
    $memory=trim((string)@file_get_contents('/sys/fs/cgroup/memory.max'));
    line('Memory limit',ctype_digit($memory)?mb((int)$memory):'no limit');
    $load=sys_getloadavg()?:[0,0,0];
    line('Load average (1/5/15 min)',implode(' / ',array_map(fn($v)=>number_format($v,2),$load)));
    line('Free disk (storage)',mb((int)@disk_free_space($config->storage)));
    line('Parallel media jobs',(string)$config->workerConcurrency);
    line('Telegram API',$config->api==='https://api.telegram.org'?'cloud (50 MB limit)':'local server');
    line('Proxies (YouTube/social)',($config->youtubeProxy!==''?'on':'off').' / '.($config->galleryProxy!==''?'on':'off'));
    line('TikTok prefers H.264',$config->tiktokPreferH264?'yes':'no');

    section('CPU: converting a 1080x1920 clip (3 s)');
    attempt('Encode time',function() use($runner,$folder,&$summary) {
        $out=$folder.'/bench.mp4'; $started=microtime(true);
        $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','testsrc=s=1080x1920:r=30:d=3','-c:v','libx264','-preset','veryfast','-crf','23','-threads','2','-pix_fmt','yuv420p',$out],$folder,180);
        $took=ms($started); $summary['encode']=$took;
        line('Encode time',$took.' ms ('.number_format(3000/max(1,$took),2).'x real time)');
    });

    section('Telegram connection');
    attempt('Latency',function() use($api,&$summary) {
        $times=[];
        for($i=0;$i<6;$i++) { $started=microtime(true); $api->call('getMe'); $times[]=ms($started); }
        $first=array_shift($times); sort($times);
        $summary['latency']=$times[intdiv(count($times),2)];
        line('First call (new connection)',$first.' ms');
        line('Next calls, median (reused)',$summary['latency'].' ms');
    });
    attempt('Upload',function() use($api,$admin,$megabytes,$folder,&$sent,&$summary) {
        $file=$folder.'/upload.bin'; $handle=fopen($file,'wb');
        for($i=0;$i<$megabytes;$i++) fwrite($handle,random_bytes(1048576));
        fclose($handle);
        $started=microtime(true);
        $message=$api->call('sendDocument',['chat_id'=>$admin,'document'=>new CURLFile($file,'application/octet-stream','speedtest.bin'),'caption'=>'Speed test file, deleted automatically.']);
        $took=ms($started); $sent[]=(int)($message['message_id']??0);
        $summary['upload']=rate($megabytes*1048576,$took);
        line('Upload '.$megabytes.' MB to Telegram',$took.' ms ('.$summary['upload'].')');
    });

    if($link!=='') {
        section('Your link');
        $service=Zero\galleryService($link);
        line('Service',$service??(Zero\youtubeUrl($link)?'youtube':(Zero\musicService($link)??'unknown')));
        line('Host',(string)parse_url($link,PHP_URL_HOST));
        if($service!==null) {
            attempt('Download and prepare',function() use($config,$service,$link,$folder,$runner,$api,$admin,&$sent,&$summary) {
                $work=$folder.'/job'; mkdir($work,0700);
                $started=microtime(true);
                $media=Zero\GalleryMedia::forService($config,$service)->fetch($link,$work);
                $took=ms($started); $summary['prepare']=$took;
                line('Download + prepare',$took.' ms for '.count($media).' item(s)');
                $first=$media[0]; $size=(int)filesize($first['path']);
                line('First item',$first['type'].', '.mb($size).($first['type']==='video'?', '.$first['width'].'x'.$first['height'].', '.$first['duration'].' s':''));
                if($first['type']==='video') {
                    $probe=json_decode($runner->run(['ffprobe','-v','error','-select_streams','v:0','-show_entries','stream=codec_name','-of','json',$first['path']],$work,30),true);
                    line('Video codec sent',(string)($probe['streams'][0]['codec_name']??'unknown'));
                }
                $started=microtime(true);
                $params=['chat_id'=>$admin,$first['type']=>new CURLFile($first['path'],$first['type']==='photo'?'image/jpeg':'video/mp4','speedtest')];
                if($first['type']==='video') $params+=['width'=>$first['width'],'height'=>$first['height'],'duration'=>$first['duration'],'supports_streaming'=>true];
                $message=$api->call($first['type']==='photo'?'sendPhoto':'sendVideo',$params);
                $up=ms($started); $sent[]=(int)($message['message_id']??0);
                line('Upload of this item',$up.' ms ('.rate($size,$up).')');
                $summary['total']=$took+$up;
                line('Total for the first item',$summary['total'].' ms');
            });
        } elseif(Zero\youtubeUrl($link)) {
            attempt('Inspect',function() use($runner,$config,$link,$folder,&$summary) {
                $started=microtime(true); $info=(new Zero\Download($config))->inspect($link,$folder);
                $summary['inspect']=ms($started); line('Reading the video details',$summary['inspect'].' ms ('.count($info['options']).' choices)');
                line('Note','Downloading is not timed here; send the link to the bot and read the worker log.');
            });
        } elseif($track=Zero\spotifyUrl($link)) {
            attempt('Spotify',function() use($config,$track,$folder) {
                $spotify=new Zero\Spotify($config); $started=microtime(true);
                $meta=$spotify->metadata((string)Zero\Spotify::id($track));
                line('Reading the Spotify page',ms($started).' ms');
                $started=microtime(true);
                $found=Zero\Spotify::best($meta,(new Zero\Download($config))->search($meta['artists'][0].' '.$meta['title'].' audio',$folder));
                line('Finding the YouTube match',ms($started).' ms ('.($found?'found':'none').')');
                line('Note','Downloading is not timed here; send the link to the bot and read the worker log.');
            });
        } elseif(Zero\soundcloudUrl($link)) {
            attempt('SoundCloud',function() use($config,$link,$folder) {
                $work=$folder.'/job'; mkdir($work,0700); $started=microtime(true);
                $media=(new Zero\SoundCloud($config))->fetch($link,$work);
                line('Download + prepare',ms($started).' ms, '.mb((int)filesize($media['path'])));
            });
        } else line('Result','This link is not supported.');
    }

    section('How to read this');
    echo "- Encode time above 1.5x real time suggests the CPU limit, not the network, slows conversions.\n";
    echo "- Median latency above ~400 ms or an upload below ~2 MB/s points to the server's link to Telegram.\n";
    echo "- A TikTok video sent as 'hevc' means conversion work; 'h264' is the fast path.\n";
} catch(Throwable $e) {
    fwrite(STDERR,'Speed test failed: '.preg_replace('/[A-Za-z0-9_-]{25,}/','…',Zero\diagnosticMessage($e->getMessage())).PHP_EOL);
    exit(1);
} finally {
    foreach($sent as $id) if($id>0) { try { $api->call('deleteMessage',['chat_id'=>$admin,'message_id'=>$id]); } catch(Throwable) {} }
    Zero\Download::clean($folder);
}
