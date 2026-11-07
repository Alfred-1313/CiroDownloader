<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/CookieFormats.php';

/**
 * Several independent cookie jars for one service.
 *
 * The first jar keeps the historical single-file path, so existing installs,
 * backups and environment overrides continue to work. Further jars live in
 * "<primary>.d/". A pool belongs to exactly one service: its validator rejects
 * cookies of any other site, so one export can never serve two services.
 */
final class CookiePool
{
    public const MAX_JARS=10, MAX_BYTES=1048576, MAX_ZIP_BYTES=5242880, MAX_ZIP_ENTRIES=40, MAX_ZIP_FILES=20, MAX_ZIP_TOTAL=10485760, FAILURE_SECONDS=21600;
    /** @var callable(string):void */
    private $validate;
    /** @var callable(string):string */
    private $identity;
    public function __construct(private string $primary,callable $validate,callable $identity,private string $domain='')
    {
        $this->validate=$validate; $this->identity=$identity;
    }
    /** Accept Netscape, JSON and header-string exports; the service's own validator still decides. */
    public function normalize(string $content): string { return CookieFormats::toNetscape($content,$this->domain); }
    public function directory(): string { return $this->primary.'.d'; }
    private function statePath(): string { return $this->primary.'.pool'; }
    private function lockPath(): string { return $this->primary.'.lock'; }
    /** Hold the pool-wide lock; the YouTube refresh uses the same file. */
    private function locked(callable $work,int $mode=LOCK_EX): mixed
    {
        $directory=dirname($this->primary);
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)) throw new \RuntimeException('Cannot create cookie storage');
        $lock=fopen($this->lockPath(),'c');
        if(!$lock) throw new \RuntimeException('Cannot lock cookies');
        chmod($this->lockPath(),0600);
        try {
            if(!flock($lock,$mode)) throw new \RuntimeException('Cannot lock cookies');
            return $work();
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public function identityOf(string $content): string
    {
        $identity=(string)($this->identity)($content);
        return hash('sha256',$identity!==''?'id:'.$identity:'content:'.$content);
    }
    private function read(string $path): ?string
    {
        clearstatcache(true,$path);
        if(!is_file($path)||is_link($path)||!is_readable($path)) return null;
        $size=filesize($path);
        if($size===false||$size<1||$size>self::MAX_BYTES) return null;
        $content=file_get_contents($path);
        return is_string($content)?$content:null;
    }
    /** @return list<array{id:string,path:string,hash:string,bytes:int,identity:string}> */
    private function scan(): array
    {
        $paths=[]; if(is_file($this->primary)) $paths['main']=$this->primary;
        $files=glob($this->directory().'/*.txt')?:[]; sort($files,SORT_STRING);
        foreach($files as $file) if(preg_match('/^[a-f0-9]{12}\.txt$/D',basename($file))) $paths[substr(basename($file),0,12)]=$file;
        $jars=[];
        foreach($paths as $id=>$path) {
            $content=$this->read($path);
            if($content===null) continue;
            $jars[]=['id'=>(string)$id,'path'=>$path,'hash'=>hash('sha256',$content),'bytes'=>strlen($content),'identity'=>$this->identityOf($content)];
        }
        return $jars;
    }
    public function jars(): array { return $this->locked(fn()=>$this->scan(),LOCK_SH); }
    public function count(): int { return count($this->jars()); }
    /** One value that changes whenever any jar is added, replaced or removed. */
    public function digest(): string
    {
        $parts=[]; foreach($this->jars() as $jar) $parts[]=$jar['id'].':'.$jar['hash'];
        return hash('sha256',implode('|',$parts));
    }
    private function writeAtomic(string $path,string $content): void
    {
        $directory=dirname($path);
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)) throw new \RuntimeException('Cannot create cookie directory');
        $temporary=$directory.'/.cookies-import-'.bin2hex(random_bytes(8));
        try {
            if(file_put_contents($temporary,$content)!==strlen($content)||!chmod($temporary,0600)||!rename($temporary,$path)) throw new \RuntimeException('Cannot save cookies');
        } finally { if(is_file($temporary)) unlink($temporary); }
    }
    /** @return array{status:string,id:string} status is added, replaced or unchanged */
    public function add(string $content): array
    {
        $content=$this->normalize($content);
        ($this->validate)($content);
        $identity=$this->identityOf($content);
        return $this->locked(function() use ($content,$identity): array {
            $jars=$this->scan();
            foreach($jars as $jar) {
                if($jar['identity']!==$identity) continue;
                // The same account exported again replaces its older jar.
                if(hash_equals($jar['hash'],hash('sha256',$content))) { $this->forget($jar['id']); return ['status'=>'unchanged','id'=>$jar['id']]; }
                $this->writeAtomic($jar['path'],$content); $this->forget($jar['id']);
                return ['status'=>'replaced','id'=>$jar['id']];
            }
            if(count($jars)>=self::MAX_JARS) {
                // A jar that recently failed is the first to make room for a fresh export.
                $failed=$this->state()['failed']; $evict=null;
                foreach($jars as $jar) if(($failed[$jar['id']]??0)>time()&&($evict===null||$failed[$jar['id']]<$failed[$evict['id']])) $evict=$jar;
                if($evict===null||!unlink($evict['path'])) throw new \RuntimeException('Cookie pool is full');
                $this->forget($evict['id']);
                if($evict['id']==='main') { $this->writeAtomic($this->primary,$content); return ['status'=>'added','id'=>'main']; }
            }
            if(!$jars&&!is_file($this->primary)) { $this->writeAtomic($this->primary,$content); return ['status'=>'added','id'=>'main']; }
            $id=substr($identity,0,12);
            $this->writeAtomic($this->directory().'/'.$id.'.txt',$content);
            return ['status'=>'added','id'=>$id];
        });
    }
    /** Remove one jar only if it still has the version the administrator saw. */
    public function remove(string $id,string $hash): bool
    {
        return $this->locked(function() use ($id,$hash): bool {
            foreach($this->scan() as $jar) {
                if($jar['id']!==$id) continue;
                if(strlen($hash)<8||!str_starts_with($jar['hash'],$hash)||!unlink($jar['path'])) return false;
                $this->forget($id); $this->tidy(); return true;
            }
            return false;
        });
    }
    /** Remove every jar if the whole pool is unchanged since it was shown. */
    public function clear(string $digest): bool
    {
        return $this->locked(function() use ($digest): bool {
            $jars=$this->scan(); $parts=[];
            foreach($jars as $jar) $parts[]=$jar['id'].':'.$jar['hash'];
            if(!$jars||!hash_equals($digest,hash('sha256',implode('|',$parts)))) return false;
            foreach($jars as $jar) if(!unlink($jar['path'])) throw new \RuntimeException('Cannot delete cookies');
            @unlink($this->statePath()); $this->tidy(); return true;
        });
    }
    private function tidy(): void { $directory=$this->directory(); if(is_dir($directory)&&!glob($directory.'/*')) @rmdir($directory); }
    private function state(): array
    {
        $saved=is_file($this->statePath())?json_decode((string)file_get_contents($this->statePath()),true):null;
        $saved=is_array($saved)?$saved:[];
        $failed=is_array($saved['failed']??null)?array_filter($saved['failed'],'is_int'):[];
        return ['rr'=>max(0,(int)($saved['rr']??0)),'failed'=>$failed];
    }
    private function save(array $state): void
    {
        $this->writeAtomic($this->statePath(),json_encode($state,JSON_THROW_ON_ERROR));
    }
    private function forget(string $id): void
    {
        $state=$this->state();
        if(isset($state['failed'][$id])) { unset($state['failed'][$id]); $this->save($state); }
    }
    /**
     * Jars to try for one request: healthy ones in rotation order, then recent
     * failures as a last resort. The rotation advances so load is shared.
     * @return list<array{id:string,path:string,hash:string,bytes:int,identity:string}>
     */
    public function order(int $limit=3): array
    {
        return $this->locked(function() use ($limit): array {
            $jars=$this->scan(); if(!$jars) return [];
            $state=$this->state(); $now=time(); $healthy=[]; $failed=[];
            foreach($jars as $jar) if(($state['failed'][$jar['id']]??0)>$now) $failed[]=$jar; else $healthy[]=$jar;
            if($healthy) { $shift=$state['rr']%count($healthy); $healthy=array_merge(array_slice($healthy,$shift),array_slice($healthy,0,$shift)); }
            $state['rr']=$state['rr']+1; $this->save($state);
            return array_slice(array_merge($healthy,$failed),0,max(1,$limit));
        });
    }
    public function markFailed(string $id): void
    {
        $this->locked(function() use ($id): void { $state=$this->state(); $state['failed'][$id]=time()+self::FAILURE_SECONDS; $this->save($state); });
    }
    public function markWorking(string $id): void
    {
        $this->locked(function() use ($id): void { $this->forget($id); });
    }
    /** @return array<string,int> jar id => time until which it is deprioritised */
    public function failures(): array
    {
        return $this->locked(function(): array { $now=time(); return array_filter($this->state()['failed'],fn($until)=>$until>$now); },LOCK_SH);
    }
    /** @return array{count:int,expired:int,session:int} */
    public static function stats(string $content): array
    {
        $count=0; $expired=0; $session=0;
        foreach(preg_split('/\r?\n/',$content) as $line) {
            if(str_starts_with($line,'#HttpOnly_')) $line=substr($line,10);
            elseif($line===''||str_starts_with($line,'#')) continue;
            $fields=explode("\t",$line); if(count($fields)!==7) continue;
            $count++;
            if((int)$fields[4]===0) $session++;
            elseif((int)$fields[4]<=time()) $expired++;
        }
        return ['count'=>$count,'expired'=>$expired,'session'=>$session];
    }
    public function describe(): array
    {
        $failures=$this->failures(); $result=[];
        foreach($this->jars() as $jar) {
            $content=(string)$this->read($jar['path']);
            $result[]=$jar+self::stats($content)+['failed'=>isset($failures[$jar['id']])];
        }
        return $result;
    }
    public function content(string $id): ?string
    {
        foreach($this->jars() as $jar) if($jar['id']===$id) return $this->read($jar['path']);
        return null;
    }
    /**
     * Read a ZIP of .txt exports entirely in memory. Entry names are never used
     * as file paths. Returns [name, content|null, reason] per candidate file.
     * @return list<array{0:string,1:?string,2:string}>
     */
    public static function zipEntries(string $bytes,string $workDirectory): array
    {
        if(!class_exists(\ZipArchive::class)) throw new \RuntimeException('ZIP support is unavailable');
        if(strlen($bytes)<22||strlen($bytes)>self::MAX_ZIP_BYTES||!str_starts_with($bytes,"PK")) throw new \RuntimeException('Invalid ZIP archive');
        if(!is_dir($workDirectory)&&!mkdir($workDirectory,0700,true)&&!is_dir($workDirectory)) throw new \RuntimeException('Cannot create work directory');
        $temporary=rtrim($workDirectory,'/').'/.cookie-check-zip-'.bin2hex(random_bytes(8));
        $zip=new \ZipArchive(); $opened=false;
        try {
            if(file_put_contents($temporary,$bytes)!==strlen($bytes)||!chmod($temporary,0600)) throw new \RuntimeException('Cannot read ZIP archive');
            if($zip->open($temporary,\ZipArchive::RDONLY)!==true) throw new \RuntimeException('Invalid ZIP archive');
            $opened=true;
            if($zip->numFiles<1||$zip->numFiles>self::MAX_ZIP_ENTRIES) throw new \RuntimeException('ZIP archive has too many entries');
            $candidates=[]; $total=0;
            for($index=0;$index<$zip->numFiles;$index++) {
                $stat=$zip->statIndex($index); if(!is_array($stat)) continue;
                $name=(string)$stat['name'];
                if(str_ends_with($name,'/')||str_contains($name,'__MACOSX/')||str_starts_with(basename($name),'.')||!in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),['txt','json'],true)) continue;
                $label=mb_substr(preg_replace('/[^\p{L}\p{N}._ -]+/u','_',basename($name))??'file.txt',0,40);
                $candidates[]=[$index,$label,$stat];
            }
            if(count($candidates)>self::MAX_ZIP_FILES) throw new \RuntimeException('ZIP archive has too many cookie files');
            $result=[];
            foreach($candidates as [$index,$label,$stat]) {
                $total+=(int)$stat['size'];
                if($total>self::MAX_ZIP_TOTAL) throw new \RuntimeException('ZIP archive is too large when unpacked');
                if((int)($stat['encryption_method']??0)!==0) { $result[]=[$label,null,'encrypted']; continue; }
                if((int)$stat['size']<1||(int)$stat['size']>self::MAX_BYTES) { $result[]=[$label,null,'size']; continue; }
                $content=$zip->getFromIndex($index,self::MAX_BYTES+1);
                if(!is_string($content)||$content===''||strlen($content)>self::MAX_BYTES) { $result[]=[$label,null,'unreadable']; continue; }
                $result[]=[$label,$content,''];
            }
            return $result;
        } finally {
            if($opened) $zip->close();
            if(is_file($temporary)) unlink($temporary);
        }
    }
}
