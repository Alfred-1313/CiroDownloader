<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
final class GroupApi implements Zero\Api {
    public array $calls=[]; public int $next=500;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if(isset($params['text'])&&Zero\TextFormat::length(html_entity_decode(strip_tags($params['text']),ENT_QUOTES|ENT_HTML5,'UTF-8'))>4096) throw new RuntimeException('Telegram text limit exceeded',400);
        if($method==='getMe') return ['id'=>123,'username'=>'CiroDownloaderBot','is_bot'=>true];
        return $method==='sendMessage'?['message_id'=>++$this->next]:true;
    }
}
function groupCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function groupMessage(string $text,int $user=7,int $chat=-100123,int $thread=0): array { return ['update_id'=>random_int(1,1000000),'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread,'text'=>$text]]; }
function groupCallback(string $action,int $user=42,int $chat=42,int $message=900): array { return ['callback_query'=>['id'=>'group','from'=>['id'=>$user],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']],'data'=>$action]]; }
function groupMembership(string $old,string $new,int $chat=-77,string $type='supergroup'): array { return ['my_chat_member'=>['chat'=>['id'=>$chat,'type'=>$type],'from'=>['id'=>9],'old_chat_member'=>['user'=>['id'=>123,'is_bot'=>true],'status'=>$old],'new_chat_member'=>['user'=>['id'=>123,'is_bot'=>true],'status'=>$new]]]; }
$temp=sys_get_temp_dir().'/ciro-groups-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $store=new Zero\Store($temp); $api=new GroupApi(); $bot=new Zero\Bot(new Zero\Config('123:fake',[42],$temp),$store,$api); $groups=new Zero\GroupSupport($bot);
    groupCheck($store->groupsEnabled(),'group use is enabled by default');
    $bot->handle(groupMessage('/help',7,7));
    $guide=end($api->calls)[1]; $invite=$guide['reply_markup']['inline_keyboard'][0][0];
    groupCheck($invite['url']==='https://t.me/CiroDownloaderBot?startgroup=download&admin=manage_chat'&&!isset($invite['callback_data'])&&!isset($invite['style']),'Help shows a neutral native Telegram group-picker button for this bot, with minimal administrator rights');
    groupCheck($store->get('bot_username')==='CiroDownloaderBot'&&$store->get('bot_id')==='123','the group link uses the identity returned by getMe');
    $helpId=(int)$store->query('SELECT message_id FROM help_messages WHERE chat_id=7')->fetchColumn();
    $before=count($api->calls); $bot->handle(groupCallback('custom:groups_mode'));
    $edits=array_values(array_filter(array_slice($api->calls,$before),fn($call)=>$call[0]==='editMessageText'&&($call[1]['chat_id']??0)===7));
    groupCheck(!$store->groupsEnabled()&&count($edits)===1&&$edits[0][1]['message_id']===$helpId&&$edits[0][1]['reply_markup']['inline_keyboard']===[]&&!str_contains($edits[0][1]['text'],'دسترسی مدیریتی'),'disabling groups edits an existing Help message, removing both its invitation and invitation instructions');
    $before=count($api->calls); $bot->handle(groupMessage('https://youtu.be/ybNMMK5Q318',11));
    groupCheck(count($api->calls)===$before&&(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'disabled group messages are ignored');
    $bot->handle(groupMessage('/help',7,7));
    groupCheck(empty(end($api->calls)[1]['reply_markup']['inline_keyboard']),'new private guides also omit the invitation while groups are disabled');
    $bot->handle(groupCallback('custom:groups_mode'));
    groupCheck($store->groupsEnabled()&&(int)$store->query('SELECT groups_enabled FROM help_messages WHERE chat_id=7')->fetchColumn()===1,'enabling groups refreshes the saved guide again');
    foreach(['group_help','group_welcome','help_groups_hint','groups_disabled'] as $key) groupCheck(isset($bot->catalog('text')[$key]),'group user text is editable: '.$key);
    groupCheck(isset($bot->catalog('button')['add_to_group'])&&!isset($bot->catalog('button')['groups_mode']),'the public invitation is customizable while its administrator control is not');
    $bot->handle(groupCallback('custom:edit:button:add_to_group'));
    $bot->handle(groupMessage('➕ ربات را به گروهم ببر',42,42));
    groupCheck($bot->label('add_to_group')==='➕ ربات را به گروهم ببر','the group invitation label uses the shared editor');
    $bot->handle(groupCallback('custom:setcolor:add_to_group:primary'));
    groupCheck($bot->ui->button('add_to_group')['style']==='primary','the invitation color remains editable after its neutral default');

    $before=count($api->calls);
    foreach(['hello everyone','https://example.com/','/help@AnotherBot','/download@AnotherBot https://youtu.be/ybNMMK5Q318','/admin@CiroDownloaderBot'] as $text) $bot->handle(groupMessage($text,88));
    groupCheck(count($api->calls)===$before&&!$store->query('SELECT 1 FROM users WHERE id=88')->fetchColumn(),'group chatter and commands for other bots neither trigger replies nor inflate registered users');
    $bot->handle(groupMessage('/start@CiroDownloaderBot download',8,-100123,17));
    groupCheck(str_contains(end($api->calls)[1]['text'],'به گروه اضافه شد')&&end($api->calls)[1]['message_thread_id']===17&&!isset(end($api->calls)[1]['reply_markup']),'the Telegram group-add command posts the welcome guide in its topic without buttons');
    $joined=groupMessage('',9,-55); $joined['message']['chat']['type']='group'; $joined['message']['new_chat_members']=[['id'=>123,'is_bot'=>true]];
    $bot->handle($joined);
    groupCheck(end($api->calls)[1]['chat_id']===-55&&str_contains(end($api->calls)[1]['text'],'به گروه اضافه شد'),'manually adding this bot to an ordinary group also posts its welcome guide');

    $join=groupMembership('left','administrator'); $bot->handle($join);
    $welcome=end($api->calls)[1];
    groupCheck($welcome['chat_id']===-77&&str_contains($welcome['text'],'<b>🎬')&&str_contains($welcome['text'],'<blockquote>')&&str_contains($welcome['text'],'360p، 480p و 720p')&&str_contains($welcome['text'],'استخراج صدا')&&!isset($welcome['reply_markup']),'membership updates post a formatted step-by-step welcome and usage guide without buttons');
    $before=count($api->calls); $bot->handle($join);
    $sameJoin=$joined; $sameJoin['message']['chat']=['id'=>-77,'type'=>'supergroup']; $bot->handle($sameJoin);
    $bot->handle(groupMessage('/start@CiroDownloaderBot download',9,-77));
    $bot->handle(groupMembership('member','administrator',-78));
    $other=$joined; $other['message']['new_chat_members']=[['id'=>999]]; $bot->handle($other);
    groupCheck(count($api->calls)===$before,'duplicate updates, the group-add command, promotions and other members do not repeat the welcome');
    $fresh=new Zero\Bot($bot->config,new Zero\Store($temp),$api); $fresh->handle($join);
    groupCheck(count($api->calls)===$before,'the welcome marker survives a bot restart');
    $bot->handle(groupMembership('administrator','left'));
    $bot->handle(groupMembership('left','member'));
    groupCheck(count($api->calls)===$before+1,'removing and adding the bot again allows one new welcome');
    $before=count($api->calls); $store->set('groups_enabled','0'); $bot->handle(groupMembership('left','member',-79,'group'));
    groupCheck(count($api->calls)===$before,'group welcomes honor the disabled group setting');
    $store->set('groups_enabled','1');
    $bot->handle(groupCallback('custom:edit:text:group_welcome'));
    $bot->handle(groupMessage('<b>CUSTOM_GROUP_WELCOME</b><blockquote>{username}</blockquote>',42,42));
    $bot->handle(groupMembership('left','member',-88));
    groupCheck(str_contains(end($api->calls)[1]['text'],'CUSTOM_GROUP_WELCOME')&&str_contains(end($api->calls)[1]['text'],'<blockquote>CiroDownloaderBot</blockquote>'),'the membership welcome uses its editable user-facing template');
    $store->query("DELETE FROM settings WHERE key='text.group_welcome'");

    $url='https://youtu.be/ybNMMK5Q318'; $request=groupMessage($url,11,-100123,17); $bot->handle($request);
    $job=$store->query('SELECT * FROM jobs WHERE update_id=?',[$request['update_id']])->fetch();
    groupCheck($job&&(int)$job['user_id']===11&&(int)$job['chat_id']===-100123&&(int)$job['thread_id']===17&&end($api->calls)[1]['message_thread_id']===17,'a group link queues inspection and status in its original chat and topic');
    $claimed=$store->claim(); $bot->qualityMenu($claimed,['title'=>'Group video','options'=>[360=>[['id'=>'18','audio'=>true]],'audio'=>[['id'=>'18']]]]);
    $message=(int)$job['message_id']; $action='quality:'.$job['id'].':360';
    $bot->handle(groupCallback($action,12,-100123,$message));
    groupCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='waiting_quality','another group member cannot select someone else’s video');
    $bot->handle(groupCallback($action,11,-200123,$message));
    groupCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='waiting_quality','a copied callback from another group cannot select the job');
    $bot->handle(groupCallback($action,11,-100123,$message));
    groupCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='queued','the original sender can select the group video');
    $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
    $command=groupMessage('/download@cirodownloaderbot '.$url,13,-100123); $bot->handle($command);
    groupCheck((int)$store->query('SELECT COUNT(*) FROM jobs WHERE update_id=?',[$command['update_id']])->fetchColumn()===1,'an explicitly addressed download command works with Telegram privacy mode');
    $store->query("UPDATE jobs SET status='done'");

    $state='ui:'.json_encode(['kind'=>'text','key'=>'welcome','message'=>900]);
    $store->query('INSERT OR REPLACE INTO states VALUES(?,?,?)',[42,$state,time()+600]);
    $bot->handle(groupMessage('/help@CiroDownloaderBot',42));
    $bot->handle(groupCallback('custom:access',42,-100123));
    groupCheck($store->get('admins_only','0')==='0'&&$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()===$state,'even bot administrators cannot open management in a group or overwrite their private editing session');
    $store->set('admins_only','1'); $before=(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    $bot->handle(groupMessage($url,20,-100123));
    groupCheck((int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$before,'admin-only access also applies inside groups');
    $store->set('groups_enabled','0');
    groupCheck(!$bot->allowed(42,-100123)&&$bot->allowed(42,42)&&!(new Zero\Store($temp))->groupsEnabled(),'the group switch persists and also blocks administrator group jobs while preserving private access');
    $bot->handle(groupCallback($action,11,-100123,$message));
    groupCheck(end($api->calls)[0]==='answerCallbackQuery'&&str_contains(end($api->calls)[1]['text'],'غیرفعال'),'old group callbacks receive a clear disabled notice');
    $store->set('admins_only','0'); $bot->handle(groupMessage($url,21,21));
    groupCheck((int)$store->query("SELECT COUNT(*) FROM jobs WHERE user_id=21 AND chat_id=21 AND status='queued'")->fetchColumn()===1,'private downloading still works with groups disabled');
    $bot->handle(groupCallback('custom:groups_mode',7,7));
    groupCheck(!$store->groupsEnabled(),'ordinary users cannot enable group use');

    $bot->handle(groupCallback('custom:edit:text:help'));
    $bot->handle(groupMessage('<b>'.str_repeat('A',2200).'</b>',42,42));
    $hint=$bot->ui->template('help_groups_hint');
    $bot->handle(groupCallback('custom:edit:text:help_groups_hint'));
    $bot->handle(groupMessage('<blockquote>'.str_repeat('B',2200).'</blockquote>',42,42));
    groupCheck($bot->ui->template('help_groups_hint')===$hint&&str_contains(end($api->calls)[1]['text'],$bot->text('custom_help_too_long')),'the editor rejects an oversized combined guide even while group use is disabled');
    // Existing oversized settings must also remain usable after an upgrade.
    $store->set('text.help_groups_hint','<blockquote>'.str_repeat('B',2200).'</blockquote>');
    $store->set('groups_enabled','1'); $bot->handle(groupMessage('/help',23,23));
    $long=end($api->calls)[1];
    groupCheck(str_contains($long['text'],'<b>'.str_repeat('A',2200).'</b>')&&!str_contains($long['text'],str_repeat('B',2200))&&isset($long['reply_markup']['inline_keyboard'][0][0]['url']),'a legacy oversized guide keeps its formatted main text and invitation without exceeding Telegram limits');
    $count=(int)$store->query('SELECT COUNT(*) FROM help_messages')->fetchColumn();
    $bot->handle(groupCallback('custom:groups_mode')); $bot->handle(groupCallback('custom:groups_mode'));
    groupCheck((int)$store->query('SELECT COUNT(*) FROM help_messages')->fetchColumn()===$count,'refreshing oversized legacy guides does not discard their tracking records');
} finally { Zero\Download::clean($temp); }
