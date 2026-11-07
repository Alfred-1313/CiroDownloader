<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function speedCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$temp=sys_get_temp_dir().'/ciro-speed-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
$server=null;
try {
    // Telegram client: one connection for many calls and no "Expect: 100-continue" wait on uploads.
    // PHP's built-in server never keeps a connection open, so use a small HTTP/1.1 keep-alive server.
    $log=$temp.'/requests.jsonl'; $script=$temp.'/server.py';
    file_put_contents($script,<<<'PY'
import http.server, json, sys

class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length)
        with open(sys.argv[2], "a") as log:
            log.write(json.dumps({"port": self.client_address[1], "expect": self.headers.get("Expect"), "method": self.path.rsplit("/", 1)[-1], "bytes": length}) + "\n")
        payload = json.dumps({"ok": True, "result": {"message_id": 7}}).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(payload))); self.end_headers(); self.wfile.write(payload)
    def log_message(self, *args): pass

http.server.ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1])), Handler).serve_forever()
PY);
    $port=random_int(20000,40000); $address='127.0.0.1:'.$port;
    $server=proc_open(['python3','-B',$script,(string)$port,$log],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,getenv());
    $up=false; for($i=0;$i<50&&!$up;$i++) { usleep(100000); $up=@fsockopen('127.0.0.1',$port); }
    speedCheck((bool)$up,'a local Telegram stand-in is listening');
    $config=new Zero\Config('123:fixture',[42],$temp,'http://'.$address); $api=new Zero\Telegram($config);
    $big=$temp.'/big.mp4'; file_put_contents($big,random_bytes(3*1024*1024));
    $started=microtime(true);
    $api->call('sendMessage',['chat_id'=>1,'text'=>'a']);
    $api->call('editMessageText',['chat_id'=>1,'message_id'=>7,'text'=>'b']);
    $api->call('sendVideo',['chat_id'=>1,'video'=>new CURLFile($big,'video/mp4','video.mp4')]);
    $api->call('deleteMessage',['chat_id'=>1,'message_id'=>7]);
    $records=array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    speedCheck(count($records)===4&&count(array_unique(array_column($records,'port')))===1,'four calls, including an upload, share one connection');
    speedCheck($records[2]['bytes']>3*1024*1024&&$records[2]['expect']===null,'the upload arrives complete and without an Expect: 100-continue handshake');
    speedCheck(microtime(true)-$started<1.5,'the calls finish without any 100-continue pause');

    // A forked process opens its own connection instead of sharing its parent's socket.
    $pid=pcntl_fork();
    if($pid===0) { (new Zero\Telegram($config))->call('sendMessage',['chat_id'=>1,'text'=>'child']); exit(0); }
    pcntl_waitpid($pid,$status);
    $records=array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    speedCheck(count($records)===5&&$records[4]['port']!==$records[0]['port'],'a forked job uses its own connection');
    $api->call('sendMessage',['chat_id'=>1,'text'=>'parent again']);
    $records=array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    speedCheck($records[5]['port']===$records[0]['port'],'the parent keeps reusing its connection after a child ran');

    // Videos that must be converted are capped so the work stays short; H.264 is only remuxed.
    $storage=$temp.'/s'; mkdir($storage,0700); $runner=new Zero\Download(new Zero\Config('123:fixture',[42],$storage));
    $make=function(string $name,string $pixFmt,string $size) use($runner,$temp): string {
        $file=$temp.'/'.$name; $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','testsrc=s='.$size.':r=5:d=1','-f','lavfi','-i','anullsrc=r=8000:cl=mono','-shortest','-c:v','libx264','-pix_fmt',$pixFmt,'-c:a','aac',$file],$temp,120); return $file;
    };
    $gallery=$temp.'/stub-gallery'; $mode=$temp.'/mode';
    file_put_contents($gallery,"#!/usr/bin/env php\n<?php\n\$d=\$argv[array_search('--directory',\$argv,true)+1];copy(file_get_contents(".var_export($mode,true)."),\$d.'/ig-01.mp4');echo \"CIRO|1|1|\".\$d.\"/ig-01.mp4\\nCIRO_META|\\\"c\\\"\\n\";");
    chmod($gallery,0700); $gc=new Zero\Config('123:fixture',[42],$storage); $gc->galleryDl=$gallery;
    $prepare=function(string $source) use($mode,$gc,$temp): array {
        file_put_contents($mode,$source); $folder=$temp.'/p-'.bin2hex(random_bytes(3)); mkdir($folder,0700);
        $item=Zero\GalleryMedia::forService($gc,'instagram')->fetch('https://www.instagram.com/p/ABC_def12/',$folder)[0];
        return [$item,json_decode($runner_probe=(new Zero\Download($gc))->run(['ffprobe','-v','error','-show_streams','-of','json',$item['path']],$folder,30),true)['streams'][0]];
    };
    [$item,$stream]=$prepare($make('copy.mp4','yuv420p','320x240'));
    speedCheck($item['width']===320&&$stream['codec_name']==='h264','a plain H.264 video is only remuxed and keeps its size');
    [$item,$stream]=$prepare($make('wide.mp4','yuv444p','1920x1080'));
    speedCheck($item['width']===1280&&$item['height']===720&&$stream['pix_fmt']==='yuv420p','a source that needs converting is also scaled down to 1280 on its long side');
    [$item]=$prepare($make('tall.mp4','yuv444p','1080x1920'));
    speedCheck($item['height']===1280&&$item['width']===720,'portrait videos are capped the same way');

    // TikTok option: on by default, switchable.
    $trace=$temp.'/args'; file_put_contents($gallery,"#!/usr/bin/env php\n<?php\nfile_put_contents(".var_export($trace,true).",json_encode(\$argv));\$d=\$argv[array_search('--directory',\$argv,true)+1];copy(file_get_contents(".var_export($mode,true)."),\$d.'/tt-01.mp4');echo \"CIRO|1|1|\".\$d.\"/tt-01.mp4\\nCIRO_META|\\\"c\\\"\\n\";");
    file_put_contents($mode,$make('tt.mp4','yuv420p','320x240'));
    $tiktok=function(bool $prefer) use($gc,$trace,$temp): array { $gc->tiktokPreferH264=$prefer; $folder=$temp.'/t-'.bin2hex(random_bytes(3)); mkdir($folder,0700); Zero\GalleryMedia::forService($gc,'tiktok')->fetch('https://www.tiktok.com/@u/video/1234567890123456789',$folder); return json_decode((string)file_get_contents($trace),true); };
    speedCheck(!in_array('extractor.tiktok.prefer-h264=false',$tiktok(true),true),'TikTok prefers H.264 by default');
    speedCheck(in_array('extractor.tiktok.prefer-h264=false',$tiktok(false),true),'TIKTOK_PREFER_H264=0 turns the preference off');
} finally {
    if($server) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
