<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';

/** Telegram: records every call; chat 55 is an administrator of every chat, others are members. */
final class RadioApi implements Zero\Api {
    public array $calls=[]; private int $next=900; public array $admins=[55];
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='getChatMember') return ['status'=>in_array((int)$params['user_id'],$this->admins,true)?'administrator':'member','user'=>['id'=>$params['user_id']]];
        if($method==='getChat') return ['id'=>$params['chat_id'],'title'=>'Fetched Title','type'=>'channel'];
        return str_starts_with($method,'send')?['message_id'=>++$this->next]:true;
    }
    public function last(string $method): ?array { foreach(array_reverse($this->calls) as $call) if($call[0]===$method) return $call[1]; return null; }
    public function all(string $method): array { return array_values(array_map(fn($call)=>$call[1],array_filter($this->calls,fn($call)=>$call[0]===$method))); }
}
function rdCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function rdClick(string $data,int $user,int $chat,int $message=800): array {
    return ['update_id'=>random_int(1,1000000),'callback_query'=>['id'=>'q'.random_int(1,99999),'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']]+($chat<0?['message_thread_id'=>0]:[]),'data'=>$data]];
}
function rdText(string $text,int $user,int $chat=0): array {
    $chat=$chat?:$user; return ['update_id'=>random_int(1,1000000),'message'=>['message_id'=>random_int(1,99999),'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'text'=>$text]];
}
function rdActions(?array $params): array { return array_values(array_filter(array_column(array_merge(...($params['reply_markup']['inline_keyboard']??[[]])),'callback_data'))); }
function rdAnswer(RadioApi $api): string { return (string)($api->last('answerCallbackQuery')['text']??''); }

// A made-up address and key: real ones are secrets and never belong in a test.
$KEY='rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf';
$temp=sys_get_temp_dir().'/ciro-radio-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    // 1. The address: what is accepted, where it lives.
    foreach([$KEY,"rtmps://dc4-1.rtmp.t.me/s/\n1234567890:FakeKeyForTestsOnly_AbCdEf","  rtmp://a-2.rtmp.t.me:443/s/12345678:abcdefghij_KL-mn  "] as $ok) rdCheck(Zero\Radio::parseTarget($ok)!==null,'a stream address is accepted: '.preg_replace('/:[A-Za-z0-9_-]{10,}/',':…',trim($ok)));
    foreach(['','hello','https://example.com/s/12345678:abcdefghij','rtmps://evil.example/s/12345678:abcdefghij','rtmps://dc4-1.rtmp.t.me.evil.example/s/12345678:abcdefghij','rtmps://dc4-1.rtmp.t.me/s/12:short',"rtmps://dc4-1.rtmp.t.me/s/12345678:abcdefghij; rm -rf /"] as $bad) rdCheck(Zero\Radio::parseTarget($bad)===null,'not a stream address: '.mb_substr($bad,0,40));

    $config=new Zero\Config('123:fake',[42],$temp); $store=new Zero\Store($temp); $api=new RadioApi(); $bot=new Zero\Bot($config,$store,$api); $radio=new Zero\Radio($config,$store);
    $store->query('INSERT INTO users(id) VALUES(42),(55),(66),(7)');
    $radio->save(-1001,'Test Channel','channel',55,$KEY);
    $file=$temp.'/radio/keys/-1001';
    rdCheck(is_file($file)&&(fileperms($file)&0777)===0600&&$radio->target(-1001)===$KEY,'the address is a private file (mode 0600)');
    $dump=''; foreach($store->query('SELECT * FROM radio_stations')->fetchAll(PDO::FETCH_NUM) as $row) $dump.=json_encode($row);
    $database=''; foreach(glob($temp.'/*.sqlite*')?:[] as $path) $database.=(string)file_get_contents($path);
    rdCheck(!str_contains($dump,'FakeKeyForTests')&&!str_contains($database,'FakeKeyForTests'),'the key is in no database row or file');
    rdCheck($radio->station(-1001)['title']==='Test Channel'&&count($radio->stations())===1,'the chat is a station');

    // 2. Who may use it.
    rdCheck($radio->mayControl($api,-1001,55,false)&&!$radio->mayControl($api,-1001,66,false)&&$radio->mayControl($api,-1001,66,true)&&!$radio->mayControl($api,-999,55,false),'chat administrators and bot administrators may; members and unknown chats may not');
    $before=count($api->all('getChatMember')); $radio->mayControl($api,-1001,55,false); rdCheck(count($api->all('getChatMember'))===$before,'Telegram is asked about the same person at most every two minutes');

    // 3. The queue.
    $song=fn(string $name)=>['service'=>'spotify','m'=>['title'=>$name,'artists'=>['A'],'duration'=>200]];
    $one=$radio->enqueue(-1001,55,$song('One'),'One','A',200); $two=$radio->enqueue(-1001,55,$song('Two'),'Two','A',200);
    rdCheck($one['position']===1&&$two['position']===2&&$radio->queued(-1001)===2,'songs queue in order');
    rdCheck($radio->enqueue(-1001,55,['service'=>'x'],'Bad','A',10)==='invalid'&&$radio->enqueue(-1001,55,$song('Long'),'Long','A',1300)==='long','a wrong source or a song over twenty minutes is refused');
    $radio->command(-1001,'stop'); $radio->enqueue(-1001,55,$song('Three'),'Three','A',200);
    rdCheck($store->query('SELECT command FROM radio_stations WHERE chat_id=-1001')->fetchColumn()==='','adding a song resumes a stopped radio');
    for($i=0;$i<100;$i++) $last=$radio->enqueue(-1001,55,$song('S'.$i),'S'.$i,'A',100);
    rdCheck($last==='full'&&$radio->queued(-1001)===100,'the queue holds at most 100 songs');
    $radio->clear(-1001); rdCheck($radio->queued(-1001)===0,'clearing empties the queue');

    // 4. Live state and seen chats, from the service messages.
    $post=fn(string $kind,array $extra=[])=>['update_id'=>1,'channel_post'=>['message_id'=>5,'chat'=>['id'=>-1001,'type'=>'channel','title'=>'Test Channel'],'date'=>time(),$kind=>$extra]];
    $bot->handle($post('video_chat_started')); rdCheck((int)$radio->station(-1001)['live']===1,'a started stream makes the station live');
    $bot->handle($post('video_chat_ended',['duration'=>5])); rdCheck((int)$radio->station(-1001)['live']===0,'an ended stream makes it idle');
    $bot->handle(['update_id'=>2,'channel_post'=>['message_id'=>6,'chat'=>['id'=>-1005,'type'=>'channel','title'=>'New Channel'],'date'=>time(),'video_chat_started'=>[]]]);
    rdCheck(array_column($radio->candidates(),'title')===['New Channel'],'a chat where a stream started becomes a candidate for a radio');
    $bot->handle(['update_id'=>3,'message'=>['message_id'=>7,'from'=>['id'=>66,'is_bot'=>false],'chat'=>['id'=>-1006,'type'=>'supergroup','title'=>'Strangers'],'date'=>time(),'video_chat_started'=>[]]]);
    $bot->handle(['update_id'=>4,'message'=>['message_id'=>8,'from'=>['id'=>55,'is_bot'=>false],'chat'=>['id'=>-1007,'type'=>'supergroup','title'=>'Admins Group'],'date'=>time(),'video_chat_started'=>[]]]);
    rdCheck(array_column($radio->candidates(),'title')===['Admins Group','New Channel'],'in a group only a chat administrator starting a stream makes it a candidate');

    // 5. Setting a radio up in the private chat: pick the chat, send the address, it is deleted and kept privately.
    $api->calls=[]; $bot->handle(rdText('/radio',55)); $panel=$api->last('sendMessage');
    rdCheck(str_contains($panel['text'],'📻 رادیو')&&in_array('rd:u:-1005',rdActions($panel),true)&&in_array('rd:u:-1007',rdActions($panel),true)&&in_array('rd:v:-1001',rdActions($panel),true),'/radio lists the radios a person runs and the chats they could set up');
    $api->admins=[]; $api->calls=[]; $bot->handle(rdText('/radio',66)); $panel=$api->last('sendMessage');
    rdCheck(!array_filter(rdActions($panel),fn($data)=>str_starts_with($data,'rd:u:')||str_starts_with($data,'rd:v:')),'somebody who administers nothing is offered nothing');
    $api->admins=[55];
    $api->calls=[]; $bot->handle(rdClick('rd:u:-1005',66,66)); rdCheck(str_contains(rdAnswer($api),'فقط مدیرهای'),'a member cannot start setting a chat up');
    $api->calls=[]; $bot->handle(rdClick('rd:u:-1005',55,55)); $prompt=$api->last('sendMessage');
    rdCheck(str_contains($prompt['text'],'New Channel')&&$store->query('SELECT key FROM states WHERE user_id=55')->fetchColumn()==='radio.key:-1005','an administrator is asked for the address');
    $api->calls=[]; $bot->handle(rdText('this is not it',55)); rdCheck(str_contains($api->last('sendMessage')['text'],'rtmps://')&&$api->last('deleteMessage')!==null&&$radio->station(-1005)===null,'a wrong address is explained and the message is deleted');
    $api->calls=[]; $bot->handle(rdText($KEY,55)); $delete=$api->all('deleteMessage');
    rdCheck(count($delete)===1&&$radio->station(-1005)!==null&&$radio->target(-1005)===$KEY&&(int)$store->query('SELECT COUNT(*) FROM states WHERE user_id=55')->fetchColumn()===0,'the address is saved, the waiting state ends and the message with the key is deleted at once');
    rdCheck(!str_contains(json_encode($api->calls),'FakeKeyForTests')&&str_contains($api->all('sendMessage')[0]['text'],'✅'),'the key never appears in anything the bot sends');
    rdCheck($radio->station(-1005)['title']==='New Channel'&&(int)$store->get('radio.active.55')===-1005,'the new radio is named after the chat and becomes the person\'s active one');
    $api->calls=[]; $store->query("INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(66,'radio.key:-1007',?)",[time()+600]); $bot->handle(rdText($KEY,66));
    rdCheck($radio->station(-1007)===null&&$api->last('deleteMessage')!==null,'a member who gets hold of the setup step still cannot save an address');

    // 6. The ▶️ under a song, in a group and in a private chat.
    $radio->save(-1100,'The Group','group',55,$KEY); $radio->setLive(-1100,true);
    $token=$radio->pick(['service'=>'spotify','u'=>'https://open.spotify.com/track/AAAAAAAAAAAAAAAAAAAAAA'],'Song','Artist',230);
    $api->calls=[]; $bot->handle(rdClick('rd:p:'.$token,55,-1100)); rdCheck(str_contains(rdAnswer($api),'جایگاه 1')&&$radio->queued(-1100)===1,'an administrator of the group queues the song on the group\'s radio');
    $api->calls=[]; $bot->handle(rdClick('rd:p:'.$token,66,-1100)); rdCheck(str_contains(rdAnswer($api),'فقط مدیرهای')&&$radio->queued(-1100)===1,'a member of the group cannot');
    $api->calls=[]; $bot->handle(rdClick('rd:p:'.$token,55,-2222)); rdCheck(str_contains(rdAnswer($api),'راه‌اندازی نشده'),'a group without a radio says so');
    $api->calls=[]; $bot->handle(rdClick('rd:p:AAAAAAAAAAAAAAAA',55,-1100)); rdCheck(str_contains(rdAnswer($api),'دیگر کار نمی‌کند'),'an unknown or old button is refused');
    $radio->setLive(-1100,false); $api->calls=[]; $bot->handle(rdClick('rd:p:'.$token,55,-1100)); rdCheck(str_contains(rdAnswer($api),'خودکار پخش می‌شود'),'when the stream is off the answer says it starts by itself');
    $api->calls=[]; $bot->handle(rdClick('rd:p:'.$token,55,55)); rdCheck(str_contains(rdAnswer($api),'«New Channel»')&&$radio->queued(-1005)===1,'in a private chat the song goes to the person\'s active radio');
    $api->calls=[]; $bot->handle(rdClick('rd:p:'.$token,66,66)); rdCheck(str_contains(rdAnswer($api),'راه‌اندازی نشده'),'in a private chat somebody without a radio is told so');
    $panelBot=new Zero\RadioPanel($bot);
    rdCheck($panelBot->available(-1100,66)&&!$panelBot->available(-2222,66)&&$panelBot->available(55,55)&&!$panelBot->available(66,66),'the play button shows in a group with a radio (to everybody) and in a private chat only for a person who runs one');
    rdCheck($panelBot->allowed(-1100,55)&&!$panelBot->allowed(-1100,66),'"play all" is for the group\'s administrators only');

    // 7. Controls in the group.
    $radio->setLive(-1100,true); $api->calls=[]; $bot->handle(rdText('/radio',55,-1100)); $status=$api->last('sendMessage');
    rdCheck(str_contains($status['text'],'رادیوی «The Group»')&&str_contains($status['text'],'🔴')&&rdActions($status)===['rd:k:-1100','rd:s:-1100','rd:x:-1100','rd:v:-1100','rd:z:-1100'],'/radio in a group shows the controls to an administrator');
    $api->calls=[]; $bot->handle(rdText('/radio',66,-1100)); rdCheck(str_contains($api->last('sendMessage')['text'],'فقط مدیرهای'),'a member only gets a short refusal');
    $api->calls=[]; $bot->handle(rdText('/radio',55,-3333)); rdCheck(str_contains($api->last('sendMessage')['text'],'رادیو ندارد'),'a group without a radio says so');
    $bot->handle(rdClick('rd:k:-1100',55,-1100)); rdCheck($radio->station(-1100)['command']==='skip','skip is passed to the player');
    $bot->handle(rdClick('rd:s:-1100',55,-1100)); rdCheck($radio->station(-1100)['command']==='stop','stop is passed to the player');
    $before=$radio->queued(-1100); $bot->handle(rdClick('rd:x:-1100',55,-1100)); rdCheck($before>0&&$radio->queued(-1100)===0,'clear empties the queue');
    $bot->handle(rdClick('rd:k:-1100',66,-1100)); rdCheck($radio->station(-1100)['command']==='stop','a member\'s press changes nothing');
    $bot->handle(rdClick('rd:k:-1001',55,-1100)); rdCheck($radio->station(-1001)['command']==='','a group\'s controls cannot steer another radio');
    $api->calls=[]; $bot->handle(rdClick('rd:z:-1100',66,-1100)); rdCheck($api->last('deleteMessage')===null,'only an administrator closes the controls in a group');
    $bot->handle(rdClick('rd:z:-1100',55,-1100)); rdCheck($api->last('deleteMessage')!==null,'an administrator closes them');

    // 8. Removing a radio.
    $api->calls=[]; $bot->handle(rdClick('rd:d:-1005',55,55)); rdCheck(str_contains($api->last('editMessageText')['text']??'','حذف شود')&&$radio->station(-1005)!==null,'removing asks first');
    $bot->handle(rdClick('rd:D:-1005',66,66)); rdCheck($radio->station(-1005)!==null,'a member cannot remove it');
    $bot->handle(rdClick('rd:D:-1005',55,55)); rdCheck($radio->station(-1005)===null&&!is_file($temp.'/radio/keys/-1005')&&$radio->queued(-1005)===0,'removing deletes the address file and the queue');

    // 8b. The two switches: the whole radio off, and "only the bot's administrators".
    $store->set('radio.enabled','0'); $api->calls=[];
    $bot->handle(rdClick('rd:p:'.$token,55,-1100)); rdCheck(str_contains(rdAnswer($api),'خاموش است')&&$radio->queued(-1100)===0,'with the radio off a play button answers that it is off and queues nothing');
    $panelOff=new Zero\RadioPanel($bot); rdCheck(!$panelOff->available(-1100,55)&&!$panelOff->allowed(-1100,55)&&$radio->stationsOf($api,55,true)===[],'with the radio off no song gets a play button and nobody runs a radio');
    $api->calls=[]; $bot->handle(rdText('/radio',55)); rdCheck(str_contains($api->last('sendMessage')['text'],'خاموش است'),'/radio in a private chat says it is off');
    $api->calls=[]; $bot->handle(rdText('/radio',55,-1100)); rdCheck(str_contains($api->last('sendMessage')['text'],'خاموش است'),'/radio in a group says it is off');
    $api->calls=[]; $bot->handle(rdClick('rd:k:-1100',55,-1100)); rdCheck(str_contains(rdAnswer($api),'خاموش است'),'the controls are off too');
    $store->set('radio.enabled','1');
    $store->set('radio.access','bot'); $api->calls=[]; $before=$radio->queued(-1100);
    $bot->handle(rdClick('rd:p:'.$token,55,-1100)); rdCheck(str_contains(rdAnswer($api),'فقط مدیرهای ربات')&&$radio->queued(-1100)===$before,'in bot-administrators-only mode a chat administrator who is not a bot administrator is refused');
    $bot->handle(rdClick('rd:p:'.$token,42,-1100)); rdCheck($radio->queued(-1100)===$before+1,'a bot administrator may queue in any radio');
    $panelBot=new Zero\RadioPanel($bot); rdCheck(!$panelBot->allowed(-1100,55)&&$panelBot->allowed(-1100,42),'"play all" follows the same rule');
    $api->calls=[]; $bot->handle(rdText('/radio',55,-1100)); rdCheck(str_contains($api->last('sendMessage')['text'],'فقط مدیرهای ربات'),'the group controls are refused to a chat administrator');
    $api->calls=[]; $bot->handle(rdText('/radio',55)); $panelText=$api->last('sendMessage'); rdCheck(!array_filter(rdActions($panelText),fn($data)=>str_starts_with($data,'rd:u:')||str_starts_with($data,'rd:v:')),'the private panel offers a chat administrator nothing to set up');
    $api->calls=[]; $bot->handle(rdText('/radio',42)); $panelText=$api->last('sendMessage'); rdCheck((bool)array_filter(rdActions($panelText),fn($data)=>str_starts_with($data,'rd:v:')),'but a bot administrator sees every radio');
    $store->set('radio.access','chat'); rdCheck($panelBot->allowed(-1100,55),'switching back gives the chat administrators their radio again');
    // The switches are on the features page, for bot administrators only.
    $api->calls=[]; $bot->handle(rdClick('custom:features',42,42)); $features=$api->last('editMessageText')??$api->last('sendMessage'); $labels=implode(' ',array_column(array_merge(...$features['reply_markup']['inline_keyboard']),'text'));
    rdCheck(str_contains($labels,'📻 رادیو: ✅')&&str_contains($labels,'فقط ادمین‌های ربات: ❌'),'the features page shows both switches with their state');
    $bot->handle(rdClick('custom:radio',42,42)); $bot->handle(rdClick('custom:radioaccess',42,42));
    rdCheck($store->get('radio.enabled')==='0'&&$store->get('radio.access')==='bot','the switches flip the settings');
    $bot->handle(rdClick('custom:radio',55,55)); rdCheck($store->get('radio.enabled')==='0','somebody who is not a bot administrator cannot flip them');
    $store->set('radio.enabled','1'); $store->set('radio.access','chat');

    // 9. Every text is the user's to reword; the controls obey the colour rule.
    foreach(['radio_panel','radio_status','radio_key_prompt','radio_added','radio_full'] as $key) rdCheck($bot->ui->customizable('text',$key)&&($bot->ui->definition('text',$key)['audience']??'')==='user',"editable text: $key");
    foreach(['radio_play','radio_play_all','radio_skip','radio_stop','radio_clear'] as $key) rdCheck($bot->ui->customizable('button',$key)&&($bot->ui->definition('button',$key)['audience']??'')==='user',"editable button: $key");
    rdCheck(($bot->ui->definition('button','radio_play_all')['style']??'')==='success'&&($bot->ui->definition('button','radio_stop')['style']??'')==='danger','green starts, red stops');
} finally { exec('rm -rf '.escapeshellarg($temp)); }
