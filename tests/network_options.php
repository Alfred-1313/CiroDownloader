<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function netCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function loadConfig(array $env,string $storage): Zero\Config {
    foreach(['GALLERY_PROXY','YOUTUBE_POT_URL','YOUTUBE_PROXY'] as $name) putenv($name);
    foreach($env+['BOT_TOKEN'=>'123:fixture','ADMIN_IDS'=>'42','STORAGE_DIR'=>$storage] as $name=>$value) putenv($name.'='.$value);
    return Zero\Config::load();
}
$temp=sys_get_temp_dir().'/ciro-net-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $plain=loadConfig([],$temp);
    netCheck($plain->galleryProxy===''&&$plain->youtubePotUrl==='','both options are off by default');
    $set=loadConfig(['GALLERY_PROXY'=>'socks5h://user:pw@127.0.0.1:1080','YOUTUBE_POT_URL'=>'http://pot:4416/'],$temp);
    netCheck($set->galleryProxy==='socks5h://user:pw@127.0.0.1:1080'&&$set->youtubePotUrl==='http://pot:4416','the proxy and provider addresses are read, and the provider address loses a trailing slash');
    $bad=0;
    foreach([['GALLERY_PROXY'=>'ftp://host'],['GALLERY_PROXY'=>'not a url'],['YOUTUBE_POT_URL'=>'ftp://pot:4416'],['YOUTUBE_POT_URL'=>'http://user:pw@pot:4416'],['YOUTUBE_POT_URL'=>'http://pot:4416/?token=x']] as $env) {
        try { loadConfig($env,$temp); } catch(RuntimeException) { $bad++; }
    }
    netCheck($bad===5,'unsupported schemes, credentials and queries are refused');
    loadConfig([],$temp);

    // YouTube: the provider is only passed to yt-dlp when configured.
    $trace=$temp.'/yt-args'; $stub=$temp.'/yt';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\nfile_put_contents(".var_export($trace,true).",json_encode(\$argv));echo ".var_export(json_encode(['title'=>'T','formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac']]]),true).";");
    chmod($stub,0700); $config=new Zero\Config('123:fixture',[42],$temp.'/s'); mkdir($temp.'/s',0700); $config->ytDlp=$stub;
    $run=function() use($config,$trace,$temp): array { @unlink($trace); $folder=$temp.'/yt-'.bin2hex(random_bytes(3)); mkdir($folder,0700); (new Zero\Download($config))->inspect('https://youtu.be/ybNMMK5Q318',$folder); return json_decode((string)file_get_contents($trace),true); };
    $args=$run();
    netCheck(!in_array('--extractor-args',$args,true),'yt-dlp is not given a provider address by default');
    $config->youtubePotUrl='http://pot:4416'; $args=$run();
    netCheck($args[array_search('--extractor-args',$args,true)+1]==='youtubepot-bgutilhttp:base_url=http://pot:4416','yt-dlp is pointed at the configured provider');
    $config->youtubeProxy='socks5://127.0.0.1:1080'; $args=$run();
    netCheck($args[array_search('--proxy',$args,true)+1]==='socks5://127.0.0.1:1080'&&in_array('--extractor-args',$args,true),'the provider and the YouTube proxy work together');

    // Gallery services: the proxy reaches gallery-dl only when configured.
    $image=$temp.'/fixture.jpg';
    (new Zero\Download($config))->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=red:s=64x48:d=1','-frames:v','1',$image],$temp,60);
    $galleryTrace=$temp.'/gallery-args'; $gallery=$temp.'/stub-gallery';
    file_put_contents($gallery,"#!/usr/bin/env php\n<?php\n\$directory=\$argv[array_search('--directory',\$argv,true)+1];file_put_contents(".var_export($galleryTrace,true).",json_encode(\$argv));copy(".var_export($image,true).",\$directory.'/ig-01.jpg');echo \"CIRO|1|1|\".\$directory.\"/ig-01.jpg\\nCIRO_META|\\\"c\\\"\\n\";");
    chmod($gallery,0700); $gc=new Zero\Config('123:fixture',[42],$temp.'/g'); mkdir($temp.'/g',0700); $gc->galleryDl=$gallery;
    $fetch=function() use($gc,$galleryTrace,$temp): array { @unlink($galleryTrace); $folder=$temp.'/g-'.bin2hex(random_bytes(3)); mkdir($folder,0700); Zero\GalleryMedia::forService($gc,'instagram')->fetch('https://www.instagram.com/p/ABC_def12/',$folder); return json_decode((string)file_get_contents($galleryTrace),true); };
    $args=$fetch();
    netCheck(!in_array('--proxy',$args,true),'gallery-dl gets no proxy by default');
    $gc->galleryProxy='socks5h://127.0.0.1:1080'; $args=$fetch();
    netCheck($args[array_search('--proxy',$args,true)+1]==='socks5h://127.0.0.1:1080','gallery-dl uses the social media proxy when configured');
    netCheck(!in_array('--proxy',(function() use($gc,$fetch): array { $gc->galleryProxy=''; return $fetch(); })(),true),'clearing the proxy removes it again');

    // The health message shows both options.
    final class NetApi implements Zero\Api { public array $calls=[]; public function call(string $m,array $p=[]): mixed { $this->calls[]=[$m,$p]; return ['message_id'=>1]; } }
    $api=new NetApi(); $store=new Zero\Store($temp.'/s'); $bot=new Zero\Bot($config,$store,$api); $config->galleryProxy='socks5h://127.0.0.1:1';
    $bot->health(42); $text=strip_tags(end($api->calls)[1]['text']);
    netCheck(str_contains($text,'پراکسی شبکه‌های اجتماعی')&&str_contains($text,'PO Token یوتیوب')&&str_contains($text,'کوکی‌ها:'),'the health message shows the social proxy, PO Token and cookie summary');
    netCheck(!str_contains($text,'127.0.0.1')&&!str_contains($text,'pot:4416'),'the health message never prints the addresses');
} finally { Zero\Download::clean($temp); }
