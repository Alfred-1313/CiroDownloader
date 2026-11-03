<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class CookieApi implements Zero\FileApi {
    public array $calls=[];
    public string $content='';
    public int $downloads=0;
    public bool $failDownload=false;
    public bool $failDelete=false;
    public bool $missingPanel=false;
    public int $nextMessage=1000;
    public ?int $reportedSize=null;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='getFile') return ['file_path'=>'documents/export.txt','file_size'=>$this->reportedSize??strlen($this->content)];
        if($method==='deleteMessage'&&$this->failDelete) throw new RuntimeException('Simulated deletion failure');
        if($method==='editMessageText'&&$this->missingPanel) { $this->missingPanel=false; throw new RuntimeException('Missing panel',404); }
        if($method==='sendMessage') return ['message_id'=>++$this->nextMessage];
        return true;
    }
    public function downloadFile(string $filePath,int $maxBytes): string {
        $this->downloads++;
        if($this->failDownload||strlen($this->content)>$maxBytes) throw new RuntimeException('Simulated file download failure');
        return $this->content;
    }
}
function expect(bool $ok,string $message): void {
    if(!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function callback(int $user,string $action): array {
    return ['callback_query'=>['id'=>'test','from'=>['id'=>$user],
        'message'=>['message_id'=>900,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$action]];
}
function document(int $user,string $name='youtube-cookies.txt',int $size=300): array {
    return ['message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'message_id'=>123,
        'document'=>['file_id'=>'fake-document','file_name'=>$name,'file_size'=>$size]]];
}
$temp=sys_get_temp_dir().'/ciro-cookie-test-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
try {
    $config=new Zero\Config('123:test',[42],$temp);
    $store=new Zero\Store($temp); $api=new CookieApi(); $bot=new Zero\Bot($config,$store,$api);
    $old="# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tFAKE_OLD_SESSION\n";
    $new=str_replace('FAKE_OLD_SESSION','FAKE_NEW_SESSION',$old);
    $mode=$temp.'/probe-mode'; file_put_contents($mode,'ok');
    $probe=$temp.'/probe';
    $metadata=['title'=>'Public test','formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac']]];
    file_put_contents($probe,"#!/usr/bin/env php\n<?php\n".
        'file_put_contents('.var_export($temp.'/probe-arguments.json',true).',json_encode($argv));'.
        '$mode=file_get_contents('.var_export($mode,true).');'.
        'if($mode!=="ok") {fwrite(STDERR,$mode==="blocked"?"Sign in to confirm you are not a bot":"Network is unreachable");exit(1);}'.
        'echo '.var_export(json_encode($metadata),true).';');
    chmod($probe,0700); $config->ytDlp=$probe; $config->youtubeProxy='socks5://127.0.0.1:1080';
    Zero\saveYouTubeCookies($config,$old);
    $api->content=$new;
    $bot->handle(callback(1,'cookies'));
    expect(!$store->query('SELECT key FROM states WHERE user_id=1')->fetchColumn(),'non-admin cannot start cookie import');
    $bot->handle(document(1));
    expect($api->downloads===0&&file_get_contents($config->cookiePath())===$old,'non-admin document cannot replace cookies');
    $bot->handle(document(42));
    expect($api->downloads===0,'admin must explicitly enter cookie import flow');
    $bot->handle(callback(42,'cookies'));
    expect($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='cookie.upload'&&(int)$store->query('SELECT expires FROM states WHERE user_id=42')->fetchColumn()===0,'admin import waits without an expiry');
    $screen=end($api->calls)[1];
    expect(end($api->calls)[0]==='editMessageText'&&$screen['message_id']===900&&count($screen['reply_markup']['inline_keyboard'])===1,'import edits the same panel and offers only Back');
    expect(!str_contains($screen['text'],'10 دقیقه')&&!str_contains($screen['text'],'/cancel')&&!str_contains($screen['text'],'youtube-cookies.txt'),'import instructions have no deadline, cancel hint or required filename');
    $bot->handle(document(42,'export.json'));
    $bot->handle(document(42,'export.txt',1048577));
    expect($api->downloads===0,'wrong extension and oversized documents rejected before fetching');
    $api->content='NOT_A_COOKIE_EXPORT_WITH_PRIVATE_DATA';
    $bot->handle(document(42));
    expect(file_get_contents($config->cookiePath())===$old,'malformed upload preserves previous valid cookie file');
    expect(!str_contains(json_encode($api->calls),'NOT_A_COOKIE_EXPORT_WITH_PRIVATE_DATA'),'invalid content never appears in replies');
    $api->content=$new; $api->failDownload=true;
    $bot->handle(document(42));
    expect(file_get_contents($config->cookiePath())===$old,'transport failure preserves previous cookies');
    $api->failDownload=false;
    file_put_contents($mode,'blocked'); $bot->handle(document(42,'any-name.txt'));
    expect(file_get_contents($config->cookiePath())===$old,'YouTube rejection preserves the existing cookie file');
    file_put_contents($mode,'network'); $bot->handle(document(42));
    expect(file_get_contents($config->cookiePath())===$old,'network failure cannot replace working cookies');
    $api->content=str_replace('2147483647','1',$new); file_put_contents($mode,'ok'); $bot->handle(document(42));
    expect(file_get_contents($config->cookiePath())===$old,'an entirely expired cookie export is rejected before replacement');
    $api->content=$new;
    $before=count($api->calls); $bot->handle(document(42,'my-export-2026.TXT'));
    expect(file_get_contents($config->cookiePath())===$new,'valid Telegram document replaces the shared cookie file');
    $verification=json_decode((string)file_get_contents($temp.'/probe-arguments.json'),true);
    expect(in_array('--dump-single-json',$verification,true)&&in_array('--skip-download',$verification,true),'replacement is gated on a real downloader metadata-access check');
    expect($verification[array_search('--proxy',$verification,true)+1]===$config->youtubeProxy,'verification uses the configured server proxy');
    expect($verification[array_search('--cookies',$verification,true)+1]!==$config->cookiePath(),'verification never edits the live cookie jar before success');
    expect(count(array_filter(array_slice($api->calls,$before),fn($call)=>$call[0]==='sendMessage'))===0,'checking and success edit the original import message without extra replies');
    expect((fileperms($config->cookiePath())&0077)===0,'replacement cookie file is private');
    expect(!$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn(),'successful import ends upload session');
    expect(count(array_filter($api->calls,fn($c)=>$c[0]==='deleteMessage'))>0,'uploaded cookie message deletion is attempted');
    $bot->handle(callback(42,'cookies'));
    $store->query('UPDATE states SET expires=1 WHERE user_id=42');
    $api->content=$old;
    $bot=new Zero\Bot($config,new Zero\Store($temp),$api);
    $bot->handle(document(42));
    expect(file_get_contents($config->cookiePath())===$old,'waiting for a cookie survives a long delay and a process restart');
    $bot->handle(callback(42,'cookies'));
    $bot->handle(callback(42,'admin'));
    expect(end($api->calls)[0]==='editMessageText'&&end($api->calls)[1]['message_id']===900&&!$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn(),'Back cancels import and edits the same message into the admin panel');
    $bot->handle(callback(42,'cookies')); $count=$api->downloads;
    $bot->handle(['message'=>['from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'text'=>'/cancel']]);
    $bot->handle(document(42));
    expect($api->downloads===$count,'cancel prevents subsequent upload from being imported');
    $store->set('enabled','0');
    $bot->handle(callback(42,'cookies'));
    $api->content=$new; $api->failDelete=true;
    $bot->handle(document(42));
    expect(file_get_contents($config->cookiePath())===$new,'admin import works during maintenance and tolerates message deletion failure');
    expect(str_contains((string)end($api->calls)[1]['text'],'حذف خودکار'),'administrator warned when message deletion fails');
    $api->failDelete=false;
    $bot->handle(callback(42,'cookies'));
    $api->reportedSize=1048577;
    $count=$api->downloads;
    $bot->handle(document(42));
    expect($api->downloads===$count&&file_get_contents($config->cookiePath())===$new,'getFile metadata size cannot bypass the import limit');
    $api->reportedSize=null; $api->content=$old;
    $bot->handle(callback(42,'cookies')); $api->missingPanel=true; $before=count($api->calls);
    $bot->handle(document(42,'deleted-panel.txt'));
    $replacement=(int)$store->get('cookie_menu.42');
    expect($replacement>1000&&file_get_contents($config->cookiePath())===$old,'a deleted persistent import panel is recreated and the cookie is still verified and saved');
    expect(count(array_filter(array_slice($api->calls,$before),fn($call)=>$call[0]==='sendMessage'))===1,'only one replacement panel is created after a missing-message error');
    $edits=array_values(array_filter(array_slice($api->calls,$before),fn($call)=>$call[0]==='editMessageText'));
    expect(end($edits)[1]['message_id']===$replacement,'subsequent cookie notices edit the saved replacement message');
    expect(glob($temp.'/.cookies-import-*')===[],'atomic import leaves no temporary cookie exports');
    expect(glob($temp.'/.cookie-check-*')===[],'failed and successful verification leave no candidate cookie files');
} finally { Zero\Download::clean($temp); }
