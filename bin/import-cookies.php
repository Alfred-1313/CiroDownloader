<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Core.php';
umask(0077);
$exitCode=0;
try {
    $config=Zero\Config::load();
    $content=stream_get_contents(STDIN,1048577);
    if($content===false) throw new RuntimeException('Cannot read cookie export');
    // Another account is added to the pool; the same account replaces its jar.
    $result=Zero\youtubeCookiePool($config)->add($content);
    echo "YouTube cookies imported ({$result['status']}). Run delivery diagnostics with a YouTube URL to verify access.\n";
} catch(Throwable $e) {
    fwrite(STDERR,'Cookie import failed: '.Zero\diagnosticMessage($e->getMessage()).PHP_EOL);
    $exitCode=1;
}
exit($exitCode);
