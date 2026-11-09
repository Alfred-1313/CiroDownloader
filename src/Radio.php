<?php
declare(strict_types=1);
namespace Zero;

/**
 * The radio of a group or channel: songs are queued and one service (bin/radio.php) plays them, one after another with no
 * gap, into the chat's live stream over RTMP. Telegram lets a bot neither start nor join a live stream, so a person
 * starts it in the app ("stream with another app") and the chat's stream address is given to the bot once, privately.
 * A start/end service message (VideoChats) tells the bot when the stream is live.
 *
 * Data (SQLite): radio_stations (one row per chat), radio_queue (songs: pending, ready, playing, done, failed),
 * radio_picks (what a play button under a sent song stands for) and radio_seen (chats where a stream was seen).
 * The stream address and key are the only secret: a file with mode 0600 under storage/radio/keys, never in the database,
 * a log or a backup. Prepared audio waits under storage/radio/files (not in backups either).
 */
final class Radio
{
    public const MAX_QUEUE=100, MAX_SECONDS=1200, IDLE_SECONDS=60, KEEP_PICKS_DAYS=30;
    public function __construct(private Config $config,private Store $store)
    {
        $store->db->exec('CREATE TABLE IF NOT EXISTS radio_stations (chat_id INTEGER PRIMARY KEY,title TEXT NOT NULL DEFAULT \'\',type TEXT NOT NULL DEFAULT \'\',owner INTEGER NOT NULL DEFAULT 0,
            live INTEGER NOT NULL DEFAULT 0,live_since INTEGER NOT NULL DEFAULT 0,command TEXT NOT NULL DEFAULT \'\',now TEXT NOT NULL DEFAULT \'\',created INTEGER NOT NULL)');
        $store->db->exec('CREATE TABLE IF NOT EXISTS radio_queue (id INTEGER PRIMARY KEY AUTOINCREMENT,chat_id INTEGER NOT NULL,title TEXT NOT NULL DEFAULT \'\',artists TEXT NOT NULL DEFAULT \'\',
            duration INTEGER NOT NULL DEFAULT 0,source TEXT NOT NULL,status TEXT NOT NULL DEFAULT \'pending\',file TEXT NOT NULL DEFAULT \'\',added_by INTEGER NOT NULL DEFAULT 0,tries INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL)');
        $store->db->exec('CREATE INDEX IF NOT EXISTS radio_queue_chat ON radio_queue(chat_id,status,id)');
        $store->db->exec('CREATE TABLE IF NOT EXISTS radio_picks (token TEXT PRIMARY KEY,source TEXT NOT NULL,title TEXT NOT NULL DEFAULT \'\',artists TEXT NOT NULL DEFAULT \'\',duration INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL)');
        $store->db->exec('CREATE TABLE IF NOT EXISTS radio_seen (chat_id INTEGER PRIMARY KEY,title TEXT NOT NULL DEFAULT \'\',type TEXT NOT NULL DEFAULT \'\',seen INTEGER NOT NULL)');
    }

    // ---- The stream address: one file per chat -------------------------------------------------------------------

    /** "rtmps://dc4-1.rtmp.t.me/s/ID:KEY" from what the person pasted (address and key may be on two lines), or null. */
    public static function parseTarget(string $text): ?string
    {
        $clean=preg_replace('/\s+/u','',trim($text));
        if($clean===null||strlen($clean)>200) return null;
        return preg_match('~^rtmps?://[a-z0-9-]+\.rtmp\.t\.me(?::\d{2,5})?/s/\d{5,15}:[A-Za-z0-9_-]{10,64}$~D',$clean)===1?$clean:null;
    }
    public function root(): string { return $this->config->storage.'/radio'; }
    private function keyFile(int $chat): string { return $this->root().'/keys/'.$chat; }
    private function ensure(string $folder): void { if(!is_dir($folder)&&!@mkdir($folder,0700,true)&&!is_dir($folder)) throw new \RuntimeException('Cannot create the radio folder'); }
    /** The stream address of a chat, or null. */
    public function target(int $chat): ?string
    {
        $text=@file_get_contents($this->keyFile($chat));
        return is_string($text)?self::parseTarget($text):null;
    }
    /** Save a chat's stream address (replacing the old one). The chat becomes a station. */
    public function save(int $chat,string $title,string $type,int $owner,string $target): void
    {
        if(self::parseTarget($target)===null) throw new \InvalidArgumentException('Invalid stream address');
        $this->ensure($this->root().'/keys'); $file=$this->keyFile($chat);
        $old=umask(0077); $written=file_put_contents($file,$target,LOCK_EX); umask($old);
        if($written===false) throw new \RuntimeException('Cannot save the stream address');
        @chmod($file,0600);
        $this->store->query('INSERT INTO radio_stations(chat_id,title,type,owner,created) VALUES(?,?,?,?,?) ON CONFLICT(chat_id) DO UPDATE SET title=excluded.title,type=excluded.type,owner=excluded.owner',
            [$chat,mb_substr($title,0,100),$type==='channel'?'channel':'group',$owner,time()]);
    }
    /** Forget a station: its address, its queue and its prepared audio. */
    public function remove(int $chat): void
    {
        @unlink($this->keyFile($chat)); $this->clear($chat,true);
        $this->store->query('DELETE FROM radio_queue WHERE chat_id=?',[$chat]);
        $this->store->query('DELETE FROM radio_stations WHERE chat_id=?',[$chat]);
    }

