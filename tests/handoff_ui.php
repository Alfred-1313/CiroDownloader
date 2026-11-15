<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function handoffUiCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
final class HandoffUiApi implements Zero\Api {
    public array $calls=[]; public bool $deleteFails=false; public bool $missingMessage=false; public bool $rejectNext=false;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='deleteMessage') { if($this->deleteFails) throw new RuntimeException('fixture',403); return true; }
        if($method==='editMessageText'&&$this->missingMessage) { $this->missingMessage=false; throw new RuntimeException('fixture',404); }
        if($method==='editMessageText'&&$this->rejectNext) { $this->rejectNext=false; throw new RuntimeException('fixture',400); }
        if(in_array($method,['sendMessage','editMessageText'],true)) {
            Zero\TextFormat::validate($params['text'],4096);
            foreach($params['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button)
                if(strlen($button['callback_data']??'')>64) throw new RuntimeException('Callback exceeds Telegram limit');
        }
        return $method==='sendMessage'?['message_id'=>900]:true;
    }
    public function screen(): array {
        $calls=array_values(array_filter($this->calls,fn($c)=>in_array($c[0],['sendMessage','editMessageText'],true)));
        return end($calls)[1]??[];
    }
    public function button(string $data): ?array {
        foreach($this->screen()['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button)
            if(($button['callback_data']??'')===$data) return $button;
        return null;
    }
}
$root=sys_get_temp_dir().'/ciro-handoff-ui-'.bin2hex(random_bytes(5));
try {
    $store=new Zero\Store($root); $api=new HandoffUiApi(); $cfg=new Zero\Config('123:main_fixture',[42],$root); $bot=new Zero\Bot($cfg,$store,$api);
    $seq=0;
    $click=function(string $data,int $user=42,int $message=700,int $chat=42) use($bot,&$seq): void {
        $bot->handle(['update_id'=>++$seq,'callback_query'=>['id'=>'ui'.$seq,'from'=>['id'=>$user],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']],'data'=>$data]]);
    };
    $input=function(string $text,int $message=701) use($bot,&$seq): void {
        $bot->handle(['update_id'=>++$seq,'message'=>['message_id'=>$message,'from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'text'=>$text]]);
    };
    $state=fn()=>$store->query('SELECT key FROM states WHERE user_id=?',[42])->fetchColumn();
    $click('custom:adultpage');
    handoffUiCheck(($api->button('custom:hint:handoff_route')['style']??'')===''&&($api->button('custom:handoff')['style']??'')==='primary','adult settings give the companion a white explanatory heading and blue control');
    $click('custom:handoff'); $screen=$api->screen();
    handoffUiCheck(str_contains($screen['text'],'متصل نشده')&&str_contains($screen['text'],'2 دقیقه')&&($api->button('custom:handofftoken')['style']??'')==='primary'&&($api->button('custom:hint:handoff_expiry')['style']??'')==='','the companion page separates connection and time and shows its current state');
    $click('custom:handofftoggle');
    handoffUiCheck($store->get('adult.handoff.enabled','0')==='0'&&str_contains($api->screen()['text'],'اول توکن'),'enabling without a configured bot gives a concrete next step');
    $api->rejectNext=true;
    try { $click('custom:handoffttl'); } catch(RuntimeException) {}
    handoffUiCheck($state()===false,'a rejected input prompt does not leave an invisible pending state');
    $click('custom:handoffttl');
    handoffUiCheck($state()==='handoff.ttl:700'&&str_contains($api->button('custom:handoffpreset:120')['text'],'✅')&&count($api->screen()['reply_markup']['inline_keyboard'])===3,'duration choices show the current value and preserve typed input');
    $click('custom:handoffpreset:42');
    handoffUiCheck($state()==='handoff.ttl:700'&&$store->get('adult.handoff.ttl','120')==='120'&&str_contains($api->screen()['text'],'یک عدد بین'),'unknown preset values cannot change settings or abandon the input');
    $input('خیلی طولانی');
    handoffUiCheck($state()==='handoff.ttl:700'&&str_contains($api->screen()['text'],'یک عدد بین')&&$api->button('custom:handoffpreset:300')!==null,'invalid duration keeps the input screen and tells the admin how to recover');
    $input('۲۴۰');
    handoffUiCheck($state()===false&&$store->get('adult.handoff.ttl')==='240'&&str_contains($api->screen()['text'],'4 دقیقه'),'Persian digits set a custom duration and return to the status screen');
    $click('custom:handoffttl'); $click('custom:handoffpreset:300');
    handoffUiCheck($state()===false&&$store->get('adult.handoff.ttl')==='300'&&str_contains($api->screen()['text'],'5 دقیقه'),'one tap applies a preset and returns to the status screen');
    $click('custom:handoffpreset:120');
    $last=end($api->calls);
    handoffUiCheck($store->get('adult.handoff.ttl')==='300'&&$last[0]==='answerCallbackQuery'&&($last[1]['show_alert']??false)&&str_contains($last[1]['text'],'دیگر فعال نیست'),'stale preset buttons show an alert without changing the duration');
    $store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[42,'ui:{"kind":"text","key":"instagram_caption","message":700}',time()+600]);
    $before=$api->screen(); $click('custom:handoffpreset:120');
    handoffUiCheck(str_starts_with((string)$state(),'ui:')&&$api->screen()===$before,'a stale preset cannot replace a different pending editor');
    $click('custom:handoffttl'); $click('custom:handoffpreset:120',7,700,7);
    handoffUiCheck($store->get('adult.handoff.ttl')==='300'&&$state()==='handoff.ttl:700','ordinary users cannot change the setting or cancel the admin input');
    $click('custom:handoff'); handoffUiCheck($state()===false,'back cancels pending input');
    $click('custom:settings:beeg'); $click('custom:adultpage'); $click('custom:handoff'); $click('custom:adultpage');
    handoffUiCheck($api->button('custom:settings:beeg')!==null,'Back returns through shared adult settings to the service that opened them');
    $api->missingMessage=true; $click('custom:handoffttl');
    handoffUiCheck($state()==='handoff.ttl:900','a deleted settings message is replaced without losing its input state');
    $click('custom:handoffpreset:600',42,900);
    handoffUiCheck($store->get('adult.handoff.ttl')==='600'&&$state()===false,'the replacement message accepts its own preset');
    $click('custom:handoffttl'); $api->rejectNext=true;
    try { $input('180'); } catch(RuntimeException) {}
    handoffUiCheck($store->get('adult.handoff.ttl')==='180'&&$state()===false,'a rejected confirmation does not reopen or misreport a successfully saved setting');
    $click('custom:handofftoken'); $input('not-a-secret');
    handoffUiCheck($state()==='handoff.token:700'&&str_contains($api->screen()['text'],'توکن معتبر نیست')&&!str_contains($api->screen()['text'],'not-a-secret'),'an invalid token is deleted, not echoed, and the input stays open');
    handoffUiCheck((bool)array_filter($api->calls,fn($c)=>$c[0]==='deleteMessage'&&($c[1]['chat_id']??0)===42&&($c[1]['message_id']??0)===701)&&!str_contains(json_encode($api->calls,JSON_UNESCAPED_UNICODE),'not-a-secret'),'the incoming token message is actually deleted without appearing in any API request');
    $input('123:main_fixture',702);
    handoffUiCheck($state()==='handoff.token:700'&&str_contains($api->screen()['text'],'متعلق به ربات اصلی')&&!str_contains($api->screen()['text'],'main_fixture'),'the main bot token gets its own clear error without exposing it');
    $api->deleteFails=true; $input('456:private_fixture',703);
    handoffUiCheck($state()==='handoff.token:700'&&str_contains($api->screen()['text'],'دستی')&&!str_contains($api->screen()['text'],'private_fixture'),'a failed token deletion stops setup and tells the admin to delete it');
    $api->deleteFails=false; $click('custom:handoff');
    $h=new Zero\AdultHandoff($cfg,$store);
    $fake=new class implements Zero\Api { public function call(string $m,array $p=[]): mixed { return $m==='getMe'?['id'=>456,'is_bot'=>true,'username'=>'fixture_bot']:['url'=>'']; } };
    $h->configure('456:fixture_secret',$fake); $click('custom:handoff');
    handoffUiCheck(str_contains($api->screen()['text'],'@fixture_bot')&&!str_contains($api->screen()['text'],'fixture_secret'),'the connected username, never its token, is shown');
    $click('custom:handofftoggle'); handoffUiCheck($h->enabled(),'one tap enables a configured companion');
    foreach([0=>'0 ثانیه',10=>'10 ثانیه',120=>'2 دقیقه',3661=>'1 ساعت و 1 دقیقه و 1 ثانیه',86400=>'24 ساعت'] as $n=>$expected)
        handoffUiCheck(Zero\AdultHandoff::durationLabel($n)===$expected,'duration display is accurate: '.$expected);
    $ui=$bot->ui->forService('pornhub');
    handoffUiCheck(str_contains($ui->text('handoff_ready',['expiry'=>'2 دقیقه']),'2 دقیقه')&&$ui->customizable('text','pornhub__handoff_ready'),'the recipient sees the link lifetime in a per-service editable text');
    $store->set('text.pornhub__handoff_ready','CUSTOM READY');
    handoffUiCheck($ui->text('handoff_ready',['expiry'=>'2 دقیقه'])==='CUSTOM READY','existing customized delivery text is preserved');
    $click('custom:edit:text:pornhub__handoff_ready'); $input('زمان لینک: {expiry}',704);
    handoffUiCheck($ui->text('handoff_ready',['expiry'=>'5 دقیقه'])==='زمان لینک: 5 دقیقه'&&$bot->ui->forService('beeg')->text('handoff_ready',['expiry'=>'2 دقیقه'])!==$ui->text('handoff_ready',['expiry'=>'5 دقیقه']),'the expiry placeholder is editable through personalization independently for each site');
    $badApi=new class implements Zero\Api { public function call(string $m,array $p=[]): mixed { throw new RuntimeException('sensitive upstream',401); } };
    try { $h->configure('789:fake_secret',$badApi); $reason=''; } catch(Zero\AdultHandoffSetupException $e) { $reason=$e->reason; handoffUiCheck(!str_contains($e->getMessage(),'fake_secret')&&!str_contains($e->getMessage(),'sensitive'),'safe errors never contain tokens or upstream output'); }
    handoffUiCheck($reason==='invalid_token','Telegram token rejection maps to an actionable reason');
    $webhookApi=new class implements Zero\Api { public function call(string $m,array $p=[]): mixed { return $m==='getMe'?['id'=>789,'is_bot'=>true,'username'=>'other_bot']:['url'=>'https://private.invalid/']; } };
    try { $h->configure('789:fake_secret',$webhookApi); $reason=''; } catch(Zero\AdultHandoffSetupException $e) { $reason=$e->reason; }
    handoffUiCheck($reason==='webhook','an active webhook has its own actionable error');
    $networkApi=new class implements Zero\Api { public function call(string $m,array $p=[]): mixed { throw new RuntimeException('private network detail'); } };
    try { $h->configure('789:fake_secret',$networkApi); $reason=''; } catch(Zero\AdultHandoffSetupException $e) { $reason=$e->reason; }
    handoffUiCheck($reason==='connection','connection failure has its own safe, actionable reason');
} finally { Zero\Download::clean($root); }
