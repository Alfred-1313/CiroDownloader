<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class VideoApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed { $this->calls[]=[$method,$params]; return $method==='sendMessage'?['message_id'=>500+count($this->calls)]:true; }
    public function sent(): array { return array_values(array_filter($this->calls,fn($call)=>$call[0]==='sendMessage')); }
}
function vcCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-videochat-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
$log=$temp.'/php-errors.log'; ini_set('log_errors','1'); ini_set('error_log',$log);
try {
    $config=new Zero\Config('123:fake',[42],$temp); $store=new Zero\Store($temp); $api=new VideoApi(); $bot=new Zero\Bot($config,$store,$api);
    $store->query('INSERT INTO users(id) VALUES(42),(77),(9)'); $store->addAdministrator(77,42,[42]);
    $serial=0; $channelPost=function(array $extra) use (&$serial) { return ['update_id'=>++$serial,'channel_post'=>['message_id'=>$serial,'chat'=>['id'=>-1001,'type'=>'channel','title'=>'Radio <Test>'],'date'=>time()]+$extra]; };
    $groupMessage=function(int $from,array $extra) use (&$serial) { return ['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$from,'is_bot'=>false],'chat'=>['id'=>-1002,'type'=>'supergroup','title'=>'Party'],'date'=>time()]+$extra]; };

    // A channel's live stream starts: every administrator hears about it, the title can never become markup.
    $bot->handle($channelPost(['video_chat_started'=>[]])); $sent=$api->sent();
    vcCheck(count($sent)===2&&array_column(array_column($sent,1),'chat_id')===[42,77]&&str_contains($sent[0][1]['text'],'🎙 گفت‌وگوی ویدیویی شروع شد')&&str_contains($sent[0][1]['text'],'Radio &lt;Test&gt;'),'a live stream starting in a channel is reported to the administrators, the configured one and those they added');
    $api->calls=[]; $bot->handle($channelPost(['video_chat_ended'=>['duration'=>65]])); $sent=$api->sent();
    vcCheck(count($sent)===2&&str_contains($sent[0][1]['text'],'🔚 گفت‌وگوی ویدیویی تمام شد')&&str_contains($sent[0][1]['text'],'01:05'),'its end is reported with the length');
    // Other channel posts are not read at all.
    $api->calls=[]; $bot->handle($channelPost(['text'=>'hello'])); vcCheck(!$api->calls,'an ordinary channel post sends nothing');
    $logged=(string)@file_get_contents($log);
    vcCheck(substr_count($logged,'Channel update:')===1&&!str_contains($logged,'hello'),'channel updates are logged by field name only, at most every ten minutes');
    // Groups: only when a bot administrator started the video chat (the bot may sit in groups of strangers).
    $api->calls=[]; $bot->handle($groupMessage(42,['video_chat_started'=>[]])); vcCheck(count($api->sent())===2&&str_contains($api->sent()[0][1]['text'],'Party'),'an administrator starting a video chat in a group is reported');
    $api->calls=[]; $bot->handle($groupMessage(9,['video_chat_started'=>[]])); vcCheck(!$api->sent(),'a stranger starting one in a group is not');
    $api->calls=[]; $bot->handle($groupMessage(9,['video_chat_ended'=>['duration'=>5]])); vcCheck(!$api->sent(),'nor is its end');
    // An administrator who stays anonymous starts it "as the group": the group may be set up as a radio, and once it is one the administrators hear about it.
    $anonymous=function(array $extra) use (&$serial) { return ['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>1087968824,'is_bot'=>true,'first_name'=>'Group'],'sender_chat'=>['id'=>-1002,'type'=>'supergroup','title'=>'Party'],'chat'=>['id'=>-1002,'type'=>'supergroup','title'=>'Party'],'date'=>time()]+$extra]; };
    $api->calls=[]; $bot->handle($anonymous(['video_chat_started'=>[]])); $radio=new Zero\Radio($config,$store);
    vcCheck(in_array(-1002,array_map('intval',array_column($radio->candidates(),'chat_id')),true)&&count($api->sent())===2,'a stream started anonymously is reported to the administrators and makes the group a radio candidate');
    $radio->save(-1002,'Party','group',42,'rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf'); $api->calls=[];
    $bot->handle($anonymous(['video_chat_started'=>[]])); vcCheck(count($api->sent())===2&&(int)$radio->station(-1002)['live']===1,'once it is a radio, an anonymous start is reported and makes the radio live');
    $bot->handle($anonymous(['video_chat_ended'=>['duration'=>3]])); vcCheck((int)$radio->station(-1002)['live']===0,'and an anonymous end stops it');
    // Everything else is handled as before.
    vcCheck(Zero\VideoChats::handle($bot,$groupMessage(9,['text'=>'hello']))===false&&Zero\VideoChats::handle($bot,['update_id'=>3,'callback_query'=>['id'=>'x']])===false,'ordinary group messages and other updates are left to the normal handlers');
    $api->calls=[]; $bot->handle(['update_id'=>4,'message'=>['message_id'=>8,'from'=>['id'=>42,'is_bot'=>false],'chat'=>['id'=>42,'type'=>'private'],'text'=>'/id']]);
    vcCheck(count($api->sent())===1,'a private message still works');
    // The poller asks for channel posts; the texts are administrator texts and never reach Personalization.
    vcCheck(str_contains((string)file_get_contents(dirname(__DIR__).'/bin/bot.php'),"'inline_query','channel_post'"),'the poller asks Telegram for channel posts');
    foreach(['video_chat_started','video_chat_ended'] as $key) vcCheck(($bot->ui->definition('text',$key)['audience']??'')==='admin'&&!$bot->ui->customizable('text',$key),"administrator text, not editable: $key");
    vcCheck($store->administratorIds([42])===[42,77]&&$store->administratorIds([])===[],'the administrators are the configured ones and those added by them');
} finally { exec('rm -rf '.escapeshellarg($temp)); }
