<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function scCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
final class StoryCookieApi implements Zero\FileApi {
    public array $calls=[]; public string $content=''; private int $id=400;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='getFile') return ['file_path'=>'fixture.txt','file_size'=>strlen($this->content)];
        if($method==='getMe') return ['id'=>123,'username'=>'FixtureBot'];
        return str_starts_with($method,'send')?['message_id'=>++$this->id]:true;
    }
    public function downloadFile(string $filePath,int $maxBytes): string { return $this->content; }
    public function last(string $method): array { foreach(array_reverse($this->calls) as [$name,$params]) if($name===$method) return $params; return []; }
}
$root=sys_get_temp_dir().'/ciro-story-cookies-'.bin2hex(random_bytes(5));mkdir($root,0700);mkdir($root.'/downloads',0700);
try {
    $config=new Zero\Config('123:fixture',[42],$root);$store=new Zero\Store($root);$api=new StoryCookieApi();$bot=new Zero\Bot($config,$store,$api);$worker=new Zero\Worker($config,$store,$api);$update=0;
    $cookie=fn(string $value)=>"# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\t".$value."\n";
    $post=$cookie('POST_FIXTURE');$story=$cookie('STORY_FIXTURE');Zero\Instagram::saveCookies($config,$post);
    $send=function(string $text,int $user=42) use($bot,&$update): void { $bot->handle(['update_id'=>++$update,'message'=>['message_id'=>$update,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]); };
    $tap=function(string $data,int $user=42) use($bot,&$update): void { $bot->handle(['update_id'=>++$update,'callback_query'=>['id'=>'sc'.$update,'from'=>['id'=>$user,'is_bot'=>false],'data'=>$data,'message'=>['message_id'=>500,'chat'=>['id'=>$user,'type'=>'private']]]]); };
    $send('/cookies');$labels=array_column(array_merge(...$api->last('sendMessage')['reply_markup']['keyboard']),'text');
    scCheck(in_array($bot->label('cookie_instagram_stories'),$labels,true),'the cookie menu has a separate Instagram stories entry');
    $send($bot->label('cookie_instagram_stories'),7);scCheck(!is_file($config->instagramStoriesCookiePath()),'ordinary users cannot import story cookies');
    $send($bot->label('cookie_instagram_stories'));$send($bot->label('igs_cookie_upload'));
    scCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='igs_cookie.upload','story import has an independent pending state');
    $api->content=$story;$bot->handle(['update_id'=>++$update,'message'=>['message_id'=>$update,'from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'document'=>['file_id'=>'fixture','file_name'=>'story.txt','file_size'=>strlen($story)]]]);
    scCheck(file_get_contents($config->instagramCookiePath())===$post&&file_get_contents($config->instagramStoriesCookiePath())===$story&&(fileperms($config->instagramStoriesCookiePath())&0777)===0600,'a document stores only a private story cookie file and leaves the post jar unchanged');
    scCheck(isset($api->last('deleteMessage')['message_id']),'the cookie-bearing message is removed');
    $send($bot->label('igs_cookie_back'));$send($bot->label('igs_cookie_view'));$view=$api->last('sendMessage');
    $buttons=array_merge(...$view['reply_markup']['inline_keyboard']);$export='';$test='';$label='';
    foreach($buttons as $button) {
        $data=$button['callback_data']??'';
        if(str_starts_with($data,'ckex:igs:')) $export=$data;
        if(str_starts_with($data,'ckts:igs:')) $test=$data;
        if(str_starts_with($data,'cklb:igs:')) $label=$data;
    }
    scCheck($export!==''&&$test!==''&&$label!==''&&!str_contains($view['text'],'STORY_FIXTURE'),'the story jar list exposes its own controls without cookie values');
    $tap($export);$doc=$api->last('sendDocument');scCheck(($doc['protect_content']??false)&&$doc['document'] instanceof CURLStringFile&&$doc['document']->data===$story,'private export contains only the requested story jar');
    $tap($test);scCheck(str_contains($api->last('editMessageText')['text'],$bot->ui->plain('cookie_test_structure_ok')),'the story check uses Instagram session validation, not an unknown media service');
    $tap($label);$send('نشست استوری');scCheck(Zero\InstagramStories::pool($config)->describe()[0]['label']==='نشست استوری'&&Zero\Instagram::pool($config)->describe()[0]['label']==='','story labels are independent of post labels');
    $send($bot->label('cookie_instagram_stories'));$send($bot->label('igs_cookie_upload'));$fresh=$story.".instagram.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tFRESH_FIXTURE\n";
    $store->set('cookie.parts.42',json_encode(['text'=>'[{"domain":".instagram.com","name":"sessionid","value":"POST_PART"}','time'=>time(),'parts'=>1,'scope'=>'ig_cookie.upload']));
    $send($fresh);
    scCheck(file_get_contents($config->instagramStoriesCookiePath())===$fresh&&file_get_contents($config->instagramCookiePath())===$post,'pasted cookies also go to the independent story pool');
    $pools=(new Zero\CookieHealth($config,$store,$api))->pools();scCheck(isset($pools['ig'],$pools['igs']),'health checks keep both pools separate');
    $pools['ig']->markFailed('main');scCheck(!$pools['igs']->describe()[0]['failed'],'post failures do not mark a story jar as failed');
    file_put_contents($config->instagramCookiePath(),str_replace("\t0\t","\t1\t",$post));$pools['ig']->pruneExpired();
    scCheck(!is_file($config->instagramCookiePath())&&file_get_contents($config->instagramStoriesCookiePath())===$fresh,'expiry cleanup of post cookies leaves the story session intact');
    Zero\Instagram::saveCookies($config,$post);$send($bot->label('igs_cookie_back'));$send($bot->label('igs_cookie_remove'));
    Zero\InstagramStories::saveCookies($config,$story);$send($bot->label('igs_cookie_confirm'));scCheck(is_file($config->instagramStoriesCookiePath()),'a stale story deletion confirmation cannot delete a refreshed session');
    $send($bot->label('igs_cookie_remove'));$send($bot->label('igs_cookie_confirm'));
    scCheck(!is_file($config->instagramStoriesCookiePath())&&file_get_contents($config->instagramCookiePath())===$post,'deleting all story cookies preserves post cookies');
    $store->set('instagram.user_search','1');$send('@eliyas_sport',7);$send('/user_instagram eliyas_sport',7);
    scCheck(!(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn(),'bare IDs and the removed profile command cannot queue work even with a saved old switch');
    $tap('iu:1:0:s',7);scCheck(str_contains($api->last('answerCallbackQuery')['text'],'دیگر فعال نیست'),'old profile/story buttons are retired without queueing another request');
    $store->query('INSERT OR IGNORE INTO users(id) VALUES(7)');$store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,created,message_id,phase,options) VALUES(777,7,7,'https://www.instagram.com/fixture/',?,500,'instagram_users','{}')",[time()]);
    $legacy=$store->claim('media');$worker->process($legacy);scCheck($store->query('SELECT status FROM jobs WHERE id=?',[$legacy['id']])->fetchColumn()==='cancelled','queued profile work is cancelled safely after upgrading');
    $commands=array_column(Zero\AdminCommands::menu($bot->ui),'command');scCheck(!in_array('user_instagram',$commands,true)&&in_array('insta',$commands,true),'the command menu retains stories and removes profiles');
    $store->set('commands.public','old-profile-menu');$store->set('commands.public_tried',(string)time());Zero\MusicSearch::register($api,$store,$bot->ui);
    $registered=array_column($api->last('setMyCommands')['commands']??[],'command');
    scCheck(!in_array('user_instagram',$registered,true)&&in_array('insta',$registered,true),'a changed public command menu refreshes immediately after profile removal');
    $store->set('instagram.stories','1');$store->query('UPDATE users SET last_request=0 WHERE id=7');$send('/insta rahkar_mobile',7);
    $insta=$store->query("SELECT url,phase FROM jobs WHERE status='queued' ORDER BY id DESC LIMIT 1")->fetch();
    scCheck($insta&&$insta['url']==='https://www.instagram.com/stories/rahkar_mobile/'&&$insta['phase']==='instagram','/insta username enters the existing story pipeline');
    scCheck(Zero\InstagramStories::parse('/insta@FixtureBot @rahkar_mobile')===['bot'=>'FixtureBot','argument'=>'@rahkar_mobile'],'the new command parses addressed bots and @usernames');
    scCheck(!array_filter(array_keys($bot->catalog('text')),fn($key)=>str_starts_with($key,'instagram_user_')),'no profile field remains in Personalization');
} finally { Zero\Download::clean($root); }
