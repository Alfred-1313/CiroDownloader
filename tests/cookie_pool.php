<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function poolCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
final class PoolApi implements Zero\FileApi {
    public array $calls=[]; public array $files=[]; public int $next=900;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='getFile') return ['file_path'=>$params['file_id'],'file_size'=>strlen($this->files[$params['file_id']]??'')];
        return ['message_id'=>++$this->next];
    }
    public function downloadFile(string $filePath,int $maxBytes): string {
        $content=$this->files[$filePath]??'';
        if(strlen($content)>$maxBytes) throw new RuntimeException('Too large');
        return $content;
    }
    public function last(string $method): ?array { foreach(array_reverse($this->calls) as $call) if($call[0]===$method) return $call[1]; return null; }
}
function jar(string $domain,string $name,string $session,string $other='one'): string {
    return "# Netscape HTTP Cookie File\n#HttpOnly_.$domain\tTRUE\t/\tTRUE\t2147483647\t$name\t$session\n.$domain\tTRUE\t/\tTRUE\t0\tcsrftoken\t$other\n";
}
function igJar(string $session,string $other='one'): string { return jar('instagram.com','sessionid',$session,$other); }
function ytJar(string $sid,string $other='one'): string {
    return "# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\t$sid\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tPSIDTS\t$other\n";
}
function zipOf(array $entries,?string $password=null): string {
    $path=sys_get_temp_dir().'/ciro-pool-zip-'.bin2hex(random_bytes(6)); $zip=new ZipArchive();
    $zip->open($path,ZipArchive::CREATE);
    foreach($entries as $name=>$content) {
        $zip->addFromString($name,$content);
        if($password!==null) $zip->setEncryptionName($name,ZipArchive::EM_AES_256,$password);
    }
    $zip->close(); $bytes=(string)file_get_contents($path); unlink($path); return $bytes;
}
function message(int $user,array $extra): array { return ['message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private']]+$extra]; }
$temp=sys_get_temp_dir().'/ciro-pool-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $api=new PoolApi(); $bot=new Zero\Bot($config,$store,$api);

    // 1. Pool semantics: one account per jar, services never share a jar.
    $pool=Zero\Instagram::pool($config);
    $first=$pool->add(igJar('SESSION_A'));
    poolCheck($first['status']==='added'&&$first['id']==='main'&&is_file($config->instagramCookiePath()),'the first jar keeps the historical single-file path');
    $second=$pool->add(igJar('SESSION_B'));
    $secondPath=$config->instagramCookiePath().'.d/'.$second['id'].'.txt';
    poolCheck($second['status']==='added'&&is_file($secondPath)&&(fileperms($secondPath)&0777)===0600&&$pool->count()===2,'a different account becomes a second private jar');
    $replaced=$pool->add(igJar('SESSION_B','rotated'));
    poolCheck($replaced['status']==='replaced'&&$replaced['id']===$second['id']&&$pool->count()===2&&str_contains((string)file_get_contents($secondPath),'rotated'),'the same account exported again replaces its own jar');
    poolCheck($pool->add(igJar('SESSION_B','rotated'))['status']==='unchanged'&&$pool->count()===2,'an identical export is reported as a duplicate');
    $rejected=0;
    foreach([fn()=>$pool->add(jar('tiktok.com','sessionid','TT')),fn()=>Zero\TikTok::pool($config)->add(igJar('SESSION_C')),fn()=>Zero\Twitter::pool($config)->add(jar('tiktok.com','sessionid','TT')),fn()=>Zero\Pinterest::pool($config)->add(igJar('SESSION_C'))] as $try) {
        try { $try(); } catch(RuntimeException) { $rejected++; }
    }
    poolCheck($rejected===4&&$pool->count()===2&&Zero\TikTok::pool($config)->count()===0,'one service never accepts another service\'s cookies, so one export cannot serve two services');
    $a=Zero\TikTok::pool($config)->add(jar('tiktok.com','sessionid','TT_ONE')); Zero\Twitter::pool($config)->add(jar('x.com','auth_token','X_ONE'));
    poolCheck(Zero\TikTok::pool($config)->count()===1&&Zero\Twitter::pool($config)->count()===1&&$pool->count()===2&&is_file($config->tiktokCookiePath())&&is_file($config->twitterCookiePath()),'every service owns a separate pool and file');

    // 2. Capacity and eviction of a failing jar.
    $full=Zero\Pinterest::pool($config);
    for($i=1;$i<=Zero\CookiePool::MAX_JARS;$i++) $full->add(jar('pinterest.com','_pinterest_sess','P'.$i));
    $overflow=false; try { $full->add(jar('pinterest.com','_pinterest_sess','P_EXTRA')); } catch(RuntimeException) { $overflow=true; }
    poolCheck($overflow&&$full->count()===Zero\CookiePool::MAX_JARS,'a pool holds at most ten jars');
    $victim=$full->jars()[3]; $full->markFailed($victim['id']);
    $full->add(jar('pinterest.com','_pinterest_sess','P_EXTRA'));
    $ids=array_column($full->jars(),'id');
    poolCheck(!in_array($victim['id'],$ids,true)&&$full->count()===Zero\CookiePool::MAX_JARS,'a full pool replaces its recently failing jar with the new export');

    // 3. Rotation and failure demotion.
    $rotation=Zero\Twitter::pool($config); $rotation->add(jar('x.com','auth_token','X_TWO')); $rotation->add(jar('x.com','auth_token','X_THREE'));
    $firsts=[]; for($i=0;$i<3;$i++) $firsts[]=$rotation->order(1)[0]['id'];
    poolCheck(count(array_unique($firsts))===3,'requests rotate across all healthy jars');
    $bad=$rotation->jars()[1]['id']; $rotation->markFailed($bad);
    $orders=[]; for($i=0;$i<4;$i++) { $order=$rotation->order(3); $orders[]=array_column($order,'id'); }
    poolCheck(!in_array($bad,array_map(fn($o)=>$o[0],$orders),true)&&end($orders[0])===$bad,'a failing jar is tried last until it works again');
    $rotation->markWorking($bad);
    poolCheck($rotation->failures()===[],'a working jar leaves the failure list');

    // 4. Removal is bound to the version the administrator saw.
    $victim=$rotation->jars()[2];
    poolCheck(!$rotation->remove($victim['id'],'00000000')&&$rotation->count()===3,'a stale removal prefix cannot delete a jar');
    poolCheck($rotation->remove($victim['id'],substr($victim['hash'],0,8))&&$rotation->count()===2,'a matching prefix removes exactly one jar');
    poolCheck(!$rotation->clear(str_repeat('0',64))&&$rotation->count()===2,'clearing with an outdated digest changes nothing');
    $digest=$rotation->digest(); poolCheck($rotation->clear($digest)&&$rotation->count()===0&&!is_dir($config->twitterCookiePath().'.d'),'clearing with the current digest removes every jar and its directory');

    // 5. ZIP imports are read in memory and report every file.
    $mixed=zipOf([
        'a.txt'=>igJar('ZIP_A'),'nested/b.txt'=>igJar('ZIP_B'),'a-copy.txt'=>igJar('ZIP_A'),'broken.txt'=>'not a cookie export',
        '__MACOSX/._a.txt'=>'x','notes.md'=>'ignore me','.hidden.txt'=>igJar('ZIP_H'),'../outside.txt'=>igJar('ZIP_OUT'),
    ]);
    $zipPool=Zero\Instagram::pool(new Zero\Config('123:fixture',[42],$temp.'/zip-service'));
    $zipConfig=new Zero\Config('123:fixture',[42],$temp.'/zip-service'); $zipPool=Zero\Instagram::pool($zipConfig);
    $api->files['zip-1']=$mixed;
    $summary=Zero\CookieJars::ingest($api,['file_id'=>'zip-1','file_name'=>'cookies.ZIP','file_size'=>strlen($mixed)],$zipPool,$zipConfig->storage);
    poolCheck($summary['added']===3&&$summary['unchanged']===1&&$summary['invalid']===1&&$zipPool->count()===3,'a ZIP imports every valid export, skips duplicates and reports bad files');
    poolCheck(!is_file($temp.'/outside.txt')&&!is_file($temp.'/zip-service/outside.txt')&&glob($zipConfig->storage.'/.cookie-check-*')===[],'ZIP entry names are never used as paths and no temporary archive remains');
    poolCheck($summary['notes'][0][0]==='broken.txt'&&$summary['notes'][0][1]==='invalid','the invalid file is named with its reason');
    $limits=0;
    foreach([['name'=>'many.zip','bytes'=>zipOf(array_combine(array_map(fn($i)=>"f$i.txt",range(1,41)),array_fill(0,41,igJar('X'))))],['name'=>'plain.zip','bytes'=>'This is not an archive at all, only text.'],['name'=>'wide.zip','bytes'=>zipOf(array_combine(array_map(fn($i)=>"f$i.txt",range(1,21)),array_fill(0,21,igJar('X'))))]] as $case) {
        $api->files['bad']=$case['bytes'];
        try { Zero\CookieJars::ingest($api,['file_id'=>'bad','file_name'=>$case['name'],'file_size'=>strlen($case['bytes'])],$zipPool,$zipConfig->storage); } catch(RuntimeException) { $limits++; }
    }
    poolCheck($limits===3&&$zipPool->count()===3,'oversized, non-archive and over-limit ZIPs are rejected without changing the pool');
    $oversize=false; try { Zero\CookieJars::ingest($api,['file_id'=>'bad','file_name'=>'big.zip','file_size'=>Zero\CookiePool::MAX_ZIP_BYTES+1],$zipPool,$zipConfig->storage); } catch(InvalidArgumentException) { $oversize=true; }
    $wrong=false; try { Zero\CookieJars::ingest($api,['file_id'=>'bad','file_name'=>'cookies.rar','file_size'=>100],$zipPool,$zipConfig->storage); } catch(InvalidArgumentException) { $wrong=true; }
    poolCheck($oversize&&$wrong,'only TXT and appropriately sized ZIP documents are accepted');
    if(method_exists('ZipArchive','setEncryptionName')) {
        $locked=zipOf(['secret.txt'=>igJar('LOCKED')],'password'); $api->files['locked']=$locked;
        $result=Zero\CookieJars::ingest($api,['file_id'=>'locked','file_name'=>'locked.zip','file_size'=>strlen($locked)],$zipPool,$zipConfig->storage);
        poolCheck($result['added']===0&&$result['invalid']===1&&$result['notes'][0][1]==='encrypted','encrypted archives are refused instead of guessed');
    }

    // 6. Telegram flows: several TXT files, a ZIP, per-jar buttons and the mode switch.
    $send=fn(string $text,int $user=42)=>$bot->handle(message($user,['text'=>$text]));
    $doc=function(string $name,string $content,int $user=42) use($bot,$api): void {
        static $n=0; $id='doc-'.++$n; $api->files[$id]=$content;
        $bot->handle(message($user,['message_id'=>5000+$n,'document'=>['file_id'=>$id,'file_name'=>$name,'file_size'=>strlen($content)]]));
    };
    $flow=Zero\TikTok::pool($config); $flow->clear($flow->digest());
    $send($bot->label('cookie_tiktok')); $send($bot->label('tt_cookie_upload'));
    $doc('one.txt',jar('tiktok.com','sessionid','T1')); $doc('two.txt',jar('tiktok.com','sessionid','T2')); $doc('three.txt',jar('tiktok.com','sessionid','T3'));
    $text=$api->last('editMessageText')['text']??'';
    poolCheck($flow->count()===3&&$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='tt_cookie.upload'&&str_contains($text,'<b>3</b> از 10'),'several TXT exports can follow each other and the total is reported');
    $zip=zipOf(['d.txt'=>jar('tiktok.com','sessionid','T4'),'e.txt'=>jar('tiktok.com','sessionid','T5')]); $doc('batch.zip',$zip);
    poolCheck($flow->count()===5&&str_contains($api->last('editMessageText')['text'],'<b>5</b> از 10'),'a ZIP of exports is accepted through the same panel');
    $before=$flow->count(); $doc('mine.txt',jar('tiktok.com','sessionid','T6'),7); $doc('foreign.txt',igJar('IG_IN_TT'));
    poolCheck($flow->count()===$before,'other users and foreign-service files cannot add to the pool');
    $send($bot->label('tt_cookie_view'));
    $view=$api->last('sendMessage'); $inline=$view['reply_markup']['inline_keyboard'];
    poolCheck(count($inline)===5&&!str_contains($view['text'],'T1')&&str_contains($view['text'],'5. ✅'),'the list shows one numbered row with export and delete buttons per jar');
    $query=fn(string $data,int $user=42)=>$bot->handle(['callback_query'=>['id'=>'q','from'=>['id'=>$user],'message'=>['message_id'=>777,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]);
    $query($inline[1][1]['callback_data'],7);
    poolCheck($flow->count()===5,'an ordinary user cannot remove a jar through a forged button');
    $stale=preg_replace('/:[a-f0-9]{8}$/','00000000',$inline[1][1]['callback_data']); $stale=substr($inline[1][1]['callback_data'],0,-8).'00000000';
    $query($stale);
    poolCheck($flow->count()===5,'a button for a jar that changed is refused');
    $query($inline[1][1]['callback_data']);
    poolCheck($flow->count()===4,'a current delete button removes exactly that jar and redraws the list');
    $query($inline[1][1]['callback_data']);
    poolCheck($flow->count()===4,'pressing the same delete button twice removes nothing more');

    $send($bot->label('cookies'));
    $send($bot->label('cookie_mode'));
    poolCheck($store->get('cookies.fallback')==='1'&&str_contains($api->last('sendMessage')['text'],'فقط در صورت نیاز'),'the mode switch turns the cookie-only-when-needed mode on and shows it');
    $send($bot->label('cookie_mode'));
    poolCheck($store->get('cookies.fallback')==='0','the mode switch turns it back off');
    $before=$store->get('cookies.fallback'); $send($bot->label('cookie_mode'),7);
    poolCheck($store->get('cookies.fallback')===$before,'ordinary users cannot change the mode through a forged label');

    // 7. YouTube: one TXT is tested live, a ZIP gets only a structure check, backups see a normal directory.
    $probe=$temp.'/probe'; $probeTrace=$temp.'/probe-trace';
    file_put_contents($probe,"#!/usr/bin/env php\n<?php\nfile_put_contents(".var_export($probeTrace,true).",'x',FILE_APPEND);echo ".var_export(json_encode(['title'=>'Public','formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac']]]),true).";");
    chmod($probe,0700); $config->ytDlp=$probe;
    $send($bot->label('cookies')); $send($bot->label('cookie_youtube')); $send($bot->label('cookie_upload'));
    $doc('yt1.txt',ytJar('YT_ONE'));
    poolCheck(is_file($probeTrace)&&Zero\youtubeCookiePool($config)->count()===1,'a single YouTube export is verified with the real downloader');
    @unlink($probeTrace);
    $doc('yts.zip',zipOf(['p.txt'=>ytJar('YT_TWO'),'q.txt'=>ytJar('YT_THREE')]));
    poolCheck(!is_file($probeTrace)&&Zero\youtubeCookiePool($config)->count()===3,'a ZIP of YouTube exports is checked structurally without blocking the poller on live probes');
    $doc('yt-same.txt',ytJar('YT_ONE','rotated'));
    poolCheck(Zero\youtubeCookiePool($config)->count()===3,'the same YouTube account is replaced, not duplicated');

    // 8. Downloads: failover between jars and cookies only as a last resort.
    $trace=$temp.'/trace.jsonl'; $mode=$temp.'/mode.json'; $stub=$temp.'/downloader';
    $metadata=['id'=>'ybNMMK5Q318','title'=>'Fixture','duration'=>1,'formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac']]];
    file_put_contents($stub,"#!/usr/bin/env php\n<?php\n".
        '$mode=json_decode(file_get_contents('.var_export($mode,true).'),true);$cookie=in_array("--cookies",$argv,true);'.
        '$id=$cookie?md5(preg_replace("/^.*SID\t([A-Z_]+)\n.*$/s","$1",file_get_contents($argv[array_search("--cookies",$argv,true)+1]))):"anonymous";'.
        'file_put_contents('.var_export($trace,true).',json_encode(["cookie"=>$cookie,"id"=>$id]).PHP_EOL,FILE_APPEND);'.
        '$result=$mode[$id]??"ok"; if($result!=="ok"){fwrite(STDERR,$result);exit(1);}'.
        'echo '.var_export(json_encode($metadata),true).';');
    chmod($stub,0700);
    $dl=new Zero\Config('123:fixture',[42],$temp.'/downloads'); $dl->ytDlp=$stub; mkdir($temp.'/downloads',0700);
    $runner=new Zero\Download($dl); $url='https://youtu.be/ybNMMK5Q318'; $counter=0;
    $setup=function(array $settings) use($mode,$trace,$temp,&$counter): string { file_put_contents($mode,json_encode($settings)); file_put_contents($trace,''); $d=$temp.'/yt-run-'.++$counter; mkdir($d,0700); return $d; };
    $seen=fn()=>array_map(fn($line)=>json_decode($line,true),file($trace,FILE_IGNORE_NEW_LINES));
    $yt=Zero\youtubeCookiePool($dl); $yt->add(ytJar('JAR_A')); $yt->add(ytJar('JAR_B'));
    $idA=md5('JAR_A'); $idB=md5('JAR_B');
    $info=$runner->inspect($url,$setup([$idA=>'Sign in to confirm you are not a bot']));
    $attempts=$seen();
    poolCheck($info['title']==='Fixture'&&count($attempts)===2&&$attempts[0]['id']===$idA&&$attempts[1]['id']===$idB,'when the first YouTube jar is refused the next jar completes the request');
    $runner->inspect($url,$setup([$idA=>'Sign in to confirm you are not a bot']));
    $attempts=$seen();
    poolCheck(count($attempts)===1&&$attempts[0]['id']===$idB,'the refused jar is demoted so the next request starts with the working one');
    $failure='';
    try { $runner->inspect($url,$setup([$idA=>'Sign in to confirm you are not a bot',$idB=>'Sign in to confirm you are not a bot'])); } catch(Zero\MediaFailure $e) { $failure=$e->reason; }
    poolCheck($failure==='blocked'&&count($seen())===2,'when every jar is refused the request fails with the blocked diagnosis');
    $failure='';
    try { $runner->inspect($url,$setup([$idA=>'connection refused',$idB=>'connection refused'])); } catch(Zero\MediaFailure $e) { $failure=$e->reason; }
    poolCheck($failure==='network'&&count($seen())===1,'a network failure is not retried with other jars');
    $yt->markWorking($idA); $yt->markWorking(Zero\youtubeCookiePool($dl)->jars()[0]['id']);
    foreach($yt->jars() as $one) $yt->markWorking($one['id']);
    $dl->cookieFallback=true;
    $runner->inspect($url,$setup([]));
    $attempts=$seen();
    poolCheck(count($attempts)===1&&!$attempts[0]['cookie'],'cookies-only-when-needed mode tries without cookies first and stops when that works');
    $runner->inspect($url,$setup(['anonymous'=>'Sign in to confirm you are not a bot']));
    $attempts=$seen();
    poolCheck(count($attempts)===2&&!$attempts[0]['cookie']&&$attempts[1]['cookie'],'a refused anonymous attempt is retried with a cookie jar');
    $runner->inspect($url,$setup(['anonymous'=>'Private video']));
    $attempts=$seen();
    poolCheck(count($attempts)===2&&!$attempts[0]['cookie']&&$attempts[1]['cookie'],'a restricted-looking video is retried with cookies because a login may unlock it');
    $failure='';
    try { $runner->inspect($url,$setup(['anonymous'=>'Private video',$idA=>'Private video',$idB=>'Private video'])); } catch(Zero\MediaFailure $e) { $failure=$e->reason; }
    poolCheck($failure==='restricted'&&$yt->failures()===[],'a video that stays private with cookies is reported as restricted without condemning the jars');
    $dl->cookieFallback=false;
    $runner->inspect($url,$setup([]));
    poolCheck(count($seen())===1&&$seen()[0]['cookie'],'the default mode keeps using the cookie from the first attempt');

    // 9. Gallery services use the same failover.
    $image=$temp.'/fixture.jpg';
    (new Zero\Download($dl))->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=red:s=64x48:d=1','-frames:v','1',$image],$temp,60);
    $galleryTrace=$temp.'/gallery.jsonl'; $galleryMode=$temp.'/gallery-mode.json'; $gallery=$temp.'/stub-gallery';
    file_put_contents($gallery,"#!/usr/bin/env php\n<?php\n".
        '$mode=json_decode(file_get_contents('.var_export($galleryMode,true).'),true);$cookie=in_array("--cookies",$argv,true);'.
        '$directory=$argv[array_search("--directory",$argv,true)+1];'.
        '$id=$cookie?(preg_match("/sessionid\t([A-Z_]+)/",file_get_contents($argv[array_search("--cookies",$argv,true)+1]),$m)?$m[1]:"?"):"anonymous";'.
        'file_put_contents('.var_export($galleryTrace,true).',json_encode(["cookie"=>$cookie,"id"=>$id]).PHP_EOL,FILE_APPEND);'.
        'if(($mode[$id]??"ok")!=="ok"){fwrite(STDERR,$mode[$id]);exit(1);}'.
        'copy('.var_export($image,true).',$directory."/ig-01.jpg");echo "CIRO|1|1|".$directory."/ig-01.jpg\nCIRO_META|\"caption\"\n";');
    chmod($gallery,0700);
    $gc=new Zero\Config('123:fixture',[42],$temp.'/gallery-storage'); $gc->galleryDl=$gallery; mkdir($temp.'/gallery-storage',0700);
    $gp=Zero\Instagram::pool($gc); $gp->add(igJar('IG_A')); $gp->add(igJar('IG_B'));
    $fetch=function(array $settings) use($galleryMode,$galleryTrace,$gc,$temp): array {
        file_put_contents($galleryMode,json_encode($settings)); file_put_contents($galleryTrace,'');
        $folder=$temp.'/gallery-run-'.bin2hex(random_bytes(3)); mkdir($folder,0700);
        $items=Zero\GalleryMedia::forService($gc,'instagram')->fetch('https://www.instagram.com/p/ABC_def12/',$folder);
        return [$items,array_map(fn($line)=>json_decode($line,true),file($galleryTrace,FILE_IGNORE_NEW_LINES))];
    };
    [$items,$attempts]=$fetch(['IG_A'=>'login required']);
    poolCheck(count($items)===1&&count($attempts)===2&&$attempts[0]['id']==='IG_A'&&$attempts[1]['id']==='IG_B','Instagram moves to the next jar when one is refused');
    [, $attempts]=$fetch([]);
    poolCheck(count($attempts)===1&&$attempts[0]['id']==='IG_B','the refused Instagram jar is demoted');
    $gc->cookieFallback=true; foreach($gp->jars() as $one) $gp->markWorking($one['id']);
    [, $attempts]=$fetch([]);
    poolCheck(count($attempts)===1&&!$attempts[0]['cookie'],'gallery services also try without cookies first in the last-resort mode');
    [, $attempts]=$fetch(['anonymous'=>'login required']);
    poolCheck(count($attempts)===2&&!$attempts[0]['cookie']&&$attempts[1]['cookie'],'a refused anonymous gallery attempt falls back to a cookie jar');
    $reason='';
    try { $fetch(['anonymous'=>'login required','IG_A'=>'login required','IG_B'=>'login required']); } catch(Zero\MediaFailure $e) { $reason=$e->reason; }
    poolCheck($reason==='blocked','when every attempt is refused the blocked diagnosis is kept');
} finally { Zero\Download::clean($temp); }