    // ---- Stations ----------------------------------------------------------------------------------------------------

    public function station(int $chat): ?array
    {
        $row=$this->store->query('SELECT * FROM radio_stations WHERE chat_id=?',[$chat])->fetch();
        return $row&&$this->target($chat)!==null?$row:null;
    }
    /** @return list<array> every station that still has its address */
    public function stations(): array
    {
        return array_values(array_filter($this->store->query('SELECT * FROM radio_stations ORDER BY created')->fetchAll(),fn($row)=>$this->target((int)$row['chat_id'])!==null));
    }
    /** A chat where a live stream was seen starting, so its administrators can offer to set it up. */
    public function seen(int $chat,string $title,string $type): void
    {
        $this->store->query('INSERT INTO radio_seen(chat_id,title,type,seen) VALUES(?,?,?,?) ON CONFLICT(chat_id) DO UPDATE SET title=excluded.title,type=excluded.type,seen=excluded.seen',[$chat,mb_substr($title,0,100),$type==='channel'?'channel':'group',time()]);
        $this->store->query('DELETE FROM radio_seen WHERE seen<?',[time()-30*86400]);
    }
    /** @return list<array> chats where a stream was seen lately and that are not stations yet, newest first */
    public function candidates(): array
    {
        return array_values(array_filter($this->store->query('SELECT * FROM radio_seen ORDER BY seen DESC LIMIT 20')->fetchAll(),fn($row)=>$this->station((int)$row['chat_id'])===null));
    }
    /** The stream of this chat started (true) or ended (false). Only a station cares. */
    public function setLive(int $chat,bool $live): void
    {
        $this->store->query('UPDATE radio_stations SET live=?,live_since=?,command=\'\',now=\'\' WHERE chat_id=?',[$live?1:0,$live?time():0,$chat]);
    }

    // ---- Who may use it: the administrators of the chat (and of the bot) -----------------------------------------------

    /** The bot administrators switch the whole radio off and on (default on). */
    public static function enabled(Store $store): bool { return $store->get('radio.enabled','1')==='1'; }
    /** Whether only the bot's administrators may use it, instead of the administrators of each group or channel (default). */
    public static function botOnly(Store $store): bool { return $store->get('radio.access','chat')==='bot'; }

    /** Whether the user is a creator or administrator of the chat; Telegram is asked at most every two minutes per pair. */
    public static function chatAdmin(Api $api,Store $store,int $chat,int $user): bool
    {
        $key='radio.admin.'.$chat.'.'.$user; $cached=json_decode($store->get($key,'{}'),true);
        if(is_array($cached)&&isset($cached['t'])&&time()-(int)$cached['t']<120) return !empty($cached['a']);
        try {
            $member=$api->call('getChatMember',['chat_id'=>$chat,'user_id'=>$user]);
            $admin=is_array($member)&&in_array($member['status']??'',['creator','administrator'],true);
        } catch(\Throwable) { $admin=false; }
        $store->set($key,json_encode(['t'=>time(),'a'=>$admin]));
        return $admin;
    }
    /** May this user queue, skip or stop the radio of the chat? Administrators of the bot always may. */
    public function mayControl(Api $api,int $chat,int $user,bool $botAdmin): bool
    {
        if(!self::enabled($this->store)||$this->station($chat)===null) return false;
        return $botAdmin||(!self::botOnly($this->store)&&self::chatAdmin($api,$this->store,$chat,$user));
    }
    /** @return list<array> the stations this user administers */
    public function stationsOf(Api $api,int $user,bool $botAdmin): array
    {
        if(!self::enabled($this->store)) return [];
        return array_values(array_filter($this->stations(),fn($row)=>$botAdmin||(!self::botOnly($this->store)&&self::chatAdmin($api,$this->store,(int)$row['chat_id'],$user))));
    }
    /** The station a play button pressed in a private chat goes to: the one the user chose, else the first they administer. */
    public function activeFor(Api $api,int $user,bool $botAdmin): ?array
    {
        $mine=$this->stationsOf($api,$user,$botAdmin); if(!$mine) return null;
        $chosen=(int)$this->store->get('radio.active.'.$user,'0');
        foreach($mine as $row) if((int)$row['chat_id']===$chosen) return $row;
        return $mine[0];
    }
    public function choose(int $user,int $chat): void { $this->store->set('radio.active.'.$user,(string)$chat); }

    // ---- The queue -----------------------------------------------------------------------------------------------------

