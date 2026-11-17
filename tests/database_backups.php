<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function dbCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class DatabaseBackupApi implements Zero\FileApi
{
    public array $calls=[]; public int $mid=100; public string $file=''; public string $sent=''; public bool $failUpload=false;
    public function call(string $method,array $p=[]): mixed {
        $this->calls[]=[$method,$p];
        if(in_array($method,['sendMessage','editMessageText'],true)) Zero\TextFormat::validate($p['text'],4096);
        if(isset($p['caption'])) Zero\TextFormat::validate($p['caption'],1024);
        if($method==='sendDocument') {
            $this->sent=(string)file_get_contents($p['document']->getFilename());
            dbCheck((fileperms($p['document']->getFilename())&0777)===0600,'export remains private until its Telegram upload');
            if($this->failUpload) throw new RuntimeException('Uncertain upload');
        }
        return match($method) {'sendMessage','sendDocument'=>['message_id'=>++$this->mid],'getFile'=>['file_path'=>'fixture.zip','file_size'=>strlen($this->file)],default=>true};
    }
    public function downloadFile(string $path,int $max): string { if(strlen($this->file)>$max) throw new RuntimeException('Too large'); return $this->file; }
    public function last(): array { foreach(array_reverse($this->calls) as [$m,$p]) if(in_array($m,['sendMessage','editMessageText'],true)) return $p; return []; }
}
$root=sys_get_temp_dir().'/ciro-database-backups-'.bin2hex(random_bytes(5));mkdir($root,0700);
try {
    $c=new Zero\Config('123:fixture',[42,55],$root);$s=new Zero\Store($root);$a=new DatabaseBackupApi();$b=new Zero\Bot($c,$s,$a);$model=new Zero\DatabaseBackups($b);$serial=0;
    $send=function(string $text,int $user=42,?array $doc=null)use($b,&$serial):void {
        $m=['message_id'=>++$serial,'from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]; if($doc)$m['document']=$doc;
        $b->handle(['update_id'=>$serial,'message'=>$m]);
    };
    $menu=fn(int $user=42)=>json_decode($s->get('database.menu.'.$user),true);
    $tap=function(string $op,int $user=42,?array $p=null,int $chat=42)use($b,$menu,&$serial):void {
        $p??=$menu();$b->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'db'.$serial,'from'=>['id'=>$user],'message'=>['message_id'=>$p['message'],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']],'data'=>'dbb:'.$p['token'].':'.$op]]);
    };
    $send($b->label('database_backups'));$p=$menu();
    dbCheck(!Zero\DatabaseBackups::due($s)&&str_contains($a->last()['text'],'60 دقیقه'),'automatic database backups start off with the requested sixty-minute default');
    $before=$s->get('database.backup.enabled');$tap('toggle',55,$p,42);$tap('toggle',42,$p,-100);
    dbCheck($s->get('database.backup.enabled')===$before,'a different administrator or a copied group callback cannot change the private backup panel');
    $tap('interval');$send('0');dbCheck(str_starts_with((string)$s->query('SELECT key FROM states WHERE user_id=42')->fetchColumn(),'database.interval:'),'an invalid minute value keeps the interval input open');
    $send('۱۵');dbCheck($s->get('database.backup.minutes')==='15'&&!$s->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'a Persian minute value saves through the bot input flow');
    $tap('toggle');dbCheck(Zero\DatabaseBackups::due($s)&&$s->get('database.backup.chat')==='42','enabling schedules the first snapshot for the enabling administrator');
    $s->set('fixture.durable','from-wal');$second=new Zero\Store($root);$second->set('fixture.concurrent','other-connection');
    $model->run();dbCheck($a->sent!==''&&!Zero\DatabaseBackups::due($s)&&abs((int)$s->get('database.backup.next')-time()-900)<3,'one scheduled export advances the configured interval without duplicate scheduling');
    $zipfile=$root.'/roundtrip.zip';file_put_contents($zipfile,$a->sent);$model->validateArchive($zipfile);$z=new ZipArchive();$z->open($zipfile);$snapshot=$root.'/snapshot.sqlite';file_put_contents($snapshot,$z->getFromName('zero.sqlite'));$meta=json_decode($z->getFromName('manifest.json'),true);$z->close();
    $check=new PDO('sqlite:'.$snapshot);dbCheck($check->query("SELECT value FROM settings WHERE key='fixture.durable'")->fetchColumn()==='from-wal'&&$check->query("SELECT value FROM settings WHERE key='fixture.concurrent'")->fetchColumn()==='other-connection'&&$check->query('PRAGMA quick_check')->fetchColumn()==='ok','native SQLite snapshot includes committed WAL changes from concurrent connections');unset($check);
    dbCheck($meta['bot_id']==='123'&&$meta['format']==='ciro-database-backup'&&!glob($root.'/database-backups/export-*'),'archive carries the bot identity and all temporary snapshot files are removed');
    $tap('toggle');$tap('now');$a->failUpload=true;$count=count(array_filter($a->calls,fn($v)=>$v[0]==='sendDocument'));$model->run();$model->run();
    dbCheck(count(array_filter($a->calls,fn($v)=>$v[0]==='sendDocument'))===$count+1&&!glob($root.'/database-backups/export-*'),'an uncertain explicit upload is never automatically replayed and leaves no files');$a->failUpload=false;
    $send($b->label('database_backup_import'));dbCheck(str_contains($a->last()['text'],'سرویس مدیریت'),'import explains an unavailable host agent without accepting work');
    $s->set('updater_heartbeat',(string)time());$send($b->label('database_backup_import'));$a->file='invalid';$doc=['file_id'=>'fixture','file_name'=>'backup.zip','file_size'=>7];$send('',42,$doc);
    dbCheck((int)$s->query('SELECT COUNT(*) FROM update_requests')->fetchColumn()===0&&str_contains($a->last()['text'],'معتبر')&&!glob($root.'/database-backups/import-*'),'invalid archives cannot queue a replacement or leave private import files');
    $a->file=(string)file_get_contents($zipfile);$doc['file_size']=strlen($a->file);$send('',42,$doc);$request=$s->query('SELECT * FROM update_requests')->fetch();$source=$s->query('SELECT * FROM database_restore_files')->fetch();
    dbCheck($request['action']==='database_restore'&&(int)$request['chat_id']===42&&(int)$request['message_id']>0&&is_file($root.'/database-backups/import-'.$source['token'].'.zip'),'a valid original archive queues one private, confirmed host replacement request');
    $send('/update');dbCheck((int)$s->query('SELECT message_id FROM update_requests')->fetchColumn()===(int)$request['message_id'],'opening the update menu cannot rebind the database restoration result');
    dbCheck($s->get('fixture.durable')==='from-wal'&&$s->get('fixture.concurrent')==='other-connection'&&!Zero\DatabaseBackups::due($s),'bot intake never replaces a live database and pending restoration pauses exports');
    $s->set('database.backup.enabled','1');$s->set('database.backup.next','0');$s->query("UPDATE update_requests SET status='done'");$s->set('update_in_progress','1');dbCheck(!Zero\DatabaseBackups::due($s),'updating or restoring the application blocks snapshots');$s->set('update_in_progress','0');
    $s->set('database.backup.chat','7');$count=count($a->calls);$model->run();dbCheck(count($a->calls)===$count,'automatic snapshots never send private database contents to a non-administrator');
    $send($b->label('database_backups'),7);dbCheck($s->get('database.menu.7')==='','forged management reply labels cannot create an ordinary user backup menu');
    $send($b->label('database_backups'));$old=$menu();$send($b->label('database_backups'));$before=$s->get('database.backup.enabled');$tap('toggle',42,$old);dbCheck($s->get('database.backup.enabled')===$before,'superseded backup menus stay inert');
    $tap('interval');$send('/cancel');dbCheck(!$s->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'cancel clears a pending minute or import flow');
    foreach(['database_backups','database_backup_import','database_backup_toggle','database_backup_interval','database_backup_now'] as $key) dbCheck(!$b->ui->customizable('button',$key),'backup management stays outside public Personalization: '.$key);
    $pending=$root.'/database-backups/import-'.$source['token'].'.zip';touch($pending,time()-7200);$s->query("UPDATE update_requests SET status='running'");Zero\DatabaseBackups::prune($c,$s);dbCheck(is_file($pending),'cleanup preserves a pending replacement upload');$s->query("UPDATE update_requests SET status='denied'");Zero\DatabaseBackups::prune($c,$s);dbCheck(!is_file($pending),'cleanup removes an abandoned or denied private upload');
} finally { Zero\Download::clean($root); }
