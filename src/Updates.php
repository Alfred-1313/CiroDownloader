<?php
declare(strict_types=1);
namespace Zero;

// The bot only queues fixed actions. A separate host service owns Docker/Git access.
final class Updates
{
    public function __construct(private Config $config, private Store $store, private Api $api) {}

    public static function version(): string
    {
        $file=dirname(__DIR__).'/VERSION';
        return is_readable($file)?trim((string)file_get_contents($file)):'نامشخص';
    }

    private function active(): array|false
    {
        return $this->store->query("SELECT * FROM update_requests WHERE status IN ('queued','running') ORDER BY id LIMIT 1")->fetch();
    }

    private function view(int $chat, int $user, string $text, array $rows, int $message = 0): int
    {
        $message=$message?:((int)$this->store->get('update_menu.'.$user,'0'));
        $params=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true,
            'reply_markup'=>['inline_keyboard'=>$rows]];
        $hash=hash('sha256',json_encode($params,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if($message>0&&$message===(int)$this->store->get('update_menu.'.$user,'0')&&$hash===$this->store->get('update_menu_hash.'.$user)) return $message;
        if($message>0) {
            $params['message_id']=$message;
            try { $this->api->call('editMessageText',$params); }
            catch(\RuntimeException $e) {
                if($e->getCode()!==404) throw $e;
                $message=0; unset($params['message_id']); // The user deleted the old menu.
            }
        }
        if($message===0) {
            $result=$this->api->call('sendMessage',$params);
            if(!is_array($result)||empty($result['message_id'])) throw new \RuntimeException('Missing update menu message ID');
            $message=(int)$result['message_id'];
        }
        $this->store->set('update_menu.'.$user,(string)$message);
        $this->store->set('update_menu_hash.'.$user,$hash);
        return $message;
    }

    private function freshChanges(): array
    {
        $raw=$this->store->get('update_new_changes','#unset#');
        // An older host agent may have performed the upgrade installing this release.
        if($raw==='#unset#') {
            $from=$this->store->get('update_previous_version'); $notes=[]; $known=[];
            $entries=json_decode((string)file_get_contents(dirname(__DIR__).'/CHANGELOG.json'),true)?:[];
            usort($entries,fn($a,$b)=>version_compare($a['version'],$b['version']));
            if(preg_match('/^\d+\.\d+\.\d+$/',$from)&&version_compare($from,self::version(),'<')) {
                foreach($entries as $entry) {
                    if(version_compare($entry['version'],self::version(),'>')) continue;
                    foreach($entry['changes']??[] as $note) {
                        $normalized=mb_strtolower(preg_replace('/\s+/u',' ',trim($note))??'');
                        if(version_compare($entry['version'],$from,'>')&&!isset($known[$normalized])) $notes[]=$note;
                        $known[$normalized]=true;
                    }
                }
            }
            $raw=json_encode(['from'=>$from,'to'=>self::version(),'changes'=>$notes],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $this->store->set('update_new_changes',$raw);
        }
        $data=json_decode($raw,true);
        return is_array($data)&&($data['to']??'')===self::version()&&is_array($data['changes']??null)?array_values(array_filter($data['changes'],'is_string')):[];
    }

    public function menu(int $chat, int $user, int $message = 0, string $notice = '', bool $fresh = false): void
    {
        if(!in_array($user,$this->config->admins,true)||$chat!==$user) return;
        $active=$this->active();
        if($active&&(int)$active['user_id']===$user) return; // Preserve the live progress screen.
        if($fresh) $this->store->set('update_new_changes','');
        $version=htmlspecialchars(self::version(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $checked=htmlspecialchars($this->store->get('last_update_at','هنوز بررسی نشده'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $text="<blockquote><b>🔄 مدیریت به‌روزرسانی سیرو دانلودر</b></blockquote>\n\n";
        if($notice!=='') $text.='<blockquote><b>'.htmlspecialchars($notice,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</b></blockquote>\n\n";
        $text.="نسخهٔ فعلی: <b>$version</b>\nآخرین بررسی: <b>$checked</b>\n\n".
            "با زدن دکمهٔ زیر، آخرین نسخه بررسی و در صورت وجود نصب می‌شود.\nبازکردن این منو به‌تنهایی به‌روزرسانی را شروع نمی‌کند.";
        $rows=[];
        if($active) $text.="\n\n<b>⏳ یک مدیر دیگر در حال به‌روزرسانی است.</b>";
        else {
            $rows[]=[['text'=>'🚀 شروع به‌روزرسانی','callback_data'=>'update:start','style'=>'success']];
            if($this->store->get('update_previous_version')!=='') $rows[]=[['text'=>'↩️ بازگشت به نسخهٔ قبلی','callback_data'=>'update:rollback','style'=>'danger']];
        }
        if($this->freshChanges()) $rows[]=[['text'=>'✨ تغییرات تازهٔ این ارتقا','callback_data'=>'update:changes','style'=>'primary']];
        $rows[]=[['text'=>'بازگشت به پنل مدیریت','callback_data'=>'update:back']];
        $this->view($chat,$user,$text,$rows,$message);
    }

    public function changes(int $chat, int $user, int $message): void
    {
        if(!in_array($user,$this->config->admins,true)||$chat!==$user) return;
        if($this->active()) return;
        $notes=array_slice($this->freshChanges(),0,12);
        $text=$notes?"<blockquote><b>✨ تغییرات تازهٔ این ارتقا</b>\n".implode("\n",array_map(fn($note)=>'• '.htmlspecialchars(mb_substr($note,0,240),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),$notes)).'</blockquote>':
            '<blockquote><b>✅ قابلیت تازه‌ای در این بررسی اضافه نشده است.</b></blockquote>';
        $this->view($chat,$user,$text,[[['text'=>'بازگشت به منوی به‌روزرسانی','callback_data'=>'update:menu','style'=>'primary']]],$message);
    }

    public function confirmRollback(int $chat, int $user, int $message): void
    {
        if(!in_array($user,$this->config->admins,true)||$chat!==$user) return;
        if($this->active()) return;
        if($this->store->get('update_previous_version')==='') { $this->menu($chat,$user,$message,'نسخهٔ قبلی برای بازگشت موجود نیست.'); return; }
        $version=htmlspecialchars($this->store->get('update_previous_version'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $this->view($chat,$user,"<blockquote><b>↩️ بازگشت به نسخهٔ قبلی</b></blockquote>\n\nنسخهٔ مقصد: <b>$version</b>\n\nکد و تصویر اجرایی به نسخهٔ قبلی برمی‌گردند.\n\n<blockquote><b>اطلاعات شما حفظ می‌شود</b>\nکاربران، تنظیمات و کوکی فعلی تغییر نمی‌کنند.</blockquote>\n\nبازگشت را تأیید می‌کنی؟",[
            [['text'=>'تأیید بازگشت به نسخهٔ قبلی','callback_data'=>'update:rollback:confirm','style'=>'danger']],
            [['text'=>'انصراف','callback_data'=>'update:menu']],
        ],$message);
    }

    public function request(int $chat, int $user, string $action, int $message): void
    {
        if(!in_array($user,$this->config->admins,true)||$chat!==$user||!in_array($action,['update','rollback'],true)) return;
        if($message<1||$message!==(int)$this->store->get('update_menu.'.$user,'0')||$this->active()) return;
        if(time()-(int)$this->store->get('updater_heartbeat','0')>60) {
            $this->menu($chat,$user,$message,'⚠️ سرویس به‌روزرسانی سرور در دسترس نیست؛ گزینهٔ ۱۱ منوی نصب را اجرا کن.');
            return;
        }
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $active=$this->store->query("SELECT 1 FROM update_requests WHERE status IN ('queued','running')")->fetchColumn();
            if(!$active) $this->store->query('INSERT INTO update_requests(user_id,chat_id,action,created) VALUES(?,?,?,?)',[$user,$chat,$action,time()]);
            $id=(int)$this->store->db->lastInsertId();
            $this->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
        if($active) return;
        $this->store->set('update_new_changes','');
        try {
            $message=$this->view($chat,$user,"<blockquote><b>🔄 در حال به‌روزرسانی</b></blockquote>\n\n<code>▱▱▱▱▱▱▱▱▱▱ ۰٪</code>\n\n⏳ درخواست ثبت شد؛ در انتظار شروع بررسی سرور…\n\nدرصد بر اساس مراحل انجام‌شده نمایش داده می‌شود.",
                [[['text'=>'⏳ در انتظار شروع · ۰٪','callback_data'=>'update:progress','style'=>'primary']]],$message);
            $this->store->query('UPDATE update_requests SET message_id=? WHERE id=?',[$message,$id]);
        } catch(\Throwable $e) {
            $this->store->query("UPDATE update_requests SET status='failed' WHERE id=?",[$id]);
            throw $e;
        }
    }
}
