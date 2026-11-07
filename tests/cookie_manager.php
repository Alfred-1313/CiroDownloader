<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
final class ManagerCookieApi implements Zero\Api {
    public array $calls=[]; public array $exports=[]; public bool $failExport=false;
    public function call(string $method,array $params=[]): mixed {
        if(str_starts_with($method,'edit')&&isset($params['reply_markup']['keyboard'])) throw new RuntimeException('Reply keyboards cannot be edited');
        $this->calls[]=[$method,$params];
        if($method==='sendDocument') {
            if($this->failExport) throw new RuntimeException('Upload rejected');
            $document=$params['document'];
            $this->exports[]=['hash'=>hash('sha256',$document->data),'name'=>$document->postname,'chat'=>$params['chat_id'],'protected'=>$params['protect_content']??false];
        }
        return ['message_id'=>100+count($this->calls)];
    }
}
function managerCookieCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function cookieMessage(string $text,int $user=42,int $chat=42): array { return ['message'=>['from'=>['id'=>$user],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'text'=>$text]]; }
function cookieLabels(ManagerCookieApi $api): array { return array_column(array_merge(...(end($api->calls)[1]['reply_markup']['keyboard']??[[]])),'text'); }
$temp=sys_get_temp_dir().'/ciro-cookie-manager-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $api=new ManagerCookieApi(); $bot=new Zero\Bot($config,$store,$api);
    $old="# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_SESSION_A\n#HttpOnly_.youtube.com\tTRUE\t/\tTRUE\t1\tOLD\tFAKE_EXPIRED\n";
    $new=str_replace('FAKE_SESSION_A','FAKE_SESSION_B',$old); Zero\saveYouTubeCookies($config,$old);
    $click=function(string $key) use($bot): void { $bot->handle(cookieMessage($bot->label($key))); };
    $bot->handle(cookieMessage('/cookies'));
    managerCookieCheck(cookieLabels($api)===[$bot->label('cookie_youtube'),$bot->label('cookie_instagram'),$bot->label('cookie_pinterest'),$bot->label('cookie_tiktok'),$bot->label('cookie_twitter'),$bot->label('cookie_mode'),$bot->label('cookie_guide'),$bot->label('cookie_back_admin')],'cookie import opens independent service selectors with YouTube first and the cookie-mode switch');
    $click('cookie_youtube');
    managerCookieCheck(cookieLabels($api)===array_map(fn($key)=>$bot->label($key),['cookie_upload','cookie_view','cookie_remove','cookie_back_services']),'YouTube has independent import, view, delete and Back reply controls');
    $click('cookie_upload');
    managerCookieCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='cookie.upload'&&!isset(end($api->calls)[1]['reply_markup']),'import waits with an editable message and retains the YouTube reply keyboard');
    $click('cookie_back_youtube');
    managerCookieCheck(!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'Back to YouTube cancels the upload session');
    $click('cookie_view');
    $details=end($api->calls)[1]['text']; $inline=end($api->calls)[1]['reply_markup']['inline_keyboard']??[];
    managerCookieCheck(str_contains($details,'1</b> از 10')&&str_contains($details,'2 منقضی')===false&&str_contains($details,'1 منقضی')&&!str_contains($details,'FAKE_'),'view lists each jar with counts but never puts cookie values in message text');
    managerCookieCheck(count($inline)===1&&count($inline[0])===2&&str_starts_with($inline[0][0]['callback_data'],'ckex:yt:main:')&&str_starts_with($inline[0][1]['callback_data'],'ckrm:yt:main:'),'view offers per-jar export and removal as inline buttons');
    $click('cookie_view'); $exportQuery=['callback_query'=>['id'=>'q','from'=>['id'=>42],'message'=>['message_id'=>500,'chat'=>['id'=>42,'type'=>'private']],'data'=>$inline[0][0]['callback_data']]];
    $bot->handle($exportQuery); $export=end($api->exports);
    managerCookieCheck($export['chat']===42&&$export['hash']===hash('sha256',$old)&&$export['protected']&&$export['name']==='youtube-cookies.txt','explicit export delivers an exact private protected snapshot only to the requesting administrator');
    managerCookieCheck(glob($temp.'/.cookie-export-*')===[]&&file_get_contents($config->cookiePath())===$old,'export uses an in-memory snapshot and preserves the active cookie file');
    $api->failExport=true; $bot->handle($exportQuery); $api->failExport=false;
    managerCookieCheck(glob($temp.'/.cookie-export-*')===[]&&file_get_contents($config->cookiePath())===$old,'export failure preserves the active jar and leaves no temporary credential file');
    $forged=$exportQuery; $forged['callback_query']['from']['id']=7; $forged['callback_query']['message']['chat']['id']=7; $before=count($api->exports); $bot->handle($forged);
    managerCookieCheck(count($api->exports)===$before,'an ordinary user cannot export a jar through a forged inline callback');
    foreach(Zero\Cookies::REPLIES as $key) {
        managerCookieCheck(!$bot->ui->customizable('button',$key),'cookie administration stays outside Personalization: '.$key);
        $bot->handle(cookieMessage($bot->label($key),7,7));
        $bot->handle(cookieMessage($bot->label($key),42,-100));
    }
    managerCookieCheck(count($api->exports)===1&&file_get_contents($config->cookiePath())===$old&&!$store->query('SELECT 1 FROM states WHERE user_id=7')->fetchColumn(),'ordinary users and groups cannot export, remove or import cookies through forged reply labels');
    $click('cookie_remove'); $state=$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn();
    managerCookieCheck(str_starts_with($state,'cookie.delete:')&&is_file($config->cookiePath()),'delete first opens an explicit confirmation without changing the file');
    $click('cookie_back_youtube'); $click('cookie_delete_confirm');
    managerCookieCheck(is_file($config->cookiePath()),'cancelling deletion makes a stale confirmation harmless');
    $click('cookie_remove'); $store->query('UPDATE states SET expires=? WHERE user_id=42',[time()-1]); $click('cookie_delete_confirm');
    managerCookieCheck(is_file($config->cookiePath()),'expired confirmation cannot delete the cookie file');
    $click('cookie_remove'); Zero\saveYouTubeCookies($config,$new); $click('cookie_delete_confirm');
    managerCookieCheck(file_get_contents($config->cookiePath())===$new&&str_contains(end($api->calls)[1]['text'],'تغییر کرده'),'a confirmation for an older jar cannot remove a newer administrator import');
    $click('cookie_remove'); $click('cookie_delete_confirm');
    managerCookieCheck(!is_file($config->cookiePath())&&str_contains(end($api->calls)[1]['text'],'حذف شد'),'confirmed deletion removes the current cookie file and reports success');
    managerCookieCheck(!Zero\saveYouTubeCookies($config,$new,hash('sha256',$new))&&!is_file($config->cookiePath()),'a late refresh from an in-flight downloader cannot resurrect a deleted cookie jar');
    $click('cookie_view'); $click('cookie_export');
    managerCookieCheck(count($api->exports)===1&&str_contains(end($api->calls)[1]['text'],'هنوز کوکی'),'view and export handle a missing file without sending an empty document');
    $click('cookie_back_services'); $click('cookie_back_admin');
    managerCookieCheck(in_array($bot->label('customize'),cookieLabels($api),true),'Back returns through service selection to the administration keyboard');
    $bot->handle(cookieMessage('🍪 ایمپورت کوکی یوتیوب'));
    managerCookieCheck(cookieLabels($api)[0]===$bot->label('cookie_youtube'),'the previous reply label still opens the renamed service selector');
    $store->set('button.cookies','🍪 ایمپورت کوکی یوتیوب'); $store->query("DELETE FROM settings WHERE key='ui_cookie_panel_v1'");
    $reopened=new Zero\Bot($config,new Zero\Store($temp),$api);
    managerCookieCheck($reopened->label('cookies')==='🍪 ایمپورت کوکی','upgrade replaces the known historical menu title');
    $store->set('enabled','0'); $click('cookies'); $click('cookie_youtube');
    managerCookieCheck(in_array($bot->label('cookie_upload'),cookieLabels($api),true),'the administrator can manage cookies while the public bot is stopped');
} finally { Zero\Download::clean($temp); }
