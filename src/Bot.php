<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/MusicMenu.php';
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
require_once __DIR__.'/CookieJars.php';
require_once __DIR__.'/CookieHealth.php';
require_once __DIR__.'/Reports.php';
require_once __DIR__.'/AdminCommands.php';
require_once __DIR__.'/MusicSearch.php';
require_once __DIR__.'/Radio.php';
require_once __DIR__.'/RadioPanel.php';
require_once __DIR__.'/VideoChats.php';
require_once __DIR__.'/Shares.php';
require_once __DIR__.'/TimedDeletes.php';
require_once __DIR__.'/XnxxSearch.php';

final class Bot
{
    public Ui $ui;
    /** Set only by tests, which read music pages from local fixtures instead of the real sites. */
    public ?MusicCatalog $musicCatalog=null;
    public ?SoundCloud $musicSoundCloud=null;
    public function __construct(public Config $config,public Store $store,public Api $api) { $this->ui=new Ui($store); ErrorAlerts::bind($config,$store,$api); }
    public function text(string $key,array $values=[],array $raw=[]): string { return $this->ui->text($key,$values,$raw); }
    public function label(string $key): string { return $this->ui->label($key); }
    public function admin(int $id): bool { return $this->store->isAdmin($id,$this->config->admins); }
    public function allowed(int $id,?int $chat=null): bool { return !($chat<0&&!$this->store->groupsEnabled())&&($this->admin($id)||($this->store->enabled()&&$this->store->get('admins_only','0')!=='1')); }
    /**
     * Adult-site links: off unless the administrator turns them on, and by default only for administrators. They work in a
     * private chat with the bot and in groups (when group use is on); the video is deleted again a few seconds after it
     * is sent unless that filter is switched off for that kind of chat. Everyone else gets the usual "send a supported
     * link" answer, so the site is never advertised.
     */
    public function adultAllowed(int $user,int $chat,string $site='pornhub'): bool
    {
        if(!in_array($site,adultServices(),true)||$this->store->get('adult.'.$site,'0')!=='1') return false;
        if($chat<0) { if(!$this->store->groupsEnabled()) return false; }
        elseif($chat<1||$chat!==$user) return false;
        return $this->store->get('adult.admins_only','1')!=='1'||$this->admin($user);
    }
    /**
     * Why an adult-site link was refused, told ONLY to an administrator of the bot (who can change it): the text key, or null
     * for everyone else, so the site is never advertised. In a group the hint goes to the administrator's private chat.
     */
    public function adultHint(int $user,int $chat,string $site='pornhub'): ?string
    {
        if(!$this->admin($user)||!in_array($site,adultServices(),true)) return null;
        if($this->store->get('adult.'.$site,'0')!=='1') return $site==='pornhub'?'adult_hint_off':$site.'_hint_off';
        if($chat<0&&!$this->store->groupsEnabled()) return 'adult_hint_groups';
        return null;
    }
    /** Seconds until the video of this chat is deleted, or 0 when the filter for this kind of chat is off. */
    public function adultDeleteAfter(int $chat): int
    {
        if($this->store->get($chat<0?'adult.delete_group':'adult.delete_private','1')!=='1') return 0;
        return self::adultDelays($this->store);
    }
    /** The chosen delay, one of 10, 30, 60 or 180 seconds. */
    public static function adultDelays(Store $store): int
    {
        $seconds=(int)$store->get('adult.delete_seconds','10');
        return in_array($seconds,self::ADULT_DELAYS,true)?$seconds:10;
    }
    public const ADULT_DELAYS=[10,30,60,180];
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
            if($message>0) $result=$this->api->call('editMessageMedia',$params+['message_id'=>$message,
                'media'=>['type'=>'photo','media'=>$poster,'caption'=>Ui::fitCaption($caption),'parse_mode'=>'HTML']]);
            else {
                if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
                $result=$this->api->call('sendPhoto',$params+['photo'=>$poster,'caption'=>Ui::fitCaption($caption),'parse_mode'=>'HTML']);
                $message=(int)($result['message_id']??0);
                if(!$message) throw new \RuntimeException('Missing poster message ID');
            }
            if(in_array(adultService($job['url']),['xvideos','tikporn','youporn','eporner'],true)) {
                $photos=is_array($result)&&is_array($result['photo']??null)?$result['photo']:[];
                $poster=$photos?(string)(end($photos)['file_id']??''):'';
            }
            $this->store->query("UPDATE jobs SET message_id=?,message_kind='photo',poster=? WHERE id=?",[$message,$poster,$job['id']]);
        } catch(\RuntimeException $e) {
            if($e->getCode()===404&&$message>0) {
                $this->store->query('UPDATE jobs SET message_id=0 WHERE id=?',[$job['id']]);
                $this->jobPoster($job,$poster,$caption,$rows); return;
            }
            if($e->getCode()!==400) throw $e;
            // A rejected thumbnail must not prevent choosing and downloading the video.
            if(in_array($site=adultService($job['url']),['xvideos','tikporn','youporn','eporner'],true)) ErrorAlerts::event((['xvideos'=>'XVideos','tikporn'=>'TikPorn','youporn'=>'YouPorn','eporner'=>'Eporner'][$site]).' quality cover remote=telegram_400');
            $this->jobMessage($job,$caption,$rows);
        }
    }
    /**
     * The status message becomes a photo uploaded from a local file (for pictures Telegram cannot fetch by address). The
     * file ID Telegram returns is kept as the job's poster, so a lost message can be sent again without the file.
     */
    public function jobPosterFile(array $job,string $path,string $caption,array $rows): void
    {
        $message=(int)$this->store->query('SELECT message_id FROM jobs WHERE id=?',[$job['id']])->fetchColumn();
        $head=(string)file_get_contents($path,false,null,0,12);
        [$mime,$name]=str_starts_with($head,"\x89PNG")?['image/png','poster.png']:(str_starts_with($head,'RIFF')?['image/webp','poster.webp']:['image/jpeg','poster.jpg']);
        $file=new \CURLFile($path,$mime,$name);
        $params=['chat_id'=>(int)$job['chat_id'],'reply_markup'=>['inline_keyboard'=>$rows]];
        try {
            if($message>0) $result=$this->api->call('editMessageMedia',$params+['message_id'=>$message,'poster'=>$file,
                'media'=>['type'=>'photo','media'=>'attach://poster','caption'=>Ui::fitCaption($caption),'parse_mode'=>'HTML']]);
            else {
                if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
                $result=$this->api->call('sendPhoto',$params+['photo'=>$file,'caption'=>Ui::fitCaption($caption),'parse_mode'=>'HTML']);
                $message=(int)($result['message_id']??0);
                if(!$message) throw new \RuntimeException('Missing poster message ID');
            }
            $photos=is_array($result)&&is_array($result['photo']??null)?$result['photo']:[];
            $fileId=$photos?(string)(end($photos)['file_id']??''):'';
            $this->store->query("UPDATE jobs SET message_id=?,message_kind='photo',poster=? WHERE id=?",[$message,$fileId,$job['id']]);
        } catch(\RuntimeException $e) {
            if($e->getCode()===404&&$message>0) {
                $this->store->query('UPDATE jobs SET message_id=0 WHERE id=?',[$job['id']]);
                $this->jobPosterFile($job,$path,$caption,$rows); return;
            }
            if($e->getCode()!==400) throw $e;
            // A refused picture must not prevent choosing and downloading the video.
            if(in_array($site=adultService($job['url']),['xvideos','tikporn','youporn','eporner'],true)) ErrorAlerts::event((['xvideos'=>'XVideos','tikporn'=>'TikPorn','youporn'=>'YouPorn','eporner'=>'Eporner'][$site]).' quality cover upload=telegram_400');
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
        AdminCommands::register($this->api,$this->store,$this->ui,$chat);
        $this->sendKeyboard($chat,$this->text('admin_panel',['state'=>$this->text($on?'state_on':'state_off'),'commands'=>AdminCommands::lines($this->ui),
            'health'=>$this->ui->label('health'),'cookies'=>$this->ui->label('cookies'),'cookie_settings'=>$this->ui->label('cookie_settings'),'add_admin'=>$this->ui->label('add_admin')],['state','commands']),[
            [$this->replyButton('customize'),$this->replyButton('health')],
            [$this->replyButton('cookies'),$this->replyButton('cookie_settings')],
            [$this->replyButton('update'),$this->replyButton('add_admin')],
            [$this->replyButton($on?'off':'on')],
            [$this->replyButton('back_home')],
        ]);
    }
    public function health(int $chat,int $message=0): void
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
            'running'=>$running,'cookies'=>(new CookieHealth($this->config,$this->store,$this->api))->summary(),
            'proxy'=>$this->text($this->config->youtubeProxy!==''?'configured':'not_configured'),
            'gproxy'=>$this->text($this->config->galleryProxy!==''?'configured':'not_configured'),
            'pot'=>$this->text($this->config->youtubePotUrl!==''?'configured':'not_configured'),
            'error'=>$this->lastErrorMessage(),
        ],['state','poller','worker','cookies','proxy','gproxy','pot']),Reports::homeRows($this->ui),$message);
    }
    private function lastErrorMessage(): string
    {
        return $this->ui->plain('errors_private_only');
    }
    public function qualityMenu(array $job,array $media): void
    {
        $this->store->query("UPDATE jobs SET status='running',title=?,options=?,expires=0 WHERE id=?",
            [$media['title'],json_encode($media['options'],JSON_THROW_ON_ERROR),$job['id']]);
        $rows=[]; $row=[]; $perRow=max(1,min(3,(int)$this->store->get('cover.per_row','2')));
        // 240p exists only on sites with a lower ladder (Pornhub); it leads the menu when offered.
        $order=$this->ui->coverOrder(); if(!empty($media['options']['240'])) array_unshift($order,'240');
        if(in_array(adultService($job['url']),['tikporn','eporner'],true)) foreach(array_keys($media['options']) as $height) {
            $height=(string)$height;
            if(!ctype_digit($height)||in_array($height,$order,true)) continue;
            $position=count($order);
            foreach($order as $i=>$value) if($value==='audio'||(ctype_digit($value)&&(int)$value>(int)$height)) { $position=$i; break; }
            array_splice($order,$position,0,[$height]);
        }
        foreach($order as $height) {
            if(empty($media['options'][$height])) continue;
            if($height==='audio') {
                if($row) { $rows[]=$row; $row=[]; }
                $rows[]=[$this->button('extract_audio','quality:'.$job['id'].':audio')];
                continue;
            }
            $key=Ui::qualityKey($height);
            $row[]=$this->ui->button($this->ui->definition('button',$key)?$key:'quality_native','quality:'.$job['id'].':'.$height,['quality'=>$height.'p']);
            if(count($row)===$perRow) { $rows[]=$row; $row=[]; }
        }
        if($row) $rows[]=$row;
        $caption=$this->ui->coverCaption($media['title'],(string)round($this->config->maxBytes/1000000,1));
        if(!empty($media['poster_file'])) $this->jobPosterFile($job,(string)$media['poster_file'],$caption,$rows);
        elseif(!empty($media['poster'])) $this->jobPoster($job,$media['poster'],$caption,$rows);
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
        $message=$this->send($chat,$this->text('cookie_import',['status'=>$this->ui->plain(youtubeCookiePool($this->config)->count()>0?'cookie_present':'cookie_missing')]));
        $this->store->set('cookie_menu.'.$user,(string)$message);
        $this->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,0)',[$user,'cookie.upload']);
    }
    /**
     * A long export pasted into Telegram arrives as several messages of at most 4096 characters. While an import waits,
     * the parts of one export are kept for two minutes and joined; the export is imported once it is complete.
     * @return string|bool the whole text when it is complete, true when a part was kept and more is expected, false when this is not a paste
     */
    private function pastedParts(int $chat,int $user,array $message,string $text): string|bool
    {
        $key='cookie.parts.'.$user; $saved=json_decode($this->store->get($key,''),true);
        $buffer=is_array($saved)&&time()-(int)($saved['time']??0)<=120?(string)$saved['text']:'';
        $more=$buffer!=='';
        $jsonish=fn(string $value)=>str_starts_with(ltrim($value),'[')||str_starts_with(ltrim($value),'{')||(str_contains($value,'"domain"')&&str_contains($value,'"name"'));
        if(!$more&&!CookieFormats::looksLikeCookies($text)&&!$jsonish($text)) return false;
        $whole=$buffer.$text;
        if(!$more) { $whole=$text; }
        elseif(!str_starts_with(ltrim($buffer),'[')&&!str_starts_with(ltrim($buffer),'{')) {
            // Netscape rows: the cut fell inside a row (join as is) or after one (join with a new line).
            $last=substr($buffer,(int)strrpos($buffer,"\n")+1);
            $whole=$buffer.(count(explode("\t",$last))===7?"\n":'').$text;
        }
        $json=$jsonish($whole);
        $complete=false;
        if(strlen($whole)<=CookiePool::MAX_BYTES) {
            if($json) {
                try { CookieFormats::toNetscape($whole); $complete=true; }
                catch(\RuntimeException) {
                    // The list's closing bracket has arrived but its start did not (only the tail was sent): keep every whole cookie in it.
                    if(str_ends_with(rtrim($whole),']')&&($cookies=CookieFormats::salvage($whole))) {
                        $whole=json_encode($cookies,JSON_THROW_ON_ERROR); $complete=true; $this->store->set('cookie.partial.'.$user,'1');
                    }
                }
            } else $complete=strlen($text)<3800;
        }
        if($complete||strlen($whole)>CookiePool::MAX_BYTES) { $this->store->query('DELETE FROM settings WHERE key=?',[$key]); return $complete||$more?$whole:false; }
        // A kept part holds credentials, so its message goes now; the last one is deleted by the import itself.
        if(isset($message['message_id'])) { try { $this->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$message['message_id']]); } catch(\Throwable) {} }
        $parts=(int)($saved['parts']??0)+1;
        $this->store->set($key,json_encode(['text'=>$whole,'time'=>time(),'parts'=>$parts],JSON_THROW_ON_ERROR));
        $this->cookieNotice($chat,$user,$this->text('cookie_part_received',['n'=>$parts,'size'=>strlen($whole)]));
        return true;
    }
    private function cookieNotice(int $chat,int $user,string $text): void
    {
        (new CookieJars($this))->notice($chat,'cookie_menu.'.$user,$text);
    }
    private function cookieDocument(int $chat,int $user,array $message): void
    {
        if(!$this->admin($user)) { $this->send($chat,$this->text('cookie_admin_only')); return; }
        $state=$this->store->query('SELECT key,expires FROM states WHERE user_id=?',[$user])->fetch();
        if(!$state||$state['key']!=='cookie.upload') { $this->send($chat,$this->text('cookie_begin_first')); return; }
        $folder=null; $started=false;
        // One TXT, JSON file or pasted export is tested against YouTube. A ZIP or several files get a
        // structure check only, so the single-threaded poller is never blocked by many live probes.
        $verify=function(string $content) use (&$folder,&$started,$chat,$user): string {
            validateYouTubeCookies($content);
            $this->cookieNotice($chat,$user,$this->text('cookie_checking'));
            require_once __DIR__.'/Download.php';
            $folder=$this->config->storage.'/.cookie-check-'.bin2hex(random_bytes(8));
            if(!mkdir($folder,0700)) throw new \RuntimeException('Cannot create cookie verification directory');
            $started=true;
            return (new Download($this->config))->verifyCookies($content,$folder,
                $this->store->get('youtube_probe_url','https://www.youtube.com/watch?v=ybNMMK5Q318'));
        };
        $explain=fn(\Throwable $e): string=>$this->text($e instanceof MediaFailure?match($e->reason) {
            'cookies'=>'cookie_check_invalid','blocked'=>'cookie_check_blocked','network'=>'cookie_check_network',default=>'cookie_check_inconclusive',
        }:($started?'cookie_check_inconclusive':'cookie_failed'));
        try { (new CookieJars($this))->receive($chat,$user,$message,'yt','cookie_menu.'.$user,$this->text('cookie_failed'),$verify,$explain); }
        finally { if($folder!==null) Download::clean($folder); }
    }
    public function catalog(string $kind): array { return in_array($kind,['text','button'],true)?$this->ui->catalog($kind):[]; }
    /** @return string what happened: queued, duplicate, busy, full, cooldown, updating, denied or failed */
    /**
     * @param ?array{message:int,kind:string,poster:string} $adopt an existing message (a music menu) that becomes this
     *        download's status message instead of a new one, so the menu closes into the download
     */
    public function requestVideo(int $update,int $user,int $chat,string $url,int $thread=0,int $sourceMessage=0,?string $phase=null,array $options=[],bool $menu=false,?array $adopt=null): string
    {
        $this->store->query('INSERT OR IGNORE INTO users(id) VALUES(?)',[$user]);
        if(!$this->allowed($user,$chat)) { $this->send($chat,$this->denial($chat),[],0,$thread); return 'denied'; }
        $result=$this->store->enqueue($update,$user,$chat,$url,$thread,$phase,$options,$menu);
        if($result==='queued') {
            $job=$this->store->query('SELECT * FROM jobs WHERE update_id=?',[$update])->fetch();
            $site=adultService($url);
            $deleteSource=$sourceMessage>0&&$site!==null&&$this->adultAllowed($user,$chat,$site)&&$this->store->get('adult.delete_source','1')==='1';
            if($deleteSource) {
                // A new job has message_id=0 and cannot be claimed. Keep that barrier until the link is gone.
                try { $deleted=$this->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$sourceMessage])===true; }
                catch(\Throwable) { $deleted=false; }
                if(!$deleted) {
                    $this->store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
                    $this->jobMessage($job,$this->text('adult_link_delete_failed'));
                    ErrorAlerts::report($site,'process',$site.'=delete');
                    return 'failed';
                }
            }
            if($adopt!==null&&$adopt['message']>0) $this->store->query('UPDATE jobs SET message_id=?,message_kind=?,poster=? WHERE id=?',[$adopt['message'],$adopt['kind']==='photo'?'photo':'text',$adopt['poster'],$job['id']]);
            // A page with a list of tracks opens a menu; every other link is one download.
            $service=galleryService($url)??$phase??musicService($url);
            try { $collection=$service?null:collectionUrl($url); $this->jobMessage($job,$this->text($service?$service.'_queued':($collection!==null?($collection['service']==='search'?'search_queued':'menu_queued'):'queued'))); }
            catch(\Throwable $e) { $this->store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]); throw $e; }
            if(!$deleteSource) Reactions::apply($this->store,$this->api,$chat,$sourceMessage,musicService($url)!==null||collectionUrl($url)!==null||$phase==='spotify');
        } elseif($result!=='duplicate'&&!$menu) $this->send($chat,$this->text(match($result) {'busy'=>'busy','full'=>'queue_full','cooldown'=>'cooldown','updating'=>'maintenance',default=>'failed'}),[],0,$thread);
        return $result;
    }
    public function handle(array $update): void
    {
        // "Share" under a track: anyone may send that track on through the bot's inline mode.
        if(is_array($update['inline_query']??null)) { (new Shares($this))->answer($update['inline_query']); return; }
        // A video chat or live stream started or ended in a group or channel: the administrators are told, nothing else happens.
        if(VideoChats::handle($this,$update)) return;
        $cb=$update['callback_query']??null; $message=$cb['message']??$update['message']??null;
        $user=(int)($cb['from']['id']??$message['from']['id']??0);
        $type=$message['chat']['type']??'';
        $radioCallback=$cb&&preg_match('/^rd:[A-Za-z]:(?:-?\d{1,14}|[A-Za-z0-9_-]{16})(?::(?:0|[a-f0-9]{16}))?$/D',(string)($cb['data']??''))===1;
        $group=in_array($type,['group','supergroup'],true)||($type==='channel'&&$radioCallback);
        if(!$message||(!$group&&$type!=='private')||!$user||!empty(($cb['from']??$message['from'])['is_bot'])||(!$cb&&$group&&!empty($message['sender_chat']))) return;
        $chat=(int)$message['chat']['id'];
        if(($group&&$chat>=0)||(!$group&&$chat!==$user)) return;
        $thread=$group?max(0,(int)($message['message_thread_id']??0)):0;
        if($group&&!$this->store->groupsEnabled()) {
            if($cb) $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->ui->plain('groups_disabled'),'show_alert'=>true]);
            elseif($this->admin($user)&&preg_match('~^/radio(?:@([A-Za-z0-9_]+))?$~iD',trim((string)($message['text']??'')),$radioCommand)
                &&(empty($radioCommand[1])||strcasecmp($radioCommand[1],$this->store->get('bot_username'))===0)) {
                // An explicit management request should explain the disabled group setting privately.
                try { $this->send($user,$this->text('groups_disabled')); } catch(\Throwable) {}
            }
            elseif(($site=adultService(trim((string)($message['text']??''))))!==null&&($hint=$this->adultHint($user,$chat,$site))!==null) {
                // Group use is off, so nothing is said in the group; an administrator is told privately why the link was not taken.
                try { $this->send($user,$this->text($hint)); } catch(\Throwable) { /* The administrator never started the bot. */ }
            }
            return;
        }
        $this->store->observeUser($cb['from']??$message['from']);
        if(!$group) $this->store->query('INSERT OR IGNORE INTO users(id) VALUES(?)',[$user]);
        $custom=new Personalization($this);
        if($cb) {
            $action=(string)($cb['data']??''); $isQuality=preg_match('/^quality:(\d+):(\d+|audio|cancel)$/D',$action,$choice)===1;
            $isPage=preg_match('/^(?:ig|pin|tt|x)page:(\d+):([1-9][0-9]?)(?::(\d+))?$/D',$action,$page)===1;
            $pageCounter=$action==='igpage:noop';
            $groupUpdate=$group&&in_array($action,['update:menu','update:run','update:open','update:start','update:progress','update:rollback','update:rollback:confirm','update:changes','update:back','update:close'],true);
            $isMenu=preg_match('/^mm:\d{1,12}:[a-z](?::[A-Za-z0-9_-]{1,24})?$/D',$action)===1||preg_match('/^mb:\d{1,12}$/D',$action)===1;
            $isRadio=$radioCallback;
            $isXnxxSearch=preg_match('/^(?:xs|vs):\d{1,12}:(?:[ip]:\d{1,3}|x)$/D',$action)===1;
            $privileged=!in_array($action,['home','download','help'],true)&&!$isQuality&&!$isPage&&!$pageCounter&&!$isMenu&&!$isRadio&&!$isXnxxSearch;
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
                elseif(in_array($action,['update:back','update:close'],true)) $updater->close($chat,$user,$menu);
                return;
            }
            if($isMenu&&str_starts_with($action,'mb:')) { MusicMenu::stop($this,$cb,$chat,$user,(int)substr($action,3)); return; }
            if($isMenu) { (new MusicMenu($this,$this->musicCatalog,$this->musicSoundCloud))->callback($update,$chat,$user,$thread,(int)($message['message_id']??0)); return; }
            if($isRadio) { (new RadioPanel($this))->callback($update,$chat,$user,$thread,(int)($message['message_id']??0)); return; }
            if($isXnxxSearch) { (new XnxxSearch($this))->callback($update,$chat,$user,$thread,(int)($message['message_id']??0)); return; }
            if($isQuality) { $this->qualityChoice($chat,$user,$cb,$choice); return; }
            if($pageCounter) { $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'cache_time'=>0]); return; }
            if($isPage) {
                $topic=$group&&!isset($message['message_thread_id'])?null:$thread;
                $pageService=galleryFromPrefix(substr(strstr($action,':',true),0,-4));
                $notice=(new GalleryPages($this->config,$this->store,$this->api,$pageService))->queue((int)$page[1],$user,$chat,(int)($message['message_id']??0),$topic,(int)$page[2],isset($page[3])?(int)$page[3]:null);
                $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->ui->plain($notice===''?$pageService.'_page_loading':$notice),'show_alert'=>$notice!=='','cache_time'=>0]);
                return;
            }
            if(str_starts_with($action,'custom:hint:')) {
                // White title buttons explain themselves; they never cancel a pending edit.
                $hint=$custom->hint(substr($action,12));
                $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$hint,'show_alert'=>$hint!=='','cache_time'=>0]); return;
            }
            if(preg_match('/^ck(?:rm|ex|ts|lb):/',$action)) { (new CookieJars($this))->callback($cb,$chat,$user,(int)($message['message_id']??0)); return; }
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
            elseif(str_starts_with($action,'cs:')) (new Cookies($this))->callback($chat,substr($action,3),$menu);
            elseif(str_starts_with($action,'rp:')) (new Reports($this))->show($chat,substr($action,3),$menu);
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
            elseif($action==='update:close') (new Updates($this->config,$this->store,$this->api,$thread))->close($chat,$user,$menu);
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
        $commands=['/cancel','/start','/admin','/health','/cookies','/update','/customize','/help','/radio'];
        $previousState=in_array($reply,['cookie_delete_confirm','ig_cookie_confirm','pin_cookie_confirm','tt_cookie_confirm','x_cookie_confirm'],true)?$this->store->query('SELECT key FROM states WHERE user_id=? AND (expires=0 OR expires>?)',[$user,time()])->fetchColumn():null;
        if(in_array($text,$commands,true)||$reply!==null) { $this->store->query('DELETE FROM states WHERE user_id=?',[$user]); (new CookieJars($this))->cancelLabel($user); }
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
        if($text==='/radio') { (new RadioPanel($this))->commandPrivate($chat,$user); return; }
        if($text==='/help'||$reply==='help') { (new GroupSupport($this))->help($chat); return; }
        if($reply==='download') { $this->send($chat,$this->text('prompt')); return; }
        $state=$this->store->query('SELECT key FROM states WHERE user_id=? AND (expires=0 OR expires>?)',[$user,time()])->fetchColumn();
        if($this->admin($user)&&is_string($state)&&str_starts_with($state,'admins.add:')) {
            (new Administrators($this))->receive($chat,$user,$message,$state); return;
        }
        if($this->admin($user)&&$text!==''&&(new CookieJars($this))->pendingLabel($user)!==null) {
            (new CookieJars($this))->receiveLabel($chat,$user,$text); return;
        }
        if($this->admin($user)&&is_string($state)&&str_starts_with($state,'ui:')) {
            $custom->receive($chat,$user,$message,json_decode(substr($state,3),true,512,JSON_THROW_ON_ERROR)); return;
        }
        // The stream address and key of a radio, right after "set up": read, deleted from the chat, saved privately.
        if(is_string($state)&&str_starts_with($state,'radio.key:')&&$text!==''&&preg_match('/^radio\.key:(-?\d{1,14})$/D',$state,$radioChat)===1) { (new RadioPanel($this))->receiveKey($chat,$user,$message,(int)$radioChat[1]); return; }
        if(isset($message['document'])) {
            if($state==='tt_cookie.upload') (new TikTokCookies($this))->document($chat,$user,$message);
            elseif($state==='x_cookie.upload') (new TwitterCookies($this))->document($chat,$user,$message);
            elseif($state==='pin_cookie.upload') (new PinterestCookies($this))->document($chat,$user,$message);
            elseif($state==='ig_cookie.upload') (new InstagramCookies($this))->document($chat,$user,$message);
            else $this->cookieDocument($chat,$user,$message);
            return;
        }
        // Phones often cannot save a file, so a pasted export (Netscape, JSON or header string) is accepted while an import waits.
        $pasting=$text!==''&&$this->admin($user)&&in_array($state,['cookie.upload','ig_cookie.upload','pin_cookie.upload','tt_cookie.upload','x_cookie.upload'],true)?$this->pastedParts($chat,$user,$message,$text):false;
        if($pasting===true) return;
        if(is_string($pasting)) {
            $message['text']=$pasting;
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
        // /music and /spotify with the text to look for.
        if(($search=MusicSearch::parse($text))!==null) { MusicSearch::handle($this,$update,$user,$chat,0,$search); return; }
        $url=mediaUrl($text);
        if(!$url&&($adult=adultUrl($text))!==null) {
            if($this->adultAllowed($user,$chat,adultService($adult))) $url=$adult;
            elseif(($hint=$this->adultHint($user,$chat,adultService($adult)))!==null) { $this->send($chat,$this->text($hint)); return; }
        }
        if(!$url) { $this->send($chat,$this->text('invalid')); return; }
        $this->requestVideo((int)$update['update_id'],$user,$chat,$url,0,(int)($message['message_id']??0));
    }
}

