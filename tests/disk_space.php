<?php
declare(strict_types=1);
namespace Zero;

// Simulate different free-space values without filling the CI runner's disk.
function disk_free_space(string $path): int|false { return $GLOBALS['ciro_test_free']; }
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';

$temp=sys_get_temp_dir().'/ciro-space-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    // A 49 MB file may proceed even with far less than four times the configured 1990 MB maximum.
    $GLOBALS['ciro_test_free']=600000000;
    Download::ensureSpace($temp);
    Download::ensureSpace($temp,49000000+268435456);
    $rejected=false;
    try { Download::ensureSpace($temp,500000000+268435456); } catch(MediaFailure $e) { $rejected=$e->reason==='storage'; }
    if(!$rejected) throw new \RuntimeException('Insufficient upload copy space was accepted');
    $GLOBALS['ciro_test_free']=100000000;
    $rejected=false;
    try { (new Download(new Config('123:fake',[42],$temp)))->run(['/bin/sleep','3'],$temp,5); }
    catch(MediaFailure $e) { $rejected=$e->reason==='storage'; }
    if(!$rejected) throw new \RuntimeException('Running downloader ignored low disk space');
    echo "PASS free space checks follow the actual file and stop processing before exhausting storage\n";
} finally { Download::clean($temp); }

