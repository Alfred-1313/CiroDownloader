<?php
declare(strict_types=1);
namespace Zero;

/**
 * Messages the bot deletes itself after a delay (a video that must not stay in a chat). The rows live in the database, so
 * a restart in between does not keep a video in the chat: the next pass deletes whatever is due.
 */
final class TimedDeletes
{
    public const GIVE_UP_SECONDS=172800, MAX_TRIES=5;
    public static function schedule(Store $store,int $chat,int $message,int $at): void
    {
        if($message<1) return;
        $store->query('INSERT OR REPLACE INTO timed_deletes(chat_id,message_id,at,tries) VALUES(?,?,?,0)',[$chat,$message,$at]);
    }
    public static function due(Store $store,?int $now=null): bool
    {
        return (int)$store->query('SELECT COUNT(*) FROM timed_deletes WHERE at<=?',[$now??time()])->fetchColumn()>0;
    }
    /** Delete what is due. A message that is already gone counts as deleted; a transient failure is retried a few times. @return int messages deleted */
    public static function run(Store $store,Api $api,?int $now=null): int
    {
        $now??=time(); $done=0;
        $store->query('DELETE FROM timed_deletes WHERE at<?',[$now-self::GIVE_UP_SECONDS]);
        foreach($store->query('SELECT * FROM timed_deletes WHERE at<=? ORDER BY at LIMIT 20',[$now])->fetchAll() as $row) {
            try {
                $api->call('deleteMessage',['chat_id'=>(int)$row['chat_id'],'message_id'=>(int)$row['message_id']]);
                $store->query('DELETE FROM timed_deletes WHERE chat_id=? AND message_id=?',[$row['chat_id'],$row['message_id']]); $done++;
            } catch(\RuntimeException $e) {
                // 400/403/404: gone, too old or the bot was removed; nothing more can be done.
                if(in_array($e->getCode(),[400,403,404],true)||(int)$row['tries']+1>=self::MAX_TRIES) $store->query('DELETE FROM timed_deletes WHERE chat_id=? AND message_id=?',[$row['chat_id'],$row['message_id']]);
                else $store->query('UPDATE timed_deletes SET tries=tries+1,at=? WHERE chat_id=? AND message_id=?',[$now+5,$row['chat_id'],$row['message_id']]);
            }
        }
        return $done;
    }
}
