<?php
declare(strict_types=1);

namespace Zero;
require_once __DIR__.'/Ui.php';
require_once __DIR__.'/CookiePool.php';

final class Config
{
    public const VIDEO_QUALITIES=[360,480,720,1080];
    /** When on, downloads try without cookies first and use a cookie jar only if the site refuses. */
    public bool $cookieFallback=false;

    public function __construct(
        public string $token, public array $admins, public string $storage,
        public string $api = 'https://api.telegram.org', public int $maxBytes = 49000000,
        public string $ytDlp = '/opt/yt/bin/yt-dlp', public int $timeout = 600,
        public string $youtubeCookies = '', public string $youtubeProxy = '', public int $workerConcurrency = 3,
        public string $galleryDl = '/opt/yt/bin/gallery-dl', public string $instagramCookies = '', public string $pinterestCookies = '', public string $tiktokCookies = '', public string $twitterCookies = '',
    ) {}

    public static function load(): self
    {
        // Values are data, never sourced by a shell. Container env takes precedence.
        $path = dirname(__DIR__) . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
                if (!preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m)) continue;
                if (getenv($m[1]) === false) putenv($m[1] . '=' . trim($m[2], " \t\"'"));
            }
        }
        $token = getenv('BOT_TOKEN') ?: '';
        $ids = getenv('ADMIN_IDS') ?: '';
        if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token)) throw new \RuntimeException('BOT_TOKEN is missing or invalid');
        if (!preg_match('/^[1-9]\d*(,[1-9]\d*)*$/', $ids)) throw new \RuntimeException('ADMIN_IDS must contain numeric IDs');
        $max = (int)(getenv('MAX_FILE_BYTES') ?: 49000000);
        $api = rtrim(getenv('BOT_API_URL') ?: 'https://api.telegram.org', '/');
        if ($max < 1000000 || $max > 2000000000 || ($api === 'https://api.telegram.org' && $max > 49000000)) {
            throw new \RuntimeException('Invalid file limit for configured Bot API');
        }
        $proxy=getenv('YOUTUBE_PROXY')?:'';
        $concurrency=getenv('WORKER_CONCURRENCY');
        if($concurrency===false||$concurrency==='') $concurrency='3';
        if(!preg_match('/^[1-8]$/D',$concurrency)) throw new \RuntimeException('WORKER_CONCURRENCY must be between 1 and 8');
        if($proxy!=='') {
            $parts=parse_url($proxy);
            if(!$parts||empty($parts['host'])||!in_array($parts['scheme']??'', ['http','https','socks5','socks5h'],true)) {
                throw new \RuntimeException('YOUTUBE_PROXY must be an HTTP, HTTPS or SOCKS5 URL');
            }
        }
        return new self($token, array_map('intval', explode(',', $ids)),
            getenv('STORAGE_DIR') ?: dirname(__DIR__) . '/storage', $api, $max,
            getenv('YT_DLP_BIN') ?: '/opt/yt/bin/yt-dlp',3600,
            getenv('YOUTUBE_COOKIES_FILE')?:'', $proxy,(int)$concurrency,
            getenv('GALLERY_DL_BIN')?:'/opt/yt/bin/gallery-dl',getenv('INSTAGRAM_COOKIES_FILE')?:'',getenv('PINTEREST_COOKIES_FILE')?:'',getenv('TIKTOK_COOKIES_FILE')?:'',getenv('TWITTER_COOKIES_FILE')?:'');
    }

    public function cookiePath(): string { return $this->youtubeCookies!==''?$this->youtubeCookies:$this->storage.'/youtube-cookies.txt'; }
    public function tiktokCookiePath(): string { return $this->tiktokCookies!==''?$this->tiktokCookies:$this->storage.'/tiktok-cookies.txt'; }
    public function twitterCookiePath(): string { return $this->twitterCookies!==''?$this->twitterCookies:$this->storage.'/twitter-cookies.txt'; }
    public function galleryCookiePath(string $service): string { return match($service) {'instagram'=>$this->instagramCookiePath(),'pinterest'=>$this->pinterestCookiePath(),'tiktok'=>$this->tiktokCookiePath(),'twitter'=>$this->twitterCookiePath(),default=>throw new \InvalidArgumentException('Unknown media service')}; }
    public function pinterestCookiePath(): string { return $this->pinterestCookies!==''?$this->pinterestCookies:$this->storage.'/pinterest-cookies.txt'; }
    public function instagramCookiePath(): string { return $this->instagramCookies!==''?$this->instagramCookies:$this->storage.'/instagram-cookies.txt'; }
}

