<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Updates.php';
require_once __DIR__.'/Personalization.php';

final class Bot
{
    public Ui $ui;
    public function __construct(public Config $config,public Store $store,public Api $api) { $this->ui=new Ui($store); }
    public function text(string $key,array $values=[],array $raw=[]): string { return $this->ui->text($key,$values,$raw); }
    public function label(string $key): string { return $this->ui->label($key); }
    public function admin(int $id): bool { return in_array($id,$this->config->admins,true); }
    public function allowed(int $id): bool { return $this->admin($id)||($this->store->enabled()&&$this->store->get('admins_only','0')!=='1'); }
    public function denial(): string { return $this->text($this->store->get('admins_only','0')==='1'?'admin_only_notice':'offline'); }
    public function button(string $key,string $data): array { return $this->ui->button($key,$data); }
    public function replyButton(string $key): array { return $this->ui->button($key); }
    public function send(int $chat,string $text,array $rows=[],int $message=0): void
    {
        $params=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true];
        if($rows||$message>0) $params['reply_markup']=['inline_keyboard'=>$rows];
        if($message>0) $params['message_id']=$message;
        $this->api->call($message>0?'editMessageText':'sendMessage',$params);
    }
    public function sendKeyboard(int $chat,string $text,array $rows): void
    {
        $this->api->call('sendMessage',['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true,
            'reply_markup'=>['keyboard'=>$rows,'resize_keyboard'=>true,'is_persistent'=>true]]);
    }
    public function jobMessage(array $job,string $text,array $rows=[]): void
    {
        $message=(int)$this->store->query('SELECT message_id FROM jobs WHERE id=?',[$job['id']])->fetchColumn();
        $params=['chat_id'=>(int)$job['chat_id'],'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true,'reply_markup'=>['inline_keyboard'=>$rows]];
        if($message>0) {
            try { $this->api->call('editMessageText',$params+['message_id'=>$message]); return; }
            catch(\RuntimeException $e) { if($e->getCode()!==404) throw $e; }
        }
        $result=$this->api->call('sendMessage',$params);
        if(!is_array($result)||empty($result['message_id'])) throw new \RuntimeException('Missing download status message ID');
        $this->store->query('UPDATE jobs SET message_id=? WHERE id=?',[(int)$result['message_id'],$job['id']]);
    }
    public function home(int $chat,int $user): void
    {
        $rows=[[$this->replyButton('help')]];
        if($this->admin($user)) {
            $rows[]=[$this->replyButton('admin'),$this->replyButton('health')];
            $rows[]=[$this->replyButton('update')];
        }
        $this->sendKeyboard($chat,$this->text('welcome'),$rows);
    }
    public function panel(int $chat,int $message=0): void
    {
        $on=$this->store->enabled();
        $this->send($chat,$this->text('admin_panel',['state'=>$this->text($on?'state_on':'state_off')],['state']),[
            [$this->button($on?'off':'on',$on?'set:off':'set:on')],
            [$this->button('customize','custom:home')],
            [$this->button('health','health')],[$this->button('cookies','cookies')],
            [$this->button('update','update:open')],[$this->button('back','home')],
        ],$message);
    }
    public function health(int $chat): void
    {
        $now=time(); $running=(int)$this->store->query("SELECT COUNT(*) FROM jobs WHERE status='running'")->fetchColumn();
        $this->send($chat,$this->text('health',[
            'state'=>$this->text($this->store->enabled()?'state_on':'state_off'),
            'poller'=>$this->text($now-(int)$this->store->get('poller_heartbeat','0')<60?'online':'unresponsive'),
            'worker'=>$this->text($running>0?'processing':($now-(int)$this->store->get('worker_heartbeat','0')<20?'online':'unresponsive')),
            'queued'=>(int)$this->store->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','choosing','confirming')")->fetchColumn(),
            'running'=>$running,'cookies'=>$this->text(is_readable($this->config->cookiePath())?'cookies_loaded':'not_configured'),
            'proxy'=>$this->text($this->config->youtubeProxy!==''?'configured':'not_configured'),
            'error'=>$this->store->get('last_job_error',$this->ui->plain('not_recorded')),
        ],['state','poller','worker','cookies','proxy']));
    }
    public function qualityMenu(array $job,array $media): void
    {
        $this->store->query("UPDATE jobs SET status='choosing',title=?,options=?,expires=? WHERE id=?",
            [$media['title'],json_encode($media['options'],JSON_THROW_ON_ERROR),time()+900,$job['id']]);
        $rows=[]; $row=[];
        foreach($media['options'] as $height=>$formats) {
            $row[]=$this->ui->button('quality','quality:'.$job['id'].':'.$height,['quality'=>$height.'p']);
            if(count($row)===2) { $rows[]=$row; $row=[]; }
        }
        if($row) $rows[]=$row;
        $rows[]=[$this->button('cancel_quality','quality:'.$job['id'].':cancel')];
        $this->jobMessage($job,$this->text('quality_prompt',['title'=>$media['title'],'max_size'=>round($this->config->maxBytes/1000000,1)]),$rows);
    }
    private function qualityChoice(int $chat,int $user,array $cb,array $parts): void
    {
        $quality=$parts[2]==='cancel'?null:(int)$parts[2];
        $job=$this->store->choose((int)$parts[1],$user,$chat,$quality);
        $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$job?'':$this->ui->plain('quality_expired'),'show_alert'=>!$job,'cache_time'=>0]);
        if(!$job) return;
        try {
            $this->jobMessage($job,$this->text($quality===null?'cancelled':'queued'));
            if($quality!==null) $this->store->query("UPDATE jobs SET status='queued' WHERE id=? AND status='confirming'",[$job['id']]);
        } catch(\Throwable $e) {
            $this->store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            throw $e;
        }
    }
    public function beginCookieImport(int $chat,int $user): void
    {
        $this->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'cookie.upload',time()+600]);
        $this->send($chat,$this->text('cookie_import',['status'=>$this->ui->plain(is_readable($this->config->cookiePath())?'cookie_present':'cookie_missing')]),[
            [$this->button('cookie_info','cookies:info')],[$this->button('back','admin')],
        ]);
    }
    public function cookieInfo(int $chat): void { $this->send($chat,$this->text('cookie_info'),[[$this->button('cookies','cookies')],[$this->button('back','admin')]]); }
    private function cookieDocument(int $chat,int $user,array $message): void
    {
        if(!$this->admin($user)) { $this->send($chat,$this->text('cookie_admin_only')); return; }
        $state=$this->store->query('SELECT key,expires FROM states WHERE user_id=?',[$user])->fetch();
        if(!$state||$state['key']!=='cookie.upload') { $this->send($chat,$this->text('cookie_begin_first')); return; }
        $saved=false;
        try {
            if((int)$state['expires']<=time()) {
                $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
                $this->send($chat,$this->text('cookie_expired')); return;
            }
            $doc=$message['document'];
            if(strtolower(pathinfo((string)($doc['file_name']??''),PATHINFO_EXTENSION))!=='txt'||(int)($doc['file_size']??0)>1048576||empty($doc['file_id'])) {
                $this->send($chat,$this->text('cookie_file_invalid')); return;
            }
            $file=$this->api->call('getFile',['file_id'=>$doc['file_id']]);
            if(!is_array($file)||empty($file['file_path'])||(int)($file['file_size']??0)>1048576||!($this->api instanceof FileApi)) throw new \RuntimeException('Cookie file unavailable');
            $content=$this->api->downloadFile((string)$file['file_path'],1048576);
            saveYouTubeCookies($this->config,$content); $saved=true;
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $this->send($chat,$this->text('cookie_saved'),[[$this->button('back','admin')]]);
        } catch(\Throwable $e) {
            error_log('Cookie import failure: '.get_class($e));
            if(!$saved) $this->send($chat,$this->text('cookie_failed'));
        } finally {
            if(isset($message['message_id'])) {
                try { $this->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message['message_id']]); }
                catch(\Throwable) { $this->send($chat,$this->text('cookie_delete_failed')); }
            }
        }
    }
    public function catalog(string $kind): array { return in_array($kind,['text','button'],true)?$this->ui->catalog($kind):[]; }
    public function handle(array $update): void
    {
        $cb=$update['callback_query']??null; $message=$cb['message']??$update['message']??null;
        $user=(int)($cb['from']['id']??$message['from']['id']??0);
        if(!$message||($message['chat']['type']??'')!=='private'||!$user) return;
        $chat=(int)$message['chat']['id'];
        $this->store->query('INSERT OR IGNORE INTO users(id) VALUES(?)',[$user]);
        $custom=new Personalization($this);
        if($cb) {
            $action=(string)($cb['data']??''); $isQuality=preg_match('/^quality:(\d+):(\d+|cancel)$/D',$action,$choice)===1;
            $privileged=!in_array($action,['home','download','help'],true)&&!$isQuality;
            if($privileged&&!$this->admin($user)) {
                $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->ui->plain('denied'),'show_alert'=>true]); return;
            }
            if(!$this->allowed($user)) {
                $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id']]);
                $this->send($chat,$this->denial()); return;
            }
            if($isQuality) { $this->qualityChoice($chat,$user,$cb,$choice); return; }
            $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$action==='help'?mb_substr($this->ui->plain('help'),0,190):'','show_alert'=>$action==='help','cache_time'=>0]);
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $menu=(int)($message['message_id']??0);
            if($custom->handle($chat,$user,$action,$menu)) return;
            if($action==='home') $this->home($chat,$user);
            elseif($action==='help'||$action==='update:progress') return;
            elseif($action==='download') $this->send($chat,$this->text('prompt'));
            elseif($action==='admin') $this->panel($chat);
            elseif($action==='health') $this->health($chat);
            elseif($action==='cookies') $this->beginCookieImport($chat,$user);
            elseif($action==='cookies:info') $this->cookieInfo($chat);
            elseif(in_array($action,['update:menu','update:run','update:open'],true)) (new Updates($this->config,$this->store,$this->api))->menu($chat,$user,$menu,'',$action!=='update:menu');
            elseif($action==='update:start') (new Updates($this->config,$this->store,$this->api))->request($chat,$user,'update',$menu);
            elseif($action==='update:rollback') (new Updates($this->config,$this->store,$this->api))->confirmRollback($chat,$user,$menu);
            elseif($action==='update:rollback:confirm') (new Updates($this->config,$this->store,$this->api))->request($chat,$user,'rollback',$menu);
            elseif($action==='update:changes') (new Updates($this->config,$this->store,$this->api))->changes($chat,$user,$menu);
            elseif($action==='update:back') {
                if($this->store->query("SELECT 1 FROM update_requests WHERE status IN ('queued','running')")->fetchColumn()) return;
                $this->store->set('update_menu_hash.'.$user,''); $this->panel($chat,$menu);
            } elseif(in_array($action,['set:on','set:off'],true)) {
                $this->store->set('enabled',$action==='set:on'?'1':'0'); $this->panel($chat);
            } else {
                // Old administration buttons remain usable after upgrading.
                [$op,$kind,$key]=array_pad(explode(':',$action),3,'');
                if($op==='list'&&$this->catalog($kind)) $custom->groups($chat,$kind,$menu);
                elseif(isset($this->catalog($kind)[$key])) {
                    if($op==='item') $custom->item($chat,$kind,$key,$menu);
                    elseif($op==='edit') $custom->begin($chat,$user,$kind,$key,$menu);
                    elseif($op==='reset') $custom->handle($chat,$user,"custom:reset:$kind:$key",$menu);
                }
            }
            return;
        }
        if(!$this->allowed($user)) { $this->send($chat,$this->denial()); return; }
        $text=trim((string)($message['text']??''));
        $commands=['/cancel','/start','/admin','/health','/cookies','/update','/customize','/help',
            $this->label('cookies'),$this->label('admin'),$this->label('health'),$this->label('help'),$this->label('download'),$this->label('update')];
        if(in_array($text,$commands,true)) $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        if($text==='/id') { $this->send($chat,$this->text('your_id',['id'=>$user])); return; }
        if($this->admin($user)) {
            if($text==='/admin'||$text===$this->label('admin')) { $this->panel($chat); return; }
            if($text==='/health'||$text===$this->label('health')) { $this->health($chat); return; }
            if($text==='/cookies'||$text===$this->label('cookies')) { $this->beginCookieImport($chat,$user); return; }
            if($text==='/update'||$text===$this->label('update')) { (new Updates($this->config,$this->store,$this->api))->menu($chat,$user,0,'',true); return; }
            if($text==='/customize') { $custom->home($chat); return; }
        }
        if(in_array($text,['/start','/cancel'],true)) { $this->home($chat,$user); return; }
        if($text==='/help'||$text===$this->label('help')) { $this->send($chat,$this->text('help')); return; }
        if($text===$this->label('download')) { $this->send($chat,$this->text('prompt')); return; }
        $state=$this->store->query('SELECT key FROM states WHERE user_id=? AND expires>?',[$user,time()])->fetchColumn();
        if($this->admin($user)&&is_string($state)&&str_starts_with($state,'ui:')) {
            $custom->receive($chat,$user,$message,json_decode(substr($state,3),true,512,JSON_THROW_ON_ERROR)); return;
        }
        if(isset($message['document'])) { $this->cookieDocument($chat,$user,$message); return; }
        if($text==='') return;
        if($state==='cookie.upload'&&$this->admin($user)) { $this->send($chat,$this->text('cookie_waiting')); return; }
        $url=youtubeUrl($text);
        if(!$url) { $this->send($chat,$this->text('invalid')); return; }
        $result=$this->store->enqueue((int)$update['update_id'],$user,$chat,$url);
        if($result==='queued') {
            $job=$this->store->query('SELECT * FROM jobs WHERE update_id=?',[(int)$update['update_id']])->fetch();
            try { $this->jobMessage($job,$this->text('queued')); }
            catch(\Throwable $e) { $this->store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]); throw $e; }
            return;
        }
        if($result!=='duplicate') $this->send($chat,$this->text(match($result) {'busy'=>'busy','full'=>'queue_full','cooldown'=>'cooldown','updating'=>'maintenance',default=>'failed'}));
    }
}
