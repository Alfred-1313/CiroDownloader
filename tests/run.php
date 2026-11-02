<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class FakeApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed { $this->calls[]=[$method,$params]; return true; }
}
function check(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function msg(int $user,string $text,int $update=1): array { return ['update_id'=>$update,'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]; }
function cb(int $user,string $data): array { return ['callback_query'=>['id'=>'test','from'=>['id'=>$user],'message'=>['chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]; }
$temp=sys_get_temp_dir().'/zero-tests-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
try {
    $cfg=new Zero\Config('123:test',[42],$temp); $store=new Zero\Store($temp); $api=new FakeApi(); $bot=new Zero\Bot($cfg,$store,$api);
    $url='https://www.youtube.com/watch?v=dQw4w9WgXcQ';
    check(Zero\youtubeUrl('https://youtu.be/dQw4w9WgXcQ?si=foo')===$url,'short URL canonicalization');
    check(Zero\youtubeUrl('https://youtube.com/shorts/dQw4w9WgXcQ')===$url,'Shorts URL');
    foreach(['http://127.0.0.1/','https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ','https://youtube.com@evil.test/','https://youtube.com/watch?v[]=bad','https://youtube.com:99/watch?v=dQw4w9WgXcQ','https://youtube.com/playlist?list=abc'] as $bad) check(Zero\youtubeUrl($bad)===null,'reject invalid URL');
    $bot->handle(msg(1,'/start'));
    check(!str_contains(json_encode(end($api->calls)), 'callback_data":"admin"'),'public menu hides admin');
    $bot->handle(msg(42,'/start'));
    check(str_contains(json_encode(end($api->calls)), 'callback_data":"admin"'),'admin menu visible');
    $bot->handle(cb(1,'set:off')); check($store->enabled(),'forged admin callback denied');
    $bot->handle(cb(42,'set:off')); check(!$store->enabled(),'admin turns bot off');
    $bot->handle(msg(1,$url,10)); check((int)$store->query('SELECT count(*) FROM jobs')->fetchColumn()===0,'offline denies downloads');
    $bot->handle(msg(42,'/admin')); check(end($api->calls)[0]==='sendMessage','admin accessible while offline');
    $bot->handle(cb(42,'set:on')); check($store->enabled(),'admin turns bot on');
    $bot->handle(cb(42,'edit:button:download')); $bot->handle(msg(42,'دریافت فایل'));
    check($bot->label('download')==='دریافت فایل','edit button label');
    $bot->handle(cb(42,'reset:button:download')); check($bot->label('download')===Zero\Bot::BUTTONS['download'][1],'reset label');
    $bot->handle(cb(42,'edit:text:video_caption')); $bot->handle(msg(42,'عنوان: {title}'));
    check($bot->text('video_caption')==='عنوان: {title}','custom caption persists');
    $bot->handle(cb(42,'edit:text:welcome')); $bot->handle(msg(42,'/cancel')); $bot->handle(msg(42,'متن اشتباهی'));
    check($bot->text('welcome')===Zero\Bot::TEXTS['welcome'][1],'cancel editing');
    $bot->handle(cb(42,'edit:button:help')); $bot->handle(msg(42,str_repeat('x',49)));
    check($bot->label('help')===Zero\Bot::BUTTONS['help'][1],'reject excessive label length');
    $bot->handle(msg(1,$url,20)); $bot->handle(msg(1,$url,20)); $bot->handle(msg(1,$url,21));
    check((int)$store->query('SELECT count(*) FROM jobs')->fetchColumn()===1,'deduplicate updates and active jobs');
    $job=$store->claim(); check($job!==null&&$store->claim()===null,'claim only queued job');
    $reopen=new Zero\Store($temp); check($reopen->get('text.video_caption')==='عنوان: {title}','settings survive reload');
    $store->query("UPDATE jobs SET status='done'");
    check($store->enqueue(22,1,1,$url)==='cooldown','rate limit after finished job');
    for($i=100;$i<120;$i++) $store->enqueue($i,$i,$i,$url);
    check($store->enqueue(200,200,200,$url)==='full','bounded queue');
    mkdir($temp.'/process'); $runner=new Zero\Download($cfg);
    check(trim($runner->run([PHP_BINARY,'-r','echo "hello";'],$temp.'/process',2))==='hello','subprocess success');
    $timed=false;
    try { $runner->run([PHP_BINARY,'-r','sleep(10);'],$temp.'/process',1); } catch(RuntimeException) { $timed=true; }
    check($timed,'subprocess timeout');
    $bad=false;
    try { $runner->run([PHP_BINARY,'-r','exit(3);'],$temp.'/process',2); } catch(RuntimeException) { $bad=true; }
    check($bad,'subprocess failure');
    echo "All tests passed.\n";
} finally { Zero\Download::clean($temp); }
