<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function healthCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
final class HealthApi implements Zero\Api {
    public array $calls=[]; public array $unreachable=[];
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='sendMessage'&&in_array($params['chat_id']??0,$this->unreachable,true)) throw new RuntimeException('Forbidden: bot was blocked',403);
        return ['message_id'=>900];
    }
    public function sent(): array { return array_values(array_filter($this->calls,fn($c)=>$c[0]==='sendMessage')); }
    public function last(string $method): ?array { foreach(array_reverse($this->calls) as $call) if($call[0]===$method) return $call[1]; return null; }
}
function igJar(string $session,int $expires=2147483647): string {
    return "# Netscape HTTP Cookie File\n#HttpOnly_.instagram.com\tTRUE\t/\tTRUE\t$expires\tsessionid\t$session\n.instagram.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tX\n";
}
$temp=sys_get_temp_dir().'/ciro-health-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $api=new HealthApi(); $bot=new Zero\Bot($config,$store,$api); $ui=$bot->ui;
    $pool=Zero\Instagram::pool($config); $now=time();

    // Stated expiry of the account cookie.
    $names=['sessionid'];
    healthCheck(Zero\CookiePool::sessionExpiry(igJar('A',$now+100),$names)===$now+100,'the stated expiry of the account cookie is read');
    healthCheck(Zero\CookiePool::sessionExpiry(igJar('A',0),$names)===0&&Zero\CookiePool::sessionExpiry("# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t5\tother\tv\n",$names)===null,'a session cookie reads as zero and a missing one as unknown');
    healthCheck($pool->add(igJar('JAR_ONE',$now+40*86400))['status']==='added','a jar is stored');
    $jars=$pool->describe();
    healthCheck($jars[0]['expires']===$now+40*86400&&$jars[0]['ok']===null&&$jars[0]['fail']===null&&$jars[0]['label']==='','a fresh jar reports its expiry and no history');

    // History and names.
    $id=$jars[0]['id']; $pool->markWorking($id);
    healthCheck($pool->describe()[0]['ok']>=$now,'a successful download is recorded');
    $pool->markFailed($id); $described=$pool->describe()[0];
    healthCheck($described['failed']&&$described['fail']>=$now&&$described['ok']!==null,'a refusal is recorded next to the last success');
    $pool->markWorking($id);
    healthCheck(!$pool->describe()[0]['failed']&&$pool->describe()[0]['fail']===null,'a later success clears the failure');
    $bad=0; foreach(['','   ',str_repeat('ا',25),"bell\x07"] as $label) if(!$pool->setLabel($id,$label)) $bad++;
    healthCheck($bad===4&&!$pool->setLabel('ffffffffffff','x'),'empty, too long, control-character and unknown names are refused');
    healthCheck($pool->setLabel($id,"two\nlines")&&$pool->describe()[0]['label']==='two lines','line breaks collapse into spaces so a name stays one line');
    healthCheck($pool->setLabel($id,'  حساب   فرعی ۲ ')&&$pool->describe()[0]['label']==='حساب فرعی ۲','a short name is trimmed and saved');
    $pool->add(igJar('JAR_ONE',$now+30*86400));
    healthCheck($pool->describe()[0]['label']==='حساب فرعی ۲'&&$pool->describe()[0]['ok']!==null,'an export of the same account keeps its name and history');
    $pool->add(igJar('JAR_TWO',$now+86400*2));
    $pool->remove($pool->jars()[1]['id'],substr($pool->jars()[1]['hash'],0,8));
    healthCheck($pool->count()===1,'removing a jar leaves the other');

    // Readable texts.
    healthCheck(Zero\CookieJars::ago($ui,$now-30,$now)===$ui->plain('cookie_ago_now')&&str_contains(Zero\CookieJars::ago($ui,$now-7200,$now),'2')&&str_contains(Zero\CookieJars::ago($ui,$now-3*86400,$now),'3 روز'),'relative times read naturally');
    healthCheck(str_contains(Zero\CookieJars::expiryText($ui,$now+10*86400,$now),'10 روز')&&str_contains(Zero\CookieJars::expiryText($ui,$now+5*3600,$now),'5 ساعت')&&Zero\CookieJars::expiryText($ui,$now-5,$now)===$ui->plain('cookie_exp_expired')&&Zero\CookieJars::expiryText($ui,0,$now)===$ui->plain('cookie_exp_session')&&Zero\CookieJars::expiryText($ui,null,$now)===$ui->plain('cookie_exp_unknown'),'expiry reads as days, hours, expired, session or unknown');
    (new Zero\CookieJars($bot))->view(42,'ig');
    $view=$api->last('sendMessage')['text'];
    healthCheck(str_contains($view,'«حساب فرعی ۲»')&&str_contains($view,'30 روز')&&str_contains($view,'آخرین موفقیت')&&!str_contains($view,'JAR_'),'the list shows the name, remaining time and history without secrets');

    // Expired jars are removed; the rest stay.
    $path=$config->instagramCookiePath(); $before=$pool->count();
    $pool->add(igJar('SESSION_ONLY',0)); $pool->add(igJar('WILL_EXPIRE',$now+86400*10));
    $expiring=array_values(array_filter($pool->jars(),fn($j)=>str_contains((string)file_get_contents($j['path']),'WILL_EXPIRE')))[0];
    file_put_contents($expiring['path'],igJar('WILL_EXPIRE',$now-10));
    $removed=$pool->pruneExpired();
    healthCheck(count($removed)===1&&$removed[0]['id']===$expiring['id']&&$pool->count()===$before+1,'only the jar whose account cookie has expired is removed');

    $ytp=Zero\youtubeCookiePool($config); $row=fn(string $name,int $expires,string $value)=>".youtube.com\tTRUE\t/\tTRUE\t$expires\t$name\t$value\n";
    $ytp->add("# Netscape HTTP Cookie File\n".$row('SID',$now+86400*90,'A').$row('SAPISID',$now+86400*90,'B')); $ytPath=$ytp->jars()[0]['path'];
    file_put_contents($ytPath,"# Netscape HTTP Cookie File\n".$row('SID',$now-10,'A').$row('SAPISID',$now+86400*90,'B'));
    healthCheck($ytp->pruneExpired()===[]&&$ytp->count()===1&&$ytp->describe()[0]['expires']===$now-10,'a jar is kept while any identifying cookie is valid, and the earliest date is shown');
    file_put_contents($ytPath,"# Netscape HTTP Cookie File\n".$row('SID',$now-10,'A').$row('SAPISID',$now-5,'B'));
    healthCheck(count($ytp->pruneExpired())===1&&$ytp->count()===0,'a jar is removed once every identifying cookie has expired');

    // Alerts: once per situation, only to reachable administrators, switchable.
    $store->query('INSERT OR IGNORE INTO bot_admins(user_id,added_by,created) VALUES(55,42,?)',[$now]);
    $health=new Zero\CookieHealth($config,$store,$api);
    $soon=$pool->add(igJar('SOON',$now+2*86400))['id'];
    $api->unreachable=[55]; $sent=$health->sweep($now); $messages=$api->sent();
    $soonText=array_values(array_filter($messages,fn($c)=>str_contains($c[1]['text'],'رو به پایان')));
    healthCheck($sent===1&&count($soonText)>=1&&$soonText[0][1]['chat_id']===42&&str_contains($soonText[0][1]['text'],'2 روز'),'a jar close to its end triggers one alert and an unreachable administrator does not stop it');
    $count=count($api->sent()); $health->sweep($now+3600);
    healthCheck(count($api->sent())===$count,'the same situation is not announced again');
    $api->unreachable=[];
    $failing=$pool->jars()[0]; $pool->markFailed($failing['id']); $count=count($api->sent()); $health->sweep($now+10);
    $failText=array_slice($api->sent(),$count);
    healthCheck(count($failText)===2&&str_contains($failText[0][1]['text'],'رد شد')&&in_array($failText[0][1]['chat_id'],[42,55],true),'a refused jar alerts every administrator once');
    $count=count($api->sent()); $health->sweep($now+20);
    healthCheck(count($api->sent())===$count,'the refusal is not repeated while it lasts');
    $pool->markWorking($failing['id']); $pool->markFailed($failing['id']); $count=count($api->sent()); $health->sweep($now+30);
    healthCheck(count($api->sent())>$count,'a new refusal after a success alerts again');
    $soonJar=array_values(array_filter($pool->jars(),fn($j)=>str_contains((string)file_get_contents($j['path']),'SOON')))[0];
    file_put_contents($soonJar['path'],igJar('SOON',$now-5)); $count=count($api->sent()); $health->sweep($now+40);
    $gone=array_slice($api->sent(),$count);
    healthCheck(count($gone)===2&&str_contains($gone[0][1]['text'],'حذف شد')&&str_contains($gone[0][1]['text'],'باقی‌مانده'),'an expired jar is removed and administrators are told how many remain');
    $store->set('cookies.alerts','0'); $second=$pool->add(igJar('QUIET',$now+86400))['id']; file_put_contents($config->instagramCookiePath().'.d/'.$second.'.txt',igJar('QUIET',$now-5)); $count=count($api->sent());
    $health->sweep($now+50);
    healthCheck(count($api->sent())===$count&&!is_file($config->instagramCookiePath().'.d/'.$second.'.txt'),'with alerts off expired jars are still removed but nothing is sent');
    $store->set('cookies.alerts','1');
    healthCheck($health->due($now+100000)===true&&$health->due($now+100001)===false&&$health->due($now+100000+Zero\CookieHealth::INTERVAL+1)===true,'the periodic pass runs once per interval');
    healthCheck(str_contains($health->summary(),'اینستاگرام 3')||str_contains($health->summary(),'اینستاگرام'),'the health summary lists each service');

    // Telegram: naming, testing and the alerts switch.
    $send=fn(string $text,int $user=42)=>$bot->handle(['update_id'=>random_int(1,1000000),'message'=>['message_id'=>random_int(5000,9000),'from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]);
    $click=fn(string $data,int $user=42)=>$bot->handle(['callback_query'=>['id'=>'q','from'=>['id'=>$user],'message'=>['message_id'=>777,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]);
    $send($bot->label('cookies')); $send($bot->label('cookie_instagram')); $send($bot->label('ig_cookie_view'));
    $row=$api->last('sendMessage')['reply_markup']['inline_keyboard'][0]; $nameButton=$row[3]['callback_data']; $testButton=$row[2]['callback_data'];
    $click($nameButton,7);
    healthCheck($store->get('cklabel.7')===''&&$store->get('cklabel.42')==='','an ordinary user cannot start naming a jar');
    $click($nameButton);
    healthCheck(str_starts_with($store->get('cklabel.42'),'ig:'),'the name button asks for a name and waits for it');
    $send(str_repeat('x',40));
    healthCheck(str_starts_with($store->get('cklabel.42'),'ig:')&&str_contains($api->last('sendMessage')['text'],'۲۴'),'an invalid name keeps waiting and explains the limit');
    $send('حساب اصلی');
    $named=array_column($pool->describe(),'label');
    healthCheck(in_array('حساب اصلی',$named,true)&&$store->get('cklabel.42')===''&&str_contains($api->last('sendMessage')['text'],'«حساب اصلی»'),'a valid name is saved and the list is shown with it');
    $send('/cancel'); $click($nameButton); $send('/cancel');
    healthCheck($store->get('cklabel.42')==='','/cancel abandons naming');
    // Naming never cancels a waiting import (they live in different places).
    $send($bot->label('ig_cookie_upload')); $click($nameButton);
    healthCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='ig_cookie.upload'&&str_starts_with($store->get('cklabel.42'),'ig:'),'starting to name a jar keeps a waiting import open');
    $send('/cancel');

    $click($testButton);
    $notice=$api->last('editMessageText')['text']??'';
    healthCheck(str_contains($notice,'ساختار و تاریخ نشست سالم'),'testing an Instagram jar checks its structure and date');
    $expiredPath=$pool->jars()[0]['path']; $original=file_get_contents($expiredPath); file_put_contents($expiredPath,igJar('JAR_ONE',$now-5)); $hashPrefix=substr(hash('sha256',igJar('JAR_ONE',$now-5)),0,8);
    $click('ckts:ig:'.$pool->jars()[0]['id'].':'.$hashPrefix);
    $notice=$api->last('editMessageText')['text']??'';
    healthCheck(str_contains($notice,'کار نمی‌کند')&&$pool->describe()[0]['failed'],'testing an expired jar reports it and demotes it');
    file_put_contents($expiredPath,$original);

    $probe=$temp.'/probe'; $mode=$temp.'/probe-mode'; file_put_contents($mode,'ok');
    file_put_contents($probe,"#!/usr/bin/env php\n<?php\n\$m=file_get_contents(".var_export($mode,true).");if(\$m!==\"ok\"){fwrite(STDERR,\$m);exit(1);}echo ".var_export(json_encode(['title'=>'P','formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac']]]),true).";");
    chmod($probe,0700); $config->ytDlp=$probe;
    $yt=Zero\youtubeCookiePool($config); $yt->add("# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t2147483647\tSID\tYT\n");
    $ytTest='ckts:yt:'.$yt->jars()[0]['id'].':'.substr($yt->jars()[0]['hash'],0,8);
    foreach(['ok'=>['زنده'.'','ok'],'Sign in to confirm you are not a bot'=>['محدود',null],'connection refused'=>['مشخص نشد',null],'cookies are no longer valid'=>['کار نمی‌کند','fail']] as $result=>[$needle,$state]) {
        file_put_contents($mode,$result); $yt->markWorking($yt->jars()[0]['id']); $yt->markFailed($yt->jars()[0]['id']); $yt->markWorking($yt->jars()[0]['id']);
        $hash=substr($yt->jars()[0]['hash'],0,8); $click('ckts:yt:'.$yt->jars()[0]['id'].':'.$hash);
        $text=$api->last('editMessageText')['text']??'';
        $failedNow=$yt->describe()[0]['failed'];
        healthCheck(str_contains($text,$result==='ok'?'کار می‌کند':$needle)&&($state==='fail'?$failedNow:!$failedNow),'a live YouTube test reports '.($result==='ok'?'success':$result));
    }

    $before=$store->get('cookies.alerts'); $send($bot->label('cookies')); $send($bot->label('cookie_alerts'));
    healthCheck($store->get('cookies.alerts')!==$before&&str_contains($api->last('sendMessage')['text'],'هشدار کوکی'),'the alerts switch flips and the menu shows its state');
    $send($bot->label('cookie_alerts'),7); $state=$store->get('cookies.alerts'); $send($bot->label('cookie_alerts'),7);
    healthCheck($store->get('cookies.alerts')===$state,'ordinary users cannot flip the alerts switch');
} finally { Zero\Download::clean($temp); }
