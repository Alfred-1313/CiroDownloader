<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/AdultHandoff.php';
umask(0077);
$config=Zero\Config::load(); $store=new Zero\Store($config->storage); Zero\ErrorAlerts::boot($config,$store);
$lock=Zero\lockProcess($config->storage.'/adult-handoff.lock');
$handoff=new Zero\AdultHandoff($config,$store);
// An interrupted upload has an unknown outcome. Never automatically send it twice.
$handoff->recoverInterrupted();
pcntl_async_signals(true); $stop=false; $children=[];
foreach([SIGTERM,SIGINT] as $sig) pcntl_signal($sig,static function() use (&$stop): void { $stop=true; });
try {
    while(!$stop) {
        foreach($children as $kind=>$pid) if(pcntl_waitpid($pid,$status,WNOHANG)>0) {
            if($kind==='deliver'&&isset($deliveryToken)) { $handoff->failInterrupted($deliveryToken); unset($deliveryToken); }
            unset($children[$kind]);
        }
        if(!isset($children['poll'])&&$handoff->token()!=='') {
            $pid=pcntl_fork();
            if($pid===0) {
                pcntl_signal(SIGTERM,SIG_DFL); pcntl_signal(SIGINT,SIG_DFL);
                try {
                    $db=new Zero\Store($config->storage); $h=new Zero\AdultHandoff($config,$db); $token=$h->token(); $api=$h->api($token);
                    $offsetKey='adult.handoff.offset.'.(int)strstr($token,':',true);
                    $updates=$api->call('getUpdates',['offset'=>(int)$db->get($offsetKey,'0'),'timeout'=>5,'allowed_updates'=>['message']]);
                    foreach($updates as $update) {
                        $msg=$update['message']??[]; $user=(int)($msg['from']['id']??0); $chat=(int)($msg['chat']['id']??0);
                        if($user>0&&$chat===$user&&($msg['chat']['type']??'')==='private') {
                            $result=$h->request($user,$chat,(string)($msg['text']??''));
                            $ui=(new Zero\Ui($db))->forService($h->requestSite($user,(string)($msg['text']??'')));
                            try { $api->call('sendMessage',['chat_id'=>$chat,'text'=>$ui->text($result==='queued'?'handoff_queued':'handoff_denied',[],[],true),'parse_mode'=>'HTML']); } catch(Throwable) {}
                        }
                        $db->set($offsetKey,(string)((int)$update['update_id']+1));
                    }
                    exit(0);
                } catch(Throwable) { usleep(500000); exit(1); }
            }
            if($pid>0) $children['poll']=$pid;
        }
        if(!isset($children['deliver'])&&$handoff->token()!==''&&($row=$handoff->claim())) {
            $pid=pcntl_fork();
            if($pid===0) {
                pcntl_signal(SIGTERM,SIG_DFL); pcntl_signal(SIGINT,SIG_DFL);
                try { $db=new Zero\Store($config->storage); $h=new Zero\AdultHandoff($config,$db); $api=$h->api(); $h->deliver($row,$api); exit(0); }
                catch(Throwable) {
                    try { $h->failInterrupted($row['token']); $api->call('sendMessage',['chat_id'=>(int)$row['owner'],'text'=>(new Zero\Ui($db))->forService($row['site'])->text('handoff_failed',[],[],true),'parse_mode'=>'HTML']); } catch(Throwable) {}
                    exit(1);
                }
            }
            if($pid>0) { $children['deliver']=$pid; $deliveryToken=$row['token']; }
            else $handoff->unclaim($row['token']);
        }
        if(!isset($children['sweep'])&&time()!==($lastSweep??0)&&($handoff->sweepDue()||time()-($lastOrphanSweep??0)>=60)) {
            $lastSweep=time(); $pid=pcntl_fork();
            if($pid===0) {
                pcntl_signal(SIGTERM,SIG_DFL); pcntl_signal(SIGINT,SIG_DFL);
                try {
                    $h=new Zero\AdultHandoff($config,new Zero\Store($config->storage));
                    $h->purge(new Zero\Telegram($config));
                    $h->purgeOrphans();
                    if($h->token()!=='') $h->deleteDue($h->api());
                    exit(0);
                } catch(Throwable) { exit(1); }
            }
            if($pid>0) { $children['sweep']=$pid; $lastOrphanSweep=time(); }
        }
        usleep(200000);
    }
} finally {
    foreach($children as $pid) posix_kill($pid,SIGTERM);
    foreach($children as $pid) pcntl_waitpid($pid,$status);
}
