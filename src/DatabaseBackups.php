<?php
declare(strict_types=1);
namespace Zero;

/** Database-only exports; replacement is delegated to the existing locked host agent. */
final class DatabaseBackups
{
    public const MAX_ZIP=20*1024*1024, MAX_DATABASE=256*1024*1024;
    public function __construct(private Bot $bot)
    {
        $bot->store->db->exec('CREATE TABLE IF NOT EXISTS database_restore_files(request_id INTEGER PRIMARY KEY,token TEXT NOT NULL,sha256 TEXT NOT NULL)');
    }
    private function allowed(int $user,int $chat): bool { return $user===$chat&&$this->bot->admin($user); }
    private function menu(int $user): ?array
    {
        $p=json_decode($this->bot->store->get('database.menu.'.$user),true);
        return is_array($p)&&($p['expires']??0)>time()?$p:null;
    }
    private function button(string $key,array $p,string $op,array $values=[]): array { return $this->bot->ui->button($key,'dbb:'.$p['token'].':'.$op,$values); }
    public function open(int $user,int $chat,bool $import=false): void
    {
        if(!$this->allowed($user,$chat)) return;
        $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        $p=['token'=>bin2hex(random_bytes(8)),'message'=>0,'expires'=>time()+3600];
        $this->show($user,$p);
        if($import) $this->begin($user,$p,'import');
    }
    private function show(int $user,array &$p,string $notice=''): void
    {
        $s=$this->bot->store; $ui=$this->bot->ui;
        $on=$s->get('database.backup.enabled','0')==='1';
        $text=$ui->text('database_backup_panel',['state'=>$ui->plain($on?'switch_on':'switch_off'),'minutes'=>$s->get('database.backup.minutes','60')]);
        $rows=[[$this->button('database_backup_toggle',$p,'toggle',['state'=>$ui->plain($on?'switch_on':'switch_off')])],
            [$this->button('database_backup_interval',$p,'interval'),$this->button('database_backup_now',$p,'now')],
            [$this->button('database_backup_import',$p,'import')],[$this->button('back_admin',$p,'back'),$this->button('update_close',$p,'close')]];
        $this->view($user,$p,($notice!==''?$notice."\n\n":'').$text,$rows);
    }
    private function view(int $user,array &$p,string $text,array $rows): void
    {
        $p['message']=$this->bot->send($user,$text,$rows,(int)$p['message']);
        if($p['message']<1) throw new \RuntimeException('Missing backup menu confirmation');
        $p['expires']=time()+3600;
        $this->bot->store->set('database.menu.'.$user,json_encode($p,JSON_THROW_ON_ERROR));
    }
    private function begin(int $user,array &$p,string $kind): void
    {
        if($kind==='import'&&time()-(int)$this->bot->store->get('updater_heartbeat','0')>60) { $this->show($user,$p,$this->bot->text('database_restore_agent')); return; }
        $this->view($user,$p,$this->bot->text('database_backup_'.$kind.'_prompt'),[[$this->button('back_admin',$p,'menu'),$this->button('update_close',$p,'close')]]);
        $this->bot->store->query('INSERT INTO states(user_id,key,expires) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET key=excluded.key,expires=excluded.expires',[$user,'database.'.$kind.':'.$p['token'],time()+600]);
    }
    public function callback(array $cb,int $user,int $chat): void
    {
        $p=$this->menu($user); $parts=explode(':',(string)$cb['data']);
        $owns=$this->allowed($user,$chat)&&$p&&($parts[1]??'')===$p['token']&&(int)($cb['message']['message_id']??0)===(int)$p['message'];
        $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$owns?'':$this->bot->ui->plain('callback_stale'),'show_alert'=>!$owns,'cache_time'=>0]);
        if(!$owns) return;
        $s=$this->bot->store; $op=$parts[2]??'';
        if(!in_array($op,['toggle','interval','now','import','menu','back','close'],true)) return;
        $s->query('DELETE FROM states WHERE user_id=?',[$user]);
        if($op==='close'||$op==='back') {
            $s->set('database.menu.'.$user,'');
            if($op==='back') $this->bot->panel($chat,(int)$p['message']);
            else $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$p['message']]);
            return;
        }
        if($op==='interval'||$op==='import') { $this->begin($user,$p,$op); return; }
        if($op==='toggle') {
            $on=$s->get('database.backup.enabled','0')!=='1';
            $s->db->exec('BEGIN IMMEDIATE');
            try { $s->set('database.backup.enabled',$on?'1':'0'); $s->set('database.backup.chat',(string)$user); $s->set('database.backup.next',(string)($on?time():0)); $s->db->exec('COMMIT'); }
            catch(\Throwable $e) { $s->db->exec('ROLLBACK'); throw $e; }
        }
        if($op==='now') $s->set('database.backup.now.'.$user,(string)time());
        $this->show($user,$p,$op==='now'?$this->bot->text('database_backup_queued'):'');
    }
    public function receive(int $user,int $chat,array $message,string $state): void
    {
        if(!$this->allowed($user,$chat)) return;
        $p=$this->menu($user);
        if(!$p||!preg_match('/^database\.(interval|import):([a-f0-9]{16})$/D',$state,$m)||$m[2]!==$p['token']) return;
        $s=$this->bot->store;
        if($m[1]==='interval') {
            $value=strtr(trim((string)($message['text']??'')),array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
            if(!preg_match('/^[0-9]{1,5}$/D',$value)||(int)$value<1||(int)$value>10080) { $this->bot->send($chat,$this->bot->text('database_backup_interval_invalid')); return; }
            $s->db->exec('BEGIN IMMEDIATE');
            try { $s->set('database.backup.minutes',(string)(int)$value); $s->set('database.backup.next',(string)(time()+(int)$value*60)); $s->query('DELETE FROM states WHERE user_id=?',[$user]); $s->db->exec('COMMIT'); }
            catch(\Throwable $e) { $s->db->exec('ROLLBACK'); throw $e; }
            $this->show($user,$p); return;
        }
        if(empty($message['document'])) { $this->bot->send($chat,$this->bot->text('database_backup_import_prompt')); return; }
        $path='';
        try {
            $doc=$message['document'];
            if(strtolower(pathinfo((string)($doc['file_name']??''),PATHINFO_EXTENSION))!=='zip'||(int)($doc['file_size']??0)>self::MAX_ZIP||empty($doc['file_id'])||!($this->bot->api instanceof FileApi)) throw new \InvalidArgumentException();
            $file=$this->bot->api->call('getFile',['file_id'=>$doc['file_id']]);
            if(empty($file['file_path'])||(int)($file['file_size']??0)>self::MAX_ZIP) throw new \InvalidArgumentException();
            $bytes=$this->bot->api->downloadFile($file['file_path'],self::MAX_ZIP);
            $token=bin2hex(random_bytes(16)); $path=$this->directory().'/import-'.$token.'.zip';
            if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)||!chmod($path,0600)) throw new \RuntimeException();
            $this->validateArchive($path);
            if(time()-(int)$s->get('updater_heartbeat','0')>60) { $this->bot->send($chat,$this->bot->text('database_restore_agent')); return; }
            $status=$this->bot->send($chat,$this->bot->text('database_restore_queued'));
            if($status<1) throw new \RuntimeException();
            $s->db->exec('BEGIN IMMEDIATE');
            try {
                if($s->query("SELECT 1 FROM update_requests WHERE status IN ('queued','running')")->fetchColumn()) throw new \LogicException('Busy');
                $s->query("INSERT INTO update_requests(user_id,chat_id,message_id,action,created) VALUES(?,?,?,'database_restore',?)",[$user,$chat,$status,time()]);
                $s->query('INSERT INTO database_restore_files(request_id,token,sha256) VALUES(?,?,?)',[(int)$s->db->lastInsertId(),$token,hash_file('sha256',$path)]);
                $s->query('DELETE FROM states WHERE user_id=?',[$user]); $s->db->exec('COMMIT'); $path='';
            } catch(\Throwable $e) { $s->db->exec('ROLLBACK'); $this->bot->send($chat,$this->bot->text($e instanceof \LogicException?'database_restore_busy':'database_restore_failed'),[],$status); return; }
        } catch(\Throwable) { $this->bot->send($chat,$this->bot->text('database_backup_invalid')); }
        finally { if($path!==''&&is_file($path)) unlink($path); }
    }
    private function directory(): string
    {
        $dir=$this->bot->config->storage.'/database-backups';
        if(is_link($dir)) throw new \RuntimeException('Unsafe backup directory');
        if(!is_dir($dir)&&!mkdir($dir,0700,true)) throw new \RuntimeException('Backup directory unavailable');
        chmod($dir,0700); return $dir;
    }
    private function botId(): string { return explode(':',$this->bot->config->token,2)[0]; }
    public function validateArchive(string $path): void
    {
        $zip=new \ZipArchive();
        if($zip->open($path,\ZipArchive::RDONLY)!==true) throw new \InvalidArgumentException();
        try {
            if($zip->numFiles!==2) throw new \InvalidArgumentException();
            $meta=$zip->statName('manifest.json'); $db=$zip->statName('zero.sqlite');
            if(!$meta||!$db||$meta['size']>4096||$db['size']<100||$db['size']>self::MAX_DATABASE) throw new \InvalidArgumentException();
            $manifest=json_decode($zip->getFromName('manifest.json'),true,32,JSON_THROW_ON_ERROR);
            if(($manifest['format']??'')!=='ciro-database-backup'||($manifest['schema']??0)!==1||($manifest['bot_id']??'')!==$this->botId()) throw new \InvalidArgumentException();
            if(!is_string($manifest['sha256']??null)||!is_string($manifest['signature']??null)||!hash_equals(hash_hmac('sha256','ciro-database-backup:1:'.$this->botId().':'.$manifest['sha256'],$this->bot->config->token),$manifest['signature'])) throw new \InvalidArgumentException();
            $stream=$zip->getStream('zero.sqlite'); if(!$stream) throw new \InvalidArgumentException();
            try { $hash=hash_init('sha256'); $count=0; $header=''; while(!feof($stream)) { $chunk=fread($stream,65536); if($chunk===false) throw new \InvalidArgumentException(); if($count===0) $header=substr($chunk,0,16); $count+=strlen($chunk); if($count>self::MAX_DATABASE) throw new \InvalidArgumentException(); hash_update($hash,$chunk); } }
            finally { fclose($stream); }
            if($header!=="SQLite format 3\0"||$count!==$db['size']||!is_string($manifest['sha256']??null)||!hash_equals(hash_final($hash),$manifest['sha256'])) throw new \InvalidArgumentException();
        } finally { $zip->close(); }
    }
    public function snapshot(): string
    {
        $s=$this->bot->store;
        if((int)$s->query('PRAGMA page_count')->fetchColumn()*(int)$s->query('PRAGMA page_size')->fetchColumn()>self::MAX_DATABASE) throw new \RuntimeException('Database too large');
        $base=$this->directory().'/export-'.bin2hex(random_bytes(12)); $db=$base.'.sqlite'; $archive=$base.'.zip';
        try {
            $s->query('VACUUM INTO ?',[$db]); chmod($db,0600);
            if(filesize($db)>self::MAX_DATABASE) throw new \RuntimeException('Database too large');
            $check=new \PDO('sqlite:'.$db); if($check->query('PRAGMA quick_check')->fetchColumn()!=='ok') throw new \RuntimeException('Invalid snapshot'); unset($check);
            $zip=new \ZipArchive(); if($zip->open($archive,\ZipArchive::CREATE|\ZipArchive::EXCL)!==true) throw new \RuntimeException();
            $meta=['format'=>'ciro-database-backup','schema'=>1,'bot_id'=>$this->botId(),'version'=>Updates::version(),'created'=>gmdate('c'),'sha256'=>hash_file('sha256',$db)];
            $meta['signature']=hash_hmac('sha256','ciro-database-backup:1:'.$this->botId().':'.$meta['sha256'],$this->bot->config->token);
            $ok=$zip->addFromString('manifest.json',json_encode($meta,JSON_THROW_ON_ERROR))&&$zip->addFile($db,'zero.sqlite');
            $closed=$zip->close(); if(!$ok||!$closed||!chmod($archive,0600)||filesize($archive)>self::MAX_ZIP) throw new \RuntimeException('Backup too large');
            return $archive;
        } catch(\Throwable $e) { if(is_file($archive)) unlink($archive); throw $e; }
        finally { if(is_file($db)) unlink($db); }
    }
    public static function due(Store $s): bool
    {
        if($s->get('update_in_progress','0')==='1'||$s->query("SELECT 1 FROM update_requests WHERE status IN ('queued','running')")->fetchColumn()) return false;
        return (bool)$s->query("SELECT 1 FROM settings WHERE key GLOB 'database.backup.now.*' AND CAST(value AS INTEGER)>0")->fetchColumn()
            ||($s->get('database.backup.enabled','0')==='1'&&(int)$s->get('database.backup.next','0')<=time());
    }
    public static function prune(Config $config,Store $s): void
    {
        $dir=$config->storage.'/database-backups';
        if(!is_dir($dir)||is_link($dir)) return;
        foreach(glob($dir.'/*')?:[] as $path) {
            if(is_link($path)||!is_file($path)||filemtime($path)>=time()-3600) continue;
            if(preg_match('/^export-[a-f0-9]{24}\.(?:sqlite|zip)$/D',basename($path))) unlink($path);
            elseif(preg_match('/^import-([a-f0-9]{32})\.zip$/D',basename($path),$m)) {
                $exists=$s->query("SELECT 1 FROM sqlite_master WHERE name='database_restore_files' AND type='table'")->fetchColumn();
                if(!$exists||!$s->query("SELECT 1 FROM database_restore_files f JOIN update_requests r ON r.id=f.request_id WHERE f.token=? AND r.status IN ('queued','running')",[$m[1]])->fetchColumn()) unlink($path);
            }
        }
    }
    public function run(): void
    {
        $dir=$this->directory(); $lock=fopen($dir.'/backup.lock','c');
        if(!$lock) return;
        try {
            if(!flock($lock,LOCK_EX|LOCK_NB)) return;
            self::prune($this->bot->config,$this->bot->store);
            $s=$this->bot->store; $s->db->exec('BEGIN IMMEDIATE');
            try {
                if(!self::due($s)) { $s->db->exec('COMMIT'); return; }
                $request=$s->query("SELECT key FROM settings WHERE key GLOB 'database.backup.now.*' AND CAST(value AS INTEGER)>0 ORDER BY CAST(value AS INTEGER) LIMIT 1")->fetchColumn();
                $chat=$request?(int)substr($request,strlen('database.backup.now.')):(int)$s->get('database.backup.chat','0');
                if($request) $s->query('DELETE FROM settings WHERE key=?',[$request]);
                else $s->set('database.backup.next',(string)(time()+max(1,min(10080,(int)$s->get('database.backup.minutes','60')))*60));
                if($request&&$s->get('database.backup.enabled','0')==='1'&&(int)$s->get('database.backup.chat','0')===$chat) $s->set('database.backup.next',(string)(time()+max(1,min(10080,(int)$s->get('database.backup.minutes','60')))*60));
                $s->db->exec('COMMIT');
            } catch(\Throwable $e) { $s->db->exec('ROLLBACK'); throw $e; }
            if(!$this->allowed($chat,$chat)) return;
            $path='';
            try {
                $path=$this->snapshot();
                if(!$this->allowed($chat,$chat)||(!$request&&($s->get('database.backup.enabled','0')!=='1'||(int)$s->get('database.backup.chat','0')!==$chat))) return;
                $stamp=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tehran')))->format('Ymd-His');
                $this->bot->api->call('sendDocument',['chat_id'=>$chat,'document'=>new \CURLFile($path,'application/zip','ciro-database-'.$stamp.'.zip'),'caption'=>$this->bot->text('database_backup_caption'),'parse_mode'=>'HTML']);
            } catch(\Throwable) {
                // Only an explicit request gets a result in its existing menu; automatic failures create no diagnostic DMs.
                if($request&&($p=$this->menu($chat))) try { $this->show($chat,$p,$this->bot->text('database_backup_failed')); } catch(\Throwable) {}
            } finally { if($path!==''&&is_file($path)) unlink($path); }
        } finally { fclose($lock); }
    }
}
