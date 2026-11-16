<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function playCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class PlayApi implements Zero\Api
{
    public array $calls=[]; public array $documents=[]; public bool $uncertain=false; private int $next=700;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if(isset($params['text'])&&$method!=='answerCallbackQuery') Zero\TextFormat::validate($params['text'],4096);
        if(isset($params['caption'])) Zero\TextFormat::validate($params['caption'],1024);
        if($method==='sendDocument') {
            usleep(3000); $file=$params['document']; $this->documents[]=['name'=>$file->getPostFilename(),'bytes'=>file_get_contents($file->getFilename()),'params'=>$params];
            return $this->uncertain?true:['message_id'=>++$this->next];
        }
        return $method==='sendMessage'?['message_id'=>++$this->next]:true;
    }
    public function last(string $method): array { foreach(array_reverse($this->calls) as [$name,$params]) if($name===$method) return $params; return []; }
}
$root=sys_get_temp_dir().'/ciro-play-'.bin2hex(random_bytes(5)); $oldPath=getenv('PATH'); mkdir($root,0700);
try {
    $config=new Zero\Config('123:fixture',[42,43],$root.'/data'); $store=new Zero\Store($config->storage); mkdir($config->storage.'/downloads',0700);
    $api=new PlayApi(); $bot=new Zero\Bot($config,$store,$api); $worker=new Zero\Worker($config,$store,$api); $serial=0;
    $url='https://play.google.com/store/apps/details?id=org.example.app';
    foreach([$url,$url.'&hl=fa&gl=IR','play.google.com/store/apps/details?id=org.example.app'] as $link) playCheck(Zero\playUrl($link)===$url&&Zero\mediaUrl($link)===$url,'one app URL canonicalized');
    foreach(['https://play.google.com/store/apps','https://play.google.com.evil.test/store/apps/details?id=org.example.app','https://evil.test/app.apk','https://user@play.google.com/store/apps/details?id=org.example.app','https://play.google.com:443/store/apps/details?id=org.example.app','https://play.google.com/store/apps/details?id[]=org.example.app','https://play.google.com/store/apps/details?id=../bad','market://details?id=org.example.app','com.instagram.android'] as $bad) playCheck(Zero\playUrl($bad)===null,'reject non-app, foreign or malformed URL');
    $input=function(int $user=7,int $chat=7,int $thread=0) use($bot,$url,&$serial): void {
        $bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$user],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread,'text'=>$url]]);
    };
    $tap=function(array $job,string $device='px_9a',?int $user=null,?int $chat=null,?int $message=null,?int $thread=null) use($bot,&$serial): void {
        $chat??=(int)$job['chat_id']; $bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'play-'.$serial,'from'=>['id'=>$user??(int)$job['user_id']],
            'message'=>['message_id'=>$message??(int)$job['message_id'],'from'=>['id'=>123],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread??(int)$job['thread_id']],'data'=>'gp:'.$job['id'].':'.$device]]);
    };
    $status=fn(array $job)=>$store->query('SELECT status FROM jobs WHERE id=?',[$job['id']])->fetchColumn();
    $input(); playCheck((int)$store->db->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&str_contains($api->last('sendMessage')['text'],'فعال نشده'),'new service is off by default');
    $store->set('play.enabled','1'); $store->set('admins_only','0'); $store->set('bot_id','123');
    $prepare=function(int $user=7,int $chat=7,int $thread=0) use($input,$worker,$store): array {
        $store->query('UPDATE users SET last_request=0 WHERE id=?',[$user]); $input($user,$chat,$thread); $job=$store->claim('media');
        if(!$job) throw new RuntimeException('Missing queued Play job');
        playCheck($job['phase']==='play','uses the media lane'); $worker->process($job);
        $job=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch();
        playCheck($job['status']==='waiting_quality'&&(int)$job['expires']>time(),'opens one expiring device menu'); return $job;
    };
    $job=$prepare(); $menu=$api->last('editMessageText');
    playCheck(count($menu['reply_markup']['inline_keyboard'])===5&&str_contains($menu['text'],'org.example.app'),'four profiles and cancel in the original status message');
    foreach([fn()=>$tap($job,'px_9a',43,43),fn()=>$tap($job,'px_9a',7,7,(int)$job['message_id']+1),fn()=>$tap($job,'px_9a',7,-200)] as $foreign) {
        $count=count($api->calls); $foreign(); playCheck(count($api->calls)===$count+1&&$api->last('answerCallbackQuery')['show_alert']&&$status($job)==='waiting_quality','foreign admin, copied or stale message only gets an alert');
    }
    playCheck($store->choose((int)$job['id'],7,7,null)===null&&$status($job)==='waiting_quality','video quality callbacks cannot cancel or convert a Play request');
    $tap($job); $again=count($api->calls); $tap($job);
    playCheck($status($job)==='queued'&&count($api->calls)===$again+1&&$store->get('play.device.7')==='px_9a','one tap queues once and remembers only the owner profile');
    $worker->process($store->claim('media'));
    playCheck($status($job)==='failed'&&!$api->documents&&str_contains($api->last('editMessageText')['text'],'نشست'),'missing credentials get a clear notice, not a fake download');
    $session="[google]\nemail = fixture@example.test\naas_token = made_up_fixture_token_123456789\n";
    mkdir(dirname(Zero\GooglePlay::sessionPath($config)),0700); file_put_contents(Zero\GooglePlay::sessionPath($config),$session); chmod(Zero\GooglePlay::sessionPath($config),0600);
    playCheck(Zero\GooglePlay::configured($config),'valid private session file recognized');
    chmod(Zero\GooglePlay::sessionPath($config),0644); playCheck(!Zero\GooglePlay::configured($config),'publicly readable session refused'); chmod(Zero\GooglePlay::sessionPath($config),0600);
    foreach(["[google]\nemail=x\naas_token=abc",$session."auth_token=also_a_made_up_token\n",str_repeat('a',17000)] as $bad) {
        try { Zero\GooglePlay::session($bad); $rejected=false; } catch(Zero\MediaFailure $e) { $rejected=!str_contains($e->getMessage(),'fixture_token'); }
        playCheck($rejected,'invalid credentials rejected without disclosure');
    }
    mkdir($root.'/bin',0700); file_put_contents($root.'/bin/mode','single');
    file_put_contents($root.'/bin/apkeep', <<<'PY'
#!/usr/bin/env python3
import sys,os,zipfile,stat,time,sqlite3
from pathlib import Path
args=sys.argv[1:]; home=Path(__file__).parent; mode=(home/'mode').read_text()
package=args[args.index('-a')+1]; ini=Path(args[args.index('-i')+1]); out=Path(args[-1]); options=args[args.index('-o')+1]
assert args[args.index('-d')+1]=='google-play' and 'split_apk=true' in options and 'device=' in options
assert stat.S_IMODE(ini.stat().st_mode)==0o600 and not any('fixture_token' in x for x in args)
if mode=='auth':
 print(ini.read_text(),file=sys.stderr); print('Could not log in to Google Play',file=sys.stderr); sys.exit(1)
if mode=='skip':
 print('Invalid app response. Skipping...'); sys.exit(0)
if mode=='split': out=out/package; out.mkdir()
if mode=='huge':
 (out/package).mkdir(); (out/package/'large.apk').write_bytes(b'x'*1500000); time.sleep(3); sys.exit(1)
if mode=='html':
 (out/(package+'.apk')).write_text('<html>'+('not an APK'*30)+'</html>'); print(package+' downloaded successfully!'); sys.exit(0)
def apk(path):
 with zipfile.ZipFile(path,'w') as z:
  if mode!='no_manifest': z.writestr(zipfile.ZipInfo('AndroidManifest.xml'),'fixture_manifest')
  z.writestr(zipfile.ZipInfo('classes.dex'),'fixture_dex'*60000)
apk(out/(package+('.config.arm.apk' if mode=='missing_base' else '.apk')))
if mode=='split': apk(out/(package+'.config.arm64_v8a.apk'))
if mode=='revoked':
 db=sqlite3.connect(out.parents[2]/'zero.sqlite'); db.execute("UPDATE settings SET value='0' WHERE key='play.enabled'"); db.commit()
if mode=='partial': sys.exit(0)
print(package+' downloaded successfully!')
PY);
    chmod($root.'/bin/apkeep',0700); putenv('PATH='.$root.'/bin:'.$oldPath);
    foreach(['single','split'] as $mode) {
        file_put_contents($root.'/bin/mode',$mode); $store->set('play.source_button',$mode==='split'?'1':'0');
        $job=$prepare(); playCheck(str_starts_with($api->last('editMessageText')['reply_markup']['inline_keyboard'][0][0]['text'],'✅'),'last profile is displayed on the next request');
        $tap($job); $worker->process($store->claim('media')); $doc=end($api->documents);
        playCheck($status($job)==='done'&&str_ends_with($doc['name'],$mode==='split'?'.apks':'.apk'),'confirmed '.$mode.' document delivered');
        playCheck(!glob($config->storage.'/downloads/job-*'),'private job files cleaned after delivery');
        if($mode==='split') {
            $archive=$root.'/result.apks'; file_put_contents($archive,$doc['bytes']); $zip=new ZipArchive(); $zip->open($archive);
            playCheck($zip->numFiles===2&&$zip->locateName('base.apk')!==false,'APKS contains base and selected split together');
            $base=$zip->getFromName('base.apk'); $split=$zip->getFromName('org.example.app.config.arm64_v8a.apk'); $zip->close();
            playCheck($base===$split&&str_contains($doc['params']['caption'],'SAI'),'APK bytes are preserved and split installation is explained');
            mkdir($root.'/overhead',0700); file_put_contents($root.'/overhead/org.example.app.apk',$base); file_put_contents($root.'/overhead/org.example.app.config.arm.apk',$split);
            try { Zero\GooglePlay::artifact('org.example.app',$root.'/overhead',$root,strlen($base)+strlen($split)); $oversize=false; } catch(Zero\MediaFailure $e) { $oversize=$e->reason==='size'; }
            playCheck($oversize,'APKS overhead is included in the final upload size limit');
            playCheck(($doc['params']['reply_markup']['inline_keyboard'][0][0]['url']??'')===$url,'optional source is a real URL button');
        } else playCheck(empty($doc['params']['reply_markup']),'source button defaults off');
    }
    playCheck((int)$store->query("SELECT SUM(ok) FROM stats WHERE service='play'")->fetchColumn()===2&&count(json_decode($store->get('recent_speeds','[]'),true))===2,'downloads count under Play statistics and speed');
    foreach(['skip','partial','html','no_manifest','missing_base','auth','huge','revoked'] as $mode) {
        file_put_contents($root.'/bin/mode',$mode); $config->maxBytes=$mode==='huge'?1000000:49000000; $job=$prepare(); $tap($job); $before=count($api->documents); $worker->process($store->claim('media'));
        playCheck($status($job)==='failed'&&count($api->documents)===$before,'no document on '.$mode.' result');
        playCheck(!str_contains(json_encode($api->calls),'made_up_fixture_token')&&!str_contains(json_encode($store->query('SELECT * FROM settings')->fetchAll()),'made_up_fixture_token'),'no session secret in notices or database');
        playCheck(!glob($config->storage.'/downloads/job-*'),'failed job and session snapshot cleaned'); $store->set('play.enabled','1');
    }
    file_put_contents($root.'/bin/mode','single'); $config->maxBytes=49000000; $api->uncertain=true; $job=$prepare(); $tap($job); $before=count($api->documents); $worker->process($store->claim('media'));
    playCheck($status($job)==='failed'&&count($api->documents)===$before+1,'uncertain send is never retried'); $api->uncertain=false;
    $job=$prepare(); $tap($job,'cancel'); playCheck($status($job)==='cancelled','cancel retires the menu');
    $job=$prepare(); $store->query('UPDATE jobs SET expires=? WHERE id=?',[time()-1,$job['id']]); $tap($job); playCheck($status($job)==='waiting_quality'&&$api->last('answerCallbackQuery')['show_alert'],'expired selection cannot queue');
    $store->query("UPDATE jobs SET status='cancelled' WHERE id=?",[$job['id']]);
    $store->set('groups_enabled','1'); $job=$prepare(7,-100,17); $tap($job,'px_9a',7,-100,null,18); playCheck($status($job)==='waiting_quality','different topic cannot operate menu');
    $tap($job); $worker->process($store->claim('media')); $doc=end($api->documents); playCheck(($doc['params']['message_thread_id']??0)===17,'group APK delivered in the original topic');
    foreach(Zero\UiRegistry::items('play',Zero\Ui::definitions()) as [$kind,$key]) playCheck($bot->ui->customizable($kind,$key),'public Play field reachable in Personalization: '.$key);
    playCheck($bot->ui->required('play_cancel'),'cancel cannot be hidden');
    $store->set('text.play_queued','CUSTOM PLAY'); playCheck($bot->ui->forService('play')->text('play_queued')==='CUSTOM PLAY'&&$bot->ui->text('queued')!=='CUSTOM PLAY','Play overrides do not change other services');
} finally { putenv('PATH='.$oldPath); Zero\Download::clean($root); }
