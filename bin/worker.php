<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
umask(0077);
if(!function_exists('pcntl_fork')||!function_exists('posix_kill')) throw new RuntimeException('Worker requires the pcntl and posix PHP extensions; rebuild the application image.');
$config=Zero\Config::load(); $store=new Zero\Store($config->storage);
$lock=Zero\lockProcess($config->storage.'/worker.lock');
$processor=new Zero\Worker($config,$store,new Zero\Telegram($config));
$downloads=$config->storage.'/downloads';
if(!is_dir($downloads)) mkdir($downloads,0700,true);
// The supervisor lock is inherited by its children. A replacement supervisor
// cannot recover their running jobs or erase their files while they are alive.
foreach($store->query("SELECT * FROM jobs WHERE status='running'")->fetchAll() as $job) $processor->recover($job);
foreach(glob($downloads.'/job-*')?:[] as $folder) Zero\Download::clean($folder);
echo "Ciro Downloader worker started\n";
pcntl_async_signals(true);
$stopping=false; $children=[]; $lastHeartbeat=0;
foreach([SIGTERM,SIGINT] as $signal) pcntl_signal($signal,static function() use (&$stopping): void { $stopping=true; });
try {
    while(!$stopping) {
        try {
            foreach($children as $pid=>$child) {
                if(empty($child['exited'])&&pcntl_waitpid($pid,$status,WNOHANG)>0) $children[$pid]['exited']=true;
                if(!empty($children[$pid]['exited'])) {
                    // A killed child must not strand its slot or trigger a resend
                    // when the Telegram upload outcome is unknown. Retain the
                    // record until recovery succeeds so a busy DB can be retried.
                    $processor->recover($child['job']);
                    unset($children[$pid]);
                }
            }
            // Videos that were to disappear after a few seconds (the 18+ filters): a short-lived process deletes what is due,
            // so a slow Telegram call never delays a download.
            if(isset($deletePid)&&pcntl_waitpid($deletePid,$deleteStatus,WNOHANG)!==0) unset($deletePid);
            if(!isset($deletePid)&&time()!==($lastDeleteCheck??0)) {
                $lastDeleteCheck=time();
                if(Zero\TimedDeletes::due($store)) {
                    $pid=pcntl_fork();
                    if($pid===0) {
                        try { Zero\TimedDeletes::run(new Zero\Store($config->storage),new Zero\Telegram($config)); exit(0); }
                        catch(Throwable $e) { error_log('Timed deletion failed: '.get_class($e)); exit(1); }
                    } elseif($pid>0) $deletePid=$pid;
                }
            }
            if(time()-$lastHeartbeat>=10) {
                if(!isset($lastPageCleanup)||time()-$lastPageCleanup>=60) {
                    Zero\MusicMenu::purge($config,$store);
                    foreach(Zero\galleryServices() as $service) (new Zero\GalleryPages($config,$store,new Zero\Telegram($config),$service))->prune(); $lastPageCleanup=time();
                }
                $store->set('worker_heartbeat',(string)time()); $lastHeartbeat=time();
                // Expired jars are dropped and administrators are warned once per situation.
                // A separate short-lived process keeps a slow Telegram call from delaying downloads.
                if(isset($healthPid)&&pcntl_waitpid($healthPid,$healthStatus,WNOHANG)!==0) unset($healthPid);
                if(!isset($healthPid)&&Zero\CookieHealth::dueAt($store)) {
                    $pid=pcntl_fork();
                    if($pid===0) {
                        try { Zero\Download::pruneCache($config); $health=new Zero\CookieHealth($config,new Zero\Store($config->storage),new Zero\Telegram($config)); $health->keepAlive(); $health->sweep(); exit(0); }
                        catch(Throwable $e) { error_log('Cookie health pass failed: '.get_class($e)); exit(1); }
                    } elseif($pid>0) $healthPid=$pid;
                }
                $store->query("UPDATE jobs SET status='failed' WHERE status='queued' AND message_id=0 AND created<?",[time()-120]);
                foreach($store->query("SELECT * FROM jobs WHERE status='confirming' AND expires>0 AND expires<?",[time()])->fetchAll() as $old) {
                    if($store->query("UPDATE jobs SET status='failed' WHERE id=? AND status='confirming' AND expires<?",[$old['id'],time()])->rowCount()) {
                        try { $bot=new Zero\Bot($config,$store,new Zero\Telegram($config)); $bot->jobMessage($old,$bot->text('quality_expired')); } catch(Throwable) {}
                    }
                }
                $store->query("DELETE FROM jobs WHERE status IN ('done','failed','cancelled') AND created<?",[time()-86400*7]);
            }
            if($store->enabled()) foreach(['inspect'=>1,'media'=>$config->workerConcurrency] as $lane=>$capacity) {
                $active=count(array_filter($children,fn($child)=>$child['lane']===$lane));
                while(!$stopping&&$active<$capacity&&($job=$store->claim($lane))) {
                    pcntl_sigprocmask(SIG_BLOCK,[SIGTERM,SIGINT],$previousMask);
                    $pid=pcntl_fork();
                    if($pid===-1) {
                        pcntl_sigprocmask(SIG_SETMASK,$previousMask);
                        $store->query("UPDATE jobs SET status='queued' WHERE id=? AND status='running'",[$job['id']]);
                        throw new RuntimeException('Cannot start media worker process');
                    }
                    if($pid===0) {
                        try {
                            // Each process owns its SQLite connection and HTTP client.
                            // Keep the inherited supervisor lock until this job exits.
                            unset($processor,$store,$bot);
                            foreach([SIGTERM,SIGINT] as $signal) pcntl_signal($signal,static function(): void {
                                pcntl_signal(SIGTERM,SIG_IGN); pcntl_signal(SIGINT,SIG_IGN);
                                throw new Zero\WorkerStopped('Worker stopping');
                            });
                            pcntl_sigprocmask(SIG_SETMASK,$previousMask);
                            $childStore=new Zero\Store($config->storage);
                            (new Zero\Worker($config,$childStore,new Zero\Telegram($config)))->process($job);
                            exit(0);
                        } catch(Zero\WorkerStopped) { exit(0); }
                        catch(Throwable $e) { error_log('Worker child failure: '.get_class($e)); exit(1); }
                    }
                    $children[$pid]=['lane'=>$lane,'job'=>$job]; $active++;
                    pcntl_sigprocmask(SIG_SETMASK,$previousMask);
                }
            }
            usleep(100000);
        } catch(Throwable $e) { error_log('Worker supervisor error: '.get_class($e)); if(!$stopping) usleep(500000); }
    }
} finally {
    // A stop interrupts downloader process groups through Download::run's
    // finally block. Uncertain uploads stay running for recovery, never retry.
    foreach($children as $pid=>$child) {
        if(!empty($child['exited'])) unset($children[$pid]);
        else posix_kill($pid,SIGTERM);
    }
    $deadline=microtime(true)+8;
    while($children&&microtime(true)<$deadline) {
        foreach($children as $pid=>$child) if(pcntl_waitpid($pid,$status,WNOHANG)>0) unset($children[$pid]);
        if($children) usleep(50000);
    }
    foreach($children as $pid=>$child) { posix_kill($pid,SIGKILL); pcntl_waitpid($pid,$status); }
    $store->set('worker_heartbeat','0');
}
