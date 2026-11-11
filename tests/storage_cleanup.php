<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function storageCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-cleanup-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); $store=new Zero\Store($config->storage); $run=new Zero\Download($config);
    $out=$run->run([PHP_BINARY,'-r','for($i=0;$i<512;$i++) fwrite(STDERR,str_repeat("x",65536)); echo "metadata";'],$temp,30);
    storageCheck($out==='metadata'&&!glob($temp.'/*.log'),'32 MB of downloader stderr is drained without filling disk or blocking metadata');
    try { $run->run([PHP_BINARY,'-r','fwrite(STDERR,"connection refused final-marker"); exit(7);'],$temp,5); throw new RuntimeException('Expected failure'); }
    catch(Zero\MediaFailure $e) { storageCheck(str_contains($e->getMessage(),'final-marker'),'the diagnostic tail is preserved on process failure'); }
    try { $run->run([PHP_BINARY,'-r','for($i=0;$i<400;$i++) echo str_repeat("x",65536);'],$temp,10); throw new RuntimeException('Expected output limit'); }
    catch(Zero\MediaFailure $e) { storageCheck($e->reason==='format','oversized metadata is rejected with bounded memory'); }

    // Another writer can work while empty lanes are polled. A BEGIN IMMEDIATE here used to wait five seconds.
    $other=new Zero\Store($config->storage); $other->db->exec('BEGIN IMMEDIATE');
    try { $start=microtime(true); storageCheck($store->claim('inspect')===null&&$store->claim('media')===null&&microtime(true)-$start<0.5,'empty queue polling takes no writer lock'); }
    finally { $other->db->exec('ROLLBACK'); }

    foreach(['1-aaaaaaaa','2-bbbbbbbb','3-cccccccc'] as $key) { mkdir($config->storage.'/music-batches/'.$key,0700,true); file_put_contents($config->storage.'/music-batches/'.$key.'/audio.mp3','fixture'); }
    $store->query("INSERT INTO music_menus(id,user_id,chat_id,service,kind,data,created,expires) VALUES(1,42,42,'spotify','album',?,?,?)",[json_encode(['batch'=>['state'=>'done']]),time(),time()+86400]);
    touch($config->storage.'/music-batches/2-bbbbbbbb',time()-100000);
    touch($config->storage.'/music-batches/3-cccccccc',time()-100000);
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,phase,options,created) VALUES(1,42,42,'fixture','running','batch',?,?)",[json_encode(['batch'=>['key'=>'3-cccccccc']]),time()]);
    Zero\MusicMenu::purge($config,$store);
    storageCheck(!is_dir($config->storage.'/music-batches/1-aaaaaaaa')&&!is_dir($config->storage.'/music-batches/2-bbbbbbbb')&&is_file($config->storage.'/music-batches/3-cccccccc/audio.mp3'),'maintenance clears old delivered/orphaned batches and protects a running batch');

    // A successful response without a reusable ID must retain the file for navigation.
    $fake=new class implements Zero\Api {
        public function call(string $method,array $params=[]): mixed { return ['message_id'=>99]; }
    };
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,phase,created) VALUES(2,42,42,'https://www.instagram.com/p/Fixture1/','running','instagram',?)",[time()]);
    $job=$store->query('SELECT * FROM jobs WHERE update_id=2')->fetch(); $slides=[];
    foreach([1,2] as $i) { $path=$temp.'/slide'.$i.'.jpg'; file_put_contents($path,'fixture'); $slides[]=['path'=>$path,'type'=>'photo']; }
    $pages=new Zero\GalleryPages($config,$store,$fake); $job=$pages->prepare($job,$slides);
    $pages->deliver($job,new Zero\Bot($config,$store,$fake));
    $state=json_decode($store->query('SELECT options FROM jobs WHERE id=?',[$job['id']])->fetchColumn(),true)['pages'];
    storageCheck(is_file($config->storage.'/instagram-pages/'.$state['folder'].'/1.jpg'),'a response without a reusable Telegram ID retains the slide for navigation');

    // A confirmed slide is released before the old status message is deleted, even if that deletion fails.
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,message_id,status,phase,created) VALUES(3,42,42,'https://www.instagram.com/p/Fixture2/',70,'running','instagram',?)",[time()]);
    $job=$store->query('SELECT * FROM jobs WHERE update_id=3')->fetch(); $slides=[];
    foreach([1,2] as $i) { $path=$temp.'/confirmed'.$i.'.jpg'; file_put_contents($path,'fixture'); $slides[]=['path'=>$path,'type'=>'photo']; }
    $confirmed=new class($config,$store,(int)$job['id']) implements Zero\Api {
        public bool $checked=false;
        public function __construct(private Zero\Config $config,private Zero\Store $store,private int $id) {}
        public function call(string $method,array $params=[]): mixed {
            if($method==='deleteMessage') {
                $state=json_decode($this->store->query('SELECT options FROM jobs WHERE id=?',[$this->id])->fetchColumn(),true)['pages'];
                $dir=$this->config->storage.'/instagram-pages/'.$state['folder'];
                storageCheck(($state['items'][0]['file_id']??'')==='PHOTO_CONFIRMED'&&!is_file($dir.'/1.jpg')&&is_file($dir.'/2.jpg'),'confirmed gallery ID is saved and its local slide removed before status cleanup; unsent slide stays ready');
                $this->checked=true;
                throw new RuntimeException('Fixture status cleanup rejected');
            }
            return ['message_id'=>100,'photo'=>[['file_id'=>'PHOTO_CONFIRMED']]];
        }
    };
    $pages=new Zero\GalleryPages($config,$store,$confirmed); $job=$pages->prepare($job,$slides);
    $pages->deliver($job,new Zero\Bot($config,$store,$confirmed));
    storageCheck($confirmed->checked&&$store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='done','failed status cleanup does not retain or invalidate a delivered gallery slide');

    $file=$config->storage.'/ready.mp4'; file_put_contents($file,'fixture'); $upload=new CURLFile($file,'video/mp4','video.mp4');
    $api=new Zero\Telegram($config); $config->localApiSharedStorage=true;
    storageCheck($api->localFiles(['video'=>$upload])['video'] instanceof CURLFile,'the hosted API retains real multipart uploads');
    $config->api='http://ciro-telegram-api:8081'; $config->localApiSharedStorage=false;
    storageCheck($api->localFiles(['video'=>$upload])['video'] instanceof CURLFile,'older local API installations keep multipart until their shared mount is enabled');
    $config->localApiSharedStorage=true;
    $params=$api->localFiles(['media'=>['type'=>'video','media'=>'attach://slide'],'slide'=>$upload,'thumbnail'=>$upload,'cover'=>$upload]);
    storageCheck($params['media']['media']==='file:///app/storage/ready.mp4'&&!isset($params['slide'])&&$params['thumbnail']==='file:///app/storage/ready.mp4'&&$params['cover']==='file:///app/storage/ready.mp4','local uploads, video covers and attached media use the shared file with no second upload copy');
    file_put_contents($temp.'/outside.mp4','outside'); symlink($temp.'/outside.mp4',$config->storage.'/escape.mp4');
    storageCheck($api->localFiles(['video'=>new CURLFile($config->storage.'/escape.mp4')])['video'] instanceof CURLFile,'files outside shared storage, including symlink escapes, never become local API paths');
} finally { Zero\Download::clean($temp); }
