<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/InstagramChecker.php';
require_once __DIR__.'/YoutubeCheckerMedia.php';

/** YouTube adapter for the shared subscription, quota, baseline and delivery machinery. */
final class YoutubeChecker extends InstagramChecker
{
    protected const SERVICE='youtube', TABLE='yt_check_', DEFAULT_ENABLED='0', ALL=7, UPDATE_BASE=5000000000000000;
    protected const BITS=['video'=>1,'short'=>2,'post'=>4];
    protected const READY=['video'=>'posts_ready','short'=>'stories_ready','post'=>'extra_ready'];
    protected const SENDABLE=['queued','offered'];
    private static ?\WeakMap $profileSchemas=null;
    public function __construct(Config $config,Store $store,Api $api)
    {
        parent::__construct($config,$store,$api);
        self::$profileSchemas??=new \WeakMap();
        if(isset(self::$profileSchemas[$store])) return;
        if(!array_diff(['avatar','avatar_file','avatar_version','avatar_checked'],array_column($this->query('PRAGMA table_info({checker}targets)')->fetchAll(),'name'))) { self::$profileSchemas[$store]=true; return; }
        $store->db->exec('BEGIN IMMEDIATE');
        try {
            $columns=array_column($this->query('PRAGMA table_info({checker}targets)')->fetchAll(),'name');
            foreach(['avatar'=>"TEXT NOT NULL DEFAULT ''",'avatar_file'=>"TEXT NOT NULL DEFAULT ''",'avatar_version'=>'INTEGER NOT NULL DEFAULT 0','avatar_checked'=>'INTEGER NOT NULL DEFAULT 0'] as $name=>$type)
                if(!in_array($name,$columns,true)) $store->db->exec($this->sql("ALTER TABLE {checker}targets ADD COLUMN $name $type"));
            $columns=array_column($this->query('PRAGMA table_info({checker}panels)')->fetchAll(),'name');
            foreach(['photo','photo_source'] as $name) if(!in_array($name,$columns,true)) $store->db->exec($this->sql("ALTER TABLE {checker}panels ADD COLUMN $name TEXT NOT NULL DEFAULT ''"));
            $store->db->exec('COMMIT');
            self::$profileSchemas[$store]=true;
        } catch(\Throwable $e) { $store->db->exec('ROLLBACK'); throw $e; }
    }
    public static function avatarUrl(mixed $url): bool
    {
        if(!is_string($url)||strlen($url)>2048||preg_match('/[\x00-\x20\x7f]/',$url)) return false;
        $p=parse_url($url);
        return $p&&($p['scheme']??'')==='https'&&in_array($p['host']??'',['yt3.ggpht.com','yt3.googleusercontent.com'],true)
            &&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['port']);
    }
    protected function applyMetadata(array $target,array $snapshot): void
    {
        if(!array_key_exists('avatar',$snapshot)) return;
        $avatar=self::avatarUrl($snapshot['avatar'])?$snapshot['avatar']:'';
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
        return "SELECT p.*,s.chat_id AS subscription_chat FROM {checker}panels p JOIN {checker}targets t ON t.id=CAST(json_extract(p.data,'$.profile_target') AS INTEGER) LEFT JOIN {checker}subs s ON s.id=json_extract(p.data,'$.id') WHERE p.expires>? AND ((p.view='new' AND json_extract(p.data,'$.username')=t.username) OR (p.view='item' AND s.target=t.id AND s.owner=p.owner AND s.deleted=0 AND s.paused=0 AND s.generation=CAST(json_extract(p.data,'$.profile_generation') AS INTEGER)))";
    }
    private function profileRequests(): array
    {
        return $this->query($this->profilePanelSql()." AND json_extract(p.data,'$.profile_wait')=1 AND t.avatar_checked<? AND t.lease_until<? AND (t.state NOT IN ('blocked','cookies','restricted') OR t.next_check<=?) AND NOT EXISTS(SELECT 1 FROM settings r WHERE r.key='youtube.checker.profile.'||p.owner AND CAST(r.value AS INTEGER)>?) LIMIT 10",[time(),time()-300,time(),time(),time()-60])->fetchAll();
    }
    public function checksReady(): bool { return parent::checksReady()||(static::enabled($this->store)&&((bool)$this->profilePanels()||(bool)$this->profileRequests())); }
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
            $this->store->set('youtube.checker.profile.'.$p['owner'],(string)time());
            $target=$this->query('SELECT * FROM {checker}targets WHERE id=? AND lease=?',[$target,$lease])->fetch();
            try {
                $snapshot=$reader?$reader($target):$this->readChannel($target,null);
                $this->validateIdentity($target,$snapshot['account_id']??'');
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
    public function normalizeName(string $name): ?string
    {
        $name=trim($name);
        if(preg_match('~^(?:https?://)?(?:www\.|m\.)?youtube\.com/~i',$name)) {
            if(!str_starts_with($name,'http')) $name='https://'.$name;
            $p=parse_url($name);
            if(!$p||!in_array($p['host']??'',['youtube.com','www.youtube.com','m.youtube.com'],true)||isset($p['user'])||isset($p['pass'])||isset($p['port'])) return null;
            $name=rawurldecode(trim($p['path']??'','/'));
            if(str_starts_with($name,'channel/')) $name=substr($name,8);
            elseif(!str_starts_with($name,'@')) return null;
        }
        if(preg_match('/^UC[A-Za-z0-9_-]{22}$/D',$name)) return $name;
        if(str_starts_with($name,'@')) $name=substr($name,1);
        return preg_match('/^[\p{L}\p{N}_.-]{3,30}$/uD',$name)?'@'.mb_strtolower($name):null;
    }
    public function modeChoices(): array { return ['videos'=>1,'shorts'=>2,'posts'=>4,'videos_shorts'=>3,'videos_posts'=>5,'shorts_posts'=>6,'all'=>7]; }
    public static function prefix(array $job): string
    {
        $o=json_decode((string)($job['options']??'{}'),true);
        $kind=$o['checker_kind']??$o['pages']['checker_kind']??(youtubePostUrl($job['url'])!==null?'post':'video');
        return 'youtube_checker_'.(in_array($kind,['video','short','post'],true)?$kind:'video');
    }
    protected function deliveryPhase(array $row): string { return match($row['kind']) {'post'=>'youtube','video'=>'inspect',default=>'youtube_checker'}; }
    public function offered(array $job,int $message): void
    {
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $this->query("UPDATE {checker}deliveries SET status='offered',message_id=? WHERE id=? AND job_id=? AND status='sending'",[$message,self::deliveryId($job),$job['id']]);
            $this->store->query("UPDATE jobs SET status='waiting_quality' WHERE id=?",[$job['id']]);
            $this->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
    }
    public function quality(): int { $q=(int)$this->store->get('youtube.checker.quality','720'); return in_array($q,[360,480,720,1080],true)?$q:720; }
    protected function snapshotItems(array $target,array $snapshot): array
    {
        $account=$snapshot['account_id']??''; $mode=$snapshot['mode']??0;
        $this->validateIdentity($target,$account);
        if(!is_int($mode)||$mode<1||$mode>7) throw new MediaFailure('format','Invalid YouTube checker mode');
        $items=[];
        foreach(['videos'=>'video','shorts'=>'short','posts'=>'post']as$bucket=>$kind) {
            if(!isset($snapshot[$bucket])||!is_array($snapshot[$bucket])||count($snapshot[$bucket])>24) throw new MediaFailure('format','Incomplete YouTube checker snapshot');
            foreach($snapshot[$bucket]as$item) {
                $id=$item['id']??''; $url=$item['url']??'';
                $valid=$kind==='post'?is_string($id)&&preg_match('/^Ug[A-Za-z0-9_-]{8,126}$/D',$id)&&$url==='https://www.youtube.com/post/'.$id
                    :is_string($id)&&preg_match('/^[A-Za-z0-9_-]{11}$/D',$id)&&$url===($kind==='short'?'https://www.youtube.com/shorts/':'https://www.youtube.com/watch?v=').$id;
                if(!$valid||isset($items[$kind.':'.$id])) throw new MediaFailure('format','Invalid YouTube checker item');
                $items[$kind.':'.$id]=['id'=>$id,'url'=>$url,'kind'=>$kind,'time'=>max(0,(int)($item['time']??0))];
            }
        }
        return $items;
    }
    protected function scan(array $target,int $mode): array
    {
        return $this->readChannel($target,$mode);
    }
    private function validateIdentity(array $target,mixed $account): void
    {
        if(!is_string($account)||!preg_match('/^UC[A-Za-z0-9_-]{22}$/D',$account)||($target['account_id']!==''&&$target['account_id']!==$account)
            ||(!str_starts_with($target['username'],'@')&&$target['username']!==$account)) throw new MediaFailure('format','Invalid YouTube checker identity');
    }
    private function readChannel(array $target,?int $mode): array
    {
        $folder=$this->config->storage.'/downloads/checker-'.bin2hex(random_bytes(6));
        if(!is_dir(dirname($folder))) mkdir(dirname($folder),0700,true); mkdir($folder,0700);
        try {
            $command=[dirname($this->config->ytDlp).'/python',dirname(__DIR__).'/bin/youtube-check.py','--channel',$target['account_id']?:$target['username'],...($mode===null?['--profile-only']:['--mode',(string)$mode])];
            if($this->config->youtubeProxy!=='') array_push($command,'--proxy',$this->config->youtubeProxy);
            $jars=YoutubeCheckerMedia::pool($this->config)->order(1);
            if($jars) {
                $content=(string)file_get_contents($jars[0]['path']); validateYouTubeCookies($content);$path=$folder.'/session.txt';
                if(file_put_contents($path,$content)!==strlen($content)||!chmod($path,0600)) throw new MediaFailure('cookies','Cannot prepare checker session');
                array_push($command,'--cookies',$path);
            }
            try{$out=(new Download($this->config))->run($command,$folder,90);}
            catch(MediaFailure $e){$reason=preg_match('/YOUTUBE_CHECK_ERROR (blocked|cookies|restricted|network|format)\b/',$e->getMessage(),$m)?$m[1]:$e->reason;throw new MediaFailure($reason,'YouTube checker scan failed');}
            $data=json_decode($out,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($data)) throw new MediaFailure('format','Invalid YouTube checker snapshot');return $data;
        } finally {Download::clean($folder);}
    }
}
