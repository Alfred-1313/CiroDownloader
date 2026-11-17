<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';require dirname(__DIR__).'/src/Bot.php';require dirname(__DIR__).'/src/Download.php';require dirname(__DIR__).'/src/Worker.php';
function ycCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
final class YoutubeCheckerApi implements Zero\Api {
    public array $calls=[];public bool $uncertain=false;public bool $uncertainCover=false;public int $next=500;public array $admins=[42];public array $videos=[];public array $audios=[];
    public function call(string $method,array $p=[]):mixed{
        $this->calls[]=[$method,$p];if(isset($p['text'])&&$method!=='answerCallbackQuery')Zero\TextFormat::validate($p['text'],4096);if(isset($p['caption']))Zero\TextFormat::validate($p['caption'],1024);
        if($method==='editMessageMedia')return ['message_id'=>$p['message_id'],'photo'=>[['file_id'=>'fixture_photo']]];
        if($method==='getChatMember')return ['status'=>in_array($p['user_id'],$this->admins,true)?'administrator':'member'];
        if($method==='sendVideo'){$this->videos[]=['caption'=>$p['caption'],'topic'=>$p['message_thread_id']??0,'bytes'=>filesize($p['video']->getFilename())];if($this->uncertain)return true;}
        if($method==='sendAudio')$this->audios[]=['caption'=>$p['caption'],'topic'=>$p['message_thread_id']??0,'bytes'=>filesize($p['audio']->getFilename())];
        if($method==='sendPhoto'&&$this->uncertainCover)return true;
        return in_array($method,['sendMessage','sendVideo','sendAudio','sendPhoto','editMessageMedia'],true)?['message_id'=>++$this->next]:true;
    }
    public function last():array{return end($this->calls)?:[];}
    public function action(string $needle):string{foreach(array_reverse($this->calls)as[$m,$p])foreach($p['reply_markup']['inline_keyboard']??[]as$row)foreach($row as$b)if(str_contains($b['callback_data']??'',$needle))return $b['callback_data'];throw new RuntimeException('Missing action '.$needle);}
}
$root=sys_get_temp_dir().'/ciro-yt-checker-'.bin2hex(random_bytes(5));mkdir($root,0700);
try{
 $c=new Zero\Config('123:fixture',[42],$root.'/data','https://api.telegram.org',49000000,$root.'/yt-test');$s=new Zero\Store($c->storage);$a=new YoutubeCheckerApi();$b=new Zero\Bot($c,$s,$a);$m=new Zero\YoutubeChecker($c,$s,$a);$ig=new Zero\InstagramChecker($c,$s,$a);
 ycCheck(!Zero\YoutubeChecker::enabled($s)&&!Zero\YoutubeChecker::everyone($s)&&$m->limit(7)===3&&$m->limit(42)===0,'new checker is off, public access is off, ordinary quota is three and roots are unlimited');
 foreach(['@YouTube'=>'@youtube','https://www.youtube.com/@YouTube'=>'@youtube','UC'.str_repeat('A',22)=>'UC'.str_repeat('A',22)]as$input=>$expected)ycCheck($m->normalizeName($input)===$expected,'channel IDs and handles are normalized without damaging case-sensitive IDs');
 foreach(['https://youtube.com.evil.test/@x','https://user@youtube.com/@YouTube','https://youtube.com:443/@YouTube','https://youtube.com/watch?v=AAAAAAAAAAA','@@YouTube','a/b']as$bad)ycCheck($m->normalizeName($bad)===null,'foreign, media and malformed channel inputs are refused');
 $s->set('youtube.checker.enabled','1');$s->set('admins_only','0');$s->set('groups_enabled','1');
 ycCheck(!$m->allowed(7,7)&&$m->allowed(42,42),'admin-only default is enforced by the shared checker');
 $s->set('youtube.checker.public','1');ycCheck(!$m->allowed(7,-100)&&$m->allowed(42,-100),'public group registration still requires the group administrator');
 foreach(['one','two','three']as$name)$m->add(7,7,0,$name,7);
 try{$m->add(7,7,0,'four',7);$limited=false;}catch(RuntimeException $e){$limited=$e->getMessage()==='checker_limit_reached';}ycCheck($limited,'ordinary accounts cannot exceed the channel cap');
 $s->set('youtube.checker.limit.42','1');foreach(['rootone','roottwo']as$name)$m->add(42,42,0,$name,7);ycCheck($m->used(42)===2,'saved user caps cannot limit configured roots');
 $s->query('DELETE FROM yt_check_subs');$s->query('DELETE FROM yt_check_targets');
 $id=$m->add(42,42,0,'@testchannel',7);$group=$m->add(42,-100,17,'@testchannel',7);ycCheck($m->used(42)===1,'one channel across destinations uses one quota slot');
 $account='UC'.str_repeat('A',22);$post='Ug'.str_repeat('P',20);
 $snapshot=['account_id'=>$account,'mode'=>7,'videos'=>[['id'=>'AAAAAAAAAAA','url'=>'https://www.youtube.com/watch?v=AAAAAAAAAAA','time'=>time()]],'shorts'=>[['id'=>'BBBBBBBBBBB','url'=>'https://www.youtube.com/shorts/BBBBBBBBBBB','time'=>time()]],'posts'=>[['id'=>$post,'url'=>'https://www.youtube.com/post/'.$post,'time'=>time()]]];
 $m->poll(fn()=>$snapshot);ycCheck((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'first complete scan baselines all three kinds without replay');
 $snapshot['videos'][]=['id'=>'CCCCCCCCCCC','url'=>'https://www.youtube.com/watch?v=CCCCCCCCCCC','time'=>time()];$snapshot['shorts'][]=['id'=>'DDDDDDDDDDD','url'=>'https://www.youtube.com/shorts/DDDDDDDDDDD','time'=>time()];$snapshot['posts'][]=['id'=>$post.'x','url'=>'https://www.youtube.com/post/'.$post.'x','time'=>time()];
 $s->query('UPDATE yt_check_targets SET next_check=0');$m->poll(fn()=>$snapshot);ycCheck((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===6,'each new video, Short and post is queued once per destination');
 $s->query('UPDATE yt_check_targets SET next_check=0');$m->poll(fn()=>$snapshot);ycCheck((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===6,'repeated scans do not duplicate content');
 ycCheck((int)$s->query('SELECT COUNT(*) FROM ig_check_targets')->fetchColumn()===0,'YouTube targets and seen IDs remain isolated from Instagram');
 $job=$s->query("SELECT * FROM jobs WHERE phase='youtube_checker' AND chat_id=-100 ORDER BY id LIMIT 1")->fetch();ycCheck(Zero\InstagramChecker::forJob($c,$s,$a,$job)instanceof Zero\YoutubeChecker,'delivery factory resolves the service before reading checker IDs');
 mkdir($c->storage.'/downloads',0700);$runner=new Zero\Download($c);$source=$root.'/fixture.mp4';
 $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=640x360:r=1:d=1','-f','lavfi','-i','anullsrc=r=8000:cl=mono','-shortest','-c:v','libx264','-threads','2','-c:a','aac',$source],$root,30);
 $info=['title'=>'<video> & title','formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac','abr'=>96]]];
 Zero\youtubeCookiePool($c)->add("# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSID\tMANUAL_FIXTURE\n");
 Zero\YoutubeCheckerMedia::pool($c)->add("# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSID\tCHECKER_FIXTURE\n");
 file_put_contents($c->ytDlp,"#!/usr/bin/env php\n<?php\n".'$i=array_search("--cookies",$argv,true);if($i===false||!str_contains(file_get_contents($argv[$i+1]),"CHECKER_FIXTURE")){exit(1);}if(in_array("--dump-single-json",$argv,true)){echo '.var_export(json_encode($info),true).';exit;} $out=$argv[array_search("-o",$argv,true)+1];copy('.var_export($source,true).',str_replace("%(ext)s","mp4",$out));echo json_encode("<video> & title").PHP_EOL;');chmod($c->ytDlp,0700);
 $s->query("UPDATE jobs SET status='running' WHERE id=?",[$job['id']]);(new Zero\Worker($c,$s,$a))->process($job);
 ycCheck($s->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn()==='done'&&count($a->videos)===1&&$a->videos[0]['topic']===17&&str_contains($a->videos[0]['caption'],'&lt;video&gt; &amp; title'),'real worker selects an available quality and sends one escaped-caption video into the original topic');
 ycCheck(!glob($c->storage.'/downloads/job-*'),'confirmed automatic videos release local media immediately');
 $job=$s->query("SELECT * FROM jobs WHERE phase='youtube_checker' AND status='queued' ORDER BY id LIMIT 1")->fetch();$s->query("UPDATE jobs SET status='running' WHERE id=?",[$job['id']]);$a->uncertain=true;(new Zero\Worker($c,$s,$a))->process($job);$m->queuePending();
 ycCheck($m->context($job)['status']==='uncertain','an uncertain automatic send is never put back into the download queue');$a->uncertain=false;
 // A regular upload offers the exact manual cover/quality flow, with a persistent checker binding.
 $videoJob=$s->query("SELECT * FROM jobs WHERE phase='inspect' AND chat_id=-100 LIMIT 1")->fetch();$beforeVideos=count($a->videos);
 $s->query("UPDATE jobs SET status='running' WHERE id=?",[$videoJob['id']]);(new Zero\Worker($c,$s,$a))->process($videoJob);
 $videoJob=$s->query('SELECT * FROM jobs WHERE id=?',[$videoJob['id']])->fetch();$coverId=(int)$videoJob['message_id'];$qualityAction=$a->action('quality:'.$videoJob['id'].':360');
 $cover=[];foreach(array_reverse($a->calls)as[$method,$params])if($method==='sendPhoto'){$cover=$params;break;}
 ycCheck($videoJob['status']==='waiting_quality'&&$m->context($videoJob)['status']==='offered'&&count($a->videos)===$beforeVideos&&($cover['message_thread_id']??0)===17,'regular uploads send a cover in the original topic and wait for an explicit quality choice');
 ycCheck(str_contains($cover['caption'],'<blockquote>کانال @testchannel پست جدید گذاشته است.</blockquote>')&&json_decode($videoJob['options'],true)['checker_service']==='youtube','the cover quotes the channel and preserves checker identity beside the offered formats');
 $s->query('UPDATE yt_check_targets SET next_check=0');$m->poll(fn()=>$snapshot);$m->queuePending();
 ycCheck($m->context($videoJob)['status']==='offered'&&(int)$s->query("SELECT COUNT(*) FROM jobs WHERE phase='inspect' AND chat_id=-100")->fetchColumn()===1,'fresh scans and queue recovery do not resend an offered cover');
 $qualityTap=function(string $action,int $user=42,?int $mid=null,?int $topic=17)use($b,$coverId):void{
     $message=['message_id'=>$mid??$coverId,'chat'=>['id'=>-100,'type'=>'supergroup']];if($topic!==null)$message['message_thread_id']=$topic;
     $b->handle(['callback_query'=>['id'=>'quality-fixture','from'=>['id'=>$user],'message'=>$message,'data'=>$action]]);
 };
 $qualityTap($qualityAction,7);$qualityTap($qualityAction,42,$coverId+100);$qualityTap($qualityAction,42,null,18);$qualityTap('quality:'.$videoJob['id'].':1080');
 ycCheck($s->query('SELECT status FROM jobs WHERE id=?',[$videoJob['id']])->fetchColumn()==='waiting_quality','another user, copied message/topic and unavailable quality cannot start the checker download');
 $qualityTap($qualityAction,42,null,null);$qualityTap($qualityAction);
 $videoJob=$s->query('SELECT * FROM jobs WHERE id=?',[$videoJob['id']])->fetch();
 ycCheck($videoJob['status']==='queued'&&$videoJob['phase']==='download'&&(int)$videoJob['quality']===360,'the owner can choose one offered quality with absent topic metadata recovered from the stored job');
 $s->query("UPDATE jobs SET status='running' WHERE id=?",[$videoJob['id']]);(new Zero\Worker($c,$s,$a))->process($videoJob);
 ycCheck(count($a->videos)===$beforeVideos+1&&$m->context($videoJob)['status']==='sent'&&end($a->videos)['topic']===17,'only the selected regular video is delivered and confirmed once');
 ycCheck(!glob($c->storage.'/downloads/job-*'),'interactive checker deliveries release their temporary files');
 $snapshot['videos'][]=['id'=>'EEEEEEEEEEE','url'=>'https://www.youtube.com/watch?v=EEEEEEEEEEE','time'=>time()];$s->query('UPDATE yt_check_targets SET next_check=0');$m->poll(fn()=>$snapshot);
 $audioJob=$s->query("SELECT * FROM jobs WHERE url='https://www.youtube.com/watch?v=EEEEEEEEEEE' AND chat_id=-100")->fetch();
 $s->query("UPDATE jobs SET status='running' WHERE id=?",[$audioJob['id']]);(new Zero\Worker($c,$s,$a))->process($audioJob);$audioJob=$s->query('SELECT * FROM jobs WHERE id=?',[$audioJob['id']])->fetch();
 $qualityTap('quality:'.$audioJob['id'].':audio',42,(int)$audioJob['message_id']);$audioJob=$s->query('SELECT * FROM jobs WHERE id=?',[$audioJob['id']])->fetch();
 $s->query("UPDATE jobs SET status='running' WHERE id=?",[$audioJob['id']]);(new Zero\Worker($c,$s,$a))->process($audioJob);
 ycCheck($audioJob['phase']==='audio'&&count($a->audios)===1&&$m->context($audioJob)['status']==='sent'&&$a->audios[0]['topic']===17&&str_contains($a->audios[0]['caption'],'کانال @testchannel'),'audio selection downloads with the checker session and delivers one quoted-caption MP3 in the original topic');
 $snapshot['videos'][]=['id'=>'FFFFFFFFFFF','url'=>'https://www.youtube.com/watch?v=FFFFFFFFFFF','time'=>time()];$s->query('UPDATE yt_check_targets SET next_check=0');$m->poll(fn()=>$snapshot);
 $coverJob=$s->query("SELECT * FROM jobs WHERE url='https://www.youtube.com/watch?v=FFFFFFFFFFF' AND chat_id=-100")->fetch();$a->uncertainCover=true;
 $s->query("UPDATE jobs SET status='running' WHERE id=?",[$coverJob['id']]);(new Zero\Worker($c,$s,$a))->process($coverJob);$m->queuePending();$a->uncertainCover=false;
 ycCheck($m->context($coverJob)['status']==='uncertain'&&(int)$m->context($coverJob)['attempts']===1,'an uncertain cover send stays terminal instead of duplicating the notification');
 // A text community post uses the real fetch/worker path while the manual-post feature remains off.
 file_put_contents($root.'/python',"#!/usr/bin/env php\n<?php echo json_encode(['description'=>'<post> & text','author'=>'channel','files'=>[]]);");chmod($root.'/python',0700);
 $textJob=$s->query("SELECT * FROM jobs WHERE phase='youtube' AND chat_id=42 LIMIT 1")->fetch();$s->query("UPDATE jobs SET status='running' WHERE id=?",[$textJob['id']]);(new Zero\Worker($c,$s,$a))->process($textJob);
 ycCheck($s->query('SELECT status FROM jobs WHERE id=?',[$textJob['id']])->fetchColumn()==='done'&&$m->context($textJob)['status']==='sent'&&str_contains($a->last()[1]['text']??'','&lt;post&gt; &amp; text'),'text posts use checker captions and confirmed delivery even when manual post downloads are off');
 $postJob=$s->query("SELECT * FROM jobs WHERE phase='youtube' AND chat_id=-100 LIMIT 1")->fetch();$media=[];
 for($n=1;$n<=2;$n++){$photo=$root.'/photo'.$n.'.jpg';$runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$source,'-frames:v','1',$photo],$root,30);$media[]=['path'=>$photo,'type'=>'photo','mime'=>'image/jpeg','name'=>'photo.jpg','description'=>'carousel','author'=>'channel'];}
 $pages=new Zero\GalleryPages($c,$s,$a,'youtube');$prepared=$pages->prepare($postJob,$media);$pages->deliver($prepared,$b);$pageJob=$s->query('SELECT * FROM jobs WHERE id=?',[$postJob['id']])->fetch();$pageState=json_decode($pageJob['options'],true)['pages'];
 ycCheck(($pageState['checker_service']??'')==='youtube'&&$m->context($pageJob)['status']==='sent','community carousel retains the YouTube checker identity after gallery preparation');
 ycCheck($pages->queue((int)$pageJob['id'],7,-100,(int)$pageState['message'],17,2,(int)$pageState['revision'])==='youtube_checker_post_page_owner','another group member cannot turn the checker carousel');
 ycCheck($pages->queue((int)$pageJob['id'],42,-100,(int)$pageState['message'],17,2,(int)$pageState['revision'])==='','owner can queue the next community slide independently of the manual-post switch');
 $pageJob=$s->query('SELECT * FROM jobs WHERE id=?',[$postJob['id']])->fetch();$s->query("UPDATE jobs SET status='running' WHERE id=?",[$pageJob['id']]);(new Zero\Worker($c,$s,$a))->process($pageJob);ycCheck($s->query('SELECT status FROM jobs WHERE id=?',[$pageJob['id']])->fetchColumn()==='done','the real worker turns a checker post carousel in the original topic');
 $serial=200;
 $send=function(string $text,int $user=42,int $chat=42)use($b,&$serial):void{$b->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$user],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?17:0,'text'=>$text]]);};
 $tap=function(string $action,int $user=42,int $chat=42,?int $mid=null)use($b,$a,&$serial):void{$b->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'yc'.$serial,'from'=>['id'=>$user],'message'=>['message_id'=>$mid??$a->next,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?17:0],'data'=>$action]]);};
 $send('/youtube_checker',42,-100);$action=$a->action(':item:');$before=count($a->calls);$tap($action,7,-100);ycCheck(count($a->calls)===$before+1&&$a->last()[0]==='answerCallbackQuery'&&$a->last()[1]['show_alert'],'another user gets only an ownership alert on the checker menu');
 $send('/youtube_checker');$action=$a->action(':item:');$tap($action);$verify=$a->action(':check:');$tap($verify);ycCheck((int)$s->query("SELECT COUNT(*) FROM yt_check_panels WHERE owner=42 AND chat_id=42 AND view='item' AND json_type(data,'$.check')='object'")->fetchColumn()===1,'manual verification schedules a fresh scan on the same panel');
 $send('/customize');$tap('custom:service:youtube');$settings=$a->action('youtube_checker:settings');$tap($settings);$quality=$a->action(':quality');$tap($quality);ycCheck($s->get('youtube.checker.quality')==='1080','YouTube Personalization opens checker settings and changes automatic quality');
 foreach(Zero\UiRegistry::items('youtube',Zero\Ui::definitions())as[$kind,$key])if(str_starts_with($key,'youtube_checker'))ycCheck($b->ui->customizable($kind,$key),'YouTube checker public field is editable: '.$key);
 $s->query('UPDATE yt_check_targets SET next_check=0');$m->poll(function(){throw new Zero\MediaFailure('blocked','fixture');});(new Zero\YoutubeCheckerPanel($b))->refreshChecks();ycCheck($s->query('SELECT state FROM yt_check_targets')->fetchColumn()==='blocked'&&!$m->due(),'rate refusals preserve backoff instead of trying more endpoints');
 $queuedJob=$s->query("SELECT * FROM jobs WHERE phase='inspect' AND chat_id=42 AND status='queued' LIMIT 1")->fetch();
 $s->query("UPDATE jobs SET status='running' WHERE id=?",[$queuedJob['id']]);(new Zero\Worker($c,$s,$a))->process($queuedJob);$queuedJob=$s->query('SELECT * FROM jobs WHERE id=?',[$queuedJob['id']])->fetch();
 ycCheck($queuedJob['status']==='waiting_quality'&&$m->canDeliver($queuedJob),'an offered private video remains selectable before pause');
 ycCheck($m->change($id,42,42,0,'pause')&&!$m->canDeliver($queuedJob),'pause invalidates unsent checker work');
 $b->handle(['callback_query'=>['id'=>'paused-quality','from'=>['id'=>42],'message'=>['message_id'=>$queuedJob['message_id'],'chat'=>['id'=>42,'type'=>'private']],'data'=>'quality:'.$queuedJob['id'].':360']]);
 ycCheck($a->last()[0]==='answerCallbackQuery'&&$a->last()[1]['show_alert']&&$m->context($queuedJob)['status']==='cancelled','an offered cover cannot queue a file after its subscription is paused');
}finally{Zero\Download::clean($root);}
