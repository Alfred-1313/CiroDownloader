<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/InstagramCheckerMedia.php';

/** Persistent subscriptions and delivery cursors, never a diagnostic/error log. */
final class InstagramChecker
{
    private static ?\WeakMap $schemas=null;
    public function __construct(public Config $config,public Store $store,public Api $api)
    {
        self::$schemas??=new \WeakMap();
        if(isset(self::$schemas[$store])) return;
        $store->db->exec("CREATE TABLE IF NOT EXISTS ig_check_targets(id INTEGER PRIMARY KEY,username TEXT NOT NULL UNIQUE,account_id TEXT NOT NULL DEFAULT '',next_check INTEGER NOT NULL DEFAULT 0,lease TEXT NOT NULL DEFAULT '',lease_until INTEGER NOT NULL DEFAULT 0,checked INTEGER NOT NULL DEFAULT 0,failures INTEGER NOT NULL DEFAULT 0,state TEXT NOT NULL DEFAULT 'waiting');
            CREATE TABLE IF NOT EXISTS ig_check_subs(id INTEGER PRIMARY KEY,owner INTEGER NOT NULL,chat_id INTEGER NOT NULL,thread_id INTEGER NOT NULL DEFAULT 0,target INTEGER NOT NULL,mode INTEGER NOT NULL DEFAULT 3,paused INTEGER NOT NULL DEFAULT 0,deleted INTEGER NOT NULL DEFAULT 0,posts_ready INTEGER NOT NULL DEFAULT 0,stories_ready INTEGER NOT NULL DEFAULT 0,generation INTEGER NOT NULL DEFAULT 1,created INTEGER NOT NULL,UNIQUE(owner,chat_id,thread_id,target));
            CREATE INDEX IF NOT EXISTS ig_check_owner ON ig_check_subs(owner,deleted,target);
            CREATE INDEX IF NOT EXISTS ig_check_target ON ig_check_subs(target,deleted,paused);
            CREATE UNIQUE INDEX IF NOT EXISTS ig_check_group_destination ON ig_check_subs(chat_id,thread_id,target) WHERE chat_id<0 AND deleted=0;
            CREATE TABLE IF NOT EXISTS ig_check_seen(target INTEGER NOT NULL,kind TEXT NOT NULL,item TEXT NOT NULL,seen INTEGER NOT NULL,PRIMARY KEY(target,kind,item));
            CREATE TABLE IF NOT EXISTS ig_check_deliveries(id INTEGER PRIMARY KEY,subscription INTEGER NOT NULL,generation INTEGER NOT NULL,kind TEXT NOT NULL,item TEXT NOT NULL,url TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',job_id INTEGER NOT NULL DEFAULT 0,attempts INTEGER NOT NULL DEFAULT 0,due INTEGER NOT NULL DEFAULT 0,message_id INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL,UNIQUE(subscription,kind,item));
            CREATE INDEX IF NOT EXISTS ig_check_pending ON ig_check_deliveries(status,due);
            CREATE TABLE IF NOT EXISTS ig_check_panels(token TEXT PRIMARY KEY,owner INTEGER NOT NULL,chat_id INTEGER NOT NULL,thread_id INTEGER NOT NULL,message_id INTEGER NOT NULL DEFAULT 0,view TEXT NOT NULL DEFAULT 'home',data TEXT NOT NULL DEFAULT '{}',expires INTEGER NOT NULL);");
        self::$schemas[$store]=true;
    }
    public static function prefix(array $job): string { return instagramStoryUrl($job['url'])!==null?'instagram_checker_story':'instagram_checker_post'; }
    public static function enabled(Store $s): bool { return $s->get('instagram.checker.enabled','1')==='1'; }
    public static function everyone(Store $s): bool { return $s->get('instagram.checker.public','0')==='1'; }
    public function interval(): int { return max(60,min(3600,(int)$this->store->get('instagram.checker.seconds','300'))); }
    public function limit(int $owner): int { return max(0,min(100,(int)$this->store->get('instagram.checker.limit.'.$owner,$this->store->get('instagram.checker.limit','3')))); }
    public function used(int $owner): int { return (int)$this->store->query('SELECT COUNT(DISTINCT target) FROM ig_check_subs WHERE owner=? AND deleted=0',[$owner])->fetchColumn(); }
    public function allowed(int $owner,int $chat,bool $groupCheck=true): bool
    {
        $bot=new Bot($this->config,$this->store,$this->api);
        if(!self::enabled($this->store)||!$bot->allowed($owner,$chat)||(!self::everyone($this->store)&&!$bot->admin($owner))) return false;
        if($chat>0) return $chat===$owner;
        return !$groupCheck||!self::everyone($this->store)||Radio::chatAdmin($this->api,$this->store,$chat,$owner);
    }
    public function subscriptions(int $owner,int $chat,int $thread,bool $all=false): array
    {
        if($all&&$owner===$chat) return $this->store->query('SELECT s.*,t.username,t.state,t.checked FROM ig_check_subs s JOIN ig_check_targets t ON t.id=s.target WHERE s.owner=? AND s.deleted=0 ORDER BY s.id',[$owner])->fetchAll();
        return $this->store->query('SELECT s.*,t.username,t.state,t.checked FROM ig_check_subs s JOIN ig_check_targets t ON t.id=s.target WHERE s.owner=? AND s.chat_id=? AND s.thread_id=? AND s.deleted=0 ORDER BY s.id',[$owner,$chat,$thread])->fetchAll();
    }
    public function subscription(int $id,int $owner,int $chat,int $thread): ?array
    {
        $r=$this->store->query('SELECT s.*,t.username,t.state,t.checked FROM ig_check_subs s JOIN ig_check_targets t ON t.id=s.target WHERE s.id=? AND s.owner=? AND s.chat_id=? AND s.thread_id=? AND s.deleted=0',[$id,$owner,$chat,$thread])->fetch(); return $r?:null;
    }
    public function owned(int $id,int $owner): ?array
    {
        $r=$this->store->query('SELECT s.*,t.username,t.state,t.checked FROM ig_check_subs s JOIN ig_check_targets t ON t.id=s.target WHERE s.id=? AND s.owner=? AND s.deleted=0',[$id,$owner])->fetch(); return $r?:null;
    }
    public function add(int $owner,int $chat,int $thread,string $name,int $mode): int
    {
        $name=instagramUsername($name); $thread=$chat<0?max(0,$thread):0;
        if($name===null||!in_array($mode,[1,2,3],true)) throw new \InvalidArgumentException('checker_invalid');
        $name=strtolower($name);
        if(!$this->allowed($owner,$chat)) throw new \RuntimeException('checker_denied');
        $s=$this->store; $s->db->exec('BEGIN IMMEDIATE');
        try {
            // Recheck inside the quota transaction; simultaneous requests cannot exceed it.
            if(!$this->allowed($owner,$chat,false)) throw new \RuntimeException('checker_denied');
            $target=$s->query('SELECT id FROM ig_check_targets WHERE username=?',[$name])->fetchColumn();
            if($chat<0&&$target&&$s->query('SELECT 1 FROM ig_check_subs WHERE chat_id=? AND thread_id=? AND target=? AND deleted=0',[$chat,$thread,$target])->fetchColumn()) throw new \RuntimeException('checker_duplicate');
            $existing=$target?$s->query('SELECT id,deleted FROM ig_check_subs WHERE owner=? AND chat_id=? AND thread_id=? AND target=?',[$owner,$chat,$thread,$target])->fetch():false;
            if($existing&&!(int)$existing['deleted']) throw new \RuntimeException('checker_duplicate');
            $owned=$target&&$s->query('SELECT 1 FROM ig_check_subs WHERE owner=? AND target=? AND deleted=0',[$owner,$target])->fetchColumn();
            $limit=$this->limit($owner);
            if(!$owned&&$limit>0&&$this->used($owner)>=$limit) throw new \RuntimeException('checker_limit_reached');
            if(!$target) { $s->query('INSERT INTO ig_check_targets(username) VALUES(?)',[$name]); $target=(int)$s->db->lastInsertId(); }
            if($existing) {
                $s->query('UPDATE ig_check_subs SET mode=?,paused=0,deleted=0,posts_ready=0,stories_ready=0,generation=generation+1,created=? WHERE id=?',[$mode,time(),$existing['id']]); $id=(int)$existing['id'];
            } else {
                $s->query('INSERT INTO ig_check_subs(owner,chat_id,thread_id,target,mode,created) VALUES(?,?,?,?,?,?)',[$owner,$chat,$thread,$target,$mode,time()]); $id=(int)$s->db->lastInsertId();
            }
            $s->query('UPDATE ig_check_targets SET next_check=CASE WHEN failures=0 THEN MIN(next_check,?) ELSE next_check END WHERE id=?',[time(),$target]);
            $s->db->exec('COMMIT'); return $id;
        } catch(\Throwable $e) { $s->db->exec('ROLLBACK'); throw $e; }
    }
    public function change(int $id,int $owner,int $chat,int $thread,string $op,bool $privateControl=false): bool
    {
        $sub=$this->subscription($id,$owner,$chat,$thread); if(!$sub) return false;
        $canStop=$privateControl&&$this->allowed($owner,$owner)&&($op==='delete'||($op==='pause'&&!(int)$sub['paused']));
        if(!$canStop&&!$this->allowed($owner,$chat)) return false;
        $s=$this->store; $s->db->exec('BEGIN IMMEDIATE');
        try {
            if($op==='delete') $s->query('UPDATE ig_check_subs SET deleted=1,generation=generation+1 WHERE id=?',[$id]);
            elseif($op==='pause') $s->query('UPDATE ig_check_subs SET paused=1-paused,posts_ready=0,stories_ready=0,generation=generation+1 WHERE id=?',[$id]);
            elseif(preg_match('/^mode([123])$/D',$op,$m)) $s->query('UPDATE ig_check_subs SET mode=?,posts_ready=0,stories_ready=0,generation=generation+1 WHERE id=?',[(int)$m[1],$id]);
            else { $s->db->exec('ROLLBACK'); return false; }
            $s->query("UPDATE ig_check_deliveries SET status='cancelled' WHERE subscription=? AND status IN ('pending','queued','retry')",[$id]);
            $s->db->exec('COMMIT'); return true;
        } catch(\Throwable $e) { $s->db->exec('ROLLBACK'); throw $e; }
    }
    public static function deliveryId(array $job): int
    {
        $options=json_decode((string)($job['options']??'{}'),true); return max(0,(int)($options['checker']??$options['pages']['checker']??0));
    }
    public function context(array $job): ?array
    {
        $id=self::deliveryId($job); if(!$id) return null;
        $r=$this->store->query('SELECT d.*,s.owner,s.chat_id,s.thread_id,s.mode,s.paused,s.deleted,s.generation AS current_generation,t.username FROM ig_check_deliveries d JOIN ig_check_subs s ON s.id=d.subscription JOIN ig_check_targets t ON t.id=s.target WHERE d.id=? AND d.job_id=?',[$id,$job['id']])->fetch();
        if(!$r||(int)$r['owner']!==(int)$job['user_id']||(int)$r['chat_id']!==(int)$job['chat_id']||(int)$r['thread_id']!==(int)($job['thread_id']??0)||$r['url']!==$job['url']) return null;
        return $r;
    }
    public function canDeliver(array $job,bool $navigation=false): bool
    {
        $r=$this->context($job);
        if(!$r||!$this->allowed((int)$r['owner'],(int)$r['chat_id'])) return false;
        if($navigation) return $r['status']==='sent';
        return $r['status']==='queued'&&!(int)$r['paused']&&!(int)$r['deleted']&&(int)$r['generation']===(int)$r['current_generation']&&((int)$r['mode']&($r['kind']==='post'?1:2));
    }
    public function beginSend(array $job): void
    {
        if(!$this->canDeliver($job)) throw new MediaFailure('restricted','Checker delivery no longer allowed');
        $s=$this->store; $s->db->exec('BEGIN IMMEDIATE');
        try {
            if(!$this->allowed((int)$job['user_id'],(int)$job['chat_id'],false)) throw new MediaFailure('restricted','Checker delivery no longer allowed');
            $changed=$s->query("UPDATE ig_check_deliveries SET status='sending' WHERE id=? AND job_id=? AND status='queued' AND EXISTS(SELECT 1 FROM ig_check_subs s WHERE s.id=ig_check_deliveries.subscription AND s.generation=ig_check_deliveries.generation AND s.paused=0 AND s.deleted=0)",[self::deliveryId($job),$job['id']])->rowCount();
            if($changed!==1) throw new MediaFailure('restricted','Checker delivery already claimed');
            $s->db->exec('COMMIT');
        } catch(\Throwable $e) { $s->db->exec('ROLLBACK'); throw $e; }
    }
    public function confirmed(array $job,int $message): void
    {
        $this->store->query("UPDATE ig_check_deliveries SET status='sent',message_id=? WHERE id=? AND job_id=? AND status='sending'",[$message,self::deliveryId($job),$job['id']]);
    }
    public function cancel(array $job): void
    {
        if($this->context($job)) $this->store->query("UPDATE ig_check_deliveries SET status='cancelled' WHERE id=? AND job_id=? AND status IN ('queued','retry')",[self::deliveryId($job),$job['id']]);
    }
    public function failure(array $job): void
    {
        $r=$this->context($job); if(!$r) return;
        if($r['status']==='sending') $this->store->query("UPDATE ig_check_deliveries SET status='uncertain' WHERE id=?",[$r['id']]);
        elseif($r['status']==='queued') $this->store->query("UPDATE ig_check_deliveries SET status=?,due=? WHERE id=?",[(int)$r['attempts']<3?'retry':'failed',time()+300,$r['id']]);
    }
    public function queuePending(): void
    {
        if(!self::enabled($this->store)||$this->store->get('update_in_progress','0')==='1') return;
        $this->store->query("UPDATE ig_check_deliveries SET status=CASE WHEN attempts<3 THEN 'retry' ELSE 'failed' END,due=? WHERE status='queued' AND EXISTS(SELECT 1 FROM jobs j WHERE j.id=ig_check_deliveries.job_id AND j.status IN ('failed','cancelled'))",[time()+60]);
        $this->store->query("UPDATE ig_check_deliveries SET status='uncertain' WHERE status='sending' AND NOT EXISTS(SELECT 1 FROM jobs j WHERE j.id=ig_check_deliveries.job_id AND j.status='running')");
        $this->store->query("UPDATE ig_check_deliveries SET status='uncertain' WHERE status='queued' AND NOT EXISTS(SELECT 1 FROM jobs j WHERE j.id=ig_check_deliveries.job_id AND j.status IN ('queued','running','failed','cancelled'))");
        foreach($this->store->query("SELECT d.*,s.owner,s.chat_id,s.thread_id,s.paused,s.deleted,s.mode,s.generation AS current_generation FROM ig_check_deliveries d JOIN ig_check_subs s ON s.id=d.subscription WHERE d.status IN ('pending','retry') AND d.due<=? ORDER BY d.id LIMIT 20",[time()])->fetchAll() as $row) {
            if((int)$row['paused']||(int)$row['deleted']||(int)$row['generation']!==(int)$row['current_generation']||!$this->allowed((int)$row['owner'],(int)$row['chat_id'])) {
                $this->store->query("UPDATE ig_check_deliveries SET status='cancelled' WHERE id=?",[$row['id']]); continue;
            }
            if($this->store->full()||$this->store->busy((int)$row['owner'],(int)$row['chat_id'])) break;
            $s=$this->store; $s->db->exec('BEGIN IMMEDIATE');
            try {
                $reserved=$s->query("UPDATE ig_check_deliveries SET status='queued',attempts=attempts+1 WHERE id=? AND status IN ('pending','retry')",[$row['id']])->rowCount();
                if($reserved) {
                    $s->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,created,message_id,phase,thread_id,options) VALUES(?,?,?,?,'queued',?,NULL,'instagram',?,?)",[-4000000000000000-(int)$row['id']*10-(int)$row['attempts'],(int)$row['owner'],(int)$row['chat_id'],$row['url'],time(),(int)$row['thread_id'],json_encode(['checker'=>(int)$row['id']],JSON_THROW_ON_ERROR)]);
                    $s->query('UPDATE ig_check_deliveries SET job_id=? WHERE id=?',[(int)$s->db->lastInsertId(),$row['id']]);
                }
                $s->db->exec('COMMIT');
            } catch(\Throwable $e) { $s->db->exec('ROLLBACK'); throw $e; }
        }
    }
    public function due(): bool
    {
        if(!self::enabled($this->store)) return false;
        return (bool)$this->store->query("SELECT 1 FROM ig_check_deliveries WHERE status IN ('pending','retry') AND due<=? UNION ALL SELECT 1 FROM ig_check_targets t WHERE next_check<=? AND lease_until<? AND EXISTS(SELECT 1 FROM ig_check_subs s WHERE s.target=t.id AND s.paused=0 AND s.deleted=0) LIMIT 1",[time(),time(),time()])->fetchColumn();
    }
    public function claimTarget(): ?array
    {
        if(!self::enabled($this->store)||$this->store->get('update_in_progress','0')==='1') return null;
        $s=$this->store; $s->db->exec('BEGIN IMMEDIATE');
        try {
            $r=$s->query('SELECT t.* FROM ig_check_targets t WHERE t.next_check<=? AND t.lease_until<? AND EXISTS(SELECT 1 FROM ig_check_subs s WHERE s.target=t.id AND s.deleted=0 AND s.paused=0) ORDER BY t.next_check,t.id LIMIT 1',[time(),time()])->fetch();
            if($r) {
                $r['lease']=bin2hex(random_bytes(12)); $s->query('UPDATE ig_check_targets SET lease=?,lease_until=? WHERE id=?',[$r['lease'],time()+120,$r['id']]);
            }
            $s->db->exec('COMMIT'); return $r?:null;
        } catch(\Throwable $e) { $s->db->exec('ROLLBACK'); throw $e; }
    }
    /** All IDs and URLs are validated before the snapshot transaction advances any cursor. */
    public function apply(array $target,array $snapshot): void
    {
        $account=(string)($snapshot['account_id']??''); $mode=(int)($snapshot['mode']??3);
        if(!in_array($mode,[1,2,3],true)) throw new MediaFailure('format','Invalid checker mode');
        if(!preg_match('/^[0-9]{1,24}$/D',$account)||($target['account_id']!==''&&$target['account_id']!==$account)) throw new MediaFailure('format','Checker account mismatch');
        $items=[];
        foreach(['posts'=>'post','stories'=>'story'] as $bucket=>$kind) {
            if(!isset($snapshot[$bucket])||!is_array($snapshot[$bucket])||count($snapshot[$bucket])>($kind==='post'?24:100)) throw new MediaFailure('format','Incomplete checker snapshot');
            foreach($snapshot[$bucket] as $item) {
                $id=(string)($item['id']??''); $url=(string)($item['url']??'');
                if(!preg_match('/^[0-9]{1,30}$/D',$id)||($kind==='post'?instagramUrl($url)!==$url||instagramStoryUrl($url)!==null:instagramStoryUrl($url)!==$url||strtolower(instagramStoryUsername($url))!==$target['username']||!str_ends_with($url,'/'.$id.'/'))||isset($items[$kind.':'.$id])) throw new MediaFailure('format','Invalid checker item');
                $items[$kind.':'.$id]=['id'=>$id,'url'=>$url,'kind'=>$kind,'time'=>max(0,(int)($item['time']??0))];
            }
        }
        $s=$this->store; $s->db->exec('BEGIN IMMEDIATE');
        try {
            $live=$s->query('SELECT * FROM ig_check_targets WHERE id=? AND lease=?',[$target['id'],$target['lease']])->fetch();
            if(!$live||!self::enabled($s)||$s->get('update_in_progress','0')==='1') { $s->db->exec('ROLLBACK'); return; }
            $subs=$s->query('SELECT * FROM ig_check_subs WHERE target=? AND paused=0 AND deleted=0',[$target['id']])->fetchAll();
            foreach($items as $item) {
                $fresh=$s->query('INSERT OR IGNORE INTO ig_check_seen(target,kind,item,seen) VALUES(?,?,?,?)',[$target['id'],$item['kind'],$item['id'],time()])->rowCount();
                if(!$fresh) continue;
                $bit=$item['kind']==='post'?1:2; $ready=$item['kind']==='post'?'posts_ready':'stories_ready';
                foreach($subs as $sub) {
                    if(!((int)$sub['mode']&$bit)||!(int)$sub[$ready]) continue;
                    // Old posts can move into the latest feed when pinned. A
                    // small lookback tolerates delayed indexing and clock skew.
                    $oldPost=$item['kind']==='post'&&$item['time']>0&&$item['time']<max((int)$sub['created'],(int)$live['checked']-max(60,$this->interval()*2));
                    if($oldPost) continue;
                    $s->query('INSERT OR IGNORE INTO ig_check_deliveries(subscription,generation,kind,item,url,created) VALUES(?,?,?,?,?,?)',[$sub['id'],$sub['generation'],$item['kind'],$item['id'],$item['url'],time()]);
                }
            }
            $s->query('UPDATE ig_check_subs SET posts_ready=CASE WHEN (mode&1)<>0 AND (?&1)<>0 THEN 1 ELSE posts_ready END,stories_ready=CASE WHEN (mode&2)<>0 AND (?&2)<>0 THEN 1 ELSE stories_ready END WHERE target=? AND paused=0 AND deleted=0',[$mode,$mode,$target['id']]);
            $s->query("UPDATE ig_check_targets SET account_id=?,next_check=?,lease='',lease_until=0,checked=?,failures=0,state='ok' WHERE id=?",[$account,time()+$this->interval(),time(),$target['id']]);
            $s->db->exec('COMMIT');
        } catch(\Throwable $e) { $s->db->exec('ROLLBACK'); throw $e; }
    }
    public function prune(): void
    {
        $s=$this->store; $cutoff=time()-30*86400;
        $s->query('DELETE FROM ig_check_panels WHERE expires<?',[time()]);
        $s->query("DELETE FROM ig_check_deliveries WHERE created<? AND status IN ('sent','cancelled','failed','uncertain') AND NOT EXISTS(SELECT 1 FROM jobs j WHERE j.id=ig_check_deliveries.job_id AND j.status IN ('queued','running'))",[$cutoff]);
        $s->query('DELETE FROM ig_check_subs WHERE deleted=1 AND created<? AND NOT EXISTS(SELECT 1 FROM ig_check_deliveries d WHERE d.subscription=ig_check_subs.id)',[$cutoff]);
        $s->query("DELETE FROM ig_check_seen WHERE (kind='story' AND seen<?) OR NOT EXISTS(SELECT 1 FROM ig_check_subs s WHERE s.target=ig_check_seen.target)",[time()-7*86400]);
        $s->query('DELETE FROM ig_check_targets WHERE lease_until<? AND NOT EXISTS(SELECT 1 FROM ig_check_subs s WHERE s.target=ig_check_targets.id)',[time()]);
    }
    public function poll(?\Closure $reader=null): bool
    {
        $this->queuePending(); $target=$this->claimTarget(); if(!$target) return false;
        try {
            $subs=$this->store->query('SELECT * FROM ig_check_subs WHERE target=? AND paused=0 AND deleted=0',[$target['id']])->fetchAll(); $mode=0;
            foreach($subs as $sub) if($this->allowed((int)$sub['owner'],(int)$sub['chat_id'])) $mode|=(int)$sub['mode'];
            if(!$mode) throw new MediaFailure('restricted','Checker owners no longer allowed');
            $snapshot=$reader?$reader($target,$mode):$this->scan($target,$mode);
            $this->apply($target,$snapshot); $this->queuePending();
        } catch(\Throwable $e) {
            $reason=$e instanceof MediaFailure?$e->reason:'process';
            $state=in_array($reason,['blocked','cookies','restricted','network','format'],true)?$reason:'process';
            $delay=min(3600,max($this->interval(),($state==='blocked'?900:300)*(1+min(6,(int)$target['failures']))));
            $this->store->query("UPDATE ig_check_targets SET next_check=?,lease='',lease_until=0,failures=failures+1,state=? WHERE id=? AND lease=?",[time()+$delay,$state,$target['id'],$target['lease']]);
        }
        return true;
    }
    private function scan(array $target,int $mode): array
    {
        $jars=InstagramCheckerMedia::pool($this->config)->order(1);
        if(!$jars) throw new MediaFailure('cookies','Checker session unavailable');
        $folder=$this->config->storage.'/downloads/checker-'.bin2hex(random_bytes(6));
        if(!is_dir(dirname($folder))) mkdir(dirname($folder),0700,true); mkdir($folder,0700);
        try {
            $content=(string)file_get_contents($jars[0]['path']); InstagramCheckerMedia::validateCookies($content);
            $path=$folder.'/session.txt'; file_put_contents($path,$content); chmod($path,0600);
            $command=[dirname($this->config->galleryDl).'/python',dirname(__DIR__).'/bin/instagram-check.py','--username',$target['username'],'--cookies',$path,'--mode',(string)$mode];
            if($target['account_id']!=='') array_push($command,'--account-id',$target['account_id']);
            if($this->config->galleryProxy!=='') array_push($command,'--proxy',$this->config->galleryProxy);
            try { $out=(new Download($this->config))->run($command,$folder,90); }
            catch(MediaFailure $e) { $reason=preg_match('/CHECKER_ERROR (blocked|cookies|restricted|network|format)\b/',$e->getMessage(),$m)?$m[1]:$e->reason; throw new MediaFailure($reason,'Checker scan failed'); }
            $data=json_decode($out,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($data)) throw new MediaFailure('format','Invalid checker snapshot');
            return $data;
        } finally { Download::clean($folder); }
    }
}
