<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function privacyCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class PrivacyApi implements Zero\Api {
    public array $calls=[];
    public string $deletion='ok';
    public ?Closure $duringDelete=null;
    public mixed $claimedDuringDelete=null;
    private int $next=800;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='deleteMessage') {
            if($this->duringDelete) $this->claimedDuringDelete=($this->duringDelete)();
            if($this->deletion==='exception') throw new RuntimeException('Telegram API error 403',403);
            if($this->deletion==='timeout') throw new RuntimeException('Telegram transport error 28');
            return $this->deletion==='ok';
        }
        if(str_starts_with($method,'send')) return ['message_id'=>++$this->next];
        return true;
    }
    public function called(string $method): array { return array_values(array_filter($this->calls,fn($call)=>$call[0]===$method)); }
}
$root=sys_get_temp_dir().'/ciro-adult-privacy-'.bin2hex(random_bytes(5)); mkdir($root,0700); $serial=0;
$new=function() use($root,&$serial): array {
    $config=new Zero\Config('123:fixture',[42],$root.'/case-'.++$serial); $store=new Zero\Store($config->storage);
    $api=new PrivacyApi(); $bot=new Zero\Bot($config,$store,$api); $observer=new Zero\Store($config->storage);
    $api->duringDelete=fn()=>$observer->claim();
    (new Zero\GroupSupport($bot))->cacheIdentity(['id'=>123,'username'=>'FixtureBot']);
    return [$bot,$store,$api,$config];
};
$send=function(Zero\Bot $bot,string $link,int $user=42,int $chat=42,int $update=1,int $source=501): void {
    $bot->handle(['update_id'=>$update,'message'=>['message_id'=>$source,'from'=>['id'=>$user,'is_bot'=>false],
        'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0,'text'=>$link]]);
};
$click=function(Zero\Bot $bot,string $data,int $user=42): void {
    $bot->handle(['update_id'=>99,'callback_query'=>['id'=>'privacy','from'=>['id'=>$user,'is_bot'=>false],
        'message'=>['message_id'=>700,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]);
};
$links=['pornhub'=>'https://www.pornhub.com/view_video.php?viewkey=ph5f8a9b2c3d4e5','beeg'=>'https://beeg.com/-0123456789012345',
    'xnxx'=>'https://www.xnxx.com/video-55awb78/fixture_video','xvideos'=>'https://www.xvideos.com/video.abc123def4/fixture_video',
    'tikporn'=>'https://tik.porn/video/2344693','youporn'=>'https://www.youporn.com/watch/189780301/',
    'eporner'=>'https://www.eporner.com/video-HmstDuT4BNE/'];
try {
    privacyCheck(array_keys($links)===Zero\adultServices(),'privacy cases cover every registered adult service');
    foreach($links as $site=>$link) foreach([[42,42],[7,-100123]] as [$user,$chat]) {
        [$bot,$store,$api]=$new(); $store->set('adult.'.$site,'1'); if($user===7) $store->set('adult.admins_only','0');
        privacyCheck($bot->adultDeleteAfter(42)===10&&$bot->adultDeleteAfter(-100123)===10,$site.': sent files default to deletion after ten seconds in private and group chats');
        $send($bot,$link,$user,$chat);
        $deleted=$api->called('deleteMessage'); $messages=$api->called('sendMessage');
        privacyCheck(count($deleted)===1&&$deleted[0][1]===['chat_id'=>$chat,'message_id'=>501],$site.': the original human link message is deleted in its own chat');
        $methods=array_column($api->calls,0);
        privacyCheck($api->claimedDuringDelete===null&&array_search('deleteMessage',$methods,true)<array_search('sendMessage',$methods,true),$site.': an independent worker cannot claim the request before link deletion is confirmed');
        $job=$store->claim('inspect');
        privacyCheck($job!==null&&count($messages)===1&&(int)$messages[0][1]['chat_id']===$chat&&(int)$job['user_id']===$user&&(int)$job['chat_id']===$chat&&(int)$job['thread_id']===($chat<0?321:0)
            &&$job['url']===Zero\adultUrl($link)&&(int)$job['message_id']===801,$site.': processing starts after deletion with the original owner, URL, status and topic');
        privacyCheck(!$api->called('setMessageReaction'),$site.': no reaction is attempted on the deleted message');
        $send($bot,$link,$user,$chat);
        privacyCheck(count($api->called('deleteMessage'))===1,$site.': duplicate updates do not delete or enqueue again');
    }
    foreach(['exception','timeout','false'] as $failure) {
        [$bot,$store,$api]=$new(); $store->set('adult.beeg','1'); $api->deletion=$failure;
        $store->set('text.adult_link_delete_failed','CUSTOM deletion guidance');
        $send($bot,$links['beeg'],42,-100123);
        $row=$store->query('SELECT * FROM jobs')->fetch(); $sent=$api->called('sendMessage');
        privacyCheck($row['status']==='failed'&&$store->claim()===null&&!$api->called('setMessageReaction'),$failure.': missing permission or an uncertain deletion never starts processing');
        privacyCheck(count($sent)===1&&$sent[0][1]['text']==='CUSTOM deletion guidance'&&(int)$sent[0][1]['message_thread_id']===321,$failure.': editable deletion guidance stays in the original topic');
    }
    [$bot,$store,$api,$config]=$new();
    privacyCheck($bot->ui->customizable('text','adult_link_delete_failed'),'the deletion failure text is available in Personalization');
    $click($bot,'custom:adultpage'); $edit=$api->called('editMessageText'); $rows=end($edit)[1]['reply_markup']['inline_keyboard'];
    $button=array_values(array_filter(array_merge(...$rows),fn($button)=>($button['callback_data']??'')==='custom:adultdelsource'))[0]??[];
    privacyCheck(($button['style']??'')==='primary'&&str_contains($button['text']??'','✅'),'the adult page shows a blue source-deletion switch enabled by default');
    $click($bot,'custom:adultdelsource',7); privacyCheck($store->get('adult.delete_source','1')==='1','ordinary users cannot disable source deletion');
    $click($bot,'custom:adultdelsource'); $store->set('adult.beeg','1'); $send($bot,$links['beeg']);
    privacyCheck(!$api->called('deleteMessage')&&(bool)$api->called('setMessageReaction')&&$store->claim('inspect')!==null,'an administrator can independently disable source deletion and retain normal link reactions');
    $store->set('adult.delete_private','0'); $store->set('adult.delete_group','1'); $store->set('adult.delete_seconds','180');
    $reopened=new Zero\Store($config->storage); $restored=new Zero\Bot($config,$reopened,$api);
    privacyCheck($restored->adultDeleteAfter(42)===0&&$restored->adultDeleteAfter(-100123)===180&&$reopened->get('adult.delete_source')==='0','saved choices survive restart independently of the default ten-second policy');
    [$bot,$store,$api]=$new(); $send($bot,'https://youtu.be/ybNMMK5Q318');
    privacyCheck(!$api->called('deleteMessage')&&(bool)$api->called('setMessageReaction'),'ordinary YouTube links and their reactions are unchanged');
    [$bot,$store,$api]=$new(); $send($bot,$links['beeg']);
    privacyCheck(!$api->called('deleteMessage')&&$store->claim()===null,'a disabled adult service does not delete the source');
    [$bot,$store,$api]=$new(); $store->set('adult.beeg','1'); $send($bot,$links['beeg'],7,7);
    privacyCheck(!$api->called('deleteMessage')&&$store->claim()===null,'an unauthorized sender does not have their link deleted');
    [$bot,$store,$api]=$new(); $store->set('adult.beeg','1'); $store->set('groups_enabled','0'); $send($bot,$links['beeg'],42,-100123);
    privacyCheck(!$api->called('deleteMessage')&&$store->claim()===null,'disabled group use preserves the unaccepted source message');
    [$bot,$store,$api]=$new(); $store->set('adult.beeg','1'); $store->set('processing_limit.private','1');
    $send($bot,$links['beeg']); $send($bot,$links['beeg'],42,42,2,502);
    privacyCheck(count($api->called('deleteMessage'))===1&&(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===1,'a busy request is refused without deleting the unaccepted link');
    [$bot,$store,$api]=$new(); $store->set('adult.beeg','1');
    for($i=1;$i<=20;$i++) $bot->requestVideo($i,42,42,$links['beeg'],0,0,null,[],true);
    $send($bot,$links['beeg'],42,42,21);
    privacyCheck(!$api->called('deleteMessage')&&(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===20,'a full queue preserves the unaccepted source message');
    [$bot,$store,$api]=$new(); $store->set('adult.beeg','1');
    privacyCheck($bot->requestVideo(1,42,42,$links['beeg'])==='queued'&&!$api->called('deleteMessage'),'internal choices with no human source message continue without deleting a bot message');
} finally { Zero\Download::clean($root); }
