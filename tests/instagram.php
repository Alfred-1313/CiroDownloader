<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function igCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
final class InstagramCookieApi implements Zero\FileApi {
    public array $calls=[]; public string $content='';
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        return $method==='getFile'?['file_path'=>'test/export.txt','file_size'=>strlen($this->content)]:['message_id'=>700];
    }
    public function downloadFile(string $filePath,int $maxBytes): string { return $this->content; }
}
$temp=sys_get_temp_dir().'/ciro-instagram-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    foreach(['https://www.instagram.com/p/ABC_def12/?img_index=2','instagram.com/p/ABC_def12/','http://instagram.com/p/ABC_def12/'] as $url) igCheck(Zero\instagramUrl($url)==='https://www.instagram.com/p/ABC_def12/','post URL is canonical and includes every slide');
    igCheck(Zero\instagramUrl('https://instagram.com/reels/ABC_def12/?igsh=tracking')==='https://www.instagram.com/reel/ABC_def12/','Reels URL strips tracking and keeps its media ID');
    foreach(['https://instagram.com.evil/p/ABC_def12/','https://instagram.com@evil/p/ABC_def12/','https://www.instagram.com:443/p/ABC_def12/','https://www.instagram.com/username/','https://www.instagram.com/p/../../etc/passwd','https://www.instagram.com/p/ABC_def12/ extra','file:///p/ABC_def12/'] as $url) igCheck(Zero\instagramUrl($url)===null,'unrelated or unsafe URLs are rejected');
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $api=new InstagramCookieApi(); $bot=new Zero\Bot($config,$store,$api);
    $cookies="# Netscape HTTP Cookie File\n#HttpOnly_.instagram.com\tTRUE\t/\tTRUE\t2147483647\tsessionid\tFAKE_INSTAGRAM_SESSION\n.instagram.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tFAKE_CSRF\n";
    $youtube="# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_YOUTUBE_SESSION\n";
    Zero\saveYouTubeCookies($config,$youtube); Zero\Instagram::saveCookies($config,$cookies);
    igCheck(file_get_contents($config->cookiePath())===$youtube&&file_get_contents($config->instagramCookiePath())===$cookies&&(fileperms($config->instagramCookiePath())&0777)===0600,'Instagram and YouTube cookies are private independent files');
    foreach([$youtube,str_replace('2147483647','1',$cookies),str_replace('.instagram.com','.example.com',$cookies)] as $bad) {
        $rejected=false; try { Zero\Instagram::saveCookies($config,$bad); } catch(RuntimeException) { $rejected=true; }
        igCheck($rejected&&file_get_contents($config->instagramCookiePath())===$cookies,'foreign or expired cookie imports preserve the previous Instagram session');
    }
    $send=function(string $text,int $user=42) use($bot): void { $bot->handle(['message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]); };
    $click=fn(string $key)=>$send($bot->label($key));
    $click('cookie_instagram');
    $keys=array_column(array_merge(...end($api->calls)[1]['reply_markup']['keyboard']),'text');
    igCheck(in_array($bot->label('ig_cookie_upload'),$keys,true)&&in_array($bot->label('ig_cookie_view'),$keys,true),'Instagram has a private reply-keyboard cookie panel');
    $click('ig_cookie_view');
    igCheck(!str_contains(end($api->calls)[1]['text'],'FAKE_')&&str_contains(end($api->calls)[1]['text'],'2'),'cookie view shows counts without session values');
    $exportData=end($api->calls)[1]['reply_markup']['inline_keyboard'][0][0]['callback_data']; $bot->handle(['callback_query'=>['id'=>'export','from'=>['id'=>42],'message'=>['message_id'=>800,'chat'=>['id'=>42,'type'=>'private']],'data'=>$exportData]]);
    igCheck((($docs=array_values(array_filter($api->calls,fn($call)=>$call[0]==='sendDocument')))&&end($docs)[1]['protect_content']===true&&end($docs)[1]['document'] instanceof CURLStringFile),'only the private administrator receives a protected in-memory cookie export');
    $before=count($api->calls); $send($bot->label('ig_cookie_export'),7);
    igCheck(count(array_filter(array_slice($api->calls,$before),fn($call)=>$call[0]==='sendDocument'))===0,'ordinary users cannot export the Instagram jar');
    $click('ig_cookie_upload');
    igCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='ig_cookie.upload'&&!isset(end($api->calls)[1]['reply_markup']),'Instagram import keeps a persistent editable status and the service keyboard');
    $api->content=$cookies;
    $bot->handle(['message'=>['message_id'=>701,'from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'document'=>['file_id'=>'fixture','file_name'=>'any-name.txt','file_size'=>strlen($cookies)]]]);
    igCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='ig_cookie.upload'&&file_get_contents($config->cookiePath())===$youtube,'a valid document saves only Instagram cookies and keeps the upload open for more files');
    $click('ig_cookie_remove'); $new=str_replace('FAKE_CSRF','FAKE_FRESH_CSRF',$cookies); Zero\Instagram::saveCookies($config,$new); $click('ig_cookie_confirm');
    igCheck(file_get_contents($config->instagramCookiePath())===$new,'a stale deletion confirmation cannot delete a newer Instagram import');
    $click('ig_cookie_remove'); $click('ig_cookie_back');
    igCheck(is_file($config->instagramCookiePath())&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'Back cancels Instagram cookie deletion');
    $click('ig_cookie_remove'); $click('ig_cookie_confirm');
    igCheck(!is_file($config->instagramCookiePath())&&file_get_contents($config->cookiePath())===$youtube,'confirmed Instagram deletion preserves YouTube cookies');
    $custom=new Zero\Personalization($bot); $custom->home(42);
    $rows=end($api->calls)[1]['reply_markup']['inline_keyboard']; $header=false; $control=false;
    foreach($rows as $row) foreach($row as $button) {
        if(($button['callback_data']??'')==='custom:hint:home_social') $header=!isset($button['style']);
        if(($button['callback_data']??'')==='custom:section:instagram') $control=true;
    }
    igCheck($header&&$control,'Personalization lists Instagram under a white social-networks title');
    $toggle=fn(int $user=42)=>$bot->handle(['callback_query'=>['id'=>'ig-source','from'=>['id'=>$user],'message'=>['message_id'=>700,'chat'=>['id'=>$user,'type'=>'private']],'data'=>'custom:igsource']]);
    $toggle(7);
    igCheck(!$bot->ui->instagramSourceEnabled(),'the Instagram source button defaults off and ordinary users cannot enable it');
    $toggle(); $rows=end($api->calls)[1]['reply_markup']['inline_keyboard']; $state='';
    foreach($rows as $row) foreach($row as $button) if(($button['callback_data']??'')==='custom:igsource') $state=$button['style']??'';
    igCheck($state==='success'&&(new Zero\Ui(new Zero\Store($temp)))->instagramSourceEnabled(),'the source switch turns green and its enabled setting persists');
    $toggle();
    igCheck(!$bot->ui->instagramSourceEnabled(),'the administrator can disable the Instagram source button again');
    igCheck(!isset($bot->catalog('button')['instagram_page_counter']),'the removed counter is absent from Personalization');
    foreach(['instagram_caption','instagram_article_ready','instagram_article_title','instagram_article_slide','instagram_error_article'] as $key) igCheck(isset($bot->catalog('text')[$key]),'active Instagram text is customizable: '.$key);
    foreach(['ig_cookie_panel','ig_cookie_import','ig_cookie_saved'] as $key) igCheck(!isset($bot->catalog('text')[$key]),'administrator cookie text stays outside user personalization: '.$key);
    $bot->handle(['update_id'=>900,'message'=>['from'=>['id'=>7],'chat'=>['id'=>7,'type'=>'private'],'text'=>'https://instagram.com/p/ABC_def12/']]);
    igCheck($store->claim('inspect')===null&&($job=$store->claim('media'))['phase']==='instagram','Instagram jobs bypass quality inspection and use the bounded media lane');
    igCheck($store->choose((int)$job['id'],7,7,360)===null,'a forged quality callback cannot change an Instagram request');
} finally { Zero\Download::clean($temp); }