// Validate the export without including session contents in errors or logs.
function validateYouTubeCookies(string $content): void
{
    if(strlen($content)>1048576||!preg_match('/^# (?:Netscape )?HTTP Cookie File/', $content)) {
        throw new \RuntimeException('Expected a Netscape cookies.txt export smaller than 1 MB');
    }
    $youtube=false;
    foreach(preg_split('/\r?\n/',$content) as $line) {
        if(str_starts_with($line,'#HttpOnly_')) $line=substr($line,10);
        elseif($line===''||str_starts_with($line,'#')) continue;
        $fields=explode("\t",$line);
        if(count($fields)!==7||!ctype_digit($fields[4])||!in_array($fields[1],['TRUE','FALSE'],true)
            ||!in_array($fields[3],['TRUE','FALSE'],true)) throw new \RuntimeException('Invalid Netscape cookie row');
        $domain=ltrim(strtolower($fields[0]),'.');
        if($domain==='youtube.com'||str_ends_with($domain,'.youtube.com')) $youtube=true;
        elseif($domain!=='google.com'&&!str_ends_with($domain,'.google.com')) {
            throw new \RuntimeException('Export only YouTube cookies and related google.com cookies');
        }
    }
    if(!$youtube) throw new \RuntimeException('No youtube.com cookies found in this export');
}

// Google identifies the signed-in account by its session cookies. Exporting the same account again replaces its jar.
function youtubeCookieIdentity(string $content): string
{
    $found=[];
    foreach(preg_split('/\r?\n/',$content) as $line) {
        if(str_starts_with($line,'#HttpOnly_')) $line=substr($line,10);
        elseif($line===''||str_starts_with($line,'#')) continue;
        $fields=explode("\t",$line);
        if(count($fields)===7&&in_array($fields[5],['__Secure-3PSID','__Secure-1PSID','SAPISID','SID'],true)&&$fields[6]!=='') $found[$fields[5]]=$fields[6];
    }
    foreach(['__Secure-3PSID','__Secure-1PSID','SAPISID','SID'] as $name) if(isset($found[$name])) return $name.'='.$found[$name];
    return '';
}

function youtubeCookiePool(Config $config): CookiePool
{
    return new CookiePool($config->cookiePath(),'Zero\\validateYouTubeCookies','Zero\\youtubeCookieIdentity','youtube.com');
}

