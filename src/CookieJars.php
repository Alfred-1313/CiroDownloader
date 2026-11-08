<?php
declare(strict_types=1);
namespace Zero;

/**
 * Administrator-facing helpers shared by every cookie panel: importing one or
 * many exports (TXT files or one ZIP), listing the jars and handling the
 * per-jar inline buttons. Each service keeps its own pool; nothing here lets
 * one export serve two services.
 */
final class CookieJars
{
    public const SERVICES=['yt'=>'youtube','ig'=>'instagram','pin'=>'pinterest','tt'=>'tiktok','x'=>'twitter'];
    public function __construct(private Bot $bot) {}
    public static function pool(Config $config,string $code): ?CookiePool
    {
        if($code==='yt') return youtubeCookiePool($config);
        $service=galleryFromPrefix($code);
        return $service===null?null:GalleryMedia::poolFor($config,$service);
    }
    /**
     * Download one document and add every export it holds to the pool.
     * A single TXT or JSON keeps strict errors (so the caller can explain them);
     * a ZIP reports each file separately and never stops at the first bad one.
     * @return array{added:int,replaced:int,unchanged:int,invalid:int,notes:list<array{0:string,1:string}>,live:bool}
     */
    public static function ingest(Api $api,array $document,CookiePool $pool,string $workDirectory,?callable $verify=null): array
    {
        $extension=strtolower(pathinfo((string)($document['file_name']??''),PATHINFO_EXTENSION));
        $limit=$extension==='zip'?CookiePool::MAX_ZIP_BYTES:CookiePool::MAX_BYTES;
        if(!in_array($extension,['txt','json','zip'],true)||(int)($document['file_size']??0)>$limit||empty($document['file_id'])) throw new \InvalidArgumentException('Invalid cookie document');
        if(!($api instanceof FileApi)) throw new \RuntimeException('Cookie file unavailable');
        $file=$api->call('getFile',['file_id'=>$document['file_id']]);
        if(!is_array($file)||empty($file['file_path'])||(int)($file['file_size']??0)>$limit) throw new \RuntimeException('Cookie file unavailable');
        $bytes=$api->downloadFile((string)$file['file_path'],$limit);
        if($extension!=='zip') return self::ingestContent($bytes,$pool,$verify);
        $entries=CookiePool::zipEntries($bytes,$workDirectory);
        if(!$entries) throw new \InvalidArgumentException('The archive holds no cookie files');
        return self::addEntries($entries,$pool,false,null);
    }
    /** A pasted message or a single file: strict errors, optional live check. */
    public static function ingestContent(string $content,CookiePool $pool,?callable $verify=null): array
    {
        return self::addEntries([['cookies',$content,'']],$pool,true,$verify);
    }
    private static function addEntries(array $entries,CookiePool $pool,bool $single,?callable $verify): array
    {
        $summary=['added'=>0,'replaced'=>0,'unchanged'=>0,'invalid'=>0,'notes'=>[],'live'=>false];
        foreach($entries as [$name,$content,$reason]) {
            if($content===null) { $summary['invalid']++; $summary['notes'][]=[$name,$reason]; continue; }
            try {
                $content=$pool->normalize($content);
                // Cookies taken while logged out do not get past a service's "sign in" check; say so before anything else.
                if($pool->isGuest($content)) $summary['notes'][]=[$name,'guest'];
                if($single&&$verify!==null) { $content=$verify($content); $summary['live']=true; }
                $summary[$pool->add($content)['status']]++;
            } catch(\Throwable $e) {
                if($single) throw $e;
                $summary['invalid']++; $summary['notes'][]=[$name,$e->getMessage()==='Cookie pool is full'?'full':'invalid'];
            }
        }
        return $summary;
    }
    /** Edit the import's status message, or start a new one if it was deleted. */
    public function notice(int $chat,string $menuKey,string $text): void
    {
        $id=(int)$this->bot->store->get($menuKey,'0');
        try { $id=$this->bot->send($chat,$text,[],$id); }
        catch(\RuntimeException $e) {
            if($e->getCode()!==404||$id<1) throw $e;
            $id=$this->bot->send($chat,$text);
        }
        $this->bot->store->set($menuKey,(string)$id);
    }
    /**
     * Shared by every import panel: read the document or pasted text, add it to the pool,
     * report the result in the status message and delete the message that held credentials.
     * $explain turns an unexpected error into the notice to show.
     */
    public function receive(int $chat,int $user,array $message,string $code,string $menuKey,string $failed,?callable $verify=null,?callable $explain=null): void
    {
        $bot=$this->bot; $pool=self::pool($bot->config,$code); $notice=$failed;
        try {
            $summary=isset($message['document'])?self::ingest($bot->api,$message['document'],$pool,$bot->config->storage,$verify)
                :self::ingestContent((string)($message['text']??''),$pool,$verify);
            $notice=$this->result($code,$summary);
        } catch(\InvalidArgumentException) { $notice=$bot->text('cookie_file_invalid'); }
        catch(\Throwable $e) {
            error_log('Cookie import failure: '.get_class($e));
            if($explain) $notice=$explain($e);
            // A refused live check with logged-out cookies is explained by that, not only by the server.
            try { if($pool&&isset($message['text'])&&$pool->isGuest($pool->normalize((string)$message['text']))) $notice.="\n\n".$bot->text('cookie_guest_hint'); }
            catch(\Throwable) {}
        }
        $this->notice($chat,$menuKey,$notice);
        if(isset($message['message_id'])) {
            try { $bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$message['message_id']]); }
            catch(\Throwable) { $this->notice($chat,$menuKey,$notice."\n\n".$bot->text('cookie_delete_failed')); }
        }
    }
    public static function serviceName(Ui $ui,string $code): string
    {
        return trim(preg_replace('/^[^\p{L}\p{N}]+/u','',$ui->label('cookie_'.self::SERVICES[$code]))??'');
    }
    public function name(string $code): string { return self::serviceName($this->bot->ui,$code); }
    /** Relative time such as "2 hours ago", in the administrator's language. */
    public static function ago(Ui $ui,int $timestamp,?int $now=null): string
    {
        $seconds=max(0,($now??time())-$timestamp);
        if($seconds<60) return $ui->plain('cookie_ago_now');
        [$key,$value]=$seconds<3600?['cookie_ago_minutes',intdiv($seconds,60)]:($seconds<86400?['cookie_ago_hours',intdiv($seconds,3600)]:['cookie_ago_days',intdiv($seconds,86400)]);
        return $ui->text($key,['n'=>$value]);
    }
    /** What the account cookie's stated expiry means for the administrator. */
    public static function expiryText(Ui $ui,?int $expires,?int $now=null): string
    {
        $now??=time();
        if($expires===null) return $ui->plain('cookie_exp_unknown');
        if($expires===0) return $ui->plain('cookie_exp_session');
        if($expires<=$now) return $ui->plain('cookie_exp_expired');
        $left=$expires-$now;
        return $left<172800?$ui->text('cookie_exp_hours',['n'=>max(1,intdiv($left,3600))]):$ui->text('cookie_exp_days',['n'=>(int)ceil($left/86400)]);
    }
    public static function historyText(Ui $ui,?int $ok,?int $fail): string
    {
        if($ok===null&&$fail===null) return $ui->plain('cookie_hist_none');
        if($fail===null) return $ui->text('cookie_hist_ok',['ok'=>self::ago($ui,$ok)]);
        if($ok===null) return $ui->text('cookie_hist_fail',['fail'=>self::ago($ui,$fail)]);
        return $ui->text('cookie_hist_both',['ok'=>self::ago($ui,$ok),'fail'=>self::ago($ui,$fail)]);
    }
    /** The message shown after a document: counts, per-file reasons and the new total. */
    public function result(string $code,array $summary): string
    {
        $ui=$this->bot->ui; $notes=[];
        foreach(array_slice($summary['notes'],0,8) as [$name,$reason]) {
            $key='cookie_reason_'.$reason;
            $notes[]='• '.Ui::escape($name).' — '.($ui->definition('text',$key)?$ui->plain($key):$ui->plain('cookie_reason_invalid'));
        }
        $pool=self::pool($this->bot->config,$code);
        return $ui->text('cookie_batch_result',[
            'service'=>$this->name($code),'added'=>$summary['added'],'replaced'=>$summary['replaced'],'unchanged'=>$summary['unchanged'],'invalid'=>$summary['invalid'],
            'total'=>$pool?$pool->count():0,'max'=>CookiePool::MAX_JARS,'check'=>$ui->plain($summary['live']?'cookie_check_live':'cookie_check_structure'),
            'notes'=>$notes?"\n".implode("\n",$notes):'',
        ],['notes']);
    }
    private function rows(string $code,array $jars): array
    {
        $rows=[]; $ui=$this->bot->ui;
        foreach($jars as $index=>$jar) {
            $tag=$code.':'.$jar['id'].':'.substr($jar['hash'],0,8); $n=['n'=>$index+1];
            $rows[]=[$ui->button('cookie_jar_export','ckex:'.$tag,$n),$ui->button('cookie_jar_remove','ckrm:'.$tag,$n),$ui->button('cookie_jar_test','ckts:'.$tag,$n),$ui->button('cookie_jar_label','cklb:'.$tag,$n)];
        }
        return $rows;
    }
    public function view(int $chat,string $code,int $message=0,string $notice=''): void
    {
        $ui=$this->bot->ui; $pool=self::pool($this->bot->config,$code); if(!$pool) return;
        $lines=[]; $jars=$pool->describe();
        foreach($jars as $index=>$jar) {
            $lines[]=$ui->text($jar['failed']?'cookie_pool_jar_failed':'cookie_pool_jar',[
                'n'=>$index+1,'label'=>$jar['label']!==''?$ui->text('cookie_label_view',['label'=>$jar['label']]):'','count'=>$jar['count'],'expired'=>$jar['expired'],'session'=>$jar['session'],
                'expiry'=>self::expiryText($ui,$jar['expires']),'history'=>self::historyText($ui,$jar['ok'],$jar['fail']),
            ],['label']);
        }
        $text=$ui->text('cookie_pool_view',[
            'service'=>$this->name($code),'count'=>count($jars),'max'=>CookiePool::MAX_JARS,
            'jars'=>$lines?implode("\n",$lines):$ui->plain('cookie_pool_empty'),
            'mode'=>$ui->plain($this->bot->store->get('cookies.fallback','0')==='1'?'cookie_mode_fallback':'cookie_mode_always'),
        ],['jars']).($notice!==''?"\n\n".$notice:'');
        $this->bot->send($chat,$text,$this->rows($code,$jars),$message);
    }
    /** Test one jar: live for YouTube, structure and expiry for the others. Returns a message. */
    private function test(string $code,CookiePool $pool,array $jar): string
    {
        $ui=$this->bot->ui; $content=$pool->content($jar['id']);
        if($content===null) return $ui->text('cookie_test_inconclusive');
        try {
            // A broken or expired file is the jar's fault; a local failure below is not.
            try {
                if($code==='yt') validateYouTubeCookies($content); else GalleryMedia::validateFor((string)galleryFromPrefix($code),$content);
            } catch(\RuntimeException) { $pool->markFailed($jar['id']); return $ui->text('cookie_test_failed'); }
            if($code!=='yt') { $pool->markWorking($jar['id']); return $ui->text('cookie_test_structure_ok'); }
            require_once __DIR__.'/Download.php';
            $folder=$this->bot->config->storage.'/.cookie-check-'.bin2hex(random_bytes(8));
            if(!mkdir($folder,0700)) throw new \RuntimeException('Cannot create cookie verification directory');
            try {
                $fresh=(new Download($this->bot->config))->verifyCookies($content,$folder,$this->bot->store->get('youtube_probe_url','https://www.youtube.com/watch?v=ybNMMK5Q318'));
                // The test may rotate the cookies; keep the freshest copy of this jar only.
                try { saveYouTubeCookies($this->bot->config,$fresh,$jar['hash'],$jar['path']); } catch(\Throwable) {}
            } finally { Download::clean($folder); }
        } catch(MediaFailure $e) {
            if($e->reason==='cookies') { $pool->markFailed($jar['id']); return $ui->text('cookie_test_failed'); }
            return $ui->text($e->reason==='blocked'?'cookie_test_blocked':'cookie_test_inconclusive');
        } catch(\Throwable) { return $ui->text('cookie_test_inconclusive'); }
        $pool->markWorking($jar['id']);
        return $ui->text('cookie_test_live_ok');
    }
    /** The jar the administrator is about to name, or null when nothing is pending or it timed out. */
    public function pendingLabel(int $user): ?string
    {
        $saved=$this->bot->store->get('cklabel.'.$user);
        if(!preg_match('/^(yt|ig|pin|tt|x):([a-f0-9]{12}|main):(\d+)$/D',$saved,$match)||(int)$match[3]<time()) return null;
        return $match[1].':'.$match[2];
    }
    public function cancelLabel(int $user): void { $this->bot->store->query('DELETE FROM settings WHERE key=?',['cklabel.'.$user]); }
    /** The administrator's next message names the jar they chose. */
    public function receiveLabel(int $chat,int $user,string $text): void
    {
        $pending=$this->pendingLabel($user);
        if($chat!==$user||!$this->bot->admin($user)||$pending===null) return;
        [$code,$id]=explode(':',$pending,2);
        $pool=self::pool($this->bot->config,$code);
        if(!$pool||!$pool->setLabel($id,$text)) { $this->bot->send($chat,$this->bot->text('cookie_label_invalid')); return; }
        $this->cancelLabel($user);
        $this->view($chat,$code,0,$this->bot->text('cookie_label_saved'));
    }
    /** Inline buttons of the pool list: export or remove one jar. */
    public function callback(array $query,int $chat,int $user,int $message): void
    {
        $ui=$this->bot->ui; $answer=fn(string $text='',bool $alert=false)=>$this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$query['id'],'text'=>$text,'show_alert'=>$alert,'cache_time'=>0]);
        if($chat!==$user||!$this->bot->admin($user)||!preg_match('/^ck(rm|ex|ts|lb):(yt|ig|pin|tt|x):([a-f0-9]{12}|main):([a-f0-9]{8})$/D',(string)($query['data']??''),$match)) { $answer($ui->plain('denied'),true); return; }
        [, $action,$code,$id,$prefix]=$match;
        $pool=self::pool($this->bot->config,$code); $jar=null;
        $position=0;
        foreach($pool?$pool->jars():[] as $index=>$candidate) if($candidate['id']===$id&&str_starts_with($candidate['hash'],$prefix)) { $jar=$candidate; $position=$index+1; }
        if(!$jar) { $answer($ui->plain('cookie_remove_changed'),true); $this->view($chat,$code,$message); return; }
        if($action==='lb') {
            $answer();
            $this->bot->store->set('cklabel.'.$user,$code.':'.$id.':'.(time()+600));
            $this->bot->send($chat,$ui->text('cookie_label_prompt',['n'=>$position]));
            return;
        }
        if($action==='ts') {
            // Testing can take a while, so acknowledge first and report in the list itself.
            $answer($ui->plain('cookie_checking'));
            $this->view($chat,$code,$message,$this->test($code,$pool,$jar));
            return;
        }
        if($action==='ex') {
            try {
                $content=$pool->content($id);
                if($content===null||!hash_equals($jar['hash'],hash('sha256',$content))) throw new \RuntimeException('Cookie jar changed');
                $this->bot->api->call('sendDocument',['chat_id'=>$chat,'document'=>new \CURLStringFile($content,self::SERVICES[$code].'-cookies.txt','text/plain'),
                    'caption'=>$ui->text($code==='yt'?'cookie_export_caption':($code.'_cookie_export_caption')),'parse_mode'=>'HTML','protect_content'=>true]);
                $answer();
            } catch(\Throwable) { $answer($ui->plain('cookie_export_failed'),true); }
            return;
        }
        try { $removed=$pool->remove($id,$jar['hash']); }
        catch(\Throwable) { $answer($ui->plain('cookie_remove_failed'),true); return; }
        $answer($ui->plain($removed?'cookie_jar_removed':'cookie_remove_changed'),!$removed);
        $this->view($chat,$code,$message);
    }
}
