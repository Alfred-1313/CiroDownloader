<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Core.php';
umask(0077);
$temporary=null;
$exitCode=0;
try {
    $config=Zero\Config::load();
    $content=stream_get_contents(STDIN,1048577);
    if($content===false) throw new RuntimeException('Cannot read cookie export');
    Zero\validateYouTubeCookies($content);
    if(!is_dir($config->storage)) mkdir($config->storage,0700,true);
    $destination=$config->cookiePath();
    $temporary=dirname($destination).'/.cookies-import-'.bin2hex(random_bytes(6));
    if(file_put_contents($temporary,$content,LOCK_EX)===false) throw new RuntimeException('Cannot save cookies');
    chmod($temporary,0600);
    if(!rename($temporary,$destination)) throw new RuntimeException('Cannot replace cookie file');
    echo "YouTube cookies imported. Run delivery diagnostics with a YouTube URL to verify access.\n";
} catch(Throwable $e) {
    fwrite(STDERR,'Cookie import failed: '.Zero\diagnosticMessage($e->getMessage()).PHP_EOL);
    $exitCode=1;
} finally {
    if($temporary!==null&&is_file($temporary)) unlink($temporary);
}
exit($exitCode);
