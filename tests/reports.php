<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class ReportApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed { $this->calls[]=[$method,$params]; return $method==='sendMessage'?['message_id'=>700+count($this->calls)]:true; }
    public function last(string $method): ?array { foreach(array_reverse($this->calls) as $call) if($call[0]===$method) return $call[1]; return null; }
}
function rpCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function rpClick(string $data,int $message=800,int $user=42,int $chat=42): array {
    return ['callback_query'=>['id'=>'r','from'=>['id'=>$user],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']]+($chat<0?['message_thread_id'=>0]:[]),'data'=>$data]];
}
function rpActions(array $params): array { return array_values(array_filter(array_column(array_merge(...($params['reply_markup']['inline_keyboard']??[[]])),'callback_data'))); }
$temp=sys_get_temp_dir().'/ciro-reports-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fake',[42],$temp); $store=new Zero\Store($temp); $api=new ReportApi(); $bot=new Zero\Bot($config,$store,$api); $ui=$bot->ui;

    // Counting.
    $store->record('youtube',true,4000); $store->record('youtube',true,2000); $store->record('youtube',false,1000);
    $store->record('spotify',true,6000); $store->record('soundcloud',false,500);
    $store->query('INSERT INTO stats(day,service,ok,failed,ms) VALUES(?,?,?,?,?)',[gmdate('Y-m-d',time()-3*86400),'instagram',5,1,6000]);
    $store->query('INSERT INTO stats(day,service,ok,failed,ms) VALUES(?,?,?,?,?)',[gmdate('Y-m-d',time()-20*86400),'tiktok',2,0,2000]);
    $store->query('INSERT INTO stats(day,service,ok,failed,ms) VALUES(?,?,?,?,?)',[gmdate('Y-m-d',time()-200*86400),'x',1,0,1000]);
    $today=$store->query('SELECT SUM(ok),SUM(failed) FROM stats WHERE day=?',[gmdate('Y-m-d')])->fetch(PDO::FETCH_NUM);
    rpCheck((int)$today[0]===3&&(int)$today[1]===2,'finished requests are counted per day, split into successes and failures');
    $store->record('youtube',true,1000);
    rpCheck((int)$store->query("SELECT ok FROM stats WHERE service='youtube' AND day=?",[gmdate('Y-m-d')])->fetchColumn()===3,'the same day and service accumulate in one row');
    for($i=0;$i<12;$i++) $store->pushError($i%2?'spotify':'youtube',$i%2?'format':'blocked',$i===11?'youtube=blocked, soundcloud=format':'');
    $errors=json_decode($store->get('recent_errors'),true);
    rpCheck(count($errors)===10&&$errors[0]['s']==='spotify'&&$errors[0]['c']==='youtube=blocked, soundcloud=format'&&!isset($errors[0]['url']),'the last ten failures are kept, newest first, without any link');

    // Entry point: the existing status message gets the report buttons.
    $store->query('INSERT INTO users(id,last_request) VALUES(1,0),(2,0),(3,0)');
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,created) VALUES(1,1,1,'https://example.invalid/a','done',?),(2,2,2,'https://example.invalid/b','failed',?),(3,3,3,'https://example.invalid/c','done',?)",[time()-100,time()-3*86400,time()-20*86400]);
    $bot->handle(['message'=>['message_id'=>5,'from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'text'=>'/health']]);
    $home=$api->last('sendMessage');
    rpCheck(str_contains($home['text'],'📊 وضعیت ربات')&&rpActions($home)===['rp:stats','rp:services','rp:cookies','rp:errors','rp:server','rp:x'],'the status message keeps its text and offers the five reports and Close');

    // Each report is edited into the same message.
    $bot->handle(rpClick('rp:stats',777)); $edit=$api->last('editMessageText');
    rpCheck(($edit['message_id']??0)===777&&str_contains($edit['text'],'<b>📈 آمار ربات</b>')&&str_contains($edit['text'],'➖➖➖➖➖➖➖➖➖➖')&&str_contains($edit['text'],'<blockquote>'),'the statistics open in the same message with a title, state lines and an explanation');
    rpCheck(rpActions($edit)===['rp:stats','rp:home','rp:x'],'every report ends with Refresh, Back and Close');
    rpCheck(str_contains($edit['text'],'کاربران ثبت‌شده: <b>4</b>')&&str_contains($edit['text'],'فعال در ۲۴ ساعت: <b>1</b> · در ۷ روز: <b>2</b>'),'users and active users over 24 hours and 7 days come from the job history');
    rpCheck(str_contains($edit['text'],'امروز: <b>4</b> موفق')&&str_contains($edit['text'],'<b>2</b> ناموفق'),'today counts successes and failures');
    rpCheck(str_contains($edit['text'],'۷ روز اخیر: <b>9</b> موفق · <b>3</b> ناموفق')&&str_contains($edit['text'],'موفقیت <b>75٪</b>'),'seven days add the earlier day and give a success rate');
    rpCheck(str_contains($edit['text'],'۳۰ روز اخیر: <b>11</b> موفق')&&!str_contains($edit['text'],'<b>12</b> موفق'),'thirty days include the older day but not the one from 200 days ago');
    rpCheck(str_contains($edit['text'],'پرکاربردترین سرویس (۷ روز): <b>اینستاگرام (6)</b>')&&preg_match('/میانگین زمان هر درخواست: <b>[\d.]+ ثانیه<\/b>/u',$edit['text'])===1,'the busiest service and the average time are shown');

    $bot->handle(rpClick('rp:services',777)); $edit=$api->last('editMessageText');
    rpCheck(str_contains($edit['text'],'<b>🧩 وضعیت سرویس‌ها</b>')&&str_contains($edit['text'],'<b>یوتیوب</b>')&&str_contains($edit['text'],'✅ 3 · ❌ 1 · 75٪'),'services list their successes, failures and rate');
    rpCheck(str_contains($edit['text'],'<b>اینستاگرام</b>')&&str_contains($edit['text'],'<b>پینترست</b>')&&substr_count($edit['text'],'در ۷ روز اخیر درخواستی نبوده')>=3&&!str_contains($edit['text'],'تیک‌تاک</b>'."\n".'✅'),'a service without requests says so and a request older than a week is not counted');

    $bot->handle(rpClick('rp:errors',777)); $edit=$api->last('editMessageText');
    rpCheck(str_contains($edit['text'],'<b>🚨 آخرین خطاها</b>')&&str_contains($edit['text'],'<code>format</code>')&&str_contains($edit['text'],'youtube=blocked, soundcloud=format')&&substr_count($edit['text'],'🕒')===10,'the errors list the service, short reason and source results');
    $store->set('recent_errors',json_encode([['t'=>time(),'s'=>'youtube','r'=>'<b>x</b>','c'=>'<script>']]));
    $bot->handle(rpClick('rp:errors',777)); $edit=$api->last('editMessageText');
    rpCheck(!str_contains($edit['text'],'<script>')&&!str_contains($edit['text'],'<code><b>x')&&str_contains($edit['text'],'&lt;b&gt;x&lt;/b&gt;'),'stored text never turns into markup');
    $store->set('recent_errors','[]');
    $bot->handle(rpClick('rp:errors',777)); rpCheck(str_contains($api->last('editMessageText')['text'],'خطایی ثبت نشده'),'an empty list says no error was recorded');

    // Cookies: counts, failures, expiry, interval.
    $pool=Zero\Instagram::pool($config); $soon=time()+5*86400;
    $pool->add("# Netscape HTTP Cookie File\n#HttpOnly_.instagram.com\tTRUE\t/\tTRUE\t$soon\tsessionid\tFAKE_REPORT_VALUE\n.instagram.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tX\n");
    $pool->markFailed($pool->jars()[0]['id']);
    $store->set('cookies.check_minutes','180'); $store->set('cookie_sweep_last',(string)(time()-600));
    $bot->handle(rpClick('rp:cookies',777)); $edit=$api->last('editMessageText');
    rpCheck(str_contains($edit['text'],'<b>🍪 وضعیت کوکی‌ها</b>')&&str_contains($edit['text'],'<b>اینستاگرام</b>: 1 کوکی · 1 ردشده')&&str_contains($edit['text'],'5</b> روز دیگر')&&!str_contains($edit['text'],'FAKE_REPORT_VALUE'),'each service shows its cookies, refused ones and nearest expiry without any value');
    rpCheck(str_contains($edit['text'],'هر <b>3 ساعت</b>')&&str_contains($edit['text'],'10 دقیقه پیش'),'the check interval and the last check are shown');

    // Server.
    $store->set('poller_heartbeat',(string)time());
    $bot->handle(rpClick('rp:server',777)); $edit=$api->last('editMessageText');
    rpCheck(str_contains($edit['text'],'<b>🖥 وضعیت سرور</b>')&&str_contains($edit['text'],Zero\Updates::version())&&str_contains($edit['text'],'فضای آزاد')&&str_contains($edit['text'],'بار پردازنده')&&str_contains($edit['text'],'سرور ابری تلگرام')&&str_contains($edit['text'],'/setinline'),'the server report shows the version, free space, load, the Telegram interface and whether sharing (inline mode) is on');
    rpCheck(!str_contains($edit['text'],$config->token)&&!str_contains($edit['text'],'42'.'</b>'),'the report never shows the token or an administrator ID');

    // Back goes home, Close removes the message, unknown screens do nothing.
    $bot->handle(rpClick('rp:home',777)); $edit=$api->last('editMessageText');
    rpCheck(str_contains($edit['text'],'📊 وضعیت ربات')&&rpActions($edit)[0]==='rp:stats'&&($edit['message_id']??0)===777,'Back returns to the status message in place');
    $bot->handle(rpClick('rp:x',777)); $delete=$api->last('deleteMessage');
    rpCheck(($delete['message_id']??0)===777,'Close deletes the report');
    $before=count($api->calls); $bot->handle(rpClick('rp:nonsense',777));
    rpCheck(count(array_filter(array_slice($api->calls,$before),fn($c)=>str_starts_with($c[0],'edit')||$c[0]==='sendMessage'))===0,'an unknown report name changes nothing');

    // Only administrators, only in private.
    $before=count($api->calls); $bot->handle(rpClick('rp:stats',777,7,7)); $bot->handle(rpClick('rp:stats',777,42,-100500));
    $changed=array_filter(array_slice($api->calls,$before),fn($c)=>in_array($c[0],['editMessageText','sendMessage','deleteMessage'],true)&&!str_contains((string)($c[1]['text']??''),'groups_disabled'));
    rpCheck(!array_filter($changed,fn($c)=>str_contains((string)($c[1]['text']??''),'📈')),'an ordinary user or a group cannot open a report');

    // Reports are administrator texts and never reach Personalization.
    $leaked=array_filter(array_merge(array_keys($ui->catalog('text')),array_keys($ui->catalog('button'))),fn($key)=>str_starts_with($key,'report_'));
    rpCheck(!$leaked,'no report text or button is editable through Personalization');
    $all=true;
    foreach(Zero\Ui::definitions()['texts'] as $key=>$item) if(str_starts_with($key,'report_')&&($item['audience']??'')!=='admin') $all=false;
    foreach(Zero\Ui::definitions()['buttons'] as $key=>$item) if(str_starts_with($key,'report_')&&($item['audience']??'')!=='admin') $all=false;
    rpCheck($all,'every report entry is registered with the admin audience');
} finally { exec('rm -rf '.escapeshellarg($temp)); }
