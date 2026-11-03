<?php
declare(strict_types=1);
namespace Zero;

final class Bot
{
    public const TEXTS = [
        'welcome' => ['خوش‌آمدگویی', "<b>به سیرو دانلودر خوش آمدی</b>\n\nلینک ویدیو یا شورت یوتیوب را بفرست تا فایل MP4 همراه صدا، با کیفیت حداکثر ۴۸۰ دریافت کنی."],
        'help' => ['راهنما', "لینک ویدیو یا Shorts یوتیوب را بفرست.\nهر بار یک درخواست؛ حداکثر ۲۰ دقیقه.\nپخش زنده و پلی‌لیست پشتیبانی نمی‌شوند.\nبعد از آماده‌سازی، فایل ویدیو همین‌جا ارسال می‌شود."],
        'offline' => ['توقف ربات', 'ربات موقتاً متوقف است؛ لطفاً بعداً دوباره تلاش کن.'],
        'prompt' => ['درخواست لینک', 'لینک ویدیو یا شورت یوتیوب را بفرست.'],
        'queued' => ['ثبت در صف', 'درخواستت در صف قرار گرفت؛ به محض شروع دانلود خبر می‌دهم.'],
        'downloading' => ['شروع دانلود', 'در حال دانلود و آماده‌سازی ویدیو…'],
        'uploading' => ['شروع ارسال', 'دانلود تمام شد؛ در حال آپلود فایل ویدیو…'],
        'video_caption' => ['کپشن ویدیو', "ویدیوی شما آماده است\n\n{title}\nکیفیت: {quality}\n{url}"],
        'failed' => ['خطای پردازش', 'پردازش ویدیو انجام نشد. لطفاً دوباره تلاش کن؛ جزئیات خطا برای مدیر ثبت شد.'],
        'invalid' => ['لینک نامعتبر', 'لینک معتبر یک ویدیوی یوتیوب یا Shorts را بفرست.'],
        'restarted' => ['راه‌اندازی مجدد', 'ربات هنگام پردازش دوباره راه‌اندازی شد؛ اگر فایل را نگرفتی، لینک را مجدد بفرست.'],
    ];
    public const BUTTONS = [
        'download' => ['دکمه دانلود', 'دانلود ویدیو'], 'help' => ['دکمه راهنما', 'راهنما'],
        'admin' => ['دکمه مدیریت', 'پنل مدیریت'], 'on' => ['دکمه فعال‌سازی', 'فعال‌کردن ربات'],
        'off' => ['دکمه توقف', 'توقف ربات'],
        'texts' => ['تنظیم پیام‌ها', 'ویرایش پیام‌ها'],
        'buttons' => ['تنظیم دکمه‌ها', 'ویرایش دکمه‌ها'],
        'health' => ['دکمه وضعیت', 'وضعیت ربات'],
        'cookies' => ['دکمه واردکردن کوکی', 'واردکردن کوکی یوتیوب'],
        'back' => ['دکمه بازگشت', 'بازگشت'],
    ];
    public function __construct(public Config $config, public Store $store, public Api $api) {}
    public function text(string $key): string { return $this->store->get('text.'.$key, str_replace('\\n', "\n", self::TEXTS[$key][1])); }
    public function label(string $key): string { return $this->store->get('button.'.$key, self::BUTTONS[$key][1]); }
    public function admin(int $id): bool { return in_array($id, $this->config->admins, true); }
    public function button(string $key, string $data): array { return ['text'=>$this->label($key), 'callback_data'=>$data]; }
    public function replyButton(string $key): array { return ['text'=>$this->label($key)]; }
    public function send(int $chat, string $text, array $rows = []): void
    {
        $params = ['chat_id'=>$chat, 'text'=>$text, 'parse_mode'=>'HTML', 'disable_web_page_preview'=>true];
        if ($rows) $params['reply_markup'] = ['inline_keyboard'=>$rows];
        $this->api->call('sendMessage', $params);
    }
    public function sendKeyboard(int $chat, string $text, array $rows): void
    {
        $this->api->call('sendMessage', [
            'chat_id'=>$chat, 'text'=>$text, 'parse_mode'=>'HTML', 'disable_web_page_preview'=>true,
            'reply_markup'=>['keyboard'=>$rows, 'resize_keyboard'=>true, 'is_persistent'=>true],
        ]);
    }
    public function home(int $chat, int $user): void
    {
        if ($this->admin($user)) {
            $this->sendKeyboard($chat, $this->text('welcome'), [
                [$this->replyButton('download'), $this->replyButton('help')],
                [$this->replyButton('admin'), $this->replyButton('health')],
            ]);
            return;
        }
        $rows = [[$this->button('download','download'),$this->button('help','help')]];
        $this->send($chat, $this->text('welcome'), $rows);
    }
    public function panel(int $chat): void
    {
        $on = $this->store->enabled();
        $this->send($chat, "<b>پنل مدیریت</b>\nوضعیت: <b>".($on ? 'فعال' : 'متوقف').'</b>', [
            [$this->button($on?'off':'on', $on?'set:off':'set:on')],
            [$this->button('texts','list:text')],[$this->button('buttons','list:button')],
            [$this->button('health','health')],
            [$this->button('cookies','cookies')],
            [$this->button('back','home')],
        ]);
    }
    public function health(int $chat): void
    {
        $now=time();
        $worker=(int)$this->store->get('worker_heartbeat','0');
        $poller=(int)$this->store->get('poller_heartbeat','0');
        $queued=(int)$this->store->query("SELECT COUNT(*) FROM jobs WHERE status='queued'")->fetchColumn();
        $running=(int)$this->store->query("SELECT COUNT(*) FROM jobs WHERE status='running'")->fetchColumn();
        $workerOk=$worker>0&&$now-$worker<20;
        $pollerOk=$poller>0&&$now-$poller<60;
        $enabled=$this->store->enabled();
        clearstatcache(true,$this->config->cookiePath());
        $cookieStatus=is_readable($this->config->cookiePath())?'بارگذاری شده (اعتبار ورود هنوز نیاز به تست دارد)':'تنظیم نشده';
        $proxyStatus=$this->config->youtubeProxy!==''?'تنظیم شده':'تنظیم نشده';
        $workerStatus=$running>0?'در حال پردازش':($workerOk?'آنلاین':'بدون پاسخ اخیر');
        $lastError=htmlspecialchars($this->store->get('last_job_error','ثبت نشده'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->send($chat,"<b>وضعیت ربات</b>\n\n".
            'ربات: <b>'.($enabled?'فعال':'متوقف')."</b>\n".
            'دریافت پیام: <b>'.($pollerOk?'آنلاین':'بدون پاسخ اخیر')."</b>\n".
            'پردازشگر: <b>'.$workerStatus."</b>\n".
            "صف: <b>$queued منتظر / $running در حال اجرا</b>\n".
            "کوکی یوتیوب: $cookieStatus\nپراکسی یوتیوب: $proxyStatus\n\nآخرین خطا:\n<code>$lastError</code>");
    }
    public function catalog(string $kind): array { return $kind === 'text' ? self::TEXTS : ($kind === 'button' ? self::BUTTONS : []); }

    public function beginCookieImport(int $chat, int $user): void
    {
        $this->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'cookie.upload',time()+600]);
        clearstatcache(true,$this->config->cookiePath());
        $status=is_readable($this->config->cookiePath())?'کوکی فعلی موجود است؛ فایل جدید جایگزین آن می‌شود.':'هنوز کوکی وارد نشده است.';
        $this->send($chat,"<b>واردکردن کوکی یوتیوب</b>\n\n$status\n\n".
            "فایل <code>youtube-cookies.txt</code> را همین‌جا به‌صورت <b>فایل / Document</b> بفرست.\n".
            "فرمت: Netscape؛ حداکثر حجم: ۱ مگابایت.\n".
            "مهلت ارسال: ۱۰ دقیقه. برای لغو /cancel را بزن.\n\n".
            'فایل بعد از دریافت بررسی می‌شود؛ فایل نامعتبر جای کوکی قبلی نمی‌نشیند. پس از واردکردن، برای بررسی اعتبار ورود یک لینک ویدیو بفرست.',[
                [['text'=>'کوکی چیست و چه زمانی باطل می‌شود؟','callback_data'=>'cookies:info']],
                [$this->button('back','admin')],
            ]);
    }

