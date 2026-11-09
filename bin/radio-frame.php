<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/RadioArtwork.php';
umask(0077);
if(count($argv)!==4) exit(1);
exit(Zero\RadioArtwork::render($argv[1],$argv[2],$argv[3])?0:1);
