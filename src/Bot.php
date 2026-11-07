<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Updates.php';
require_once __DIR__.'/Personalization.php';
require_once __DIR__.'/GroupSupport.php';
require_once __DIR__.'/Cookies.php';
require_once __DIR__.'/InstagramCookies.php';
require_once __DIR__.'/InstagramPages.php';
require_once __DIR__.'/PinterestCookies.php';
require_once __DIR__.'/TikTokCookies.php';
require_once __DIR__.'/TwitterCookies.php';
require_once __DIR__.'/PinterestPages.php';
require_once __DIR__.'/Administrators.php';
require_once __DIR__.'/Reactions.php';

final class Bot
{
    public Ui $ui;
    public function __construct(public Config $config,public Store $store,public Api $api) { $this->ui=new Ui($store); }
    public function text(string $key,array $values=[],array $raw=[]): string { return $this->ui->text($key,$values,$raw); }
    public function label(string $key): string { return $this->ui->label($key); }
    public function admin(int $id): bool { return $this->store->isAdmin($id,$this->config->admins); }
    public function allowed(int $id,?int $chat=null): bool { return !($chat<0&&!$this->store->groupsEnabled())&&($this->admin($id)||($this->store->enabled()&&$this->store->get('admins_only','0')!=='1')); }
    public function denial(?int $chat=null): string { return $this->text($chat<0&&!$this->store->groupsEnabled()?'groups_disabled':($this->store->get('admins_only','0')==='1'?'admin_only_notice':'offline')); }
    public function button(string $key,string $data): array { return $this->ui->button($key,$data); }
    public function replyButton(string $key): array { return $this->ui->button($key); }
    public function send(int $chat,string $text,array $rows=[],int $message=0,int $thread=0): int
    {
        $params=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true];
        if($rows||$message>0) $params['reply_markup']=['inline_keyboard'=>$rows];
        if($message>0) $params['message_id']=$message;
        elseif($chat<0&&$thread>0) $params['message_thread_id']=$thread;
        $result=$this->api->call($message>0?'editMessageText':'sendMessage',$params);
        return $message>0?$message:(int)($result['message_id']??0);
    }
    public function sendKeyboard(int $chat,string $text,array $rows): void
    {
        $this->api->call('sendMessage',['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true,
            'reply_markup'=>['keyboard'=>$rows,'resize_keyboard'=>true,'is_persistent'=>true]]);
    }
    public function jobMessage(array $job,string $text,array $rows=[]): void
    {
        $saved=$this->store->query('SELECT message_id,message_kind,poster FROM jobs WHERE id=?',[$job['id']])->fetch();
        $message=(int)$saved['message_id']; $photo=$saved['message_kind']==='photo';
        $params=['chat_id'=>(int)$job['chat_id'],'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true,'reply_markup'=>['inline_keyboard'=>$rows]];
        if($photo) {
            $params['caption']=Ui::fitCaption($text); unset($params['text'],$params['disable_web_page_preview']);
        }
        if($message>0) {
            try { $this->api->call($photo?'editMessageCaption':'editMessageText',$params+['message_id'=>$message]); return; }
            catch(\RuntimeException $e) { if($e->getCode()!==404) throw $e; }
        }
        if($photo) $params['photo']=$saved['poster'];
        if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
        $result=$this->api->call($photo?'sendPhoto':'sendMessage',$params);
        if(!is_array($result)||empty($result['message_id'])) throw new \RuntimeException('Missing download status message ID');
        $this->store->query('UPDATE jobs SET message_id=? WHERE id=?',[(int)$result['message_id'],$job['id']]);
    }
    public function jobPoster(array $job,string $poster,string $caption,array $rows): void
    {
        $message=(int)$this->store->query('SELECT message_id FROM jobs WHERE id=?',[$job['id']])->fetchColumn();
        $params=['chat_id'=>(int)$job['chat_id'],'reply_markup'=>['inline_keyboard'=>$rows]];
        try {
            if($message>0) $this->api->call('editMessageMedia',$params+['message_id'=>$message,
                'media'=>['type'=>'photo','media'=>$poster,'caption'=>Ui::fitCaption($caption),'parse_mode'=>'HTML']]);
            else {
                if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
                $result=$this->api->call('sendPhoto',$params+['photo'=>$poster,'caption'=>Ui::fitCaption($caption),'parse_mode'=>'HTML']);
                $message=(int)($result['message_id']??0);
                if(!$message) throw new \RuntimeException('Missing poster message ID');
            }
            $this->store->query("UPDATE jobs SET message_id=?,message_kind='photo',poster=? WHERE id=?",[$message,$poster,$job['id']]);
        } catch(\RuntimeException $e) {
            if($e->getCode()===404&&$message>0) {
                $this->store->query('UPDATE jobs SET message_id=0 WHERE id=?',[$job['id']]);
                $this->jobPoster($job,$poster,$caption,$rows); return;
            }
            if($e->getCode()!==400) throw $e;
            // A rejected thumbnail must not prevent choosing and downloading the video.
            $this->jobMessage($job,$caption,$rows);
        }
    }
    public function finishJob(array $job): void
    {
        $saved=$this->store->query('SELECT message_id FROM jobs WHERE id=?',[$job['id']])->fetch();
        if(!$saved||(int)$saved['message_id']<1) return;
        $params=['chat_id'=>(int)$job['chat_id'],'message_id'=>(int)$saved['message_id']];
        $this->api->call('deleteMessage',$params);
    }
    public function home(int $chat,int $user): void
    {
        $rows=[[$this->replyButton('help')]];
        if($this->admin($user)) $rows[]=[$this->replyButton('admin')];
        $this->sendKeyboard($chat,$this->text('welcome'),$rows);
    }
    public function panel(int $chat,int $message=0): void
    {
        if(!$this->admin($chat)) return;
        if($message>0) {
            try { $this->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); }
            catch(\Throwable) {
                try { $this->api->call('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>$message,'reply_markup'=>['inline_keyboard'=>[]]]); } catch(\Throwable) {}
            }
        }
        $on=$this->store->enabled();
        $this->sendKeyboard($chat,$this->text('admin_panel',['state'=>$this->text($on?'state_on':'state_off')],['state']),[
            [$this->replyButton('customize'),$this->replyButton('health')],
            [$this->replyButton('cookies'),$this->replyButton('update')],
            [$this->replyButton('add_admin')],
            [$this->replyButton($on?'off':'on')],
            [$this->replyButton('back_home')],
        ]);
    }
    public function health(int $chat): void
    {
        $now=time(); $running=(int)$this->store->query("SELECT COUNT(*) FROM jobs WHERE status='running'")->fetchColumn();
        $this->send($chat,$this->text('health',[
            'state'=>$this->text($this->store->enabled()?'state_on':'state_off'),
            'poller'=>$this->text($now-(int)$this->store->get('poller_heartbeat','0')<60?'online':'unresponsive'),
            'worker'=>$this->text($running>0?'processing':($now-(int)$this->store->get('worker_heartbeat','0')<20?'online':'unresponsive')),
            'queued'=>(int)$this->store->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','confirming')")->fetchColumn(),
            'users'=>(int)$this->store->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'completed'=>(int)$this->store->query("SELECT COUNT(*) FROM jobs WHERE status='done' AND created>=?",[$now-7*86400])->fetchColumn(),
            'failed'=>(int)$this->store->query("SELECT COUNT(*) FROM jobs WHERE status='failed' AND created>=?",[$now-7*86400])->fetchColumn(),
            'running'=>$running,'cookies'=>$this->text(is_readable($this->config->cookiePath())?'cookies_loaded':'not_configured'),
            'proxy'=>$this->text($this->config->youtubeProxy!==''?'configured':'not_configured'),
            'error'=>$this->lastErrorMessage(),
        ],['state','poller','worker','cookies','proxy']));
    }
    private function lastErrorMessage(): string
    {
        $detail=$this->store->get('last_job_error');
        if($detail==='') return $this->ui->plain('not_recorded');
        $reason=$this->store->get('last_job_error_reason');
        if($reason==='') {
            require_once __DIR__.'/Download.php';
            $reason=MediaFailure::process(1,$detail)->reason;
        }
        $key=(in_array($this->store->get('last_job_service'),galleryServices(),true)?$this->store->get('last_job_service').'_':'').'error_'.$reason;
        return $this->ui->plain($this->ui->definition('text',$key)?$key:'failed');
    }
    public function qualityMenu(array $job,array $media): void
    {
        $this->store->query("UPDATE jobs SET status='running',title=?,options=?,expires=0 WHERE id=?",
            [$media['title'],json_encode($media['options'],JSON_THROW_ON_ERROR),$job['id']]);
        $rows=[]; $row=[];
        foreach($this->ui->coverOrder() as $height) {
            if(empty($media['options'][$height])) continue;
            if($height==='audio') {
                if($row) { $rows[]=$row; $row=[]; }
                $rows[]=[$this->button('extract_audio','quality:'.$job['id'].':audio')];
                continue;
            }
            $row[]=$this->ui->button(Ui::qualityKey($height),'quality:'.$job['id'].':'.$height,['quality'=>$height.'p']);
            if(count($row)===2) { $rows[]=$row; $row=[]; }
        }
        if($row) $rows[]=$row;
        $caption=$this->ui->coverCaption($media['title'],(string)round($this->config->maxBytes/1000000,1));
        if(!empty($media['poster'])) $this->jobPoster($job,$media['poster'],$caption,$rows);
        else $this->jobMessage($job,$caption,$rows);
        $this->store->query("UPDATE jobs SET status='waiting_quality' WHERE id=?",[$job['id']]);
    }
    private function qualityChoice(int $chat,int $user,array $cb,array $parts): void
    {
        $quality=match($parts[2]) {'cancel'=>null,'audio'=>'audio',default=>(int)$parts[2]};
        $job=$this->store->choose((int)$parts[1],$user,$chat,$quality);
        $waiting=!$job&&$this->store->query("SELECT 1 FROM jobs WHERE id=? AND user_id=? AND chat_id=? AND status='waiting_quality'",[(int)$parts[1],$user,$chat])->fetchColumn();
        $notice=$waiting?($this->store->busy($user,$chat)?'quality_busy':($this->store->full()?'queue_full':'quality_expired')):'quality_expired';
        $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$job?'':$this->ui->plain($notice),'show_alert'=>!$job,'cache_time'=>0]);
        if(!$job) return;
        try {
            $this->jobMessage($job,$this->text($quality===null?'cancelled':($quality==='audio'?'audio_downloading':'downloading')));
            if($quality!==null) $this->store->query("UPDATE jobs SET status='queued' WHERE id=? AND status='confirming'",[$job['id']]);
        } catch(\Throwable $e) {
            $this->store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            throw $e;
        }
    }
    public function beginCookieImport(int $chat,int $user,int $message=0): void
    {
        if($chat!==$user||!$this->admin($user)) return;
        // Keep the service reply keyboard visible. A separate message without
        // reply markup remains editable throughout verification and success.
        $message=$this->send($chat,$this->text('cookie_import',['status'=>$this->ui->plain(is_readable($this->config->cookiePath())?'cookie_present':'cookie_missing')]));
        $this->store->set('cookie_menu.'.$user,(string)$message);
        $this->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,0)',[$user,'cookie.upload']);
    }
    private function cookieNotice(int $chat,int $user,string $text): void
    {
        $message=(int)$this->store->get('cookie_menu.'.$user,'0');
        $rows=[];
        try { $message=$this->send($chat,$text,$rows,$message); }
        catch(\RuntimeException $e) {
            if($e->getCode()!==404||$message<1) throw $e;
            $message=$this->send($chat,$text,$rows);
        }
        $this->store->set('cookie_menu.'.$user,(string)$message);
    }
    private function cookieDocument(int $chat,int $user,array $message): void
    {
        if(!$this->admin($user)) { $this->send($chat,$this->text('cookie_admin_only')); return; }
        $state=$this->store->query('SELECT key,expires FROM states WHERE user_id=?',[$user])->fetch();
        if(!$state||$state['key']!=='cookie.upload') { $this->send($chat,$this->text('cookie_begin_first')); return; }
        $saved=false; $folder=null; $notice='cookie_failed';
        try {
            $doc=$message['document'];
            if(strtolower(pathinfo((string)($doc['file_name']??''),PATHINFO_EXTENSION))!=='txt'||(int)($doc['file_size']??0)>1048576||empty($doc['file_id'])) {
                $notice='cookie_file_invalid'; $this->cookieNotice($chat,$user,$this->text($notice)); return;
            }
            $file=$this->api->call('getFile',['file_id'=>$doc['file_id']]);
            if(!is_array($file)||empty($file['file_path'])||(int)($file['file_size']??0)>1048576||!($this->api instanceof FileApi)) throw new \RuntimeException('Cookie file unavailable');
            $content=$this->api->downloadFile((string)$file['file_path'],1048576);
            validateYouTubeCookies($content);
            $this->cookieNotice($chat,$user,$this->text('cookie_checking'));
            require_once __DIR__.'/Download.php';
            $folder=$this->config->storage.'/.cookie-check-'.bin2hex(random_bytes(8));
            if(!mkdir($folder,0700)) throw new \RuntimeException('Cannot create cookie verification directory');
            $notice='cookie_check_inconclusive';
            $content=(new Download($this->config))->verifyCookies($content,$folder,
                $this->store->get('youtube_probe_url','https://www.youtube.com/watch?v=ybNMMK5Q318'));
            saveYouTubeCookies($this->config,$content); $saved=true;
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $notice='cookie_saved'; $this->cookieNotice($chat,$user,$this->text($notice));
        } catch(\Throwable $e) {
            error_log('Cookie import failure: '.get_class($e));
            if(!$saved) {
                if($e instanceof MediaFailure) $notice=match($e->reason) {
                    'cookies'=>'cookie_check_invalid','blocked'=>'cookie_check_blocked','network'=>'cookie_check_network',default=>'cookie_check_inconclusive',
                };
                $this->cookieNotice($chat,$user,$this->text($notice));
            }
        } finally {
            if($folder!==null) Download::clean($folder);
            if(isset($message['message_id'])) {
                try { $this->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message['message_id']]); }
                catch(\Throwable) { $this->cookieNotice($chat,$user,$this->text($notice)."\n\n".$this->text('cookie_delete_failed')); }
            }
        }
    }
    public function catalog(string $kind): array { return in_array($kind,['text','button'],true)?$this->ui->catalog($kind):[]; }
    public function requestVideo(int $update,int $user,int $chat,string $url,int $thread=0,int $sourceMessage=0): void
    {
        $this->store->query('INSERT OR IGNORE INTO users(id) VALUES(?)',[$user]);
        if(!$this->allowed($user,$chat)) { $this->send($chat,$this->denial($chat),[],0,$thread); return; }
        $result=$this->store->enqueue($update,$user,$chat,$url,$thread);
        if($result==='queued') {
            $job=$this->store->query('SELECT * FROM jobs WHERE update_id=?',[$update])->fetch();
            try { $this->jobMessage($job,$this->text(($service=galleryService($url))?$service.'_queued':'queued')); }
            catch(\Throwable $e) { $this->store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]); throw $e; }
            Reactions::apply($this->store,$this->api,$chat,$sourceMessage);
        } elseif($result!=='duplicate') $this->send($chat,$this->text(match($result) {'busy'=>'busy','full'=>'queue_full','cooldown'=>'cooldown','updating'=>'maintenance',default=>'failed'}),[],0,$thread);
    }
    public function handle(array $update): void
    {
        $cb=$update['callback_query']??null; $message=$cb['message']??$update['message']??null;
        $user=(int)($cb['from']['id']??$message['from']['id']??0);
        $type=$message['chat']['type']??''; $group=in_array($type,['group','supergroup'],true);
        if(!$message||(!$group&&$type!=='private')||!$user||!empty(($cb['from']??$message['from'])['is_bot'])||($group&&!empty($message['sender_chat']))) return;
        $chat=(int)$message['chat']['id'];
        if(($group&&$chat>=0)||(!$group&&$chat!==$user)) return;
        $thread=$group?max(0,(int)($message['message_thread_id']??0)):0;
        if($group&&!$this->store->groupsEnabled()) {
            if($cb) $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->ui->plain('groups_disabled'),'show_alert'=>true]);
            return;
        }
        $this->store->observeUser($cb['from']??$message['from']);
        if(!$group) $this->store->query('INSERT OR IGNORE INTO users(id) VALUES(?)',[$user]);
        $custom=new Personalization($this);
        if($cb) {
            $action=(string)($cb['data']??''); $isQuality=preg_match('/^quality:(\d+):(\d+|audio|cancel)$/D',$action,$choice)===1;
            $isPage=preg_match('/^(?:ig|pin|tt|x)page:(\d+):([1-9][0-9]?)(?::(\d+))?$/D',$action,$page)===1;
            $pageCounter=$action==='igpage:noop';
            $groupUpdate=$group&&in_array($action,['update:menu','update:run','update:open','update:start','update:progress','update:rollback','update:rollback:confirm','update:changes','update:back'],true);
            $privileged=!in_array($action,['home','download','help'],true)&&!$isQuality&&!$isPage&&!$pageCounter;
            if($privileged&&(($group&&!$groupUpdate)||!$this->admin($user))) {
                $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->ui->plain('denied'),'show_alert'=>true]); return;
            }
            if(!$this->allowed($user,$chat)) {
                $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id']]);
                $this->send($chat,$this->denial($chat),[],0,$thread); return;
            }
            if($groupUpdate) {
                $updater=new Updates($this->config,$this->store,$this->api,$thread); $menu=(int)($message['message_id']??0);
                $owns=$updater->ownsMenu($chat,$user,$menu);
                $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$owns?'':$this->ui->plain('denied'),'show_alert'=>!$owns,'cache_time'=>0]);
                if(!$owns) return;
                if(in_array($action,['update:menu','update:run','update:open'],true)) $updater->menu($chat,$user,$menu);
                elseif($action==='update:start') $updater->request($chat,$user,'update',$menu);
                elseif($action==='update:rollback') $updater->confirmRollback($chat,$user,$menu);
                elseif($action==='update:rollback:confirm') $updater->request($chat,$user,'rollback',$menu);
                elseif($action==='update:changes') $updater->changes($chat,$user,$menu);
                elseif($action==='update:back') $updater->close($chat,$user,$menu);
                return;
            }
            if($isQuality) { $this->qualityChoice($chat,$user,$cb,$choice); return; }
            if($pageCounter) { $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'cache_time'=>0]); return; }
            if($isPage) {
                $topic=$group&&!isset($message['message_thread_id'])?null:$thread;
                $pageService=galleryFromPrefix(substr(strstr($action,':',true),0,-4));
                $notice=(new GalleryPages($this->config,$this->store,$this->api,$pageService))->queue((int)$page[1],$user,$chat,(int)($message['message_id']??0),$topic,(int)$page[2],isset($page[3])?(int)$page[3]:null);
                $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->ui->plain($notice===''?$pageService.'_page_loading':$notice),'show_alert'=>$notice!=='','cache_time'=>0]);
                return;
            }
            $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'cache_time'=>0]);
            if($action==='custom:noop') return;
            if(!$group) $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            if($action==='help'||($group&&in_array($action,['home','download'],true))) { (new GroupSupport($this))->help($chat,$thread); return; }
            $menu=(int)($message['message_id']??0);
            if($custom->handle($chat,$user,$action,$menu)) return;
            if($action==='home') $this->home($chat,$user);
            elseif($action==='update:progress') return;
            elseif($action==='download') $this->send($chat,$this->text('prompt'));
            elseif($action==='admin') $this->panel($chat,$menu);
            elseif($action==='admins:cancel') $this->panel($chat,$menu);
            elseif($action==='health') $this->health($chat);
            elseif($action==='cookies') (new Cookies($this))->handle($chat,$user,'cookies');
            elseif(in_array($action,['cookies:info','cookies:import'],true)) {
                (new Cookies($this))->handle($chat,$user,'cookie_youtube');
                $this->beginCookieImport($chat,$user);
            }
            elseif($action==='cookies:youtube') (new Cookies($this))->handle($chat,$user,'cookie_youtube');
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
        if($group) { (new GroupSupport($this))->message($update,$user,$chat,$thread); return; }
        if(!$this->allowed($user)) { $this->send($chat,$this->denial()); return; }
        $text=trim((string)($message['text']??''));
        if(preg_match('~^(/\w+)@[A-Za-z0-9_]+$~D',$text,$command)) $text=$command[1];
        $reply=$this->ui->replyAction($text);
        $commands=['/cancel','/start','/admin','/health','/cookies','/update','/customize','/help'];
        $previousState=in_array($reply,['cookie_delete_confirm','ig_cookie_confirm','pin_cookie_confirm','tt_cookie_confirm','x_cookie_confirm'],true)?$this->store->query('SELECT key FROM states WHERE user_id=? AND (expires=0 OR expires>?)',[$user,time()])->fetchColumn():null;
        if(in_array($text,$commands,true)||$reply!==null) $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        if($text==='/id') { $this->send($chat,$this->text('your_id',['id'=>$user])); return; }
        if($this->admin($user)) {
            if($text==='/admin'||$reply==='admin') { $this->panel($chat); return; }
            if($reply==='add_admin') { (new Administrators($this))->begin($chat,$user); return; }
            if($text==='/health'||$reply==='health') { $this->health($chat); return; }
            if($text==='/cookies') { (new Cookies($this))->handle($chat,$user,'cookies'); return; }
            if($reply!==null&&(new InstagramCookies($this))->handle($chat,$user,$reply,is_string($previousState)?$previousState:null)) return;
            if($reply!==null&&(new PinterestCookies($this))->handle($chat,$user,$reply,is_string($previousState)?$previousState:null)) return;
            if($reply!==null&&(new TikTokCookies($this))->handle($chat,$user,$reply,is_string($previousState)?$previousState:null)) return;
            if($reply!==null&&(new TwitterCookies($this))->handle($chat,$user,$reply,is_string($previousState)?$previousState:null)) return;
            if($reply!==null&&(new Cookies($this))->handle($chat,$user,$reply,is_string($previousState)?$previousState:null)) return;
            if($text==='/update'||$reply==='update') { (new Updates($this->config,$this->store,$this->api))->menu($chat,$user,0,'',true); return; }
            if($text==='/customize'||$reply==='customize') { $custom->home($chat); return; }
            if($reply==='on'||$reply==='off') { $this->store->set('enabled',$reply==='on'?'1':'0'); $this->panel($chat); return; }
            if($reply==='back_home') { $this->home($chat,$user); return; }
        }
        if(in_array($text,['/start','/cancel'],true)) { $this->home($chat,$user); return; }
        if($text==='/help'||$reply==='help') { (new GroupSupport($this))->help($chat); return; }
        if($reply==='download') { $this->send($chat,$this->text('prompt')); return; }
        $state=$this->store->query('SELECT key FROM states WHERE user_id=? AND (expires=0 OR expires>?)',[$user,time()])->fetchColumn();
        if($this->admin($user)&&is_string($state)&&str_starts_with($state,'admins.add:')) {
            (new Administrators($this))->receive($chat,$user,$message,$state); return;
        }
        if($this->admin($user)&&is_string($state)&&str_starts_with($state,'ui:')) {
            $custom->receive($chat,$user,$message,json_decode(substr($state,3),true,512,JSON_THROW_ON_ERROR)); return;
        }
        if(isset($message['document'])) {
            if($state==='tt_cookie.upload') (new TikTokCookies($this))->document($chat,$user,$message);
            elseif($state==='x_cookie.upload') (new TwitterCookies($this))->document($chat,$user,$message);
            elseif($state==='pin_cookie.upload') (new PinterestCookies($this))->document($chat,$user,$message);
            elseif($state==='ig_cookie.upload') (new InstagramCookies($this))->document($chat,$user,$message);
            else $this->cookieDocument($chat,$user,$message);
            return;
        }
        if($text==='') return;
        if($state==='cookie.upload'&&$this->admin($user)) { $this->cookieNotice($chat,$user,$this->text('cookie_waiting')); return; }
        if($state==='ig_cookie.upload'&&$this->admin($user)) { $this->send($chat,$this->text('ig_cookie_waiting')); return; }
        if($state==='pin_cookie.upload'&&$this->admin($user)) { $this->send($chat,$this->text('pin_cookie_waiting')); return; }
        if($state==='tt_cookie.upload'&&$this->admin($user)) { $this->send($chat,$this->text('tt_cookie_waiting')); return; }
        if($state==='x_cookie.upload'&&$this->admin($user)) { $this->send($chat,$this->text('x_cookie_waiting')); return; }
        $url=mediaUrl($text);
        if(!$url) { $this->send($chat,$this->text('invalid')); return; }
        $this->requestVideo((int)$update['update_id'],$user,$chat,$url,0,(int)($message['message_id']??0));
    }
}

