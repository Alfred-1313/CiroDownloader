#!/usr/bin/env php
<?php
declare(strict_types=1);
// Yoinks' upstream functions accept an executable path rather than base args.
// This shim execs the pinned binary with the bot's isolation/proxy settings.
$binary=getenv('CIRO_YTDLP_BIN')?:'';
if($binary!==''&&!str_contains($binary,'/')) foreach(explode(PATH_SEPARATOR,getenv('PATH')?:'') as $directory) {
    if($directory!==''&&is_executable($directory.'/'.$binary)) { $binary=$directory.'/'.$binary; break; }
}
if($binary===''||!is_executable($binary)) { fwrite(STDERR,"ERROR: Configured yt-dlp is unavailable\n"); exit(1); }
$args=array_slice($argv,1);
foreach($args as $arg) if(preg_match('/^--(?:cookies|cookies-from-browser|config-locations)(?:=|$)/D',$arg)) {
    fwrite(STDERR,"ERROR: Yoinks fallback cannot use login cookies or external configuration\n"); exit(1);
}
$base=['--ignore-config','--no-cache-dir','--js-runtimes','node','--socket-timeout','20','--retries','2','--fragment-retries','2'];
if(($proxy=getenv('CIRO_YOUTUBE_PROXY')?:'')!=='') $base=array_merge($base,['--proxy',$proxy]);
pcntl_exec($binary,array_merge($base,$args));
fwrite(STDERR,"ERROR: Cannot execute the configured yt-dlp\n"); exit(1);
