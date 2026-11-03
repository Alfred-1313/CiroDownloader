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
    public ?int $reportedSize=null;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='getFile') return ['file_path'=>'documents/export.txt','file_size'=>$this->reportedSize??strlen($this->content)];
        if($method==='deleteMessage'&&$this->failDelete) throw new RuntimeException('Simulated deletion failure');
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
        'message'=>['chat'=>['id'=>$user,'type'=>'private']],'data'=>$action]];
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
    Zero\saveYouTubeCookies($config,$old);
    $api->content=$new;
    $bot->handle(callback(1,'cookies'));
    expect(!$store->query('SELECT key FROM states WHERE user_id=1')->fetchColumn(),'non-admin cannot start cookie import');
    $bot->handle(document(1));
    expect($api->downloads===0&&file_get_contents($config->cookiePath())===$old,'non-admin document cannot replace cookies');
    $bot->handle(document(42));
    expect($api->downloads===0,'admin must explicitly enter cookie import flow');
    $bot->handle(callback(42,'cookies'));
    expect($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='cookie.upload','admin import starts a bounded upload session');
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
    $bot->handle(document(42));
    expect(file_get_contents($config->cookiePath())===$new,'valid Telegram document replaces the shared cookie file');
    expect((fileperms($config->cookiePath())&0077)===0,'replacement cookie file is private');
    expect(!$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn(),'successful import ends upload session');
    expect(count(array_filter($api->calls,fn($c)=>$c[0]==='deleteMessage'))>0,'uploaded cookie message deletion is attempted');
    $bot->handle(callback(42,'cookies'));
    $store->query('UPDATE states SET expires=0 WHERE user_id=42');
    $count=$api->downloads;
    $bot->handle(document(42));
    expect($api->downloads===$count&&file_get_contents($config->cookiePath())===$new,'expired session cannot replace cookies');
    $bot->handle(callback(42,'cookies'));
    $bot->handle(['message'=>['from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'text'=>'/cancel']]);
    $bot->handle(document(42));
    expect($api->downloads===$count,'cancel prevents subsequent upload from being imported');
    $store->set('enabled','0');
    $bot->handle(callback(42,'cookies'));
    $api->content=$old; $api->failDelete=true;
    $bot->handle(document(42));
    expect(file_get_contents($config->cookiePath())===$old,'admin import works during maintenance and tolerates message deletion failure');
    expect(str_contains((string)end($api->calls)[1]['text'],'حذف خودکار'),'administrator warned when message deletion fails');
    $api->failDelete=false;
    $bot->handle(callback(42,'cookies'));
    $api->reportedSize=1048577;
    $count=$api->downloads;
    $bot->handle(document(42));
    expect($api->downloads===$count&&file_get_contents($config->cookiePath())===$old,'getFile metadata size cannot bypass the import limit');
    expect(glob($temp.'/.cookies-import-*')===[],'atomic import leaves no temporary cookie exports');
} finally { Zero\Download::clean($temp); }
