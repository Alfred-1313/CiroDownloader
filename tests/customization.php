<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
final class SettingsApi implements Zero\Api {
    public array $calls=[]; public bool $rejectEmoji=false;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($this->rejectEmoji&&str_contains(json_encode($params),'icon_custom_emoji_id')) throw new RuntimeException('Telegram API error 400');
        return $method==='sendMessage'?['message_id'=>count($this->calls)]:true;
    }
}
function ensure(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function input(int $id,string $text): array { return ['update_id'=>random_int(1,1000000),'message'=>['from'=>['id'=>$id],'chat'=>['id'=>$id,'type'=>'private'],'text'=>$text]]; }
function click(int $id,string $data): array { return ['callback_query'=>['id'=>'fake','from'=>['id'=>$id],'message'=>['message_id'=>900,'chat'=>['id'=>$id,'type'=>'private']],'data'=>$data]]; }
$temp=sys_get_temp_dir().'/ciro-custom-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
try {
    $legacyDir=$temp.'/legacy'; mkdir($legacyDir,0700);
    $legacyDb=new PDO('sqlite:'.$legacyDir.'/zero.sqlite');
    $legacyDb->exec('CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL)');
    $legacyDb->prepare('INSERT INTO settings VALUES(?,?)')->execute(['text.video_caption','<channel> {title} & audio']);
    $legacyDb=null;
    $legacyStore=new Zero\Store($legacyDir);
    $converted=$legacyStore->get('text.video_caption');
    ensure($converted==='&lt;channel&gt; {title} &amp; audio','legacy plaintext captions are escaped without losing their placeholders');
    $legacyAgain=new Zero\Store($legacyDir);
    ensure($legacyAgain->get('text.video_caption')===$converted,'caption migration runs exactly once across restarts');
    Zero\TextFormat::validate((new Zero\Ui($legacyStore))->text('video_caption',['title'=>'Example']),1024);

    $store=new Zero\Store($temp); $api=new SettingsApi(); $config=new Zero\Config('123:fake-token',[42],$temp); $bot=new Zero\Bot($config,$store,$api);
    $bot->handle(input(7,'/start'));
    $keyboard=end($api->calls)[1]['reply_markup'];
    ensure(isset($keyboard['keyboard'])&&count($keyboard['keyboard'])===1&&count($keyboard['keyboard'][0])===1,'ordinary users get only Help on the reply keyboard');
    $bot->handle(input(42,'/admin'));
    $markup=json_encode(end($api->calls)[1]['reply_markup']);
    ensure(str_contains($markup,'custom:home')&&!str_contains($markup,'list:text'),'message and button editors live inside Personalization');
    $bot->handle(click(7,'custom:access'));
    ensure($store->get('admins_only','0')==='0','forged personalization callback cannot enable access restrictions');
    $bot->handle(click(42,'custom:edit:text:admin_only_notice'));
    $bot->handle(input(42,'<blockquote><b>PRIVATE_MESSAGE</b></blockquote>'));
    $bot->handle(click(42,'custom:access'));
    $bot->handle(input(7,'https://youtu.be/ybNMMK5Q318'));
    ensure(str_contains(end($api->calls)[1]['text'],'PRIVATE_MESSAGE')&&(int)$store->query('SELECT count(*) FROM jobs')->fetchColumn()===0,'admin-only mode uses its editable notice and blocks public jobs');
    $bot->handle(input(42,'https://youtu.be/ybNMMK5Q318'));
    ensure((int)$store->query('SELECT count(*) FROM jobs')->fetchColumn()===1,'administrators can request videos in admin-only mode');
    $store->query("UPDATE jobs SET status='done'");
    $bot->handle(click(42,'custom:access'));

    $bot->handle(click(42,'custom:setcolor:help:danger'));
    ensure($store->get('button.style.help')==='danger','button colors persist through the shared editor');
    $bot->handle(click(42,'custom:icon:help'));
    $iconInput=input(42,'😎');
    $iconInput['message']['entities']=[['type'=>'custom_emoji','offset'=>0,'length'=>2,'custom_emoji_id'=>'5368324170671202286']];
    $bot->handle($iconInput);
    $reopen=new Zero\Bot($config,new Zero\Store($temp),$api);
    $reopen->handle(input(7,'/start'));
    $help=end($api->calls)[1]['reply_markup']['keyboard'][0][0];
    ensure($help['style']==='danger'&&$help['icon_custom_emoji_id']==='5368324170671202286','reply keyboard uses saved color and premium emoji after reloading');
    $old=$store->get('button.icon.help'); $api->rejectEmoji=true;
    $bot->handle(click(42,'custom:icon:admin'));
    $bot->handle(input(42,'5368324170671202286'));
    ensure($store->get('button.icon.admin')===''&&$store->get('button.icon.help')===$old,'Telegram rejection preserves existing settings instead of breaking the interface');
    $api->rejectEmoji=false;

    $formatted=input(42,'X 😎');
    $formatted['message']['entities']=[['type'=>'bold','offset'=>0,'length'=>4],['type'=>'custom_emoji','offset'=>2,'length'=>2,'custom_emoji_id'=>'5368324170671202286']];
    $html=Zero\TextFormat::fromMessage($formatted['message']);
    ensure($html==='<b>X <tg-emoji emoji-id="5368324170671202286">😎</tg-emoji></b>','native Telegram bold and custom emoji preserve UTF-16 offsets');
    Zero\TextFormat::validate($html,700);
    $bot->handle(click(42,'custom:edit:text:video_caption'));
    $bot->handle($formatted);
    ensure($bot->text('video_caption')===$html,'captions preserve native premium emoji and bold formatting');
    $before=$bot->text('video_caption');
    $bot->handle(click(42,'custom:edit:text:video_caption'));
    $bot->handle(input(42,'<b>bad</i>'));
    ensure($bot->text('video_caption')===$before,'invalid HTML cannot replace a working caption');
    $store->query('DELETE FROM states');
    $store->set('text.help','<blockquote><b>CUSTOM_HELP</b></blockquote>');
    $bot->handle(input(7,$bot->label('help')));
    ensure(str_contains(end($api->calls)[1]['text'],'CUSTOM_HELP'),'reply Help uses the editable formatted message');
    $oldHelp=$bot->label('help');
    $bot->handle(click(42,'custom:edit:button:help'));
    $bot->handle(input(42,'راهنمای من'));
    $bot->handle(input(7,$oldHelp));
    ensure(str_contains(end($api->calls)[1]['text'],'CUSTOM_HELP'),'renaming reply buttons keeps already displayed keyboards usable');

    $info=['formats'=>[
        ['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac'],
        ['format_id'=>'135','width'=>854,'height'=>480,'vcodec'=>'avc1','acodec'=>'none'],
        ['format_id'=>'244','width'=>480,'height'=>854,'vcodec'=>'vp9','acodec'=>'none'],
        ['format_id'=>'136','width'=>1280,'height'=>720,'vcodec'=>'avc1','acodec'=>'none'],
        ['format_id'=>'160','width'=>256,'height'=>144,'vcodec'=>'avc1','acodec'=>'none'],
        ['format_id'=>'137','width'=>1920,'height'=>1080,'vcodec'=>'avc1','acodec'=>'none'],
        ['format_id'=>'248','width'=>1080,'height'=>1920,'vcodec'=>'vp9','acodec'=>'none'],
        ['format_id'=>'sb0','width'=>160,'height'=>90,'vcodec'=>'images','acodec'=>'none','protocol'=>'mhtml'],
        ['format_id'=>'drm','width'=>3840,'height'=>2160,'vcodec'=>'av1','acodec'=>'none','has_drm'=>true],
        ['format_id'=>'bad/selector','width'=>640,'height'=>480,'vcodec'=>'avc1','acodec'=>'none'],
    ]];
    $options=Zero\Download::qualityOptions($info);
    ensure(array_keys($options)===[360,480,720]&&count($options[480])===2,'only real 360p, 480p and 720p qualities are offered, deduplicated and portrait-aware');
    $partial=$info;
    $partial['formats']=array_values(array_filter($info['formats'],fn($format)=>min((int)($format['width']??0),(int)($format['height']??0))!==480));
    ensure(array_keys(Zero\Download::qualityOptions($partial))===[360,720],'missing 480p is omitted while the other available qualities remain');
    $audioOnly=['formats'=>[
        ['format_id'=>'137','width'=>1920,'height'=>1080,'vcodec'=>'avc1','acodec'=>'none'],
        ['format_id'=>'140','vcodec'=>'none','acodec'=>'aac'],
    ]];
    ensure(Zero\Download::qualityOptions($audioOnly)===[]&&Zero\Download::audioOptions($audioOnly)===[['id'=>'140']],'no unsupported or invented video quality appears when only audio is available within the policy');
    $bot->handle(input(9,'https://youtu.be/ybNMMK5Q318'));
    $job=$store->query('SELECT * FROM jobs WHERE user_id=9')->fetch();
    $store->query("UPDATE jobs SET status='running' WHERE id=?",[$job['id']]);
    $bot->qualityMenu($job,['title'=>'<title> & audio','options'=>$options]);
    $qualityRows=end($api->calls)[1]['reply_markup']['inline_keyboard'];
    ensure($qualityRows[0][0]['text']==='360p'&&$qualityRows[0][1]['text']==='480p'&&$qualityRows[1][0]['text']==='720p','quality buttons show the allowed choices in ascending order');
    ensure($store->choose((int)$job['id'],7,7,720)===null,'another user cannot select a quality for this job');
    $store->query('UPDATE jobs SET options=? WHERE id=?',[json_encode($options+[1080=>[['id'=>'137','audio'=>false]]]),$job['id']]);
    ensure($store->choose((int)$job['id'],9,9,1080)===null,'1080p choices from an older saved menu are also rejected');
    $store->query('UPDATE jobs SET options=? WHERE id=?',[json_encode(Zero\Download::qualityOptions($partial)),$job['id']]);
    ensure($store->choose((int)$job['id'],9,9,480)===null,'an unavailable allowed quality cannot be downloaded');
    $chosen=$store->choose((int)$job['id'],9,9,720);
    ensure($chosen!==null&&$store->choose((int)$job['id'],9,9,360)===null,'quality choice is atomic and repeat taps cannot enqueue a second download');
    $store->query("UPDATE jobs SET status='waiting_quality',expires=? WHERE id=?",[time()-1,$job['id']]);
    ensure($store->choose((int)$job['id'],9,9,360)!==null,'a past legacy deadline no longer prevents a quality choice');
    $store->query("UPDATE jobs SET status='choosing',expires=? WHERE id=?",[time()-86400,$job['id']]);
    $reloaded=new Zero\Store($temp);
    ensure((int)$reloaded->query('SELECT expires FROM jobs WHERE id=?',[$job['id']])->fetchColumn()===0,'saved menus lose their old deadline after a process restart');
    ensure($reloaded->choose((int)$job['id'],9,9,null)!==null,'legacy cancel callbacks remain safe after a process restart');
    $store->query("UPDATE jobs SET status='waiting_quality',expires=0 WHERE id=?",[$job['id']]);
    ensure($store->choose((int)$job['id'],9,9,'audio')===null,'old menus without audio formats cannot be forged into audio downloads');
    $store->query("UPDATE jobs SET status='cancelled' WHERE id=?",[$job['id']]);
    $audioOptions=Zero\Download::audioOptions($info);
    ensure($audioOptions===[['id'=>'18']],'audio choices exclude video-only formats, DRM and invalid identifiers');
    $bot->handle(input(10,'https://youtu.be/ybNMMK5Q318'));
    $audioJob=$store->query('SELECT * FROM jobs WHERE user_id=10')->fetch();
    $bot->qualityMenu($audioJob,['title'=>'Audio test','options'=>$options+['audio'=>$audioOptions]]);
    ensure($store->choose((int)$audioJob['id'],7,7,'audio')===null,'another user cannot request the audio of this job');
    $store->set('admins_only','1');
    $bot->handle(click(10,'quality:'.$audioJob['id'].':audio'));
    ensure($store->query('SELECT status FROM jobs WHERE id=?',[$audioJob['id']])->fetchColumn()==='waiting_quality','admin-only mode also blocks audio callbacks from existing public menus');
    $store->set('admins_only','0');
    $bot->handle(click(10,'quality:'.$audioJob['id'].':audio'));
    $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$audioJob['id']])->fetch();
    ensure($saved['phase']==='audio'&&$saved['quality']===null&&$saved['status']==='queued','audio selection queues an audio job without a fake video resolution');
    ensure($store->choose((int)$audioJob['id'],10,10,'audio')===null,'repeated audio taps cannot queue duplicate downloads');
    $bot->handle(click(42,'custom:edit:text:audio_caption'));
    $bot->handle(input(42,'<blockquote><b>{title}</b></blockquote> {format}'));
    $bot->handle(click(42,'custom:setcolor:extract_audio:danger'));
    ensure($store->get('button.style.extract_audio')==='danger'&&str_contains($bot->text('audio_caption',['title'=>'Test','format'=>'MP3']),'MP3'),'audio captions and button styles use the shared Personalization editor');
} finally { Zero\Download::clean($temp); }
