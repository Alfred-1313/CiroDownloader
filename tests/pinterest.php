<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function pinCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
final class PinterestCookieApi implements Zero\FileApi {
    public array $calls=[]; public string $content='';
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        return $method==='getFile'?['file_path'=>'test/export.txt','file_size'=>strlen($this->content)]:['message_id'=>700];
    }
    public function downloadFile(string $filePath,int $maxBytes): string { return $this->content; }
}
$temp=sys_get_temp_dir().'/ciro-pinterest-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    foreach(['https://www.pinterest.com/pin/123456789/?tracking=1','pinterest.com/pin/123456789/','http://pinterest.co.uk/pin/title--123456789/','https://br.pinterest.com/pin/123456789/','https://www.pinterest.com/pin/123456789/sent/?invite_code=fixture','https://pinterest.co.uk/pin/title--123456789/sent/'] as $url) pinCheck(Zero\pinterestUrl($url)==='https://www.pinterest.com/pin/123456789/','pin and shared-pin URLs normalize to a single canonical pin');
    pinCheck(Zero\pinterestUrl('https://pin.it/AbC123?tracking=1')==='https://pin.it/AbC123','short links preserve their identifier without tracking');
    foreach(['https://pinterest.com.evil/pin/123/','https://pinterest.com@evil/pin/123/','https://www.pinterest.com:443/pin/123/','https://www.pinterest.com/user/board/','https://www.pinterest.com/pin/../../etc/passwd','https://pin.it/abc/extra','https://www.pinterest.com/pin/123/ extra','file:///pin/123/'] as $url) pinCheck(Zero\pinterestUrl($url)===null,'boards and unrelated or unsafe Pinterest URLs are rejected');
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $api=new PinterestCookieApi(); $bot=new Zero\Bot($config,$store,$api);
    $cookies="# Netscape HTTP Cookie File\n#HttpOnly_.pinterest.com\tTRUE\t/\tTRUE\t2147483647\t_pinterest_sess\tFAKE_INSTAGRAM_SESSION\n.pinterest.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tFAKE_CSRF\n";
    $youtube="# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_YOUTUBE_SESSION\n";
    $instagram="# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t2147483647\tsessionid\tFAKE_INSTAGRAM_SESSION\n";
    Zero\Instagram::saveCookies($config,$instagram);
    Zero\saveYouTubeCookies($config,$youtube); Zero\Pinterest::saveCookies($config,$cookies);
    pinCheck(file_get_contents($config->cookiePath())===$youtube&&file_get_contents($config->pinterestCookiePath())===$cookies&&(fileperms($config->pinterestCookiePath())&0777)===0600,'Pinterest and YouTube cookies are private independent files');
    foreach([$youtube,$instagram,str_replace('2147483647','1',$cookies),str_replace('.pinterest.com','.example.com',$cookies)] as $bad) {
        $rejected=false; try { Zero\Pinterest::saveCookies($config,$bad); } catch(RuntimeException) { $rejected=true; }
        pinCheck($rejected&&file_get_contents($config->pinterestCookiePath())===$cookies,'foreign or expired cookie imports preserve the previous Pinterest session');
    }
    $send=function(string $text,int $user=42) use($bot): void { $bot->handle(['message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]); };
    $click=fn(string $key)=>$send($bot->label($key));
    $click('cookie_pinterest');
    $keys=array_column(array_merge(...end($api->calls)[1]['reply_markup']['keyboard']),'text');
    pinCheck(in_array($bot->label('pin_cookie_upload'),$keys,true)&&in_array($bot->label('pin_cookie_view'),$keys,true),'Pinterest has a private reply-keyboard cookie panel');
    $click('pin_cookie_view');
    pinCheck(!str_contains(end($api->calls)[1]['text'],'FAKE_')&&str_contains(end($api->calls)[1]['text'],'2'),'cookie view shows counts without session values');
    $exportData=end($api->calls)[1]['reply_markup']['inline_keyboard'][0][0]['callback_data']; $bot->handle(['callback_query'=>['id'=>'export','from'=>['id'=>42],'message'=>['message_id'=>800,'chat'=>['id'=>42,'type'=>'private']],'data'=>$exportData]]);
    pinCheck((($docs=array_values(array_filter($api->calls,fn($call)=>$call[0]==='sendDocument')))&&end($docs)[1]['protect_content']===true&&end($docs)[1]['document'] instanceof CURLStringFile),'only the private administrator receives a protected in-memory cookie export');
    $before=count($api->calls); $send($bot->label('pin_cookie_export'),7);
    pinCheck(count(array_filter(array_slice($api->calls,$before),fn($call)=>$call[0]==='sendDocument'))===0,'ordinary users cannot export the Pinterest jar');
    $click('pin_cookie_upload');
    pinCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='pin_cookie.upload'&&!isset(end($api->calls)[1]['reply_markup']),'Pinterest import keeps a persistent editable status and the service keyboard');
    $api->content=$cookies;
    $bot->handle(['message'=>['message_id'=>701,'from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'document'=>['file_id'=>'fixture','file_name'=>'any-name.txt','file_size'=>strlen($cookies)]]]);
    pinCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='pin_cookie.upload'&&file_get_contents($config->cookiePath())===$youtube,'a valid document saves only Pinterest cookies and keeps the upload open for more files');
    $click('pin_cookie_remove'); $new=str_replace('FAKE_CSRF','FAKE_FRESH_CSRF',$cookies); Zero\Pinterest::saveCookies($config,$new); $click('pin_cookie_confirm');
    pinCheck(file_get_contents($config->pinterestCookiePath())===$new,'a stale deletion confirmation cannot delete a newer Pinterest import');
    $click('pin_cookie_remove'); $click('pin_cookie_back');
    pinCheck(is_file($config->pinterestCookiePath())&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'Back cancels Pinterest cookie deletion');
    $click('pin_cookie_remove'); $click('pin_cookie_confirm');
    pinCheck(!is_file($config->pinterestCookiePath())&&file_get_contents($config->cookiePath())===$youtube&&file_get_contents($config->instagramCookiePath())===$instagram,'confirmed Pinterest deletion preserves YouTube cookies');
    $custom=new Zero\Personalization($bot); $custom->home(42);
    $rows=end($api->calls)[1]['reply_markup']['inline_keyboard']; $header=false; $control=false;
    foreach($rows as $row) foreach($row as $button) {
        if(($button['callback_data']??'')==='custom:hint:hub_downloaders') $header=!isset($button['style']);
        if(($button['callback_data']??'')==='custom:service:pinterest') $control=true;
    }
    pinCheck($header&&$control,'Personalization lists Pinterest under a white downloaders title');
    $toggle=fn(int $user=42)=>$bot->handle(['callback_query'=>['id'=>'pin-source','from'=>['id'=>$user],'message'=>['message_id'=>700,'chat'=>['id'=>$user,'type'=>'private']],'data'=>'custom:pinsource']]);
    $toggle(7);
    pinCheck(!$bot->ui->gallerySourceEnabled('pinterest'),'the Pinterest source button defaults off and ordinary users cannot enable it');
    $toggle(); $rows=end($api->calls)[1]['reply_markup']['inline_keyboard']; $state='';
    foreach($rows as $row) foreach($row as $button) if(($button['callback_data']??'')==='custom:pinsource') $state=($button['style']??'').(str_contains($button['text'],'✅')?'+on':'');
    pinCheck($state==='primary+on'&&(new Zero\Ui(new Zero\Store($temp)))->gallerySourceEnabled('pinterest'),'the source switch turns on (✅ in its text, blue like every selectable button) and its enabled setting persists');
    $toggle();
    pinCheck(!$bot->ui->gallerySourceEnabled('pinterest'),'the administrator can disable the Pinterest source button again');
    pinCheck(!isset($bot->catalog('button')['pinterest_page_counter']),'the removed counter is absent from Personalization');
    foreach(['pinterest_caption','pinterest_article_ready','pinterest_article_title','pinterest_article_slide','pinterest_error_article'] as $key) pinCheck(isset($bot->catalog('text')[$key]),'active Pinterest text is customizable: '.$key);
    foreach(['pin_cookie_panel','pin_cookie_import','pin_cookie_saved'] as $key) pinCheck(!isset($bot->catalog('text')[$key]),'administrator cookie text stays outside user personalization: '.$key);
    $bot->handle(['update_id'=>900,'message'=>['from'=>['id'=>7],'chat'=>['id'=>7,'type'=>'private'],'text'=>'https://pinterest.com/pin/123456789/']]);
    pinCheck($store->claim('inspect')===null&&($job=$store->claim('media'))['phase']==='pinterest','Pinterest jobs bypass quality inspection and use the bounded media lane');
    pinCheck($store->choose((int)$job['id'],7,7,360)===null,'a forged quality callback cannot change an Pinterest request');
} finally { Zero\Download::clean($temp); }
