<?php
declare(strict_types=1);
namespace Zero;

/** Service-bound avatar cache, pending previews and bounded worker lookups. */
trait CheckerProfiles
{
    private static ?\WeakMap $profileSchemas=null;
    protected function initCheckerProfiles(): void
    {
        $store=$this->store;
        self::$profileSchemas??=new \WeakMap();
        if(isset((self::$profileSchemas[$store]??[])[static::SERVICE])) return;
        if(!array_diff(['avatar','avatar_file','avatar_version','avatar_checked'],array_column($this->query('PRAGMA table_info({checker}targets)')->fetchAll(),'name'))) { $this->profileSchemaReady(); return; }
        $store->db->exec('BEGIN IMMEDIATE');
        try {
            $columns=array_column($this->query('PRAGMA table_info({checker}targets)')->fetchAll(),'name');
            foreach(['avatar'=>"TEXT NOT NULL DEFAULT ''",'avatar_file'=>"TEXT NOT NULL DEFAULT ''",'avatar_version'=>'INTEGER NOT NULL DEFAULT 0','avatar_checked'=>'INTEGER NOT NULL DEFAULT 0'] as $name=>$type)
                if(!in_array($name,$columns,true)) $store->db->exec($this->sql("ALTER TABLE {checker}targets ADD COLUMN $name $type"));
            $columns=array_column($this->query('PRAGMA table_info({checker}panels)')->fetchAll(),'name');
            foreach(['photo','photo_source'] as $name) if(!in_array($name,$columns,true)) $store->db->exec($this->sql("ALTER TABLE {checker}panels ADD COLUMN $name TEXT NOT NULL DEFAULT ''"));
            $store->db->exec('COMMIT');
            $this->profileSchemaReady();
        } catch(\Throwable $e) { $store->db->exec('ROLLBACK'); throw $e; }
    }
    private function profileSchemaReady(): void
    {
        $schemas=self::$profileSchemas[$this->store]??[]; $schemas[static::SERVICE]=true; self::$profileSchemas[$this->store]=$schemas;
    }
    protected function applyMetadata(array $target,array $snapshot): void
    {
        if(!array_key_exists('avatar',$snapshot)) return;
        $avatar=static::avatarUrl($snapshot['avatar'])?$snapshot['avatar']:'';
        $this->query("UPDATE {checker}targets SET avatar=?,avatar_file=CASE WHEN avatar=? THEN avatar_file ELSE '' END,avatar_version=avatar_version+CASE WHEN avatar<>? OR avatar_version=0 THEN 1 ELSE 0 END,avatar_checked=? WHERE id=?",[$avatar,$avatar,$avatar,time(),$target['id']]);
    }
    public function profile(array $sub): array
    {
        return $this->query('SELECT id,avatar,avatar_file,avatar_version,avatar_checked FROM {checker}targets WHERE id=?',[$sub['target']])->fetch()?:['id'=>0,'avatar'=>'','avatar_file'=>'','avatar_version'=>0,'avatar_checked'=>0];
    }
    public function previewProfile(string $name,int $owner,int $chat): ?array
    {
        $name=$this->normalizeName($name);
        if($name===null||!$this->allowed($owner,$chat)) return null;
        $known=$this->query('SELECT id FROM {checker}targets WHERE username=?',[$name])->fetchColumn();
        if(!$this->canPreview($owner,(int)$known)) return null;
        // A metadata cache entry is not a subscription and consumes no quota or media baseline.
        $this->query('INSERT OR IGNORE INTO {checker}targets(username) VALUES(?)',[$name]);
        $id=(int)$this->query('SELECT id FROM {checker}targets WHERE username=?',[$name])->fetchColumn();
        return $this->profile(['target'=>$id]);
    }
    private function canPreview(int $owner,int $target): bool
    {
        $limit=$this->limit($owner);
        return $limit===0||$this->used($owner)<$limit||(bool)$this->query('SELECT 1 FROM {checker}subs WHERE owner=? AND target=? AND deleted=0',[$owner,$target])->fetchColumn();
    }
    public function profilePanels(): array
    {
        return $this->query($this->profilePanelSql()." AND t.avatar_version>CAST(COALESCE(json_extract(p.data,'$.profile_version'),0) AS INTEGER) LIMIT 10",[time()])->fetchAll();
    }
    private function profilePanelSql(): string
    {
        // An optional photo must not bypass a pending verification's completion/retry timing.
        return "SELECT p.*,s.chat_id AS subscription_chat FROM {checker}panels p JOIN {checker}targets t ON t.id=CAST(json_extract(p.data,'$.profile_target') AS INTEGER) LEFT JOIN {checker}subs s ON s.id=json_extract(p.data,'$.id') WHERE p.expires>? AND json_type(p.data,'$.check') IS NULL AND ((p.view='new' AND json_extract(p.data,'$.username')=t.username) OR (p.view='item' AND s.target=t.id AND s.owner=p.owner AND s.deleted=0 AND s.paused=0 AND s.generation=CAST(json_extract(p.data,'$.profile_generation') AS INTEGER)))";
    }
    private function profileRequests(): array
    {
        return $this->query($this->profilePanelSql()." AND json_extract(p.data,'$.profile_wait')=1 AND t.avatar_checked<? AND t.lease_until<? AND (t.state NOT IN ('blocked','cookies','restricted') OR t.next_check<=?) AND NOT EXISTS(SELECT 1 FROM settings r WHERE r.key=?||p.owner AND CAST(r.value AS INTEGER)>?) LIMIT 10",[time(),time()-300,time(),time(),static::SERVICE.'.checker.profile.',time()-60])->fetchAll();
    }
    public function checksReady(): bool { return $this->verificationReady()||(static::enabled($this->store)&&((bool)$this->profilePanels()||(bool)$this->profileRequests())); }
    public function pollProfile(?\Closure $reader=null): bool
    {
        if(!static::enabled($this->store)||$this->store->get('update_in_progress','0')==='1') return false;
        foreach($this->profileRequests() as $p) {
            $data=json_decode($p['data'],true);
            if(!$this->allowed((int)$p['owner'],(int)($p['view']==='item'?$p['subscription_chat']:$p['chat_id']))||($p['view']==='new'&&!$this->canPreview((int)$p['owner'],(int)$data['profile_target']))) {
                $data['profile_wait']=false;
                $this->query('UPDATE {checker}panels SET data=? WHERE token=? AND data=?',[json_encode($data,JSON_THROW_ON_ERROR),$p['token'],$p['data']]); continue;
            }
            $lease=bin2hex(random_bytes(12)); $target=(int)$data['profile_target'];
            $claimed=$this->query("UPDATE {checker}targets SET lease=?,lease_until=? WHERE id=? AND avatar_checked<? AND lease_until<? AND (state NOT IN ('blocked','cookies','restricted') OR next_check<=?)",[$lease,time()+120,$target,time()-300,time(),time()])->rowCount();
            if(!$claimed) continue;
            $this->store->set(static::SERVICE.'.checker.profile.'.$p['owner'],(string)time());
            $target=$this->query('SELECT * FROM {checker}targets WHERE id=? AND lease=?',[$target,$lease])->fetch();
            try {
                $snapshot=$reader?$reader($target):$this->readProfile($target);
                $this->validateProfileIdentity($target,$snapshot['account_id']??'');
                $this->store->db->exec('BEGIN IMMEDIATE');
                try {
                    if($this->query('SELECT 1 FROM {checker}targets WHERE id=? AND lease=?',[$target['id'],$lease])->fetchColumn()) {
                        $this->applyMetadata($target,['avatar'=>$snapshot['avatar']??'']);
                        $this->query("UPDATE {checker}targets SET account_id=?,lease='',lease_until=0 WHERE id=? AND lease=?",[$snapshot['account_id'],$target['id'],$lease]);
                    }
                    $this->store->db->exec('COMMIT');
                } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
            } catch(\Throwable $e) {
                $reason=$e instanceof MediaFailure?$e->reason:'process';
                $refused=in_array($reason,['blocked','cookies','restricted'],true);
                $delay=$reason==='blocked'?900:300;
                $this->query("UPDATE {checker}targets SET avatar_checked=?,avatar_version=avatar_version+1,lease='',lease_until=0".($refused?",state=?,failures=failures+1,next_check=MAX(next_check,?)":'')." WHERE id=? AND lease=?",$refused?[time(),$reason,time()+$delay,$target['id'],$lease]:[time(),$target['id'],$lease]);
            }
            return true;
        }
        return false;
    }
}
