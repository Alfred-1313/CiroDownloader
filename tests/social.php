<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function socialCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class SocialApi implements Zero\FileApi {
    public array $calls=[]; public string $content='';
    public function call(string $method,array $params=[]): mixed { $this->calls[]=[$method,$params]; return $method==='getFile'?['file_path'=>'fixture/export.txt']:['message_id'=>700]; }
    public function downloadFile(string $filePath,int $maxBytes): string { return $this->content; }
}
$temp=sys_get_temp_dir().'/ciro-social-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $api=new SocialApi(); $bot=new Zero\Bot($config,$store,$api);
    $send=fn(string $text,int $user=42,int $chat=42)=>$bot->handle(['message'=>['from'=>['id'=>$user],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'text'=>$text]]);
    $click=fn(string $key)=>$send($bot->label($key));
    $callback=fn(string $action,int $user=42,int $chat=42)=>$bot->handle(['callback_query'=>['id'=>'social','from'=>['id'=>$user],'message'=>['message_id'=>700,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']],'data'=>$action]]);
    $valid=[
        ['tiktok','https://www.tiktok.com/@fixture/video/1234567890123456789?tracking=1','https://www.tiktok.com/@fixture/video/1234567890123456789'],
        ['tiktok','https://m.tiktok.com/@fixture/photo/1234567890123456789?photoindex=2','https://www.tiktok.com/@fixture/photo/1234567890123456789'],
        ['tiktok','https://www.tiktok.com/share/video/1234567890123456789/','https://www.tiktok.com/@/video/1234567890123456789'],
        ['tiktok','https://vm.tiktok.com/AbC123/?tracking=1','https://vm.tiktok.com/AbC123/'],
        ['tiktok','https://vt.tiktok.com/AbC123/','https://vt.tiktok.com/AbC123/'],
        ['tiktok','tiktok.com/t/AbC123/?tracking=1','https://www.tiktok.com/t/AbC123/'],
        ['twitter','https://x.com/fixture/status/1234567890123456789/photo/2?tracking=1','https://x.com/fixture/status/1234567890123456789'],
        ['twitter','https://mobile.twitter.com/fixture/status/1234567890123456789/video/1','https://x.com/fixture/status/1234567890123456789'],
        ['twitter','https://x.com/i/status/1234567890123456789','https://x.com/i/web/status/1234567890123456789'],
        ['twitter','https://twitter.com/i/web/status/1234567890123456789','https://x.com/i/web/status/1234567890123456789'],
    ];
    foreach($valid as [$service,$url,$expected]) socialCheck(Zero\galleryUrl($service,$url)===$expected&&Zero\mediaUrl($url)===$expected&&Zero\galleryService($url)===$service,'recognized single-post URL: '.$service);
    foreach(['https://www.tiktok.com/@fixture','https://www.tiktok.com/@fixture/live','https://vm.tiktok.com/abc/extra','https://tiktok.com.evil/@user/video/123','https://www.tiktok.com:443/@user/video/123','https://tiktok.com@evil/@user/video/123','https://x.com/user','https://x.com/i/spaces/123','https://x.com/user/status/123/likes','https://x.com.evil/user/status/123','https://x.com@evil/user/status/123','https://x.com:443/user/status/123','file:///user/status/123'] as $url) socialCheck(Zero\mediaUrl($url)===null,'profiles, broadcasts and unsafe destinations are rejected');
    $original="# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t2147483647\tsessionid\tFAKE_INSTAGRAM\n"; Zero\Instagram::saveCookies($config,$original);
    $serial=0;
    foreach(['tiktok'=>[Zero\TikTok::class,'tt','tiktok.com','sessionid'],'twitter'=>[Zero\Twitter::class,'x','x.com','auth_token']] as $service=>[$class,$prefix,$domain,$session]) {
        $cookies="# Netscape HTTP Cookie File\n#HttpOnly_.$domain\tTRUE\t/\tTRUE\t2147483647\t$session\tFAKE_SESSION_A\n.$domain\tTRUE\t/\tTRUE\t0\tfixture\tFAKE_OTHER\n";
        $class::saveCookies($config,$cookies); $path=$config->galleryCookiePath($service);
        socialCheck((fileperms($path)&0777)===0600&&file_get_contents($config->instagramCookiePath())===$original,'session storage remains private and separate: '.$service);
        foreach([$original,str_replace('2147483647','1',$cookies),str_replace($domain,'evil.example',$cookies)] as $invalid) {
            $rejected=false; try { $class::saveCookies($config,$invalid); } catch(RuntimeException) { $rejected=true; }
            socialCheck($rejected&&file_get_contents($path)===$cookies,'invalid cookie imports retain the active jar: '.$service);
        }
        $click('cookie_'.$service); $keys=array_column(array_merge(...end($api->calls)[1]['reply_markup']['keyboard']),'text');
        socialCheck(in_array($bot->label($prefix.'_cookie_upload'),$keys,true)&&in_array($bot->label($prefix.'_cookie_view'),$keys,true),'independent cookie reply keyboard: '.$service);
        $click($prefix.'_cookie_view'); socialCheck(!str_contains(end($api->calls)[1]['text'],'FAKE_'),'metadata view never exposes cookie values: '.$service);
        $click($prefix.'_cookie_export'); socialCheck(end($api->calls)[0]==='sendDocument'&&end($api->calls)[1]['protect_content']&&end($api->calls)[1]['document'] instanceof CURLStringFile,'administrator export is a protected document: '.$service);
        $before=count($api->calls); $send($bot->label($prefix.'_cookie_export'),7,7); $send($bot->label($prefix.'_cookie_export'),42,-100123);
        socialCheck(!array_filter(array_slice($api->calls,$before),fn($call)=>$call[0]==='sendDocument'),'ordinary users and groups cannot export: '.$service);
        $click($prefix.'_cookie_upload'); $send('waiting for my file');
        socialCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()===$prefix.'_cookie.upload','upload remains pending for a file: '.$service);
        $api->content=$cookies;
        $bot->handle(['message'=>['message_id'=>701,'from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'document'=>['file_id'=>'fixture','file_name'=>'session.txt','file_size'=>strlen($cookies)]]]);
        socialCheck(!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn()&&file_get_contents($path)===$cookies,'document import saves and clears only its own state: '.$service);
        $click($prefix.'_cookie_remove'); $fresh=str_replace('FAKE_SESSION_A','FAKE_SESSION_B',$cookies); $class::saveCookies($config,$fresh); $click($prefix.'_cookie_confirm');
        socialCheck(file_get_contents($path)===$fresh,'stale deletion cannot remove a newer import: '.$service);
        $click($prefix.'_cookie_remove'); $click($prefix.'_cookie_back'); socialCheck(is_file($path)&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'Back cancels deletion: '.$service);
        $click($prefix.'_cookie_remove'); $click($prefix.'_cookie_confirm'); socialCheck(!is_file($path)&&file_get_contents($config->instagramCookiePath())===$original,'confirmed deletion preserves other services: '.$service);
        $callback('custom:section:'.$service); $rows=end($api->calls)[1]['reply_markup']['inline_keyboard']; $heading=false;$control=false;
        foreach($rows as $row) foreach($row as $button) {
            if(($button['callback_data']??'')==='custom:noop'&&$button['text']===$bot->label('section_'.$service)) $heading=($button['style']??'')==='primary';
            if(($button['callback_data']??'')==='custom:'.$prefix.'source') $control=!isset($button['style']);
        }
        socialCheck($heading&&$control&&!$bot->ui->gallerySourceEnabled($service),'Personalization uses a blue heading, neutral controls and a default-off source: '.$service);
        $callback('custom:'.$prefix.'source',7,7); socialCheck(!$bot->ui->gallerySourceEnabled($service),'ordinary users cannot change the source setting: '.$service);
        $callback('custom:'.$prefix.'source'); socialCheck((new Zero\Ui(new Zero\Store($temp)))->gallerySourceEnabled($service)&&!$bot->ui->gallerySourceEnabled('instagram'),'source setting persists independently: '.$service);
        foreach(['_caption','_page_caption','_error_format'] as $suffix) socialCheck(isset($bot->catalog('text')[$service.$suffix]),'user-facing fields are customizable: '.$service.$suffix);
        socialCheck(!isset($bot->catalog('text')[$prefix.'_cookie_panel']),'cookie controls stay outside user text editing: '.$service);
        $url=$service==='tiktok'?'https://www.tiktok.com/@fixture/video/1234567890123456789':'https://x.com/fixture/status/1234567890123456789';
        $bot->handle(['update_id'=>900+ ++$serial,'message'=>['message_id'=>90+$serial,'from'=>['id'=>70+$serial],'chat'=>['id'=>70+$serial,'type'=>'private'],'text'=>$url]]);
        $job=$store->claim('media'); socialCheck($store->claim('inspect')===null&&$job['phase']===$service,'post bypasses quality selection in the bounded media lane: '.$service);
        socialCheck($store->choose((int)$job['id'],70+$serial,70+$serial,360)===null,'forged quality selection cannot change a direct post: '.$service);
    }
    foreach(['sessionid_ss','sid_tt'] as $session) Zero\TikTok::validateCookies("# Netscape HTTP Cookie File\n.tiktok.com\tTRUE\t/\tTRUE\t0\t$session\tFAKE_SESSION\n");
    socialCheck(true,'TikTok accepts its supported session cookie aliases');
} finally { Zero\Download::clean($temp); }