// An expected hash makes downloader refreshes lose to a newer administrator import.
// The destination is one jar of the pool; all jars share the pool lock.
function saveYouTubeCookies(Config $config, string $content, ?string $expectedHash = null, ?string $path = null): bool
{
    validateYouTubeCookies($content);
    if(!is_dir($config->storage)&&!mkdir($config->storage,0700,true)) throw new \RuntimeException('Cannot create storage');
    $destination=$path??$config->cookiePath();
    if(!is_dir(dirname($destination))&&!mkdir(dirname($destination),0700,true)) throw new \RuntimeException('Cannot create cookie directory');
    $lockFile=$config->cookiePath().'.lock';
    $lock=fopen($lockFile,'c');
    if(!$lock) throw new \RuntimeException('Cannot lock cookie file');
    chmod($lockFile,0600);
    $temporary=dirname($destination).'/.cookies-import-'.bin2hex(random_bytes(6));
    try {
        if(!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock cookie file');
        if($expectedHash!==null&&(!is_file($destination)||!hash_equals($expectedHash,hash_file('sha256',$destination)))) return false;
        $handle=fopen($temporary,'x');
        if($handle===false) throw new \RuntimeException('Cannot create cookie import file');
        try {
            if(!chmod($temporary,0600)||fwrite($handle,$content)!==strlen($content)||!fflush($handle)) {
                throw new \RuntimeException('Cannot save cookie export');
            }
        } finally { fclose($handle); }
        if(!rename($temporary,$destination)) throw new \RuntimeException('Cannot replace cookie file');
        clearstatcache(true,$destination);
        return true;
    } finally {
        if(is_file($temporary)) unlink($temporary);
        flock($lock,LOCK_UN); fclose($lock);
    }
}

function deleteYouTubeCookies(Config $config,string $expectedHash): bool
{
    $path=$config->cookiePath(); $lock=fopen($path.'.lock','c');
    if(!$lock) throw new \RuntimeException('Cannot lock cookie file');
    chmod($path.'.lock',0600);
    try {
        if(!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock cookie file');
        clearstatcache(true,$path);
        if(!is_file($path)||!hash_equals($expectedHash,hash_file('sha256',$path))) return false;
        if(!unlink($path)) throw new \RuntimeException('Cannot delete cookie file');
        return true;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

interface Api { public function call(string $method, array $params = []): mixed; }
interface FileApi extends Api { public function downloadFile(string $filePath, int $maxBytes): string; }

// Keep useful diagnostics without retaining bot tokens, signed media URLs or ANSI escapes.
function diagnosticMessage(string $message): string
{
    $message=preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $message)??'';
    $message=preg_replace('~(?:https?|socks5h?)://[^\s<>]+~i', '[url]', $message)??'';
    $message=preg_replace('/\b\d+:[A-Za-z0-9_-]{10,}\b/', '[token]', $message)??'';
    return mb_substr(trim(preg_replace('/\s+/u',' ', $message)??''),0,1200);
}

final class Telegram implements FileApi
{
    public function __construct(private Config $config) {}
    public function call(string $method, array $params = []): mixed
    {
        foreach ($params as &$value) {
            if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        unset($value);
        $ch = curl_init($this->config->api . '/bot' . $this->config->token . '/' . $method);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $method==='setMessageReaction'?2:15,
            CURLOPT_TIMEOUT => $method==='setMessageReaction'?3:(in_array($method,['sendVideo','sendAudio','sendPhoto'],true)||($method==='editMessageMedia'&&array_filter($params,fn($value)=>$value instanceof \CURLFile||$value instanceof \CURLStringFile)) ? 3600 : 45)]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($body === false) throw new \RuntimeException('Telegram transport error ' . $errno);
        $result = json_decode($body, true);
        if (!($result['ok'] ?? false)) {
            if(str_starts_with($method,'editMessage')&&str_contains((string)($result['description']??''),'message is not modified')) return true;
            if(str_starts_with($method,'editMessage')&&str_contains((string)($result['description']??''),'message to edit not found')) {
                throw new \RuntimeException('Telegram message is unavailable',404);
            }
            // Raw API errors may include credentials/URLs. Log only numeric status.
            throw new \RuntimeException('Telegram API error ' . ($result['error_code'] ?? 0),(int)($result['error_code']??0));
        }
        return $result['result'];
    }

    public function downloadFile(string $filePath, int $maxBytes): string
    {
        if($this->config->api==='http://ciro-telegram-api:8081'&&str_starts_with($filePath,'/telegram-api/')) {
            $root=realpath('/telegram-api'); $path=realpath($filePath);
            if($maxBytes<1||$root===false||$path===false||!str_starts_with($path,$root.'/')||!is_file($path)||!is_readable($path)) {
                throw new \RuntimeException('Invalid Telegram file path');
            }
            $handle=fopen($path,'rb');
            if($handle===false) throw new \RuntimeException('Telegram file download failed');
            try { $content=stream_get_contents($handle,$maxBytes+1); }
            finally { fclose($handle); }
            if($content===false) throw new \RuntimeException('Telegram file download failed');
            if(strlen($content)>$maxBytes) throw new \RuntimeException('Telegram file exceeds import size limit');
            return $content;
        }
        if($maxBytes<1||!preg_match('~^[A-Za-z0-9_./-]+$~D',$filePath)
            ||str_starts_with($filePath,'/')||in_array('..',explode('/',$filePath),true)) {
            throw new \RuntimeException('Invalid Telegram file path');
        }
        $content=''; $tooLarge=false;
        $ch=curl_init($this->config->api.'/file/bot'.$this->config->token.'/'.$filePath);
        curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$content,&$tooLarge,$maxBytes): int {
                if(strlen($content)+strlen($chunk)>$maxBytes) { $tooLarge=true; return 0; }
                $content.=$chunk;
                return strlen($chunk);
            }]);
        $ok=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($tooLarge) throw new \RuntimeException('Telegram file exceeds import size limit');
        if($ok===false||$status!==200) throw new \RuntimeException('Telegram file download failed');
        return $content;
    }
}

