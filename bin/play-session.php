<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/GooglePlay.php';
umask(0077);
// Run interactively: docker compose run --rm --no-deps worker php bin/play-session.php
// Secrets are read on stdin, never as arguments, stored UI settings or process output.
$config=Zero\Config::load();
if(($argv[1]??'')==='--check') {
    $path=Zero\GooglePlay::sessionPath($config);
    if(is_link($path)) $status='unsafe_link';
    elseif(!is_file($path)) $status='missing';
    elseif(!is_readable($path)) $status='unreadable';
    elseif((fileperms($path)&0077)!==0) $status='insecure_permissions';
    elseif(!Zero\GooglePlay::configured($config)) $status='invalid_format';
    else $status='ready';
    // This is only a local-file check, never a claim that Google accepted login.
    fwrite(STDOUT,json_encode(['session'=>$status,'login_tested'=>false],JSON_THROW_ON_ERROR)."\n");
    exit($status==='ready'?0:2);
}
if(count($argv)>1) { fwrite(STDERR,"Usage: php bin/play-session.php [--check]\n"); exit(1); }
fwrite(STDERR,"Google Play email: "); $email=trim((string)fgets(STDIN));
fwrite(STDERR,"Token type (oauth_token from browser, aas_token or auth_token): "); $type=trim((string)fgets(STDIN));
if(!in_array($type,['oauth_token','aas_token','auth_token'],true)) { fwrite(STDERR,"Unsupported token type.\n"); exit(1); }
$terminal=function_exists('stream_isatty')&&stream_isatty(STDIN);
if($terminal) system('stty -echo');
try { fwrite(STDERR,"Token (hidden): "); $token=trim((string)fgets(STDIN,16385)); }
finally { if($terminal) system('stty echo'); fwrite(STDERR,"\n"); }
try {
    if($type==='oauth_token') {
        fwrite(STDERR,"Exchanging the one-time token with Google; no download or terms acceptance.\n");
        $content=Zero\GooglePlay::oauthSession($email,$token);
    } else $content=Zero\GooglePlay::session("[google]\nemail = \"".$email."\"\n".$type.' = "'.$token."\"\n");
} catch(Throwable) { fwrite(STDERR,"Session not saved. Check the email/token; browser OAuth tokens can only be used once. Existing credentials were kept.\n"); exit(1); }
$directory=dirname(Zero\GooglePlay::sessionPath($config));
if(!is_dir($directory)&&!mkdir($directory,0700,true)) { fwrite(STDERR,"Cannot create session directory.\n"); exit(1); }
$temporary=tempnam($directory,'.session-');
try {
    if($temporary===false||file_put_contents($temporary,$content)===false||!chmod($temporary,0600)||!rename($temporary,Zero\GooglePlay::sessionPath($config))) throw new RuntimeException();
} catch(Throwable) { fwrite(STDERR,"Cannot save session; check storage permissions.\n"); exit(1); }
finally { if(is_string($temporary)&&is_file($temporary)) unlink($temporary); }
fwrite(STDERR,"Session saved privately. Login will be checked on the next download.\n");
