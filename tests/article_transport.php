<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';
function articleCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$temp=sys_get_temp_dir().'/ciro-article-tls-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $command=['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',$temp.'/key.pem','-out',$temp.'/cert.pem',
        '-subj','/CN=localhost','-addext','subjectAltName=IP:127.0.0.1,DNS:localhost','-days','1'];
    (new Zero\Download(new Zero\Config('123:fixture',[42],$temp)))->run($command,$temp,30);
    $python=is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'python3';
    $server=proc_open([$python,__DIR__.'/fixtures/article_tls_server.py',$temp],[0=>['file','/dev/null','r'],1=>['file',$temp.'/tls.log','w'],2=>['file',$temp.'/tls-error.log','w']],$pipes);
    $deadline=microtime(true)+5;
    while(!is_file($temp.'/port')&&microtime(true)<$deadline&&proc_get_status($server)['running']) usleep(50000);
    articleCheck(is_file($temp.'/port'),'the verified local TLS fixture starts');
    $base='https://127.0.0.1:'.trim(file_get_contents($temp.'/port'));
    $script=$temp.'/client.php';
    $articleFile=getenv('ARTICLES_TEST_IMPLEMENTATION')?:dirname(__DIR__).'/src/Articles.php';
    // Bind the fixture's CA explicitly without changing production transport.
    file_put_contents($script,'<?php namespace Zero { function curl_init($url=null) { $h=\curl_init($url);\curl_setopt($h,CURLOPT_CAINFO,\ini_get("curl.cainfo"));return $h; } } namespace { '.
        'require '.var_export(dirname(__DIR__).'/src/Core.php',true).';'.
        'require '.var_export(dirname(__DIR__).'/src/Download.php',true).';'.
        'require '.var_export(dirname(__DIR__).'/src/Instagram.php',true).';'.
        'require '.var_export($articleFile,true).';'.
        '$base=$argv[1];'.
        'if($argv[2]==="probe") { $h=curl_init($base);curl_setopt_array($h,[CURLOPT_CAINFO=>ini_get("curl.cainfo"),CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_2TLS,CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);curl_exec($h);echo json_encode(["curl"=>curl_errno($h),"detail"=>curl_error($h)]);exit; }'.
        '$folder=$argv[3];$config=new Zero\Config("123:fixture",[42],$folder);$ui=new Zero\Ui(new Zero\Store($folder));'.
        '$file=$folder."/photo.jpg";file_put_contents($file,"fixture image bytes");'.
        'try { $url=(new Zero\Articles($config,$base,$base."/upload"))->publish(['.
        '["type"=>"photo","path"=>$file,"index"=>1],["type"=>"photo","path"=>$file,"index"=>2]],'.
        '"https://instagram.com/p/ABC_def12/",$ui,fn()=>true);echo json_encode(["url"=>$url]); }'.
        'catch(Zero\MediaFailure $failure) { echo json_encode(["error"=>$failure->getMessage()]); } }');
    $client=function(string $mode,bool $trusted=true) use($script,$temp,$base): array {
        $folder=$temp.'/client-'.bin2hex(random_bytes(4)); mkdir($folder,0700);
        $ca=$trusted?$temp.'/cert.pem':'/etc/ssl/certs/ca-certificates.crt';
        $command=[PHP_BINARY,'-d','curl.cainfo='.$ca,$script,$base,$mode,$folder];
        $environment=array_merge(getenv(),['NO_PROXY'=>(getenv('NO_PROXY')?:'').',127.0.0.1','no_proxy'=>(getenv('no_proxy')?:'').',127.0.0.1']);
        $process=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$temp.'/client-error.log','a']],$pipes,null,$environment);
        $output=stream_get_contents($pipes[1]); fclose($pipes[1]); $exit=proc_close($process);
        if($exit!==0) throw new RuntimeException('TLS client failed: '.$output);
        $result=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        return $result;
    };
    if((curl_version()['features']&CURL_VERSION_HTTP2)!==0) {
        $probe=$client('probe');
        $reproduced=($probe['curl']??0)===92;
        articleCheck($reproduced,'HTTP/2 stream resets reproduce the deployment error curl=92'.($reproduced?'':'; received '.json_encode($probe).' fixture='.(is_file($temp.'/alpn.log')?file_get_contents($temp.'/alpn.log'):'')));
    } else echo "SKIP HTTP/2 reproduction: this libcurl has no HTTP/2 support\n";
    $result=$client('publish');
    $published=($result['url']??'')==='https://telegra.ph/fixture-11-06';
    articleCheck($published,'article creation and media uploads succeed through verified TLS using HTTP/1.1'.($published?'':'; received '.json_encode($result)));
    $trace=file($temp.'/requests.log',FILE_IGNORE_NEW_LINES);
    articleCheck($trace===['HTTP/1.1 /createAccount','HTTP/1.1 /upload','HTTP/1.1 /upload','HTTP/1.1 /createPage'],'every publishing POST uses HTTP/1.1 exactly once');
    $untrusted=$client('publish',false);
    articleCheck(str_contains($untrusted['error']??'','curl=60')&&!str_contains($untrusted['error']??'','AAAAAAAA'),'certificate verification stays enabled and credentials do not appear in diagnostics');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