final class Store
{
    public \PDO $db;
    public function __construct(string $directory)
    {
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        $this->db = new \PDO('sqlite:' . $directory . '/zero.sqlite', null, null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
        $this->db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS states (user_id INTEGER PRIMARY KEY, key TEXT NOT NULL, expires INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS help_messages (chat_id INTEGER NOT NULL,thread_id INTEGER NOT NULL DEFAULT 0,
                message_id INTEGER NOT NULL,groups_enabled INTEGER NOT NULL,PRIMARY KEY(chat_id,thread_id));
            CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, update_id INTEGER UNIQUE NOT NULL,
                user_id INTEGER NOT NULL, chat_id INTEGER NOT NULL, url TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'queued', created INTEGER NOT NULL, message_id INTEGER,
                phase TEXT NOT NULL DEFAULT 'download', quality INTEGER, options TEXT NOT NULL DEFAULT '{}', title TEXT NOT NULL DEFAULT '', expires INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS update_requests (id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL, chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL DEFAULT 0,
                action TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued', created INTEGER NOT NULL);
            CREATE UNIQUE INDEX IF NOT EXISTS one_update ON update_requests((1)) WHERE status IN ('queued','running');
            CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, last_request INTEGER NOT NULL DEFAULT 0);");
        $this->db->exec("CREATE TABLE IF NOT EXISTS user_profiles (user_id INTEGER PRIMARY KEY,username TEXT NOT NULL DEFAULT '',updated INTEGER NOT NULL);
            CREATE UNIQUE INDEX IF NOT EXISTS profile_username ON user_profiles(username) WHERE username<>'';
            CREATE TABLE IF NOT EXISTS bot_admins (user_id INTEGER PRIMARY KEY,added_by INTEGER NOT NULL,created INTEGER NOT NULL);");
        // NULL is a legacy job; 0 waits for its initial notice; >0 is ready to edit.
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $columns=array_column($this->query('PRAGMA table_info(jobs)')->fetchAll(),'name');
            if(!in_array('message_id',$columns,true)) $this->db->exec('ALTER TABLE jobs ADD COLUMN message_id INTEGER');
            foreach(['phase'=>"TEXT NOT NULL DEFAULT 'download'",'quality'=>'INTEGER','options'=>"TEXT NOT NULL DEFAULT '{}'",'title'=>"TEXT NOT NULL DEFAULT ''",'expires'=>'INTEGER NOT NULL DEFAULT 0','message_kind'=>"TEXT NOT NULL DEFAULT 'text'",'poster'=>"TEXT NOT NULL DEFAULT ''",'thread_id'=>'INTEGER NOT NULL DEFAULT 0','run_token'=>'INTEGER NOT NULL DEFAULT 0'] as $column=>$type) {
                if(!in_array($column,$columns,true)) $this->db->exec('ALTER TABLE jobs ADD COLUMN '.$column.' '.$type);
            }
            if(!in_array('thread_id',array_column($this->query('PRAGMA table_info(update_requests)')->fetchAll(),'name'),true)) $this->db->exec('ALTER TABLE update_requests ADD COLUMN thread_id INTEGER NOT NULL DEFAULT 0');
            // Limits are configurable and checked under BEGIN IMMEDIATE. A unique
            // per-user index would reject the second job even with limits off.
            $index=$this->query("SELECT sql FROM sqlite_master WHERE type='index' AND name='one_active'")->fetchColumn();
            if($index&&(str_contains(strtoupper($index),'UNIQUE')||str_contains($index,'choosing')||!str_contains($index,'confirming'))) $this->db->exec('DROP INDEX one_active');
            // Retain the old name so a rollback does not try to recreate a unique
            // index while this version has several active jobs for one user.
            $this->db->exec("CREATE INDEX IF NOT EXISTS one_active ON jobs(user_id,chat_id) WHERE status IN ('queued','running','confirming')");
            $this->query("UPDATE jobs SET status='waiting_quality',expires=0 WHERE status='choosing'");
            $this->query("UPDATE states SET expires=0 WHERE key='cookie.upload'");
            if($this->get('ui_cookie_panel_v1','0')!=='1') {
                $saved=$this->get('button.cookies');
                if(in_array($saved,['🍪 ایمپورت کوکی یوتیوب','ایمپورت کوکی یوتیوب','واردکردن کوکی یوتیوب','🍪 واردکردن کوکی یوتیوب'],true)) $this->query('DELETE FROM settings WHERE key=?',['button.cookies']);
                $this->set('ui_cookie_panel_v1','1');
            }
            if($this->get('ui_html_version','0')!=='1') {
                // Captions in older releases were sent as plaintext, even if they
                // contained angle brackets. Preserve that appearance when enabling HTML.
                $caption=$this->query("SELECT value FROM settings WHERE key='text.video_caption'")->fetchColumn();
                if($caption!==false) $this->set('text.video_caption',Ui::escape((string)$caption));
                $this->set('ui_html_version','1');
            }
            if($this->get('ui_delivery_v2','0')!=='1') {
                // Only retire known historical defaults. Deliberate custom text survives.
                foreach(Ui::definitions()['texts'] as $key=>$definition) {
                    $legacy=$definition['legacy_values']??[];
                    if(!$legacy) continue;
                    $saved=$this->query('SELECT value FROM settings WHERE key=?',['text.'.$key])->fetchColumn();
                    $known=array_merge($legacy,array_map([Ui::class,'escape'],$legacy));
                    if($saved!==false&&in_array($saved,$known,true)) $this->query('DELETE FROM settings WHERE key=?',['text.'.$key]);
                }
                $this->set('ui_delivery_v2','1');
            }
            if($this->get('ui_instagram_source_v1','0')!=='1') {
                foreach(['instagram_caption','instagram_page_caption'] as $key) {
                    $legacy=Ui::definitions()['texts'][$key]['legacy_values']??[];
                    $saved=$this->query('SELECT value FROM settings WHERE key=?',['text.'.$key])->fetchColumn();
                    if($saved!==false&&in_array($saved,array_merge($legacy,array_map([Ui::class,'escape'],$legacy)),true)) $this->query('DELETE FROM settings WHERE key=?',['text.'.$key]);
                }
                $this->set('ui_instagram_source_v1','1');
            }
            $this->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function query(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->prepare($sql); $q->execute($args); return $q;
    }
    public function get(string $key, string $default = ''): string
    {
        $v = $this->query('SELECT value FROM settings WHERE key=?', [$key])->fetchColumn();
        return $v === false ? $default : (string)$v;
    }
    public function set(string $key, string $value): void
    {
        $this->query('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key,$value]);
    }
    public function enabled(): bool { return $this->get('enabled', '1') === '1'; }
    public function observeUser(array $profile): void
    {
        $id=(int)($profile['id']??0);
        if($id<1||!empty($profile['is_bot'])) return;
        $username=(string)($profile['username']??'');
        $username=preg_match('/^[A-Za-z0-9_]{1,32}$/D',$username)?strtolower($username):'';
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if($username!=='') $this->query("UPDATE user_profiles SET username='' WHERE username=? AND user_id<>?",[$username,$id]);
            $this->query('INSERT INTO user_profiles(user_id,username,updated) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET username=excluded.username,updated=excluded.updated',[$id,$username,time()]);
            $this->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function isAdmin(int $user,array $roots): bool
    {
        if(in_array($user,$roots,true)) return true;
        if(!$roots) return false;
        // PDO execute-array values are strings; CTE values have no column affinity.
        $seeds=implode(',',array_fill(0,count($roots),'(CAST(? AS INTEGER))'));
        return (bool)$this->query('WITH RECURSIVE authorized(id) AS (VALUES '.$seeds.' UNION SELECT a.user_id FROM bot_admins a JOIN authorized p ON a.added_by=p.id) SELECT 1 FROM authorized WHERE id=CAST(? AS INTEGER)',array_merge($roots,[$user]))->fetchColumn();
    }
    public function addAdministrator(int $user,int $actor,array $roots): bool
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if($user<1||!$this->isAdmin($actor,$roots)) throw new \RuntimeException('Administrator access changed');
            $added=!$this->isAdmin($user,$roots);
            if($added) $this->query('INSERT INTO bot_admins(user_id,added_by,created) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET added_by=excluded.added_by,created=excluded.created',[$user,$actor,time()]);
            $this->db->exec('COMMIT'); return $added;
        } catch(\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function groupsEnabled(): bool { return $this->get('groups_enabled','1')==='1'; }
    public function enqueue(int $update, int $user, int $chat, string $url,int $thread=0): string
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($this->get('update_in_progress','0')==='1') $result = 'updating';
            elseif ($this->query('SELECT 1 FROM jobs WHERE update_id=?', [$update])->fetchColumn()) $result = 'duplicate';
            elseif ($this->busy($user,$chat)) $result = 'busy';
            elseif ($this->full()) $result = 'full';
            elseif ((int)$this->query('SELECT last_request FROM users WHERE id=?', [$user])->fetchColumn() > time()-15
                &&!$this->query("SELECT 1 FROM jobs WHERE user_id=? AND status IN ('waiting_quality','queued','running','confirming')",[$user])->fetchColumn()) $result = 'cooldown';
            else {
                $this->query("INSERT INTO jobs(update_id,user_id,chat_id,url,created,message_id,phase,thread_id) VALUES(?,?,?,?,?,0,?,?)", [$update,$user,$chat,$url,time(),galleryService($url)??'inspect',$chat<0?max(0,$thread):0]);
                $this->query('UPDATE users SET last_request=? WHERE id=?', [time(),$user]);
                $result = 'queued';
            }
            $this->db->exec('COMMIT'); return $result;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function claim(?string $lane=null): ?array
    {
        if(!in_array($lane,[null,'inspect','media'],true)) throw new \InvalidArgumentException('Unknown worker lane');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            // Serve chosen media before fresh inspections, but promote an
            // inspection after 30 seconds so a busy download queue cannot starve it.
            $filter=$lane==='inspect'?" AND phase='inspect'":($lane==='media'?" AND phase<>'inspect'":'');
            $job = $this->get('update_in_progress','0')==='1'?false:$this->query("SELECT * FROM jobs WHERE status='queued' AND (message_id IS NULL OR message_id>0)".$filter." ORDER BY CASE WHEN phase<>'inspect' OR created<=? THEN 0 ELSE 1 END,id LIMIT 1",[time()-30])->fetch();
            if ($job) {
                $job['run_token']=(int)$job['run_token']+1;
                $this->query("UPDATE jobs SET status='running',run_token=? WHERE id=?",[$job['run_token'],$job['id']]);
            }
            $this->db->exec('COMMIT'); return $job ?: null;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function choose(int $id,int $user,int $chat,int|string|null $quality): ?array
    {
        if($quality!==null&&$quality!=='audio'&&!in_array($quality,Config::VIDEO_QUALITIES,true)) return null;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $job=$this->query("SELECT * FROM jobs WHERE id=? AND user_id=? AND chat_id=? AND status='waiting_quality'",[$id,$user,$chat])->fetch();
            $options=$job?json_decode($job['options'],true):[];
            $audio=$quality==='audio';
            if(!$job||($quality!==null&&(empty($options[(string)$quality])||$this->busy($user,$chat)||$this->full()))) $job=null;
            if($job) $this->query('UPDATE jobs SET status=?,quality=?,phase=?,expires=? WHERE id=?',[$quality===null?'cancelled':'confirming',$audio?null:$quality,$audio?'audio':'download',time()+120,$id]);
            $this->db->exec('COMMIT');
            return $job?:null;
        } catch(\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function processingLimit(int $chat): int
    {
        return max(0,min(20,(int)$this->get($chat<0?'processing_limit.group':'processing_limit.private','0')));
    }
    public function busy(int $user,?int $chat=null): bool
    {
        $chat??=$user;
        $limit=$this->processingLimit($chat);
        return $limit>0&&(int)$this->query("SELECT COUNT(*) FROM jobs WHERE user_id=? AND chat_id=? AND status IN ('queued','running','confirming')",[$user,$chat])->fetchColumn()>=$limit;
    }
    public function full(): bool
    {
        return (int)$this->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running','confirming')")->fetchColumn()>=20;
    }
}

function youtubeUrl(string $input): ?string
{
    $input = trim($input);
    if (strlen($input)>2048 || preg_match('/\s/u', $input)) return null;
    if (preg_match('~^(www\.)?(youtube\.com|youtu\.be)/~', $input)) $input = 'https://' . $input;
    $u = parse_url($input);
    if (!$u || !in_array($u['scheme'] ?? '', ['http','https'], true) || isset($u['user']) || isset($u['pass']) || isset($u['port'])) return null;
    $id = '';
    if (($u['host'] ?? '') === 'youtu.be') $id = trim($u['path'] ?? '', '/');
    elseif (in_array($u['host'] ?? '', ['youtube.com','www.youtube.com','m.youtube.com','music.youtube.com'], true)) {
        if (($u['path'] ?? '') === '/watch') { parse_str($u['query'] ?? '', $q); $id = $q['v'] ?? ''; }
        elseif (preg_match('~^/(shorts|embed|live)/([\w-]{11})/?$~', $u['path'] ?? '', $m)) $id = $m[2];
    }
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) ? 'https://www.youtube.com/watch?v='.$id : null;
}

function instagramUrl(string $input): ?string
{
    $input=trim($input);
    if(strlen($input)>2048||preg_match('/\s/u',$input)) return null;
    if(preg_match('~^(?:www\.)?instagram\.com/~i',$input)) $input='https://'.$input;
    $parts=parse_url($input);
    if(!$parts||!in_array(strtolower($parts['scheme']??''),['https','http'],true)
        ||!in_array(strtolower($parts['host']??''),['instagram.com','www.instagram.com'],true)
        ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])
        ||!preg_match('~^/(p|reel|reels|tv)/([A-Za-z0-9_-]{5,64})/?$~D',$parts['path']??'',$match)) return null;
    return 'https://www.instagram.com/'.($match[1]==='reels'?'reel':$match[1]).'/'.$match[2].'/';
}

function pinterestUrl(string $input): ?string
{
    $input=trim($input);
    if(strlen($input)>2048||preg_match('/\s/u',$input)) return null;
    if(!str_contains($input,'://')) $input='https://'.$input;
    $parts=parse_url($input);
    if(!$parts||!in_array(strtolower($parts['scheme']??''),['https','http'],true)||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])) return null;
    $host=strtolower($parts['host']??''); $path=$parts['path']??'';
    if($host==='pin.it') return preg_match('~^/([A-Za-z0-9]{1,64})/?$~D',$path,$match)?'https://pin.it/'.$match[1]:null;
    static $domains=null;
    $domains??=json_decode((string)file_get_contents(dirname(__DIR__).'/resources/pinterest-domains.json'),true,512,JSON_THROW_ON_ERROR);
    $host=preg_replace('/^(?:www|[a-z]{2})\./','',$host);
    if(!in_array($host,array_map(fn($suffix)=>'pinterest.'.$suffix,$domains),true)||!preg_match('~^/pin/(?:[^/?#]+--)?([0-9]{1,30})(?:/sent)?/?$~D',$path,$match)) return null;
    return 'https://www.pinterest.com/pin/'.$match[1].'/';
}

function tiktokUrl(string $input): ?string
{
    $input=trim($input);
    if(strlen($input)>2048||preg_match('/\s/u',$input)) return null;
    if(!str_contains($input,'://')) $input='https://'.$input;
    $parts=parse_url($input);
    if(!$parts||!in_array(strtolower($parts['scheme']??''),['http','https'],true)||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])) return null;
    $host=strtolower($parts['host']??''); $path=$parts['path']??'';
    if(in_array($host,['vm.tiktok.com','vt.tiktok.com'],true)) return preg_match('~^/([A-Za-z0-9_-]{1,64})/?$~D',$path,$match)?'https://'.$host.'/'.$match[1].'/':null;
    if(!in_array($host,['tiktok.com','www.tiktok.com','m.tiktok.com'],true)) return null;
    if(preg_match('~^/t/([A-Za-z0-9_-]{1,64})/?$~D',$path,$match)) return 'https://www.tiktok.com/t/'.$match[1].'/';
    if(!preg_match('~^/(?:@([A-Za-z0-9_.-]{0,64})|share)/(video|photo)/([0-9]{1,30})/?$~D',$path,$match)) return null;
    return 'https://www.tiktok.com/@'.$match[1].'/'.$match[2].'/'.$match[3];
}

function twitterUrl(string $input): ?string
{
    $input=trim($input);
    if(strlen($input)>2048||preg_match('/\s/u',$input)) return null;
    if(!str_contains($input,'://')) $input='https://'.$input;
    $parts=parse_url($input);
    if(!$parts||!in_array(strtolower($parts['scheme']??''),['http','https'],true)||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])
        ||!in_array(strtolower($parts['host']??''),['x.com','www.x.com','mobile.x.com','twitter.com','www.twitter.com','mobile.twitter.com','m.twitter.com'],true)) return null;
    if(!preg_match('~^/(?:([A-Za-z0-9_]{1,15})|i/web)/status/([0-9]{1,30})(?:/(?:photo|video)/[1-9][0-9]?)?/?$~D',$parts['path']??'',$match)) return null;
    return 'https://x.com/'.(($match[1]??'')==='i'||empty($match[1])?'i/web':$match[1]).'/status/'.$match[2];
}

const GALLERY_PREFIXES=['instagram'=>'ig','pinterest'=>'pin','tiktok'=>'tt','twitter'=>'x'];
function galleryServices(): array { return array_keys(GALLERY_PREFIXES); }
function galleryPrefix(string $service): string { return GALLERY_PREFIXES[$service]??throw new \InvalidArgumentException('Unknown media service'); }
function galleryFromPrefix(string $prefix): ?string { $service=array_search($prefix,GALLERY_PREFIXES,true); return $service===false?null:$service; }
function galleryUrl(string $service,string $input): ?string { return match($service) {'instagram'=>instagramUrl($input),'pinterest'=>pinterestUrl($input),'tiktok'=>tiktokUrl($input),'twitter'=>twitterUrl($input),default=>null}; }
function galleryService(string $input): ?string { foreach(galleryServices() as $service) if(galleryUrl($service,$input)) return $service; return null; }
function mediaUrl(string $input): ?string { return youtubeUrl($input)??instagramUrl($input)??pinterestUrl($input)??tiktokUrl($input)??twitterUrl($input); }

function lockProcess(string $path): mixed
{
    $lock = fopen($path, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Another instance is already running');
    return $lock; // Caller retains handle for lifetime of process.
}