    public function cookieInfo(int $chat): void
    {
        $this->send($chat,"<b>کوکی چطور کار می‌کند؟</b>\n\n".
            "کوکی مثل کلید نشست ورود به یوتیوب است؛ بستهٔ دانلود یا حجم مصرفی نیست و با هر دانلود یکی از آن کم نمی‌شود.\n\n".
            "تاریخ اعتبار هر کوکی و اعتبار نشست را یوتیوب تعیین می‌کند. خروج از حساب، تغییر رمز، لغو نشست یا تصمیم امنیتی گوگل ممکن است آن را بی‌اعتبار کند. بازکردن دوبارهٔ همان نشست مرورگر هم می‌تواند باعث چرخش کوکی شود.\n\n".
            "تعداد زیاد درخواست ممکن است محدودیت موقت ایجاد کند؛ این به معنی تمام‌شدن کوکی نیست.\n\n".
            "فایل معتبر از نظر فرمت الزاماً ورود معتبر ندارد. بعد از جایگزینی یک لینک ویدیو بفرست؛ اگر خطای ورود ادامه داشت، فایل تازه یا اتصال سرور را بررسی کن.\n\n".
            'فایل کوکی اطلاعات ورود دارد؛ فقط در گفت‌وگوی خصوصی همین ربات و با حساب ادمین واردش کن.',[
                [$this->button('cookies','cookies')],[$this->button('back','admin')],
            ]);
    }

