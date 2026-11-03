<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
final class UxApi implements Zero\Api {
    public array $calls=[]; public int $message=100; public $onSend=null;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='sendMessage') {
            if($this->onSend) { $callback=$this->onSend; $this->onSend=null; $callback(); }
            return ['message_id'=>++$this->message];
        }
        return true;
    }
}
function uxCheck(bool $value,string $message): void { if(!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
function uxMessage(int $user,string $text): array { return ['update_id'=>random_int(1,1000000),'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]; }
function uxCallback(int $user,string $data,int $menu): array { return ['callback_query'=>['id'=>'test','from'=>['id'=>$user],'message'=>['message_id'=>$menu,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]; }
$temp=sys_get_temp_dir().'/ciro-ux-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $oldCaption='<b>🎬 ویدیوی شما آماده است</b>'."\n\n".'<blockquote>{title}</blockquote>'."\n".'کیفیت: <b>{quality}</b>'."\n".'{url}';
    $legacy=new PDO('sqlite:'.$temp.'/zero.sqlite');
    $legacy->exec('CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL)');
    foreach(['ui_html_version'=>'1','text.video_caption'=>$oldCaption,'text.downloading'=>'<blockquote><b>📥 در حال دانلود و آماده‌سازی ویدیو…</b></blockquote>'] as $key=>$value) $legacy->prepare('INSERT INTO settings VALUES(?,?)')->execute([$key,$value]);
    $legacy=null;
    $store=new Zero\Store($temp); $api=new UxApi(); $bot=new Zero\Bot(new Zero\Config('123:fake',[42],$temp),$store,$api);
    $caption=$bot->text('video_caption',['title'=>'Putak - BECHID','quality'=>'144p','duration'=>'03:42','url'=>'https://youtu.be/oXWMmELDOq0']);
    uxCheck(str_contains($caption,'<b>🎬 Putak - BECHID</b>')&&str_contains($caption,'<blockquote>')&&str_contains($caption,'144p')&&str_contains($caption,'03:42'),'a saved historical caption upgrades to the final title, quality and duration layout');
    uxCheck(!str_contains($caption,'https://')&&!str_contains($caption,'ویدیوی شما آماده است'),'the historical heading and duplicate raw URL do not remain in the new default caption');
    foreach(['queued','inspecting','quality_prompt','downloading','uploading','audio_downloading','audio_uploading'] as $key) {
        $text=$bot->text($key,['title'=>'Test','max_size'=>'49']);
        uxCheck(!str_contains($text,'<blockquote>')&&!str_contains($text,'<b>'),$key.' is simple before delivery');
    }
    $custom='<b>Custom {title}</b> <blockquote>{duration}</blockquote>';
    $store->set('text.video_caption',$custom); $reopen=new Zero\Store($temp);
    uxCheck((new Zero\Ui($reopen))->template('video_caption')===$custom,'later restarts preserve deliberately customized captions');
    $other=$temp.'/custom'; mkdir($other,0700);
    $database=new PDO('sqlite:'.$other.'/zero.sqlite'); $database->exec('CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL)');
    foreach(['ui_html_version'=>'1','text.video_caption'=>$custom] as $key=>$value) $database->prepare('INSERT INTO settings VALUES(?,?)')->execute([$key,$value]);
    $database=null;
    uxCheck((new Zero\Ui(new Zero\Store($other)))->template('video_caption')===$custom,'the upgrade itself also preserves a real custom caption');

    foreach([42,7,8] as $id) $store->query('INSERT OR IGNORE INTO users(id) VALUES(?)',[$id]);
    foreach(['done','done','failed'] as $index=>$status) $store->query('INSERT INTO jobs(update_id,user_id,chat_id,url,status,created) VALUES(?,?,?,?,?,?)',[100+$index,7,7,'https://youtu.be/oXWMmELDOq0',$status,time()]);
    $store->set('last_job_error','Job 1 [download]: cookies are no longer valid. Sign in to confirm you are not a bot.');
    $bot->handle(uxMessage(42,'/health')); $health=end($api->calls)[1]['text'];
    uxCheck(str_contains($health,'کوکی')&&str_contains($health,'ایمپورت')&&!str_contains($health,'Sign in')&&!str_contains($health,'Job 1'),'legacy cookie diagnostics are presented as a useful Persian explanation');
    uxCheck(str_contains($health,'کاربران ثبت‌شده: <b>3</b>')&&str_contains($health,'7 روز اخیر: <b>2</b>')&&str_contains($health,'7 روز اخیر: <b>1</b>'),'health reports unique registered users and accurately bounded seven-day delivery statistics');
    $store->set('last_job_error','YouTube cookie file is unreadable or too large');
    $bot->handle(uxMessage(42,'/health'));
    uxCheck(str_contains(end($api->calls)[1]['text'],'ایمپورت'),'an unreadable legacy cookie file is also identified as a cookie problem');
    $store->set('last_job_error','Job 2 [upload]: transport failed'); $store->set('last_job_error_reason','upload');
    $bot->handle(uxMessage(42,$bot->label('health')));
    uxCheck(str_contains(end($api->calls)[1]['text'],'ارسال به تلگرام')&&!str_contains(end($api->calls)[1]['text'],'transport failed'),'structured upload failures also have Persian health text');
    $bot->handle(uxCallback(7,'health',1));
    uxCheck(end($api->calls)[0]==='answerCallbackQuery'&&!empty(end($api->calls)[1]['show_alert']),'public users cannot open administrator statistics');

    foreach(['🔄 به‌روزرسانی ربات','🔄️ به روز رسانی ربات',"\u{200F}🔄 بروزرسانی روبات",'بروزرسانی روبات','آپدیت ربات','Update bot'] as $label) {
        $bot->handle(uxMessage(42,$label));
        uxCheck(end($api->calls)[0]==='sendMessage'&&str_contains(end($api->calls)[1]['text'],'مدیریت به‌روزرسانی'),'reply update label opens a visible menu: '.$label);
    }
    $store->set('updater_heartbeat',(string)time()); $menu=(int)$store->get('update_menu.42');
    $bot->handle(uxCallback(42,'update:start',$menu));
    $store->set('update_percent','35'); $store->set('update_stage','update_build');
    $bot->handle(uxMessage(42,'🔄 بروزرسانی روبات')); $new=(int)$store->get('update_menu.42');
    $job=$store->query("SELECT * FROM update_requests WHERE status='queued'")->fetch();
    uxCheck($new!==$menu&&(int)$job['message_id']===$new&&(int)$store->query('SELECT COUNT(*) FROM update_requests')->fetchColumn()===1,'reopening an active update reconnects to the same request instead of silently returning or starting a second update');
    $messages=array_values(array_filter($api->calls,fn($call)=>$call[0]==='sendMessage'));
    uxCheck(str_contains(end($messages)[1]['text'],'35%')&&end($api->calls)[0]==='deleteMessage'&&end($api->calls)[1]['message_id']===$menu,'the current progress is visible and the old progress message is removed');
    $api->onSend=fn()=>$store->query("UPDATE update_requests SET status='done'");
    $bot->handle(uxMessage(42,'/update'));
    uxCheck(end($api->calls)[0]==='editMessageText'&&str_contains(json_encode(end($api->calls)[1]),'update:start'),'an update that finishes while reopening returns to the menu instead of leaving a stuck progress view');
} finally { Zero\Download::clean($temp); }
