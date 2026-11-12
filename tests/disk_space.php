<?php
declare(strict_types=1);
namespace Zero;

// Simulate different free-space values without filling the CI runner's disk.
function disk_free_space(string $path): int|false { return $GLOBALS['ciro_test_free']; }
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Worker.php';

$temp=sys_get_temp_dir().'/ciro-space-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    // A 49 MB file may proceed even with far less than four times the configured 1990 MB maximum.
    $GLOBALS['ciro_test_free']=600000000;
    Download::ensureSpace($temp);
    Download::ensureSpace($temp,49000000+268435456);
    $rejected=false;
    try { Download::ensureSpace($temp,500000000+268435456); } catch(MediaFailure $e) { $rejected=$e->reason==='storage'; }
    if(!$rejected) throw new \RuntimeException('Insufficient upload copy space was accepted');
    $GLOBALS['ciro_test_free']=100000000;
    $rejected=false;
    try { (new Download(new Config('123:fake',[42],$temp)))->run(['/bin/sleep','3'],$temp,5); }
    catch(MediaFailure $e) { $rejected=$e->reason==='storage'; }
    if(!$rejected) throw new \RuntimeException('Running downloader ignored low disk space');
    echo "PASS free space checks follow the actual file and stop processing before exhausting storage\n";

    $config=new Config('123:fixture',[42],$temp.'/data'); $store=new Store($config->storage); mkdir($config->storage.'/downloads',0700);
    $config->ytDlp=$temp.'/downloader-fixture';
    file_put_contents($config->ytDlp,"#!/usr/bin/env php\n<?php sleep(3);\n"); chmod($config->ytDlp,0700);
    $api=new class implements Api {
        public array $calls=[];
        public function call(string $method,array $params=[]): mixed { $this->calls[]=[$method,$params]; return ['message_id'=>70]; }
        public function last(string $method): array { foreach(array_reverse($this->calls) as [$name,$params]) if($name===$method) return $params; return []; }
    };
    $bot=new Bot($config,$store,$api); $worker=new Worker($config,$store,$api);
    $store->set('text.error_storage','CUSTOM STORAGE NOTICE');
    $urls=['pornhub'=>'https://www.pornhub.com/view_video.php?viewkey=phfixture', 'beeg'=>'https://beeg.com/12345',
        'xnxx'=>'https://www.xnxx.com/video-fixture/title','xvideos'=>'https://www.xvideos.com/video.fixture/title',
        'tikporn'=>'https://tik.porn/video/2344693','youporn'=>'https://www.youporn.com/watch/189780301/','eporner'=>'https://www.eporner.com/video-HmstDuT4BNE/'];
    foreach($urls as $site=>$url) {
        if(adultService($url)!==$site) throw new \RuntimeException('Invalid fixture URL for '.$site);
        $store->set('adult.'.$site,'1');
        $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,message_id,status,phase,created) VALUES(?,42,42,?,70,'running','inspect',?)",[count($api->calls)+1,$url,time()]);
        $job=$store->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch(); $worker->process($job);
        if($api->last('editMessageText')['text']!=='CUSTOM STORAGE NOTICE'||$store->get('last_job_error_reason')!=='storage'||$store->get('last_job_sources')!==''||glob($config->storage.'/downloads/job-*'))
            throw new \RuntimeException($site.' hid a local storage failure behind a site error or retained files');
        echo "PASS $site uses the editable storage notice and keeps local disk failures separate from site failures\n";
    }
    // Existing records from old versions may contain a misleading "site=unknown" suffix.
    $store->set('last_job_sources','youporn=unknown');
    $store->set('recent_errors',json_encode([['t'=>time(),'s'=>'youporn','r'=>'storage','c'=>'youporn=unknown']]));
    $bot->health(42); $health=$api->last('sendMessage')['text'];
    $bot->handle(['callback_query'=>['id'=>'report','from'=>['id'=>42],'message'=>['message_id'=>70,'chat'=>['id'=>42,'type'=>'private']],'data'=>'rp:errors']]);
    $report=$api->last('editMessageText')['text'];
    if(!str_contains($health,'CUSTOM STORAGE NOTICE')||!str_contains($report,'CUSTOM STORAGE NOTICE')||str_contains($health,'youporn=unknown')||str_contains($report,'youporn=unknown'))
        throw new \RuntimeException('Health/recent errors obscured the storage reason');
    echo "PASS health and recent errors explain disk failures without a misleading site-unknown suffix\n";
} finally { Download::clean($temp); }

