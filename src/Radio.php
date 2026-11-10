<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/RadioArtwork.php';
require_once __DIR__.'/RadioPlayback.php';

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
            duration INTEGER NOT NULL DEFAULT 0,source TEXT NOT NULL,status TEXT NOT NULL DEFAULT \'pending\',file TEXT NOT NULL DEFAULT \'\',added_by INTEGER NOT NULL DEFAULT 0,tries INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL,play_order INTEGER NOT NULL DEFAULT 0)');
        $store->db->exec('CREATE INDEX IF NOT EXISTS radio_queue_chat ON radio_queue(chat_id,status,id)');
        if(!in_array('cover',array_column($store->query('PRAGMA table_info(radio_queue)')->fetchAll(),'name'),true)) $store->db->exec('ALTER TABLE radio_queue ADD COLUMN cover TEXT NOT NULL DEFAULT \'\'');
        if(!in_array('play_order',array_column($store->query('PRAGMA table_info(radio_queue)')->fetchAll(),'name'),true)) {
            $store->db->exec('BEGIN IMMEDIATE');
            try {
                if(!in_array('play_order',array_column($store->query('PRAGMA table_info(radio_queue)')->fetchAll(),'name'),true)) {
                    $store->db->exec('ALTER TABLE radio_queue ADD COLUMN play_order INTEGER NOT NULL DEFAULT 0');
                    $store->db->exec('UPDATE radio_queue SET play_order=id');
                }
                $store->db->exec('COMMIT');
            } catch(\Throwable $e) { $store->db->exec('ROLLBACK'); throw $e; }
        }
        $store->db->exec('CREATE INDEX IF NOT EXISTS radio_queue_order ON radio_queue(chat_id,status,play_order,id)');
        $store->db->exec('CREATE TABLE IF NOT EXISTS radio_picks (token TEXT PRIMARY KEY,source TEXT NOT NULL,title TEXT NOT NULL DEFAULT \'\',artists TEXT NOT NULL DEFAULT \'\',duration INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL)');
        $store->db->exec("CREATE TABLE IF NOT EXISTS radio_panels (chat_id INTEGER NOT NULL,message_id INTEGER NOT NULL,station_id INTEGER NOT NULL,user_id INTEGER NOT NULL,thread_id INTEGER NOT NULL DEFAULT 0,kind TEXT NOT NULL DEFAULT 'text',photo_key TEXT NOT NULL DEFAULT '',view TEXT NOT NULL DEFAULT 'player',hash TEXT NOT NULL DEFAULT '',updated INTEGER NOT NULL DEFAULT 0,expires INTEGER NOT NULL,PRIMARY KEY(chat_id,message_id))");
        $panelColumns=array_column($store->query('PRAGMA table_info(radio_panels)')->fetchAll(),'name');
        if(!in_array('artwork_id',$panelColumns,true)||!in_array('artwork',$panelColumns,true)) {
            // The bot and radio may start together after an update. Recheck under the writer lock before migrating.
            $store->db->exec('BEGIN IMMEDIATE');
            try {
                $panelColumns=array_column($store->query('PRAGMA table_info(radio_panels)')->fetchAll(),'name');
                if(!in_array('artwork_id',$panelColumns,true)) $store->db->exec('ALTER TABLE radio_panels ADD COLUMN artwork_id INTEGER NOT NULL DEFAULT 0');
                if(!in_array('artwork',$panelColumns,true)) $store->db->exec("ALTER TABLE radio_panels ADD COLUMN artwork TEXT NOT NULL DEFAULT ''");
                $store->db->exec('COMMIT');
            } catch(\Throwable $e) { $store->db->exec('ROLLBACK'); throw $e; }
        }
        $store->db->exec('CREATE TABLE IF NOT EXISTS radio_seen (chat_id INTEGER PRIMARY KEY,title TEXT NOT NULL DEFAULT \'\',type TEXT NOT NULL DEFAULT \'\',seen INTEGER NOT NULL)');
        $store->db->exec("CREATE TABLE IF NOT EXISTS radio_sessions (chat_id INTEGER PRIMARY KEY,token TEXT NOT NULL,event TEXT NOT NULL DEFAULT '',event_id INTEGER NOT NULL DEFAULT 0,pending INTEGER NOT NULL DEFAULT 0,thread_id INTEGER NOT NULL DEFAULT 0,due INTEGER NOT NULL DEFAULT 0,tries INTEGER NOT NULL DEFAULT 0)");
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
        // The stream may have started before the address was given: then the radio is live at once (a start within the last 12 hours, no end since).
        $since=(int)$this->store->get('radio.live.'.$chat,'0');
        if($since>time()-43200) $this->store->query('UPDATE radio_stations SET live=1,live_since=? WHERE chat_id=? AND live=0',[$since,$chat]);
        if($since>time()-43200) $this->store->query('UPDATE radio_sessions SET pending=1,due=0,tries=0 WHERE chat_id=?',[$chat]);
    }
    /** Forget a station: its address, its queue and its prepared audio. */
    public function remove(int $chat): void
    {
        @unlink($this->keyFile($chat)); $this->clear($chat,true);
        $this->store->query('DELETE FROM radio_queue WHERE chat_id=?',[$chat]);
        $this->store->query('DELETE FROM radio_stations WHERE chat_id=?',[$chat]);
        $this->store->query('DELETE FROM radio_sessions WHERE chat_id=?',[$chat]);
        $this->store->query("UPDATE radio_panels SET station_id=0,view='static' WHERE station_id=?",[$chat]);
        foreach(['radio.progress.','radio.mode.','radio.paused.'] as $prefix) $this->store->query('DELETE FROM settings WHERE key=?',[$prefix.$chat]);
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
        // Remembered for chats that are not a radio yet: a radio set up while its stream is already running starts live.
        $this->store->set('radio.live.'.$chat,$live?(string)time():'0');
    }
    public function session(int $chat): string
    {
        return (string)($this->store->query('SELECT token FROM radio_sessions WHERE chat_id=?',[$chat])->fetchColumn()?:'0');
    }
    public function callback(string $op,int $chat): string { return 'rd:'.$op.':'.$chat.':'.$this->session($chat); }
    /** A real start/end rotates the controls, even when end and restart arrive within the same second. */
    public function streamEvent(int $chat,bool $live,string $event,int $messageId=0,int $thread=0): bool
    {
        if($chat>=0) return false;
        $files=[]; $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $old=$this->store->query('SELECT * FROM radio_sessions WHERE chat_id=?',[$chat])->fetch();
            if($old&&($old['event']===$event||($messageId>0&&$messageId<=(int)$old['event_id']))) { $this->store->db->exec('COMMIT'); return false; }
            $pending=$live&&$this->station($chat)!==null?1:0;
            $this->store->query('INSERT INTO radio_sessions(chat_id,token,event,event_id,pending,thread_id,due,tries) VALUES(?,?,?,?,?,?,0,0) ON CONFLICT(chat_id) DO UPDATE SET token=excluded.token,event=excluded.event,event_id=excluded.event_id,pending=excluded.pending,thread_id=excluded.thread_id,due=0,tries=0',
                [$chat,bin2hex(random_bytes(8)),$event,max($messageId,(int)($old['event_id']??0)),$pending,max(0,$thread)]);
            $this->setLive($chat,$live);
            $this->store->set('radio.paused.'.$chat,'0');
            $this->store->query('DELETE FROM settings WHERE key=?',['radio.progress.'.$chat]);
            $files=$this->store->query("SELECT file,cover FROM radio_queue WHERE chat_id=? AND status='playing'",[$chat])->fetchAll();
            $this->store->query("UPDATE radio_queue SET status='done',file='',cover='' WHERE chat_id=? AND status='playing'",[$chat]);
            $this->store->query("UPDATE radio_panels SET view='expired',updated=0 WHERE station_id=? AND view IN ('player','menu','static')",[$chat]);
            $this->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
        foreach($files as $file) foreach(['file','cover'] as $key) if($file[$key]!=='') @unlink($file[$key]);
        return true;
    }

    // ---- Who may use it: the administrators of the chat (and of the bot) -----------------------------------------------

    /** The bot administrators switch the whole radio off and on (default on). */
    public static function enabled(Store $store): bool { return $store->get('radio.enabled','1')==='1'; }
    /** New installations default to bot administrators; explicitly saved legacy chat-admin access remains supported. */
    public static function botOnly(Store $store): bool { return $store->get('radio.access','bot')==='bot'; }
    public static function allUsers(Store $store): bool { return $store->get('radio.access','bot')==='all'; }

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
    /** Station setup, live state and deletion always require an administrator. */
    public function mayManage(Api $api,int $chat,int $user,bool $botAdmin): bool
    {
        return self::enabled($this->store)&&$user>0&&($botAdmin||(!self::botOnly($this->store)&&self::chatAdmin($api,$this->store,$chat,$user)));
    }
    /** May this user queue, skip or stop the radio of the chat? Administrators of the bot always may. */
    public function mayControl(Api $api,int $chat,int $user,bool $botAdmin): bool
    {
        if(!self::enabled($this->store)||$this->station($chat)===null) return false;
        return $botAdmin||($user>0&&(self::allUsers($this->store)||(!self::botOnly($this->store)&&self::chatAdmin($api,$this->store,$chat,$user))));
    }
    /** @return list<array> the stations this user administers */
    public function stationsOf(Api $api,int $user,bool $botAdmin): array
    {
        if(!self::enabled($this->store)) return [];
        return array_values(array_filter($this->stations(),fn($row)=>$this->mayControl($api,(int)$row['chat_id'],$user,$botAdmin)));
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
     * @return array{id:int,position:int}|string the new place in the queue, or "full" / "long" / "invalid" / "queued" (already waiting or playing)
     */
    public function enqueue(int $chat,int $user,array $source,string $title,string $artists,int $duration): array|string
    {
        if(!in_array($source['service']??'',['spotify','soundcloud'],true)||(!isset($source['u'])&&!isset($source['m']))) return 'invalid';
        if($duration>self::MAX_SECONDS) return 'long';
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $open=(int)$this->store->query("SELECT COUNT(*) FROM radio_queue WHERE chat_id=? AND status IN ('pending','preparing','ready','playing')",[$chat])->fetchColumn();
            if($open>=self::MAX_QUEUE) { $this->store->db->exec('ROLLBACK'); return 'full'; }
            // One song once: while it waits or plays it cannot be queued again (after it was played it can).
            $encoded=json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if($this->store->query("SELECT 1 FROM radio_queue WHERE chat_id=? AND source=? AND status IN ('pending','preparing','ready','playing')",[$chat,$encoded])->fetchColumn()!==false) { $this->store->db->exec('ROLLBACK'); return 'queued'; }
            $this->store->query('INSERT INTO radio_queue(chat_id,title,artists,duration,source,added_by,created) VALUES(?,?,?,?,?,?,?)',
                [$chat,mb_substr($title,0,150),mb_substr($artists,0,150),max(0,$duration),json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$user,time()]);
            $id=(int)$this->store->db->lastInsertId();
            $this->store->query('UPDATE radio_queue SET play_order=? WHERE id=?',[$this->mode($chat)==='shuffle'?random_int(1,PHP_INT_MAX):$id,$id]);
            // Adding a song also resumes a station that was stopped.
            $this->store->query("UPDATE radio_stations SET command='' WHERE chat_id=? AND command='stop'",[$chat]);
            $this->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
        return ['id'=>$id,'position'=>$open+1];
    }
    /** @return list<array> the queue of a chat in play order: the playing song first */
    public function queue(int $chat,int $limit=20): array
    {
        return $this->store->query("SELECT * FROM radio_queue WHERE chat_id=? AND status IN ('playing','ready','preparing','pending') ORDER BY CASE status WHEN 'playing' THEN 0 ELSE 1 END,play_order,id LIMIT ?",[$chat,$limit])->fetchAll();
    }
    public function queued(int $chat): int
    {
        return (int)$this->store->query("SELECT COUNT(*) FROM radio_queue WHERE chat_id=? AND status IN ('pending','preparing','ready','playing')",[$chat])->fetchColumn();
    }
    /** A command for this chat's player: skip, previous, restart, or stop (the waiting queue stays). */
    public function command(int $chat,string $command): void
    {
        if(in_array($command,['skip','stop','previous','restart'],true)) $this->store->query('UPDATE radio_stations SET command=? WHERE chat_id=?',[$command,$chat]);
        if($command==='restart') $this->store->set('radio.paused.'.$chat,'0');
    }
    public function paused(int $chat): bool { return $this->store->get('radio.paused.'.$chat,'0')==='1'; }
    public function togglePause(int $chat): void
    {
        $station=$this->station($chat); $stopped=($station['command']??'')==='stop';
        $active=$this->queued($chat)>0||!empty($this->progress($chat)['id']);
        $pause=!$stopped&&!$this->paused($chat)&&$active;
        $this->store->set('radio.paused.'.$chat,$pause?'1':'0');
        if($stopped) $this->store->query("UPDATE radio_stations SET command='' WHERE chat_id=?",[$chat]);
    }
    public function mode(int $chat): string
    {
        $mode=$this->store->get('radio.mode.'.$chat,'off'); return in_array($mode,['off','all','one','shuffle'],true)?$mode:'off';
    }
    public function setMode(int $chat,string $mode): string
    {
        if(!in_array($mode,['off','all','one','shuffle'],true)) return $this->mode($chat);
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $old=$this->mode($chat);
            if($mode==='shuffle'&&$old!=='shuffle') {
                foreach($this->store->query("SELECT id FROM radio_queue WHERE chat_id=? AND status IN ('pending','preparing','ready')",[$chat])->fetchAll(\PDO::FETCH_COLUMN) as $id) $this->store->query('UPDATE radio_queue SET play_order=? WHERE id=?',[random_int(1,PHP_INT_MAX),$id]);
            } elseif($mode!=='shuffle') $this->store->query('UPDATE radio_queue SET play_order=id WHERE chat_id=?',[$chat]);
            $this->store->set('radio.mode.'.$chat,$mode); $this->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
        return $mode;
    }
    public function cycleMode(int $chat): string
    {
        $next=match($this->mode($chat)) {'off'=>'all','all'=>'one','one'=>'shuffle',default=>'off'};
        return $this->setMode($chat,$next);
    }
    public function progress(int $chat): array
    {
        $data=json_decode($this->store->get('radio.progress.'.$chat,'{}'),true);
        return is_array($data)?$data:[];
    }
    public function previous(int $chat,int $user): void
    {
        if($this->queued($chat)>0) { $this->command($chat,'previous'); return; }
        $last=$this->store->query("SELECT * FROM radio_queue WHERE chat_id=? AND status='done' ORDER BY id DESC LIMIT 1",[$chat])->fetch();
        if($last) $this->enqueue($chat,$user,json_decode($last['source'],true),$last['title'],$last['artists'],(int)$last['duration']);
    }
    /** Empty the queue except the song playing now ($all also removes that one). */
    public function clear(int $chat,bool $all=false): void
    {
        $rows=$this->store->query("SELECT id,file FROM radio_queue WHERE chat_id=? AND status IN ('pending','preparing','ready'".($all?",'playing'":'').')',[$chat])->fetchAll();
        foreach($rows as $row) { if($row['file']!=='') @unlink($row['file']); @unlink($this->root().'/files/'.$row['id'].'.jpg'); $this->store->query('DELETE FROM radio_queue WHERE id=?',[$row['id']]); }
    }
    /** Old finished rows and files go away; audio left behind by a crash too. */
    public function tidy(): void
    {
        $this->store->query('DELETE FROM radio_sessions WHERE chat_id NOT IN (SELECT chat_id FROM radio_stations) AND chat_id NOT IN (SELECT chat_id FROM radio_seen)');
        $this->store->query('DELETE FROM radio_panels WHERE expires<?',[time()]);
        $rows=$this->store->query("SELECT id,file FROM radio_queue WHERE status IN ('done','failed') AND created<?",[time()-86400])->fetchAll();
        foreach($rows as $row) { if($row['file']!=='') @unlink($row['file']); $this->store->query('DELETE FROM radio_queue WHERE id=?',[$row['id']]); }
        $this->store->query('DELETE FROM radio_picks WHERE created<?',[time()-self::KEEP_PICKS_DAYS*86400]);
        $rows=$this->store->query("SELECT file,cover FROM radio_queue WHERE file<>''")->fetchAll();
        $keep=array_merge(array_column($rows,'file'),array_column($rows,'cover'));
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
