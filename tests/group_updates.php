<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
final class GroupUpdateApi implements Zero\Api {
    public array $calls=[]; private int $next=900;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='getMe') return ['id'=>123,'username'=>'CiroDownloaderBot'];
        return $method==='sendMessage'?['message_id'=>++$this->next]:true;
    }
}
function updateGroupCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function updateGroupMessage(string $text,int $user=42,int $chat=-100123,int $thread=17): array {
    return ['message'=>['from'=>['id'=>$user],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread,'text'=>$text]];
}
function updateGroupCallback(string $action,int $message,int $user=42,int $chat=-100123,int $thread=17): array {
    return ['callback_query'=>['id'=>'update-group','from'=>['id'=>$user],'data'=>$action,
        'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread]]];
}
$temp=sys_get_temp_dir().'/ciro-group-updates-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $store=new Zero\Store($temp); $api=new GroupUpdateApi(); $bot=new Zero\Bot(new Zero\Config('123:fixture',[42,43],$temp),$store,$api);
    (new Zero\GroupSupport($bot))->cacheIdentity($api->call('getMe'));
    $key='update_menu.42.-100123.17'; $state='ui:'.json_encode(['kind'=>'text','key'=>'welcome','message'=>700]);
    $store->query('INSERT INTO states VALUES(?,?,?)',[42,$state,time()+600]);
    $store->set('update_menu.42','700');
    $bot->handle(updateGroupMessage('/update'));
    $menu=(int)$store->get($key); $params=end($api->calls)[1];
    updateGroupCheck($menu>0&&$params['chat_id']===-100123&&$params['message_thread_id']===17&&!isset($params['reply_markup']['keyboard']),'an administrator /update opens an inline menu in its original group topic');
    updateGroupCheck($store->get('update_menu.42')==='700'&&$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()===$state,'group updates preserve the private menu and pending private input');
    updateGroupCheck((int)$store->query('SELECT COUNT(*) FROM update_requests')->fetchColumn()===0,'opening the group menu does not start an update');
    $before=count($api->calls);
    $bot->handle(updateGroupMessage('/update@AnotherBot')); $bot->handle(updateGroupMessage('/update',7));
    $anonymous=updateGroupMessage('/update'); $anonymous['message']['sender_chat']=['id'=>-100123]; $bot->handle($anonymous);
    updateGroupCheck(count($api->calls)===$before,'commands for another bot, ordinary members and anonymous senders cannot open the updater');
    foreach([
        updateGroupCallback('update:start',$menu,7), updateGroupCallback('update:start',$menu,43),
        updateGroupCallback('update:start',$menu,42,-100456), updateGroupCallback('update:start',$menu,42,-100123,18),
    ] as $update) $bot->handle($update);
    updateGroupCheck((int)$store->query('SELECT COUNT(*) FROM update_requests')->fetchColumn()===0,'copied buttons cannot cross administrator, group or topic boundaries');
    $bot->handle(updateGroupMessage('/update@cirodownloaderbot')); $new=(int)$store->get($key);
    updateGroupCheck($new!==$menu&&end($api->calls)[0]==='sendMessage','addressed /update commands open a visible group menu');
    $store->set('updater_heartbeat',(string)time());
    $bot->handle(updateGroupCallback('update:start',$menu));
    updateGroupCheck((int)$store->query('SELECT COUNT(*) FROM update_requests')->fetchColumn()===0,'an old group menu cannot start an update');
    $bot->handle(updateGroupCallback('update:start',$new)); $bot->handle(updateGroupCallback('update:start',$new));
    $job=$store->query('SELECT * FROM update_requests')->fetch();
    updateGroupCheck((int)$job['user_id']===42&&(int)$job['chat_id']===-100123&&(int)$job['thread_id']===17&&(int)$job['message_id']===$new&&(int)$store->query('SELECT COUNT(*) FROM update_requests')->fetchColumn()===1,'the explicit Start button queues one administrator-owned group update');
    $edits=array_values(array_filter($api->calls,fn($call)=>$call[0]==='editMessageText'));
    updateGroupCheck(end($edits)[1]['message_id']===$new&&str_contains(end($edits)[1]['text'],'0%'),'the menu becomes the progress message without creating a second message');
    $bot->handle(updateGroupMessage('/update',42,42,0));
    $bot->handle(updateGroupMessage('/update',42,-100123,18));
    $job=$store->query('SELECT * FROM update_requests')->fetch();
    updateGroupCheck((int)$job['message_id']===$new&&(int)$job['chat_id']===-100123&&(int)$job['thread_id']===17,'opening another conversation or topic cannot redirect active progress');
    $store->set('update_percent','65'); $store->set('update_stage','update_build');
    $bot->handle(updateGroupMessage('/update')); $resumed=(int)$store->get($key);
    $job=$store->query('SELECT * FROM update_requests')->fetch();
    $sends=array_values(array_filter($api->calls,fn($call)=>$call[0]==='sendMessage'));
    updateGroupCheck($resumed!==$new&&(int)$job['message_id']===$resumed&&str_contains(end($sends)[1]['text'],'65%'),'an explicit reopen resumes the same request in the same topic');
    $store->query("UPDATE update_requests SET status='done'");
    $store->set('update_new_changes',json_encode(['to'=>Zero\Updates::version(),'changes'=>['Group update fixture']]));
    $before=count($api->calls); $bot->handle(updateGroupCallback('update:changes',$resumed));
    updateGroupCheck(end($api->calls)[0]==='editMessageText'&&end($api->calls)[1]['message_id']===$resumed&&str_contains(end($api->calls)[1]['text'],'Group update fixture'),'the completion changes view edits the same group message');
    $bot->handle(updateGroupCallback('update:back',$resumed));
    updateGroupCheck(end($api->calls)[0]==='editMessageText'&&$store->get($key)==='0'&&end($api->calls)[1]['reply_markup']['inline_keyboard']===[],'Close clears the group menu in place without opening a private management keyboard');
    $store->set('groups_enabled','0'); $before=count($api->calls); $bot->handle(updateGroupMessage('/update'));
    updateGroupCheck(count($api->calls)===$before,'the disabled-group setting still gates group commands');
} finally { Zero\Download::clean($temp); }