    private function cookieDocument(int $chat, int $user, array $message): void
    {
        if(!$this->admin($user)) { $this->send($chat,'دریافت کوکی فقط برای مدیر مجاز است.'); return; }
        $state=$this->store->query('SELECT key,expires FROM states WHERE user_id=?',[$user])->fetch();
        if(!$state||$state['key']!=='cookie.upload') {
            $this->send($chat,'برای واردکردن کوکی، ابتدا دکمهٔ «واردکردن کوکی یوتیوب» را در پنل مدیریت بزن.');
            return;
        }
        $phase='metadata';
        $saved=false;
        try {
            if((int)$state['expires']<=time()) {
                $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
                $this->send($chat,'مهلت ارسال تمام شده؛ دوباره دکمهٔ واردکردن کوکی را بزن.');
                return;
            }
            $document=$message['document'];
            if(strtolower(pathinfo((string)($document['file_name']??''),PATHINFO_EXTENSION))!=='txt'
                ||(int)($document['file_size']??0)>1048576||empty($document['file_id'])) {
                $this->send($chat,'یک فایل txt با فرمت Netscape و حجم حداکثر ۱ مگابایت بفرست. کوکی قبلی حفظ شد.');
                return;
            }
            $phase='download';
            $file=$this->api->call('getFile',['file_id'=>$document['file_id']]);
            if(!is_array($file)||empty($file['file_path'])||(int)($file['file_size']??0)>1048576||!($this->api instanceof FileApi)) {
                throw new \RuntimeException('Telegram cookie file unavailable or too large');
            }
            $content=$this->api->downloadFile((string)$file['file_path'],1048576);
            $phase='validation';
            validateYouTubeCookies($content);
            $phase='save';
            saveYouTubeCookies($this->config,$content);
            $saved=true;
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $this->send($chat,"<b>کوکی جدید با موفقیت جایگزین شد.</b>\n\n".
                'از دانلود بعدی استفاده می‌شود؛ نیازی به راه‌اندازی مجدد نیست. برای بررسی ورود، یک لینک یوتیوب بفرست.',[
                    [$this->button('back','admin')],
                ]);
        } catch(\Throwable $e) {
            // File contents and upstream cookie parser errors never enter logs or replies.
            error_log('Cookie import failed at '.$phase.': '.get_class($e));
            if($saved) {
                $this->send($chat,'کوکی جدید ذخیره شد، اما تکمیل پیام تأیید با خطا مواجه شد. /cancel را بزن و سپس یک لینک ویدیو برای تست بفرست.');
                return;
            }
            $reason=match($phase) {
                'validation'=>'فایل، خروجی معتبر Netscape از یوتیوب نیست.',
                'save'=>'ذخیره‌سازی کوکی روی سرور انجام نشد.',
                default=>'دریافت فایل از تلگرام انجام نشد یا حجم آن بیش از حد مجاز است.',
            };
            $this->send($chat,$reason.' کوکی قبلی حفظ شد؛ فایل صحیح را دوباره بفرست یا /cancel را بزن.');
        } finally {
            if(isset($message['message_id'])) {
                try { $this->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message['message_id']]); }
                catch(\Throwable) { $this->send($chat,'حذف خودکار پیام فایل انجام نشد؛ لطفاً فایل کوکی را از گفت‌وگو پاک کن.'); }
            }
        }
    }
    public function listItems(int $chat, string $kind): void
    {
        $rows=[];
        foreach ($this->catalog($kind) as $key=>$item) $rows[]=[['text'=>$item[0], 'callback_data'=>"item:$kind:$key"]];
        $rows[]=[$this->button('back','admin')];
        $this->send($chat, '<b>موردی را برای ویرایش انتخاب کن</b>', $rows);
    }
    public function item(int $chat, string $kind, string $key): void
    {
        if (!isset($this->catalog($kind)[$key])) return;
        $current=$kind==='text'?$this->text($key):$this->label($key);
        $default=str_replace('\\n',"\n",$this->catalog($kind)[$key][1]);
        $current=htmlspecialchars($current, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $default=htmlspecialchars($default, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->send($chat,"<b>مقدار فعلی</b>\n$current\n\n<b>پیش‌فرض</b>\n$default",[
            [['text'=>'ویرایش','callback_data'=>"edit:$kind:$key"]],
            [['text'=>'بازگردانی پیش‌فرض','callback_data'=>"reset:$kind:$key"]],
            [$this->button('back',"list:$kind")],
        ]);
    }
    public function handle(array $update): void
    {
        $cb=$update['callback_query']??null;
        $message=$cb['message']??$update['message']??null;
        $user=(int)($cb['from']['id']??$message['from']['id']??0);
        if (!$message || ($message['chat']['type']??'')!=='private' || !$user) return;
        $chat=(int)$message['chat']['id'];
        $this->store->query('INSERT OR IGNORE INTO users(id) VALUES(?)',[$user]);
        if ($cb) {
            $action=(string)($cb['data']??'');
            $privileged = !in_array($action,['home','download','help'],true);
            $isHelp = $action === 'help';
            // Check every callback, including forged/stale ones, before state changes.
            $this->api->call('answerCallbackQuery',[
                'callback_query_id'=>$cb['id'],
                'text'=>($privileged&&!$this->admin($user))?'این بخش فقط برای مدیر است.':($isHelp?mb_substr(strip_tags($this->text('help')),0,190):''),
                'show_alert'=>$isHelp,
                'cache_time'=>1,
            ]);
            if ($privileged&&!$this->admin($user)) return;
            if (!$this->store->enabled()&&!$this->admin($user)) { $this->send($chat,$this->text('offline')); return; }
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            if ($action==='home') $this->home($chat,$user);
            elseif ($action==='help') return;
            elseif ($action==='download') $this->send($chat,$this->text('prompt'));
            elseif ($action==='admin') $this->panel($chat);
            elseif ($action==='health') $this->health($chat);
            elseif ($action==='cookies') $this->beginCookieImport($chat,$user);
            elseif ($action==='cookies:info') $this->cookieInfo($chat);
            elseif (in_array($action,['set:on','set:off'],true)) {
                $this->store->set('enabled',$action==='set:on'?'1':'0'); $this->panel($chat);
            } else {
                $parts=explode(':',$action); [$op,$kind,$key]=array_pad($parts,3,'');
                if ($op==='list' && $this->catalog($kind)) $this->listItems($chat,$kind);
                elseif (isset($this->catalog($kind)[$key])) {
                    if ($op==='item') $this->item($chat,$kind,$key);
                    elseif ($op==='reset') {
                        $this->store->query('DELETE FROM settings WHERE key=?',["$kind.$key"]); $this->item($chat,$kind,$key);
                    } elseif ($op==='edit') {
                        $this->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,"$kind.$key",time()+600]);
                        $this->send($chat,"<b>مقدار جدید را بفرست</b>\nبرای لغو /cancel را بزن.".($key==='video_caption'?"\nمتغیرها: <code>{title}</code>، <code>{quality}</code>، <code>{url}</code>":''));
                    }
                }
            }
            return;
        }
        if(isset($message['document'])) { $this->cookieDocument($chat,$user,$message); return; }
        $text=trim((string)($message['text']??''));
        if ($text==='') return;
        if ($text==='/id') { $this->send($chat,"شناسه تلگرام شما: <code>$user</code>"); return; }
        if (in_array($text,['/cancel','/start','/admin','/health','/cookies',$this->label('cookies'),$this->label('admin'),$this->label('health'),$this->label('help'),$this->label('download')],true)) {
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        }
        if (($text==='/admin'||$text===$this->label('admin'))&&$this->admin($user)) { $this->panel($chat); return; }
        if (($text==='/health'||$text===$this->label('health'))&&$this->admin($user)) { $this->health($chat); return; }
        if (($text==='/cookies'||$text===$this->label('cookies'))&&$this->admin($user)) { $this->beginCookieImport($chat,$user); return; }
        if (!$this->store->enabled()&&!$this->admin($user)) { $this->send($chat,$this->text('offline')); return; }
        if (in_array($text,['/start','/cancel'],true)) { $this->home($chat,$user); return; }
        if ($text===$this->label('help')||$text==='/help') { $this->send($chat,$this->text('help')); return; }
        if ($text===$this->label('download')) { $this->send($chat,$this->text('prompt')); return; }
        $state=$this->store->query('SELECT key FROM states WHERE user_id=? AND expires>?',[$user,time()])->fetchColumn();
        if ($state && $this->admin($user)) {
            if($state==='cookie.upload') { $this->send($chat,'منتظر فایل txt هستم؛ آن را به‌صورت Document بفرست یا /cancel را بزن.'); return; }
            [$kind,$key]=explode('.',$state,2);
            if (!isset($this->catalog($kind)[$key])) return;
            $limit=$kind==='button'?48:($key==='video_caption'?700:1200);
            if (mb_strlen($text)>$limit || ($kind==='button'&&str_contains($text,"\n"))) {
                $this->send($chat,"حداکثر $limit نویسه مجاز است؛ متن دکمه باید تک‌خطی باشد."); return;
            }
            $this->store->set($state,$text);
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $this->item($chat,$kind,$key); return;
        }
        $url=youtubeUrl($text);
        if (!$url) { $this->send($chat,$this->text('invalid')); return; }
        $result=$this->store->enqueue((int)$update['update_id'],$user,$chat,$url);
        $response=match($result) {
            'busy'=>'درخواست قبلی شما هنوز در حال پردازش است.', 'full'=>'صف پر است؛ کمی بعد تلاش کن.',
            'cooldown'=>'بین درخواست‌ها ۱۵ ثانیه فاصله بگذار.', 'duplicate'=>null,
            default=>$this->text('queued'),
        };
        if ($response!==null) $this->send($chat,$response);
    }
}
