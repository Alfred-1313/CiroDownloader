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

    public function confirmRollback(int $chat): void
    {
        if($this->store->get('update_previous_version')==='') {
            $this->api->call('sendMessage',['chat_id'=>$chat,'text'=>'هنوز نسخهٔ قبلی برای بازگشت ثبت نشده است.']);
            return;
        }
        $version=htmlspecialchars($this->store->get('update_previous_version'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $this->api->call('sendMessage',['chat_id'=>$chat,'parse_mode'=>'HTML',
            'text'=>"<blockquote><b>↩️ بازگشت به نسخهٔ قبلی</b></blockquote>\n\nنسخهٔ مقصد: <b>$version</b>\n\nکد و تصویر اجرایی به نسخهٔ قبلی برمی‌گردند.\n\n<blockquote><b>اطلاعات شما حفظ می‌شود</b>\nکاربران، تنظیمات و کوکی فعلی تغییر نمی‌کنند.</blockquote>\n\nبازگشت را تأیید می‌کنی؟",
            'reply_markup'=>['inline_keyboard'=>[
                [['text'=>'تأیید بازگشت به نسخهٔ قبلی','callback_data'=>'update:rollback:confirm','style'=>'danger']],
                [['text'=>'انصراف','callback_data'=>'admin']],
            ]]]);
    }

    public function request(int $chat, int $user, string $action): void
    {
        if(!in_array($user,$this->config->admins,true)||$chat!==$user||!in_array($action,['update','rollback'],true)) return;
        if(time()-(int)$this->store->get('updater_heartbeat','0')>60) {
            $this->api->call('sendMessage',['chat_id'=>$chat,'text'=>"سرویس به‌روزرسانی سرور در دسترس نیست.\nدر منوی نصب، گزینهٔ ۱۱ (Enable Telegram updates) را اجرا کن و دوباره /update را بزن."]);
            return;
        }
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $active=$this->store->query("SELECT 1 FROM update_requests WHERE status IN ('queued','running')")->fetchColumn();
            if(!$active) $this->store->query('INSERT INTO update_requests(user_id,chat_id,action,created) VALUES(?,?,?,?)',[$user,$chat,$action,time()]);
            $id=(int)$this->store->db->lastInsertId();
            $this->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
        if($active) {
            $this->api->call('sendMessage',['chat_id'=>$chat,'text'=>'یک عملیات به‌روزرسانی در حال اجراست؛ نتیجه در همان پیام اعلام می‌شود.']);
            return;
        }
        try {
            $result=$this->api->call('sendMessage',['chat_id'=>$chat,'parse_mode'=>'HTML',
                'text'=>"<blockquote><b>🔄 به‌روزرسانی سیرو دانلودر</b></blockquote>\n\nنسخهٔ فعلی: <b>".htmlspecialchars(self::version())."</b>\n\n<blockquote><b>⏳ درخواست ثبت شد</b>\nدر انتظار بررسی آخرین نسخه در سرور…</blockquote>\n\nپیشرفت و نتیجه در همین پیام نمایش داده می‌شود."]);
            if(!is_array($result)||empty($result['message_id'])) throw new \RuntimeException('Missing update message ID');
            $this->store->query('UPDATE update_requests SET message_id=? WHERE id=?',[(int)$result['message_id'],$id]);
        } catch(\Throwable $e) {
            $this->store->query("UPDATE update_requests SET status='failed' WHERE id=?",[$id]);
            throw $e;
        }
    }
}
