<?php
declare(strict_types=1);
namespace Zero;

/**
 * "Share" under a sent track: a switch_inline_query button. Anyone who sees the track can tap it, pick a chat and send
 * the same audio there through the bot's inline mode (the bot answers with the file Telegram already has, so nothing
 * is uploaded again). Each button carries a random token that names one stored file; the token is the only key.
 * Inline mode must be turned on for the bot in BotFather (/setinline); without it no share button is shown.
 */
final class Shares
{
    public const KEEP_DAYS=90, CHECK_SECONDS=600;
    public function __construct(private Bot $bot) {}

    private static function table(Store $store): void
    {
        $store->db->exec('CREATE TABLE IF NOT EXISTS shares (token TEXT PRIMARY KEY,file_id TEXT NOT NULL DEFAULT \'\',caption TEXT NOT NULL DEFAULT \'\',created INTEGER NOT NULL)');
    }
    /** Whether the bot answers inline queries (BotFather's /setinline), asked from Telegram at most every ten minutes. */
    public function enabled(): bool
    {
        $store=$this->bot->store;
        if(time()-(int)$store->get('bot_inline_checked','0')>=self::CHECK_SECONDS) {
            try {
                $me=$this->bot->api->call('getMe');
                $store->set('bot_inline',is_array($me)&&!empty($me['supports_inline_queries'])?'1':'0');
            } catch(\Throwable) { /* Keep the last answer when Telegram cannot be asked. */ }
            $store->set('bot_inline_checked',(string)time());
        }
        return $store->get('bot_inline','0')==='1';
    }
    /** A new token for a track about to be sent, or null when sharing is off. */
    public function reserve(): ?string
    {
        if(!$this->enabled()) return null;
        $store=$this->bot->store; self::table($store);
        $token=substr(strtr(base64_encode(random_bytes(12)),'+/','-_'),0,16);
        $store->query('INSERT INTO shares(token,created) VALUES(?,?)',[$token,time()]);
        if(random_int(1,50)===1) $store->query('DELETE FROM shares WHERE created<?',[time()-self::KEEP_DAYS*86400]);
        return $token;
    }
    /** The button row for a token. */
    public function row(string $token): array
    {
        $button=$this->bot->ui->button('music_share');
        $button['switch_inline_query']='s:'.$token;
        return [$button];
    }
    /** After sending: the file Telegram stored for the track, so the button can send it again anywhere. */
    public function fill(?string $token,mixed $result,string $caption): void
    {
        if($token===null||!is_array($result)) return;
        $file=(string)($result['audio']['file_id']??'');
        if($file==='') return;
        self::table($this->bot->store);
        $this->bot->store->query('UPDATE shares SET file_id=?,caption=? WHERE token=?',[$file,$caption,$token]);
    }
    /** An inline query "s:TOKEN" from anyone: the stored track, ready to send into the chat they picked. */
    public function answer(array $query): void
    {
        $results=[]; $text=(string)($query['query']??'');
        if($this->bot->store->enabled()&&preg_match('/^s:([A-Za-z0-9_-]{16})$/D',trim($text),$match)) {
            self::table($this->bot->store);
            $row=$this->bot->store->query('SELECT * FROM shares WHERE token=? AND created>=?',[$match[1],time()-self::KEEP_DAYS*86400])->fetch();
            if($row&&$row['file_id']!=='') $results[]=['type'=>'audio','id'=>$match[1],'audio_file_id'=>$row['file_id']]+($row['caption']!==''?['caption'=>$row['caption'],'parse_mode'=>'HTML']:[]);
        }
        try { $this->bot->api->call('answerInlineQuery',['inline_query_id'=>(string)$query['id'],'results'=>$results,'cache_time'=>$results?300:5,'is_personal'=>false]); }
        catch(\Throwable) { /* An old query cannot be answered any more. */ }
    }
}
