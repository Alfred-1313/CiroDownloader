<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
umask(0077);
$config=Zero\Config::load(); $store=new Zero\Store($config->storage);
$lock=Zero\lockProcess($config->storage.'/poller.lock');
$api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
$api->call('getMe');
$webhook=$api->call('getWebhookInfo');
if(!empty($webhook['url'])) throw new RuntimeException('Webhook is configured. Remove it before starting this polling bot.');
echo "Ciro Downloader poller started\n";
$lastHeartbeat=0;
while(true) {
    try {
        if(time()-$lastHeartbeat>=10) { $store->set('poller_heartbeat',(string)time()); $lastHeartbeat=time(); }
        $updates=$api->call('getUpdates',['offset'=>(int)$store->get('offset','0'),'timeout'=>30,'allowed_updates'=>['message','callback_query']]);
        foreach($updates as $update) {
            try { $bot->handle($update); }
            catch(Throwable $e) { error_log('Update failed: '.get_class($e)); }
            // Queued jobs have a unique update_id, so redeliveries cannot duplicate downloads.
            $store->set('offset',(string)($update['update_id']+1));
        }
    } catch(Throwable $e) { error_log('Polling failed: '.get_class($e)); sleep(5); }
}
