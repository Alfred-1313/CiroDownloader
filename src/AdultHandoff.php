<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Bot.php';

/** Private, owner-bound handoffs to a separately configured Telegram bot. */
final class AdultHandoff
{
    public const DEFAULT_TTL=120;
    private \PDO $db;
    private string $dir;
    public function __construct(private Config $config,private Store $store)
    {
        $this->dir=$config->storage.'/adult-handoff';
        if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true)&&!is_dir($this->dir)) throw new \RuntimeException('Cannot create handoff storage');
        if(is_link($this->dir)) throw new \RuntimeException('Unsafe handoff storage');
        $this->db=new \PDO('sqlite:'.$this->dir.'/handoff.sqlite',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
        $this->db->exec("CREATE TABLE IF NOT EXISTS items (token TEXT PRIMARY KEY, owner INTEGER NOT NULL, site TEXT NOT NULL, bot_id INTEGER NOT NULL, path TEXT NOT NULL, caption TEXT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, duration INTEGER NOT NULL, spoiler INTEGER NOT NULL, delay INTEGER NOT NULL, thumbnail INTEGER NOT NULL DEFAULT 0, prepare_ms INTEGER NOT NULL DEFAULT 0, expires INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'ready', link_chat INTEGER NOT NULL DEFAULT 0, link_message INTEGER NOT NULL DEFAULT 0, link_kind TEXT NOT NULL DEFAULT 'text', notified INTEGER NOT NULL DEFAULT 0, notice_at INTEGER NOT NULL DEFAULT 0); CREATE TABLE IF NOT EXISTS deletes (bot_id INTEGER NOT NULL, chat INTEGER NOT NULL, message INTEGER NOT NULL, at INTEGER NOT NULL, tries INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(bot_id,chat,message));");
    }
    private function query(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function configurationLock(): mixed
    {
        $path=$this->dir.'/config.lock';
        if(is_link($path)||!($lock=fopen($path,'c'))||!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock companion configuration');
        return $lock;
    }
    public function enabled(): bool { return $this->store->get('adult.handoff.enabled','0')==='1'; }
    public function ttl(): int { return max(10,min(86400,(int)$this->store->get('adult.handoff.ttl',(string)self::DEFAULT_TTL))); }
    public function token(): string
    {
        $path=$this->dir.'/token';
        if(is_link($path)||!is_file($path)) return '';
        $token=trim((string)file_get_contents($path));
        return preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D',$token)?$token:'';
    }
    public function api(?string $token=null): Telegram
    {
        $cfg=clone $this->config; $cfg->token=$token??$this->token();
        if($cfg->token==='') throw new \RuntimeException('Companion bot is not configured');
        return new Telegram($cfg);
    }
    public function configure(string $token,?Api $api=null): string
    {
        if(!preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D',$token)||hash_equals($this->config->token,$token)) throw new \RuntimeException('Invalid companion bot token');
        $id=(int)strstr($token,':',true);
        if($id===(int)strstr($this->config->token,':',true)) throw new \RuntimeException('Main bot cannot be the companion');
        $api??=$this->api($token); $me=$api->call('getMe'); $webhook=$api->call('getWebhookInfo');
        $username=(string)($me['username']??'');
        if(empty($me['is_bot'])||(int)($me['id']??0)!==$id||!preg_match('/^[A-Za-z0-9_]{5,32}$/D',$username)||!empty($webhook['url'])) throw new \RuntimeException('Companion bot validation failed');
        $lock=$this->configurationLock();
        try {
            $old=$this->token(); $changedBot=$old!==''&&(int)strstr($old,':',true)!==$id;
            if($changedBot&&((int)$this->query("SELECT COUNT(*) FROM items WHERE status IN ('ready','queued','sending')")->fetchColumn()>0||(int)$this->query('SELECT COUNT(*) FROM deletes')->fetchColumn()>0)) throw new \RuntimeException('Pending handoffs exist');
            $tmp=$this->dir.'/token.'.bin2hex(random_bytes(6));
            try { if(file_put_contents($tmp,$token,LOCK_EX)!==strlen($token)||!chmod($tmp,0600)||!rename($tmp,$this->dir.'/token')) throw new \RuntimeException('Cannot save companion token'); }
            finally { if(is_file($tmp)) unlink($tmp); }
            $this->store->set('adult.handoff.username',$username);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
        return $username;
    }
    public function prepare(array $job,array $media,string $site,string $caption,int $delay,bool $spoiler,int $prepareMs=0): string
    {
        $lock=$this->configurationLock();
        try {
            $token=$this->token(); $username=$this->store->get('adult.handoff.username');
            if(!$this->enabled()||$token===''||!preg_match('/^[A-Za-z0-9_]{5,32}$/D',$username)) throw new \RuntimeException('Companion bot is unavailable');
            $key=bin2hex(random_bytes(16)); $path=$this->dir.'/'.$key.'.mp4';
            if(!rename($media['path'],$path)) throw new \RuntimeException('Cannot store handoff video');
            chmod($path,0600);
            $thumb=$this->dir.'/'.$key.'.jpg'; $hasThumb=false;
            try {
                if(!empty($media['thumbnail'])) { if(!rename($media['thumbnail'],$thumb)) throw new \RuntimeException('Cannot store handoff thumbnail'); chmod($thumb,0600); $hasThumb=true; }
                $this->query('INSERT INTO items(token,owner,site,bot_id,path,caption,width,height,duration,spoiler,delay,thumbnail,prepare_ms,expires,link_chat) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$key,(int)$job['user_id'],$site,(int)strstr($token,':',true),$key.'.mp4',$caption,(int)$media['width'],(int)$media['height'],(int)$media['duration'],(int)$spoiler,$delay,(int)$hasThumb,$prepareMs,time()+max(600,$this->ttl()),(int)$job['chat_id']]);
            } catch(\Throwable $e) { @unlink($path); @unlink($thumb); throw $e; }
            return 'https://t.me/'.$username.'?start=v_'.$key;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public function cancel(string $url): void
    {
        if(!preg_match('/[?]start=v_([a-f0-9]{32})$/D',$url,$m)) return;
        if($this->query("DELETE FROM items WHERE token=? AND status='ready'",[$m[1]])->rowCount()) { @unlink($this->dir.'/'.$m[1].'.mp4'); @unlink($this->dir.'/'.$m[1].'.jpg'); }
    }
    public function linkMessage(string $url,int $chat,int $message,string $kind): void
    {
        if($message<1||!preg_match('/[?]start=v_([a-f0-9]{32})$/D',$url,$m)
            ||!$this->query('UPDATE items SET link_chat=?,link_message=?,link_kind=?,expires=?,notified=0,notice_at=0 WHERE token=?',[$chat,$message,$kind==='photo'?'photo':'text',time()+$this->ttl(),$m[1]])->rowCount()) throw new \RuntimeException('Handoff link could not be registered');
    }
    public function requestSite(int $user,string $text): string
    {
        if(!preg_match('/^\/start(?:@[A-Za-z0-9_]+)?\s+v_([a-f0-9]{32})$/D',trim($text),$m)) return '';
        return (string)($this->query('SELECT site FROM items WHERE token=? AND owner=?',[$m[1],$user])->fetchColumn()?:'');
    }
    private function authorized(array $row): bool
    {
        $bot=new Bot($this->config,$this->store,new Telegram($this->config));
        $user=(int)$row['owner']; $chat=(int)$row['link_chat'];
        return $bot->allowed($user,$chat)&&$bot->adultAllowed($user,$chat,(string)$row['site']);
    }
    public function request(int $user,int $chat,string $text): string
    {
        if($chat!==$user||!preg_match('/^\/start(?:@[A-Za-z0-9_]+)?\s+v_([a-f0-9]{32})$/D',trim($text),$m)) return 'invalid';
        $row=$this->query('SELECT * FROM items WHERE token=?',[$m[1]])->fetch();
        if(!$row) return 'expired';
        if((int)$row['owner']!==$user) return 'owner';
        if((int)$row['bot_id']!==(int)strstr($this->token(),':',true)) return 'expired';
        if(!$this->authorized($row)) return 'expired';
        if(in_array($row['status'],['queued','sending'],true)) return 'queued';
        if($row['status']!=='ready'||(int)$row['expires']<time()) return 'expired';
        return $this->query("UPDATE items SET status='queued' WHERE token=? AND status='ready' AND expires>?",[$m[1],time()])->rowCount()?'queued':'expired';
    }
    public function claim(): ?array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row=$this->query("SELECT * FROM items WHERE status='queued' ORDER BY rowid LIMIT 1")->fetch();
            if($row) $this->query("UPDATE items SET status='sending' WHERE token=? AND status='queued'",[$row['token']]);
            $this->db->exec('COMMIT'); return $row?:null;
        } catch(\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function recoverInterrupted(): void
    {
        $this->query("UPDATE items SET status='failed' WHERE status='sending'");
    }
    public function failInterrupted(string $token): void
    {
        $this->query("UPDATE items SET status='failed' WHERE token=? AND status='sending'",[$token]);
    }
    public function unclaim(string $token): void
    {
        $this->query("UPDATE items SET status='queued' WHERE token=? AND status='sending'",[$token]);
    }
    public function deliver(array $row,Api $api): void
    {
        if(!$this->authorized($row)) throw new \RuntimeException('Handoff access is no longer available');
        $file=$this->dir.'/'.$row['token'].'.mp4';
        if(!preg_match('/^[a-f0-9]{32}$/D',$row['token'])||$row['path']!==$row['token'].'.mp4'||is_link($file)||!is_file($file)) throw new \RuntimeException('Handoff file unavailable');
        $params=['chat_id'=>(int)$row['owner'],'video'=>new \CURLFile($file,'video/mp4','video.mp4'),'caption'=>$row['caption'],'parse_mode'=>'HTML','duration'=>(int)$row['duration'],'width'=>(int)$row['width'],'height'=>(int)$row['height'],'supports_streaming'=>true];
        $thumb=$this->dir.'/'.$row['token'].'.jpg';
        if((int)$row['thumbnail']&&is_file($thumb)&&!is_link($thumb)) {
            $params['thumbnail']=new \CURLFile($thumb,'image/jpeg','thumbnail.jpg');
            if(in_array($row['site'],['xvideos','tikporn','youporn','eporner'],true)) $params['cover']=new \CURLFile($thumb,'image/jpeg','cover.jpg');
        }
        if((int)$row['spoiler']) $params['has_spoiler']=true;
        $bytes=(int)filesize($file); $started=hrtime(true);
        $sent=$api->call('sendVideo',$params);
        if(!is_array($sent)||empty($sent['message_id'])) throw new \RuntimeException('Unconfirmed handoff delivery');
        if((int)$row['delay']>0) $this->query('INSERT OR REPLACE INTO deletes(bot_id,chat,message,at,tries) VALUES(?,?,?,?,0)',[(int)$row['bot_id'],(int)$row['owner'],(int)$sent['message_id'],time()+(int)$row['delay']]);
        if(preg_match('/[^\s\p{Z}\p{C}]/u',(string)($sent['caption']??''))!==1) {
            try { $api->call('editMessageCaption',['chat_id'=>(int)$row['owner'],'message_id'=>(int)$sent['message_id'],'caption'=>$row['caption'],'parse_mode'=>'HTML']); }
            catch(\Throwable) { /* The confirmed video is never sent again for a caption error. */ }
        }
        $this->query("UPDATE items SET status='sent',caption='' WHERE token=? AND status='sending'",[$row['token']]);
        @unlink($file);
        @unlink($thumb);
        try { $this->store->pushSpeed($row['site'],$bytes,(int)$row['prepare_ms'],(int)round((hrtime(true)-$started)/1e6)); } catch(\Throwable) {}
    }
    public function deleteDue(Api $api): void
    {
        $token=$this->token(); $id=$token!==''?(int)strstr($token,':',true):0;
        foreach($this->query('SELECT * FROM deletes WHERE bot_id=? AND at<=? ORDER BY at LIMIT 20',[$id,time()])->fetchAll() as $row) {
            try { $api->call('deleteMessage',['chat_id'=>(int)$row['chat'],'message_id'=>(int)$row['message']]); $remove=true; }
            catch(\RuntimeException $e) { $remove=in_array($e->getCode(),[400,403,404],true)||(int)$row['tries']>=4; }
            if($remove) $this->query('DELETE FROM deletes WHERE bot_id=? AND chat=? AND message=?',[$id,$row['chat'],$row['message']]);
            else $this->query('UPDATE deletes SET tries=tries+1,at=? WHERE bot_id=? AND chat=? AND message=?',[time()+5,$id,$row['chat'],$row['message']]);
        }
    }
    public function sweepDue(): bool
    {
        $now=time();
        return (int)$this->query("SELECT COUNT(*) FROM items WHERE expires<? AND ((notified=0 AND notice_at<=?) OR (notified=1 AND status NOT IN ('queued','sending')))",[$now,$now])->fetchColumn()>0
            ||(int)$this->query('SELECT COUNT(*) FROM deletes WHERE at<=?',[$now])->fetchColumn()>0;
    }
    public function purge(Api $main): void
    {
        foreach($this->query('SELECT * FROM items WHERE expires<? AND notified=0 AND notice_at<=? LIMIT 20',[time(),time()])->fetchAll() as $row) {
            $retry=false;
            if((int)$row['link_message']>0) {
                try {
                    $ui=(new Ui($this->store))->forService($row['site']);
                    $photo=$row['link_kind']==='photo';
                    $text=$ui->text('handoff_expired',[],[],true);
                    $main->call($photo?'editMessageCaption':'editMessageText',['chat_id'=>(int)$row['link_chat'],'message_id'=>(int)$row['link_message'],
                        $photo?'caption':'text'=>$photo?Ui::fitCaption($text):$text,'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>[]]]);
                } catch(\Throwable $e) { $retry=!in_array((int)$e->getCode(),[400,403,404],true)&&time()-(int)$row['expires']<86400; }
            }
            if($retry) $this->query('UPDATE items SET notice_at=? WHERE token=?',[time()+5,$row['token']]);
            else $this->query('UPDATE items SET notified=1 WHERE token=?',[$row['token']]);
        }
        foreach($this->query("SELECT token FROM items WHERE expires<? AND status NOT IN ('queued','sending')",[time()])->fetchAll() as $row) {
            if(preg_match('/^[a-f0-9]{32}$/D',$row['token'])) { @unlink($this->dir.'/'.$row['token'].'.mp4'); @unlink($this->dir.'/'.$row['token'].'.jpg'); }
        }
        $this->query("DELETE FROM items WHERE expires<? AND status NOT IN ('queued','sending') AND notified=1",[time()]);
    }
    public function purgeOrphans(): void
    {
        $lock=$this->configurationLock();
        try {
            foreach(glob($this->dir.'/*')?:[] as $path) {
                $name=basename($path);
                if(is_link($path)||!is_file($path)||filemtime($path)>time()-600) continue;
                if(preg_match('/^([a-f0-9]{32})\.(?:mp4|jpg)$/D',$name,$m)) {
                    if(!$this->query('SELECT 1 FROM items WHERE token=?',[$m[1]])->fetchColumn()) @unlink($path);
                } elseif(preg_match('/^token\.[a-f0-9]{12}$/D',$name)) @unlink($path);
            }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
