<?php
declare(strict_types=1);
namespace Zero;

final class Bot
{
    public const TEXTS = [
        'welcome' => ['خوش‌آمدگویی', 'سلام! لینک یوتیوب را بفرست تا ویدیو را با کیفیت حداکثر ۴۸۰p دریافت کنی.'],
        'help' => ['راهنما', 'لینک ویدیو یا Shorts یوتیوب را بفرست. هر بار یک دانلود؛ ویدیوی زنده پشتیبانی نمی‌شود.'],
        'offline' => ['پیام خاموش بودن', 'ربات موقتاً خاموش است؛ بعداً دوباره تلاش کن.'],
        'prompt' => ['درخواست لینک', 'لینک ویدیوی یوتیوب را بفرست.'],
        'queued' => ['ثبت در صف', 'درخواست ثبت شد و در صف دانلود قرار گرفت.'],
        'downloading' => ['شروع دانلود', 'در حال دانلود و آماده‌سازی ویدیو…'],
        'uploading' => ['شروع ارسال', 'دانلود تمام شد؛ در حال ارسال فایل…'],
        'video_caption' => ['کپشن فایل', 'ویدیوی شما آماده است 🎬\n{title}\nکیفیت: {quality}\n{url}'],
        'failed' => ['خطای دانلود', 'دانلود یا ارسال انجام نشد. ویدیو ممکن است محدود، زنده، طولانی یا بزرگ‌تر از سقف فایل باشد.'],
        'invalid' => ['لینک نامعتبر', 'لینک معتبر یک ویدیوی یوتیوب را بفرست.'],
    ];
    public const BUTTONS = [
        'download' => ['دکمه دانلود', '🎬 دانلود ویدیو'], 'help' => ['دکمه راهنما', '❓ راهنما'],
        'admin' => ['دکمه پنل ادمین', '⚙️ پنل ادمین'], 'on' => ['دکمه روشن کردن', '🟢 روشن کردن ربات'],
        'off' => ['دکمه خاموش کردن', '🔴 خاموش کردن ربات'],
        'texts' => ['دکمه تنظیم متن‌ها', '✏️ شخصی‌سازی متن‌ها و کپشن‌ها'],
        'buttons' => ['دکمه تنظیم دکمه‌ها', '🔘 شخصی‌سازی دکمه‌ها'],
        'back' => ['دکمه بازگشت', '↩️ بازگشت'],
    ];
    public function __construct(public Config $config, public Store $store, public Api $api) {}
    public function text(string $key): string { return $this->store->get('text.'.$key, str_replace('\\n', "\n", self::TEXTS[$key][1])); }
    public function label(string $key): string { return $this->store->get('button.'.$key, self::BUTTONS[$key][1]); }
    public function admin(int $id): bool { return in_array($id, $this->config->admins, true); }
    public function button(string $key, string $data): array { return ['text'=>$this->label($key), 'callback_data'=>$data]; }
    public function send(int $chat, string $text, array $rows = []): void
    {
        $params = ['chat_id'=>$chat, 'text'=>$text];
        if ($rows) $params['reply_markup'] = ['inline_keyboard'=>$rows];
        $this->api->call('sendMessage', $params);
    }
    public function home(int $chat, int $user): void
    {
        $rows = [[$this->button('download','download'),$this->button('help','help')]];
        if ($this->admin($user)) $rows[] = [$this->button('admin','admin')];
        $this->send($chat, $this->text('welcome'), $rows);
    }
    public function panel(int $chat): void
    {
        $on = $this->store->enabled();
        $this->send($chat, 'پنل ادمین — وضعیت ربات: '.($on ? 'روشن ✅' : 'خاموش ❌'), [
            [$this->button($on?'off':'on', $on?'set:off':'set:on')],
            [$this->button('texts','list:text')],[$this->button('buttons','list:button')],
            [$this->button('back','home')],
        ]);
    }
    public function catalog(string $kind): array { return $kind === 'text' ? self::TEXTS : ($kind === 'button' ? self::BUTTONS : []); }
    public function listItems(int $chat, string $kind): void
    {
        $rows=[];
        foreach ($this->catalog($kind) as $key=>$item) $rows[]=[['text'=>$item[0], 'callback_data'=>"item:$kind:$key"]];
        $rows[]=[$this->button('back','admin')];
        $this->send($chat, 'بخش موردنظر را انتخاب کن:', $rows);
    }
    public function item(int $chat, string $kind, string $key): void
    {
        if (!isset($this->catalog($kind)[$key])) return;
        $current=$kind==='text'?$this->text($key):$this->label($key);
        $default=str_replace('\\n',"\n",$this->catalog($kind)[$key][1]);
        $this->send($chat,"متن فعلی:\n$current\n\nپیش‌فرض:\n$default",[
            [['text'=>'✏️ ویرایش','callback_data'=>"edit:$kind:$key"]],
            [['text'=>'🔄 بازگردانی پیش‌فرض','callback_data'=>"reset:$kind:$key"]],
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
            // Check every callback, including forged/stale ones, before state changes.
            $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>($privileged&&!$this->admin($user))?'دسترسی ندارید.':'']);
            if ($privileged&&!$this->admin($user)) return;
            if (!$this->store->enabled()&&!$this->admin($user)) { $this->send($chat,$this->text('offline')); return; }
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            if ($action==='home') $this->home($chat,$user);
            elseif ($action==='help') $this->send($chat,$this->text('help'));
            elseif ($action==='download') $this->send($chat,$this->text('prompt'));
            elseif ($action==='admin') $this->panel($chat);
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
                        $this->send($chat,'متن جدید را بفرست. برای لغو /cancel را بزن.'.($key==='video_caption'?"\nمتغیرها: {title}، {quality}، {url}":''));
                    }
                }
            }
            return;
        }
        $text=trim((string)($message['text']??''));
        if ($text==='') return;
        if ($text==='/id') { $this->send($chat,"شناسه شما: $user"); return; }
        if (in_array($text,['/cancel','/start','/admin'],true)) $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        if ($text==='/admin'&&$this->admin($user)) { $this->panel($chat); return; }
        if (!$this->store->enabled()&&!$this->admin($user)) { $this->send($chat,$this->text('offline')); return; }
        if (in_array($text,['/start','/cancel'],true)) { $this->home($chat,$user); return; }
        $state=$this->store->query('SELECT key FROM states WHERE user_id=? AND expires>?',[$user,time()])->fetchColumn();
        if ($state && $this->admin($user)) {
            [$kind,$key]=explode('.',$state,2);
            if (!isset($this->catalog($kind)[$key])) return;
            $limit=$kind==='button'?48:($key==='video_caption'?700:1200);
            if (mb_strlen($text)>$limit || ($kind==='button'&&str_contains($text,"\n"))) {
                $this->send($chat,"متن باید حداکثر $limit نویسه باشد؛ دکمه باید تک‌خطی باشد."); return;
            }
            $this->store->set($state,$text);
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $this->item($chat,$kind,$key); return;
        }
        $url=youtubeUrl($text);
        if (!$url) { $this->send($chat,$this->text('invalid')); return; }
        $result=$this->store->enqueue((int)$update['update_id'],$user,$chat,$url);
        $response=match($result) {
            'busy'=>'درخواست قبلی شما هنوز در حال انجام است.', 'full'=>'صف پر است؛ کمی بعد تلاش کن.',
            'cooldown'=>'بین درخواست‌ها ۱۵ ثانیه فاصله بگذار.', 'duplicate'=>null,
            default=>$this->text('queued'),
        };
        if ($response!==null) $this->send($chat,$response);
    }
}
