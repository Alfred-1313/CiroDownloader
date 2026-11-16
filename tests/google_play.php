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
    playCheck(Zero\playUrl('https://play.google.com/store/apps/details?id=com.zhiliaoapp.musically')==='https://play.google.com/store/apps/details?id=com.zhiliaoapp.musically','the reported TikTok app URL is a supported Play app link');
    $checkSession=function()use($config):array {
        $process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/play-session.php','--check'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['BOT_TOKEN'=>'123:fixture','ADMIN_IDS'=>'42','STORAGE_DIR'=>$config->storage]));
        if(!is_resource($process))throw new RuntimeException('Cannot run session check');
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        playCheck(!str_contains($out.$err,'fixture@example.test')&&!str_contains($out.$err,'made_up_fixture_token'),'the local session check never discloses email or token');
        return [$code,json_decode($out,true),$err];
    };
    [$exit,$check]=$checkSession();playCheck($exit===2&&$check===['session'=>'missing','login_tested'=>false],'missing local credentials are identified without network login or input prompts');
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
    $labels=array_column(array_merge(...$menu['reply_markup']['inline_keyboard']),'text');
    playCheck(str_contains(implode(' ',$labels),'ARM64')&&str_contains(implode(' ',$labels),'ARM32')&&str_contains(implode(' ',$labels),'x86_64 (AMD / Intel)')
        &&!preg_match('/Pixel|S20|A13|HPE|Universal/i',implode(' ',$labels)),'the real menu offers processor architectures without phone models or a false universal option');
    foreach([fn()=>$tap($job,'px_9a',43,43),fn()=>$tap($job,'px_9a',7,7,(int)$job['message_id']+1),fn()=>$tap($job,'px_9a',7,-200)] as $foreign) {
        $count=count($api->calls); $foreign(); playCheck(count($api->calls)===$count+1&&$api->last('answerCallbackQuery')['show_alert']&&$status($job)==='waiting_quality','foreign admin, copied or stale message only gets an alert');
    }
    playCheck($store->choose((int)$job['id'],7,7,null)===null&&$status($job)==='waiting_quality','video quality callbacks cannot cancel or convert a Play request');
    $tap($job); $again=count($api->calls); $tap($job);
    playCheck($status($job)==='queued'&&count($api->calls)===$again+1&&$store->get('play.device.7')==='px_9a','one tap queues once and remembers only the owner profile');
    $worker->process($store->claim('media'));
    playCheck($status($job)==='failed'&&!$api->documents&&$api->last('editMessageText')['text']===$bot->text('play_error_cookies'),'missing credentials get the editable user notice, not a fake download');
    $session="[google]\nemail = fixture@example.test\naas_token = made_up_fixture_token_123456789\n";
    $oauth='oauth2_4/made_up_oauth_token_123456789'; $exchangeCalls=0;
    $exchange=function(string $address,string $form)use(&$exchangeCalls,$oauth):array {
        $exchangeCalls++; parse_str($form,$fields);
        playCheck($address==='https://android.clients.google.com/auth'&&$fields['Email']==='fixture@example.test'&&$fields['Token']===$oauth
            &&$fields['service']==='ac2dm'&&$fields['callerPkg']==='com.google.android.gms'&&$fields['ACCESS_TOKEN']==='1'
            &&$fields['callerSig']==='38918a453d07199354f8b19af05ec6562ced5788','one-time exchange targets the pinned gpapi auth contract directly');
        return [200,"SID=fixture\nToken=made_up_fixture_token_123456789\r\n"];
    };
    playCheck(Zero\GooglePlay::oauthSession('fixture@example.test',$oauth,$exchange)===$session&&$exchangeCalls===1,'browser OAuth exchanges once and returns only a normalized AAS session');
    foreach(['wrong_prefix_token_123456789',"oauth2_4/bad\nToken=secret",str_repeat('a',17000)]as$invalid){
        try{Zero\GooglePlay::oauthSession('fixture@example.test',$invalid,$exchange);$rejected=false;}catch(Zero\MediaFailure){$rejected=true;}
        playCheck($rejected&&$exchangeCalls===1,'invalid browser tokens never reach the network');
    }
    foreach([[302,'Token=made_up_fixture_token_123456789'],[429,'Error=RateLimit'],[200,'Error=BadAuthentication'],[200,'<html>not a token</html>'],[200,"Token=made_up_fixture_token_123456789\ntoken=duplicate_token_123456789"],[200,str_repeat('x',17000)],[200,'Token=bad']]as$response){
        $attempts=0;
        try{Zero\GooglePlay::oauthSession('fixture@example.test',$oauth,function()use($response,&$attempts){$attempts++;return $response;});$safe=false;}
        catch(Zero\MediaFailure $error){$safe=$error->reason==='cookies'&&!str_contains($error->getMessage(),$oauth)&&!str_contains($error->getMessage(),'fixture@example.test');}
        playCheck($safe&&$attempts===1,'a refused, redirected, oversized or malformed OAuth response is sanitized and never retried');
    }
    playCheck(Zero\GooglePlay::session("[google]\nemail = \"fixture@example.test\"\naas_token = \"made_up_fixture_token_123456789\"\n")===$session,'old quoted session files normalize to bare apkeep credentials without changing the token');
    playCheck(Zero\GooglePlay::session("[google]\nemail = \"fixture@example.test\"\nauth_token = \"ya29.made_up_fixture_token_123456789\"\n")==="[google]\nemail = fixture@example.test\nauth_token = ya29.made_up_fixture_token_123456789\n",'auth tokens also reach apkeep without surrounding quote characters');
    mkdir(dirname(Zero\GooglePlay::sessionPath($config)),0700); file_put_contents(Zero\GooglePlay::sessionPath($config),$session); chmod(Zero\GooglePlay::sessionPath($config),0600);
    playCheck(Zero\GooglePlay::configured($config),'valid private session file recognized');
    $prepend=$root.'/oauth-fixture.php';file_put_contents($prepend,<<<'PHP'
<?php
namespace Zero;
function curl_init($url){if($url!=='https://android.clients.google.com/auth')throw new \RuntimeException();return new \stdClass();}
function curl_setopt_array($h,$o){
    if($o[CURLOPT_FOLLOWLOCATION]!==false||$o[CURLOPT_SSL_VERIFYPEER]!==true||$o[CURLOPT_SSL_VERIFYHOST]!==2
        ||$o[CURLOPT_PROTOCOLS]!==CURLPROTO_HTTPS||$o[CURLOPT_TIMEOUT]>30)throw new \RuntimeException();
    $h->options=$o;return true;
}
function curl_exec($h){
    parse_str($h->options[CURLOPT_POSTFIELDS],$f);
    if($f['Email']!=='fixture@example.test'||!str_starts_with($f['Token'],'oauth2_4/made_up_'))throw new \RuntimeException();
    $body=str_ends_with($f['Token'],'reject')?'Error=BadAuthentication':"Token=made_up_fixture_token_123456789\n";
    return ($h->options[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
function curl_getinfo($h,$key){return 200;}
function curl_close($h){}
PHP);
    $runSetup=function(string $input)use($config,$prepend):array{
        $p=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$prepend,dirname(__DIR__).'/bin/play-session.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['BOT_TOKEN'=>'123:fixture','ADMIN_IDS'=>'42','STORAGE_DIR'=>$config->storage]));
        fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out];
    };
    [$code,$output]=$runSetup("fixture@example.test\noauth_token\n".$oauth."\n");
    playCheck($code===0&&str_contains($output,'Session saved privately')&&file_get_contents(Zero\GooglePlay::sessionPath($config))===$session
        &&(fileperms(Zero\GooglePlay::sessionPath($config))&0077)===0&&!str_contains($output,$oauth)&&!str_contains($output,'made_up_fixture_token'),'interactive OAuth setup stores only the resulting private AAS session without printing credentials');
    [$code,$output]=$runSetup("fixture@example.test\noauth_token\noauth2_4/made_up_reject\n");
    playCheck($code===1&&str_contains($output,'Existing credentials were kept')&&file_get_contents(Zero\GooglePlay::sessionPath($config))===$session
        &&!str_contains($output,'BadAuthentication'),'a rejected CLI exchange preserves the existing session and hides raw Google replies');
    [$exit,$check]=$checkSession();playCheck($exit===0&&$check===['session'=>'ready','login_tested'=>false],'a valid local file is not presented as a verified Google login');
    chmod(Zero\GooglePlay::sessionPath($config),0644); playCheck(!Zero\GooglePlay::configured($config),'publicly readable session refused'); chmod(Zero\GooglePlay::sessionPath($config),0600);
    $path=Zero\GooglePlay::sessionPath($config);chmod($path,0644);[$exit,$check]=$checkSession();playCheck($exit===2&&$check['session']==='insecure_permissions','the local check identifies unsafe permissions without changing them');chmod($path,0000);if(!is_readable($path)){[$exit,$check]=$checkSession();playCheck($exit===2&&$check['session']==='unreadable','the check runs with the worker account permissions');}chmod($path,0600);
    file_put_contents($path,'invalid fixture');[$exit,$check]=$checkSession();playCheck($exit===2&&$check['session']==='invalid_format','malformed local configuration has its own fixed status');file_put_contents($path,$session);
    rename($path,$path.'.saved');symlink($path.'.saved',$path);[$exit,$check]=$checkSession();playCheck($exit===2&&$check['session']==='unsafe_link','a symbolic link is never treated as a ready private session');unlink($path);rename($path.'.saved',$path);
    foreach(['play_error_cookies','play_error_storage']as$key)playCheck(!preg_match('/مدیر|نشست|توکن|تنظیم|سرور/',$bot->text($key)),'the public default gives an end-user action without private setup instructions: '.$key);
    $migration=$root.'/migration';$oldStore=new Zero\Store($migration);$oldStore->query("DELETE FROM settings WHERE key='ui_play_user_notices_v1'");foreach(['play_error_cookies','play_error_storage']as$key)$oldStore->set('text.'.$key,Zero\Ui::definitions()['texts'][$key]['legacy_values'][0]);$newStore=new Zero\Store($migration);playCheck($newStore->get('text.play_error_cookies')===''&&$newStore->get('text.play_error_storage')==='','only exact historical Play defaults are retired on upgrade');$newStore->set('text.play_error_cookies','متن دلخواه من');$newStore->query("DELETE FROM settings WHERE key='ui_play_user_notices_v1'");$againStore=new Zero\Store($migration);playCheck($againStore->get('text.play_error_cookies')==='متن دلخواه من','intentional Play error customization is preserved');
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
values={k.strip():v.strip()for k,v in (line.split('=',1)for line in ini.read_text().splitlines()if '=' in line)}
assert values['email']=='fixture@example.test' and values['aas_token']=='made_up_fixture_token_123456789', 'credential bytes differ from the real apkeep parser expectation'
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
    $migration=$root.'/architecture-migration'; $before=new Zero\Store($migration);
    $before->set('text.play_choose',Zero\Ui::definitions()['texts']['play_choose']['legacy_values'][0]);
    $before->set('button.play_device_px_9a',Zero\Ui::definitions()['buttons']['play_device_px_9a']['legacy_values'][0]);
    $before->set('button.play_device_sm_a13_5g','دستگاه دلخواه');$before->set('button.style.play_device_px_9a','primary');
    $before->set('text.play_caption','کپشن دلخواه'); $before->query("DELETE FROM settings WHERE key='ui_play_architecture_v1'");
    $after=new Zero\Store($migration);
    playCheck($after->get('text.play_choose')===''&&$after->get('button.play_device_px_9a')===''
        &&$after->get('button.play_device_sm_a13_5g')==='دستگاه دلخواه'&&$after->get('button.style.play_device_px_9a')==='primary'
        &&$after->get('text.play_caption')==='کپشن دلخواه','architecture defaults migrate without changing deliberate names, styles or captions');
} finally { putenv('PATH='.$oldPath); Zero\Download::clean($root); }