    /**
     * Add a song. $source says how to fetch it ({"service":"spotify|soundcloud","u":ADDRESS} or {"service":..,"m":DETAILS}).
     * @return array{id:int,position:int}|string the new place in the queue, or "full" / "long" / "invalid"
     */
    public function enqueue(int $chat,int $user,array $source,string $title,string $artists,int $duration): array|string
    {
        if(!in_array($source['service']??'',['spotify','soundcloud'],true)||(!isset($source['u'])&&!isset($source['m']))) return 'invalid';
        if($duration>self::MAX_SECONDS) return 'long';
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $open=(int)$this->store->query("SELECT COUNT(*) FROM radio_queue WHERE chat_id=? AND status IN ('pending','preparing','ready','playing')",[$chat])->fetchColumn();
            if($open>=self::MAX_QUEUE) { $this->store->db->exec('ROLLBACK'); return 'full'; }
            $this->store->query('INSERT INTO radio_queue(chat_id,title,artists,duration,source,added_by,created) VALUES(?,?,?,?,?,?,?)',
                [$chat,mb_substr($title,0,150),mb_substr($artists,0,150),max(0,$duration),json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$user,time()]);
            $id=(int)$this->store->db->lastInsertId();
            // Adding a song also resumes a station that was stopped.
            $this->store->query("UPDATE radio_stations SET command='' WHERE chat_id=? AND command='stop'",[$chat]);
            $this->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
        return ['id'=>$id,'position'=>$open+1];
    }
    /** @return list<array> the queue of a chat in play order: the playing song first */
    public function queue(int $chat,int $limit=20): array
    {
        return $this->store->query("SELECT * FROM radio_queue WHERE chat_id=? AND status IN ('playing','ready','preparing','pending') ORDER BY CASE status WHEN 'playing' THEN 0 ELSE 1 END,id LIMIT ?",[$chat,$limit])->fetchAll();
    }
    public function queued(int $chat): int
    {
        return (int)$this->store->query("SELECT COUNT(*) FROM radio_queue WHERE chat_id=? AND status IN ('pending','preparing','ready','playing')",[$chat])->fetchColumn();
    }
    /** A command for the player of this chat: "skip" (the song playing) or "stop" (end the session; the queue stays). */
    public function command(int $chat,string $command): void
    {
        if(in_array($command,['skip','stop'],true)) $this->store->query('UPDATE radio_stations SET command=? WHERE chat_id=?',[$command,$chat]);
    }
    /** Empty the queue except the song playing now ($all also removes that one). */
    public function clear(int $chat,bool $all=false): void
    {
        $rows=$this->store->query("SELECT id,file FROM radio_queue WHERE chat_id=? AND status IN ('pending','preparing','ready'".($all?",'playing'":'').')',[$chat])->fetchAll();
        foreach($rows as $row) { if($row['file']!=='') @unlink($row['file']); $this->store->query('DELETE FROM radio_queue WHERE id=?',[$row['id']]); }
    }
    /** Old finished rows and files go away; audio left behind by a crash too. */
    public function tidy(): void
    {
        $rows=$this->store->query("SELECT id,file FROM radio_queue WHERE status IN ('done','failed') AND created<?",[time()-86400])->fetchAll();
        foreach($rows as $row) { if($row['file']!=='') @unlink($row['file']); $this->store->query('DELETE FROM radio_queue WHERE id=?',[$row['id']]); }
        $this->store->query('DELETE FROM radio_picks WHERE created<?',[time()-self::KEEP_PICKS_DAYS*86400]);
        $keep=array_column($this->store->query("SELECT file FROM radio_queue WHERE file<>''")->fetchAll(),'file');
        foreach(glob($this->root().'/files/*')?:[] as $file) if(!in_array($file,$keep,true)&&filemtime($file)<time()-3600) @unlink($file);
    }

    // ---- Play buttons: what a button under a sent song stands for ---------------------------------------------------

    /** A token for a song just sent, to put in the play button's callback data. */
    public function pick(array $source,string $title,string $artists,int $duration): string
    {
        $token=substr(strtr(base64_encode(random_bytes(12)),'+/','-_'),0,16);
        $this->store->query('INSERT INTO radio_picks(token,source,title,artists,duration,created) VALUES(?,?,?,?,?,?)',
            [$token,json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),mb_substr($title,0,150),mb_substr($artists,0,150),max(0,$duration),time()]);
        return $token;
    }
    public function picked(string $token): ?array
    {
        if(preg_match('/^[A-Za-z0-9_-]{16}$/D',$token)!==1) return null;
        $row=$this->store->query('SELECT * FROM radio_picks WHERE token=?',[$token])->fetch();
        if(!$row) return null;
        $source=json_decode((string)$row['source'],true);
        return is_array($source)?['source'=>$source,'title'=>(string)$row['title'],'artists'=>(string)$row['artists'],'duration'=>(int)$row['duration']]:null;
    }
}
