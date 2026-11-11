<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function deliveryCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }

// Exercise real batch delivery with already-prepared tracks, without downloading or encoding media.
$temp=sys_get_temp_dir().'/ciro-delivery-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    foreach(['each','zip','post-send-failure','upload-failure','partial-upload'] as $case) {
        $config=new Zero\Config('123:fixture',[42],$temp.'/'.$case); $config->paceMessages=false;
        $config->maxBytes=2097152+700000;
        $store=new Zero\Store($config->storage); mkdir($config->storage.'/downloads',0700);
        $dir=$config->storage.'/music-batches/1-aaaaaaaa'; mkdir($dir,0700,true);
        $tracks=[]; $expected=[];
        foreach([0,1,2] as $i) {
            $content=str_repeat(chr(65+$i),600000); $expected[sprintf('%02d - Track %d.mp3',$i+1,$i)]=$content;
            file_put_contents($dir.'/'.$i.'.mp3',$content); file_put_contents($dir.'/'.$i.'.jpg','artwork');
            file_put_contents($dir.'/'.$i.'.json',json_encode(['extension'=>'mp3','cover'=>true,'name'=>'Track '.$i.'.mp3','mime'=>'audio/mpeg','title'=>'Track '.$i,'performer'=>'Fixture','length'=>12,'duration'=>12]));
            $tracks[]=['u'=>'https://soundcloud.com/fixture/track-'.$i,'n'=>'Track '.$i];
        }
        $store->query("INSERT INTO music_menus(id,user_id,chat_id,message_id,service,kind,data,created,expires) VALUES(1,42,42,70,'soundcloud','album','{}',?,?)",[time(),time()+86400]);
        $batch=['mode'=>$case==='zip'?'zip':'each','service'=>'soundcloud','tracks'=>$tracks,'key'=>'1-aaaaaaaa','menu'=>1,'title'=>'Fixture album'];
        $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,message_id,status,phase,options,created) VALUES(1,42,42,'https://soundcloud.com/fixture/sets/album',70,'running','batch',?,?)",[json_encode(['batch'=>$batch]),time()]);
        $job=$store->query('SELECT * FROM jobs WHERE update_id=1')->fetch();
        if($case==='post-send-failure') $store->db->exec("CREATE TRIGGER reject_delivery_state BEFORE UPDATE OF message_id ON music_menus BEGIN SELECT RAISE(ABORT,'Fixture metadata failure'); END");
        $count=$case==='upload-failure'?0:($case==='partial-upload'?1:3);
        $api=new class($dir,$expected,$count<3?$count:null) implements Zero\Api {
            public array $sent=[];
            public bool $valid=true;
            public function __construct(private string $dir,private array $expected,private ?int $failAfter) {}
            public function call(string $method,array $params=[]): mixed {
                if($method==='getMe') return ['supports_inline_queries'=>true];
                if(!in_array($method,['sendAudio','sendDocument'],true)) return ['message_id'=>70];
                foreach($this->sent as $path) if(is_file($path)) $this->valid=false;
                if($method==='sendAudio') {
                    $path=$params['audio']->getFilename();
                    if(!is_file($path)||!is_file($params['thumbnail']->getFilename())) $this->valid=false;
                    if(count($this->sent)>0&&is_file($this->dir.'/'.(count($this->sent)-1).'.jpg')) $this->valid=false;
                } else {
                    $path=$params['document']->getFilename(); $zip=new ZipArchive();
                    if($zip->open($path)!==true) $this->valid=false;
                    else {
                        for($i=0;$i<$zip->numFiles;$i++) {
                            $name=$zip->getNameIndex($i);
                            if(!isset($this->expected[$name])||$zip->getFromIndex($i)!==$this->expected[$name]) $this->valid=false;
                        }
                        $zip->close();
                    }
                    if(glob($this->dir.'/*.mp3')||glob($this->dir.'/*.jpg')) $this->valid=false;
                }
                if($this->failAfter!==null&&count($this->sent)===$this->failAfter) throw new RuntimeException('Fixture upload rejected');
                $this->sent[]=$path; usleep(2000);
                return ['message_id'=>100+count($this->sent),'audio'=>['file_id'=>'AUDIO_'.count($this->sent)]];
            }
        };
        (new Zero\Worker($config,$store,$api))->process($job);
        deliveryCheck($api->valid&&count($api->sent)===$count,$case.': confirmed files and covers are gone before the next upload; ZIP contents remain intact');
        deliveryCheck(!is_dir($dir)&&!glob($config->storage.'/downloads/job-*'),$case.': no media survives completion or a post-send metadata failure');
        $speeds=json_decode($store->get('recent_speeds','[]'),true);
        deliveryCheck($count>0?(count($speeds)===1&&$speeds[0]['b']===$count*600000&&($speeds[0]['d']??$speeds[0]['b'])===1800000):$speeds===[],$case.': speed reports retain accurate sizes after local files are removed');
        // Sharing and returning to the menu retain Telegram IDs, never local media.
        deliveryCheck((int)$store->query("SELECT COUNT(*) FROM shares WHERE file_id<>''")->fetchColumn()===($case==='zip'?0:$count),$case.': confirmed Telegram audio IDs remain available for sharing');
        deliveryCheck($store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()===(in_array($case,['post-send-failure','upload-failure'],true)?'failed':'done'),$case.': job outcome reflects the delivery/state result');
    }
} finally { Zero\Download::clean($temp); }
