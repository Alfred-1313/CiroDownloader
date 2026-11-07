<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function stCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$temp=sys_get_temp_dir().'/ciro-speedtest-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
$server=null;
try {
    $log=$temp.'/requests.jsonl'; $script=$temp.'/server.py';
    file_put_contents($script,<<<'PY'
import http.server, json, sys

class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0")); self.rfile.read(length)
        method = self.path.rsplit("/", 1)[-1]
        with open(sys.argv[2], "a") as log:
            log.write(json.dumps({"method": method}) + "\n")
        payload = json.dumps({"ok": True, "result": {"message_id": 7, "username": "fixture_bot"}}).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(payload))); self.end_headers(); self.wfile.write(payload)
    def log_message(self, *args): pass

http.server.ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1])), Handler).serve_forever()
PY);
    $port=random_int(20000,40000);
    $server=proc_open(['python3','-B',$script,(string)$port,$log],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,getenv());
    for($i=0,$up=false;$i<50&&!$up;$i++) { usleep(100000); $up=@fsockopen('127.0.0.1',$port); }
    stCheck((bool)$up,'a local Telegram stand-in is listening');

    // A stub gallery-dl that prints the manifest the bot expects.
    $image=$temp.'/fixture.mp4';
    (new Zero\Download(new Zero\Config('123:fixture',[42],$temp)))->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','testsrc=s=320x240:r=5:d=1','-f','lavfi','-i','anullsrc=r=8000:cl=mono','-shortest','-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac',$image],$temp,60);
    $stub=$temp.'/stub-gallery';
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n\$d=\$argv[array_search('--directory',\$argv,true)+1];copy(".var_export($image,true).",\$d.'/tt-01.mp4');echo \"CIRO|1|1|\".\$d.\"/tt-01.mp4\\nCIRO_META|\\\"c\\\"\\n\";");
    chmod($stub,0700);
    $env=array_merge(getenv(),['BOT_TOKEN'=>'123456:SECRET_TOKEN_VALUE','ADMIN_IDS'=>'987654321','BOT_API_URL'=>'http://127.0.0.1:'.$port,'STORAGE_DIR'=>$temp.'/storage','GALLERY_DL_BIN'=>$stub,'SPEED_UPLOAD_MB'=>'2']);
    mkdir($temp.'/storage',0700);
    $run=function(array $arguments) use($env): array {
        $process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/speedtest.php',...$arguments],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$env);
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); $code=proc_close($process);
        return [$code,$out,$err];
    };
    [$code,$out]=$run([]);
    stCheck($code===0&&str_contains($out,'== Server ==')&&str_contains($out,'CPU cores seen')&&str_contains($out,'Encode time')&&str_contains($out,'Next calls, median'),'the report lists the server, CPU and Telegram latency');
    stCheck(preg_match('/Upload 2 MB to Telegram\s+\d+ ms \([\d.]+ MB\/s\)/',$out)===1,'the upload test reports time and speed for the chosen size');
    stCheck(!str_contains($out,'SECRET_TOKEN_VALUE')&&!str_contains($out,'987654321')&&!str_contains($out,'127.0.0.1'),'the report never prints the token, the administrator ID or the API address');
    $methods=array_column(array_map(fn($l)=>json_decode($l,true),file($log,FILE_IGNORE_NEW_LINES)),'method');
    stCheck(in_array('sendDocument',$methods,true)&&count(array_keys($methods,'deleteMessage'))===1,'the temporary upload is deleted again');
    [$code,$out]=$run(['https://www.tiktok.com/@user/video/1234567890123456789?secret=1']);
    stCheck($code===0&&str_contains($out,'Download + prepare')&&str_contains($out,'Video codec sent')&&str_contains($out,'h264')&&str_contains($out,'Total for the first item'),'a TikTok link is timed through the real download, preparation and upload path');
    stCheck(!str_contains($out,'secret=1')&&str_contains($out,'www.tiktok.com'),'only the host of the link is shown');
    $methods=array_column(array_map(fn($l)=>json_decode($l,true),file($log,FILE_IGNORE_NEW_LINES)),'method');
    stCheck(in_array('sendVideo',$methods,true)&&count(array_keys($methods,'deleteMessage'))===3,'the temporary video is deleted too');
    [$code,$out]=$run(['https://example.com/x']);
    stCheck($code===0&&str_contains($out,'This link is not supported'),'an unsupported link is reported without failing the test');
    stCheck(glob($temp.'/storage/speedtest-*')===[],'no temporary files are left behind');
} finally {
    if($server) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
