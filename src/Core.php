<?php
declare(strict_types=1);

namespace Zero;

final class Config
{
    public function __construct(
        public string $token, public array $admins, public string $storage,
        public string $api = 'https://api.telegram.org', public int $maxBytes = 49000000,
        public string $ytDlp = '/opt/yt/bin/yt-dlp', public int $timeout = 600,
        public string $youtubeCookies = '', public string $youtubeProxy = '',
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
        if($proxy!=='') {
            $parts=parse_url($proxy);
            if(!$parts||empty($parts['host'])||!in_array($parts['scheme']??'', ['http','https','socks5','socks5h'],true)) {
                throw new \RuntimeException('YOUTUBE_PROXY must be an HTTP, HTTPS or SOCKS5 URL');
            }
        }
        return new self($token, array_map('intval', explode(',', $ids)),
            getenv('STORAGE_DIR') ?: dirname(__DIR__) . '/storage', $api, $max,
            getenv('YT_DLP_BIN') ?: '/opt/yt/bin/yt-dlp',600,
            getenv('YOUTUBE_COOKIES_FILE')?:'', $proxy);
    }

    public function cookiePath(): string { return $this->youtubeCookies!==''?$this->youtubeCookies:$this->storage.'/youtube-cookies.txt'; }
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

// An expected hash makes downloader refreshes lose to a newer administrator import.
function saveYouTubeCookies(Config $config, string $content, ?string $expectedHash = null): bool
{
    validateYouTubeCookies($content);
    if(!is_dir($config->storage)&&!mkdir($config->storage,0700,true)) throw new \RuntimeException('Cannot create storage');
    $destination=$config->cookiePath();
    $lock=fopen($destination.'.lock','c');
    if(!$lock) throw new \RuntimeException('Cannot lock cookie file');
    chmod($destination.'.lock',0600);
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
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $method === 'sendVideo' ? 600 : 45]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($body === false) throw new \RuntimeException('Telegram transport error ' . $errno);
        $result = json_decode($body, true);
        if (!($result['ok'] ?? false)) {
            if($method==='editMessageText'&&str_contains((string)($result['description']??''),'message is not modified')) return true;
            if($method==='editMessageText'&&str_contains((string)($result['description']??''),'message to edit not found')) {
                throw new \RuntimeException('Telegram message is unavailable',404);
            }
            // Raw API errors may include credentials/URLs. Log only numeric status.
            throw new \RuntimeException('Telegram API error ' . ($result['error_code'] ?? 0));
        }
        return $result['result'];
    }

    public function downloadFile(string $filePath, int $maxBytes): string
    {
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
            CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, update_id INTEGER UNIQUE NOT NULL,
                user_id INTEGER NOT NULL, chat_id INTEGER NOT NULL, url TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'queued', created INTEGER NOT NULL, message_id INTEGER);
            CREATE UNIQUE INDEX IF NOT EXISTS one_active ON jobs(user_id) WHERE status IN ('queued','running');
            CREATE TABLE IF NOT EXISTS update_requests (id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL, chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL DEFAULT 0,
                action TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued', created INTEGER NOT NULL);
            CREATE UNIQUE INDEX IF NOT EXISTS one_update ON update_requests((1)) WHERE status IN ('queued','running');
            CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, last_request INTEGER NOT NULL DEFAULT 0);");
        // NULL is a legacy job; 0 waits for its initial notice; >0 is ready to edit.
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $columns=array_column($this->query('PRAGMA table_info(jobs)')->fetchAll(),'name');
            if(!in_array('message_id',$columns,true)) $this->db->exec('ALTER TABLE jobs ADD COLUMN message_id INTEGER');
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
    public function enqueue(int $update, int $user, int $chat, string $url): string
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($this->get('update_in_progress','0')==='1') $result = 'updating';
            elseif ($this->query('SELECT 1 FROM jobs WHERE update_id=?', [$update])->fetchColumn()) $result = 'duplicate';
            elseif ($this->query("SELECT 1 FROM jobs WHERE user_id=? AND status IN ('queued','running')", [$user])->fetchColumn()) $result = 'busy';
            elseif ((int)$this->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running')")->fetchColumn() >= 20) $result = 'full';
            elseif ((int)$this->query('SELECT last_request FROM users WHERE id=?', [$user])->fetchColumn() > time()-15) $result = 'cooldown';
            else {
                $this->query('INSERT INTO jobs(update_id,user_id,chat_id,url,created,message_id) VALUES(?,?,?,?,?,0)', [$update,$user,$chat,$url,time()]);
                $this->query('UPDATE users SET last_request=? WHERE id=?', [time(),$user]);
                $result = 'queued';
            }
            $this->db->exec('COMMIT'); return $result;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function claim(): ?array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $job = $this->get('update_in_progress','0')==='1'?false:$this->query("SELECT * FROM jobs WHERE status='queued' AND (message_id IS NULL OR message_id>0) ORDER BY id LIMIT 1")->fetch();
            if ($job) $this->query("UPDATE jobs SET status='running' WHERE id=?", [$job['id']]);
            $this->db->exec('COMMIT'); return $job ?: null;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
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

function lockProcess(string $path): mixed
{
    $lock = fopen($path, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Another instance is already running');
    return $lock; // Caller retains handle for lifetime of process.
}
