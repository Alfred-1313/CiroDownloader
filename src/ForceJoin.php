<?php
declare(strict_types=1);
namespace Zero;

/** Required-channel membership, with one qualifying human administrator sponsoring a group. */
final class ForceJoin
{
    private const DOWNLOAD_TTL=600;
    private static ?\WeakMap $schemas=null;
    private Api $checks;
    private float $deadline=0;
    public function __construct(private Config $config,private Store $store,private Api $api)
    {
        $this->checks=$api instanceof Telegram?new Telegram($config,2):$api;
        self::$schemas??=new \WeakMap(); if(isset(self::$schemas[$store])) return;
        $store->db->exec("CREATE TABLE IF NOT EXISTS membership_channels(id INTEGER PRIMARY KEY,chat_id INTEGER NOT NULL UNIQUE,title TEXT NOT NULL,url TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS membership_cache(key TEXT PRIMARY KEY,value TEXT NOT NULL,expires INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS membership_panels(token TEXT PRIMARY KEY,owner INTEGER NOT NULL,chat_id INTEGER NOT NULL,thread_id INTEGER NOT NULL,message_id INTEGER NOT NULL,kind TEXT NOT NULL,expires INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS membership_downloads(panel TEXT PRIMARY KEY,revision TEXT NOT NULL,url TEXT NOT NULL,update_id INTEGER NOT NULL,source_message INTEGER NOT NULL,expires INTEGER NOT NULL)");
        self::$schemas[$store]=true;
        $this->prune();
    }
    public function prune(): void
    {
        foreach(['membership_cache','membership_panels','membership_downloads'] as $table) $this->store->query("DELETE FROM $table WHERE expires<=?",[time()]);
    }
    public function channels(): array { return $this->store->query('SELECT * FROM membership_channels ORDER BY id')->fetchAll(); }
    public function active(): bool { return $this->store->get('membership.enabled','0')==='1'&&(bool)$this->channels(); }
    private function bump(): void { $this->store->query('DELETE FROM membership_cache'); }
    private function call(string $method,array $args): mixed
    {
        if(microtime(true)>$this->deadline||(int)$this->store->get('membership.retry','0')>time()) throw new \RuntimeException('Membership lookup budget');
        try { return $this->checks->call($method,$args); }
        catch(\RuntimeException $e) { if($e->getCode()===429) { $this->store->set('membership.retry',(string)(time()+30));$this->deadline=0; } throw $e; }
    }
    private function member(int $user,int $channel,bool $fresh): ?bool
    {
        $key='m:'.$channel.':'.$user;
        $cached=$this->store->query('SELECT value FROM membership_cache WHERE key=? AND expires>?',[$key,time()])->fetchColumn();
        if(!$fresh&&$cached!==false) return $cached==='unknown'?null:$cached==='1';
        try {
            $m=$this->call('getChatMember',['chat_id'=>$channel,'user_id'=>$user]);
            if(!is_array($m)||!is_string($m['status']??null)) throw new \RuntimeException('Membership response unavailable');
            $ok=in_array($m['status'],['creator','administrator','member'],true)||($m['status']==='restricted'&&!empty($m['is_member']));
        } catch(\Throwable) { $ok=null; }
        $this->store->query('INSERT INTO membership_cache(key,value,expires) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,expires=excluded.expires',[$key,$ok===null?'unknown':($ok?'1':'0'),time()+($ok===null?5:30)]);
        return $ok;
    }
    private function all(int $user,array $channels,bool $fresh): string
    {
        $unknown=false;
        foreach($channels as $channel) { $ok=$this->member($user,(int)$channel['chat_id'],$fresh); if($ok===false) return 'join'; if($ok===null) $unknown=true; }
        return $unknown?'unavailable':'ok';
    }
    public function verdict(int $user,int $chat,bool $fresh=false): string
    {
        if(!$this->active()||$this->store->isAdmin($user,$this->config->admins)) return 'ok';
        $this->deadline=microtime(true)+8; $channels=$this->channels();
        $group=$chat<0&&$this->store->get('membership.group_admin','1')==='1';
        if($group&&!$fresh&&$this->store->query("SELECT 1 FROM membership_cache WHERE key=? AND value='1' AND expires>?",['g:'.$chat,time()])->fetchColumn()) return 'ok';
        $own=$this->all($user,$channels,$fresh); if($own==='ok'||!$group) return $own;
        $unknown=$own==='unavailable';
        try {
            $admins=$this->call('getChatAdministrators',['chat_id'=>$chat]);
            if(!is_array($admins)) throw new \RuntimeException('Group administrators unavailable');
            foreach(array_slice($admins,0,50) as $admin) {
                $id=(int)($admin['user']['id']??0);
                if($id<1||!empty($admin['user']['is_bot'])||!in_array($admin['status']??'',['creator','administrator'],true)) continue;
                $result=$this->all($id,$channels,$fresh);
                if($result==='ok') {
                    $this->store->query('INSERT INTO membership_cache(key,value,expires) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,expires=excluded.expires',['g:'.$chat,'1',time()+30]);
                    return 'ok';
                }
                $unknown=$unknown||$result==='unavailable';
                if(microtime(true)>$this->deadline) break;
            }
        } catch(\Throwable) { $unknown=true; }
        return $unknown?'unavailable':'join';
    }
    /** @param ?array{url:string,update_id:int,source_message:int} $download a validated link sent by the user */
    public function guard(Bot $bot,int $user,int $chat,int $thread=0,?array $callback=null,?array $download=null): bool
    {
        $result=$this->verdict($user,$chat); if($result==='ok') return true;
        if($callback) $this->api->call('answerCallbackQuery',['callback_query_id'=>$callback['id'],'text'=>$bot->ui->plain('membership_needed'),'show_alert'=>true,'cache_time'=>0]);
        $this->prompt($bot,$user,$chat,$thread,$result,null,$download); return false;
    }
    private function pending(string $token): array|false
    {
        return $this->store->query('SELECT * FROM membership_downloads d WHERE panel=? AND expires>? AND NOT EXISTS(SELECT 1 FROM jobs j WHERE j.update_id=d.update_id)',[$token,time()])->fetch();
    }
    public function forgetDownload(int $user,int $chat,int $thread): void
    {
        $this->store->query("DELETE FROM membership_downloads WHERE panel IN (SELECT token FROM membership_panels WHERE owner=? AND chat_id=? AND thread_id=? AND kind='download')",[$user,$chat,$thread]);
    }
    private function edit(Bot $bot,array $panel,string $text,array $rows=[]): void
    {
        if($panel['kind']==='user_photo') {
            $this->api->call('editMessageCaption',['chat_id'=>(int)$panel['chat_id'],'message_id'=>(int)$panel['message_id'],
                'caption'=>Ui::fitCaption($text),'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>$rows]]);
            return;
        }
        $bot->send((int)$panel['chat_id'],$text,$rows,(int)$panel['message_id']);
    }
    public function prompt(Bot $bot,int $user,int $chat,int $thread,string $result='join',?array $job=null,?array $download=null): void
    {
        // A worker can update an older job while the poller is handling a new link.
        // Keep job status panels separate from the saved download and its buttons.
        $kinds=$job?"'user_photo','user_job'":"'user','download'";
        $old=$this->store->query("SELECT * FROM membership_panels WHERE owner=? AND chat_id=? AND thread_id=? AND kind IN ($kinds) AND expires>? ORDER BY CASE WHEN kind='download' THEN 0 ELSE 1 END,rowid DESC LIMIT 1",[$user,$chat,$thread,time()])->fetch();
        $p=$old?:['token'=>bin2hex(random_bytes(8)),'message_id'=>0];
        if($job) $p['message_id']=(int)$job['message_id'];
        $photo=$job&&($job['message_kind']??'text')==='photo'&&(int)$p['message_id']>0;
        $kept=$job?false:$this->pending($p['token']);
        if($download) {
            if($kept&&(int)$kept['update_id']===$download['update_id']) $download=null;
            else $download+=['revision'=>bin2hex(random_bytes(8)),'expires'=>time()+self::DOWNLOAD_TTL];
        }
        $pending=$download??$kept;
        $ui=$bot->ui; $rows=[];
        foreach($this->channels() as $channel) { $button=$ui->button('membership_channel','',['title'=>$channel['title']]); $button['url']=$channel['url']; unset($button['callback_data']); $rows[]=[$button]; }
        $rows[]=[$ui->button('membership_check','fj:'.$p['token'].':check')];
        if($pending) $rows[]=[$ui->button('membership_download_cancel','fj:'.$p['token'].':cancel:'.$pending['revision'])];
        if(!$old&&!$job&&($sticker=$this->store->get('membership.sticker'))!=='') try { $args=['chat_id'=>$chat,'sticker'=>$sticker]; if($chat<0&&$thread>0) $args['message_thread_id']=$thread; $this->api->call('sendSticker',$args); } catch(\Throwable) {}
        $key=$result==='unavailable'?'membership_unavailable':($chat<0&&$this->store->get('membership.group_admin','1')==='1'?'membership_group_prompt':'membership_prompt');
        $text=$ui->text($key);
        if($pending) {
            $notice=$ui->text('membership_download_saved');
            if(TextFormat::length(html_entity_decode(strip_tags($text."\n\n".$notice),ENT_QUOTES|ENT_HTML5,'UTF-8'))>4096) {
                $notice=$ui->definition('text','membership_download_saved')['value'];
            }
            $text.="\n\n".$notice;
        }
        try {
            if($photo) { $this->edit($bot,array_replace($p,['kind'=>'user_photo','chat_id'=>$chat]),$text,$rows); $id=(int)$p['message_id']; }
            else $id=$bot->send($chat,$text,$rows,(int)$p['message_id'],$thread);
        }
        catch(\RuntimeException $e) { if($e->getCode()!==404) throw $e; $photo=false; $id=$bot->send($chat,$text,$rows,0,$thread); }
        if($id<1) throw new \RuntimeException('Missing membership message ID');
        $kind=$job?($photo?'user_photo':'user_job'):($pending?'download':'user');
        $this->store->query('INSERT OR REPLACE INTO membership_panels VALUES(?,?,?,?,?,?,?)',[$p['token'],$user,$chat,$thread,$id,$kind,time()+900]);
        if($download) $this->store->query('INSERT OR REPLACE INTO membership_downloads VALUES(?,?,?,?,?,?)',
            [$p['token'],$download['revision'],$download['url'],$download['update_id'],$download['source_message'],$download['expires']]);
    }
    private function downloadButtons(Bot $bot,array $panel,array $pending): array
    {
        $callback='fj:'.$panel['token'];
        return [[$bot->ui->button('membership_download_continue',$callback.':resume:'.$pending['revision'])],
                [$bot->ui->button('membership_download_cancel',$callback.':cancel:'.$pending['revision'])]];
    }
    private function continueDownload(Bot $bot,array $panel,array $pending): void
    {
        $user=(int)$panel['owner'];
        $chat=(int)$panel['chat_id'];
        $thread=(int)$panel['thread_id'];
        // Recheck current access and use the original update ID to deduplicate repeated taps.
        if(!$this->guard($bot,$user,$chat,$thread)) return;
        $result=$bot->requestVideo((int)$pending['update_id'],$user,$chat,$pending['url'],$thread,(int)$pending['source_message'],
            adopt:['message'=>(int)$panel['message_id'],'kind'=>'text','poster'=>'']);
        if(in_array($result,['busy','full','cooldown','updating'],true)) return;
        $this->store->query('DELETE FROM membership_downloads WHERE panel=?',[$panel['token']]);
        $this->store->query('DELETE FROM membership_panels WHERE token=?',[$panel['token']]);
        if($result==='denied') $this->edit($bot,$panel,$bot->text('membership_download_closed'));
    }
    public function callback(Bot $bot,array $cb,int $user,int $chat,?int $thread): void
    {
        $parts=explode(':',(string)$cb['data']);$p=$this->store->query('SELECT * FROM membership_panels WHERE token=?',[$parts[1]??''])->fetch();
        $error=!$p?'callback_stale':((int)$p['owner']!==$user?'callback_owner':(((int)$p['chat_id']!==$chat||(int)$p['message_id']!==(int)$cb['message']['message_id']||($thread!==null&&(int)$p['thread_id']!==$thread)||(int)$p['expires']<=time())?'callback_stale':''));
        $op=$parts[2]??'';
        $pending=false;
        if($error===''&&$p['kind']!=='admin') {
            if(!in_array($op,['check','resume','cancel'],true)) $error='callback_stale';
            elseif($op!=='check') {
                $pending=$this->pending($p['token']);
                if(!$pending||$pending['revision']!==($parts[3]??'')) $error='membership_download_expired';
            }
            if($error===''&&$op!=='cancel'&&!$bot->allowed($user,$chat)) $error='callback_access';
        }
        if($error===''&&$p['kind']!=='admin'&&$op==='check') {
            $key='membership.tap.'.$user; $last=(int)$this->store->get($key,'0');
            if($last>=time()) $error='membership_wait'; else $this->store->set($key,(string)time());
        }
        if($error!=='') { $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>mb_substr($bot->ui->plain($error),0,200),'show_alert'=>true]); return; }
        $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id']]);
        if($p['kind']==='admin') { if($chat!==$user||!$bot->admin($user)) return; $this->adminAction($bot,$p,$op); return; }
        if($op==='cancel') {
            $this->store->query('DELETE FROM membership_downloads WHERE panel=?',[$p['token']]);
            $this->store->query('DELETE FROM membership_panels WHERE token=?',[$p['token']]);
            $this->edit($bot,$p,$bot->text('membership_download_cancelled'));
            return;
        }
        if($op==='resume') { $this->continueDownload($bot,$p,$pending); return; }
        $result=$this->verdict($user,$chat,true);
        if($result==='ok') {
            $pending=$this->pending($p['token']);
            if($pending) {
                $this->edit($bot,$p,$bot->text('membership_download_ready'),$this->downloadButtons($bot,$p,$pending));
                return;
            }
            $this->edit($bot,$p,$bot->text($p['kind']==='download'?'membership_download_expired':'membership_success'));
            $this->store->query('DELETE FROM membership_panels WHERE token=?',[$p['token']]);
            if($chat===$user) $bot->home($chat,$user);
        } elseif(in_array($p['kind'],['user_photo','user_job'],true)) {
            $this->prompt($bot,$user,$chat,(int)$p['thread_id'],$result,$p+['message_kind'=>$p['kind']==='user_photo'?'photo':'text']);
        } else $this->prompt($bot,$user,$chat,(int)$p['thread_id'],$result);
    }
    public function admin(Bot $bot,int $user,int $message=0,string $notice=''): void
    {
        if(!$bot->admin($user)) return;
        $token=bin2hex(random_bytes(8)); $ui=$bot->ui;
        $text=$ui->text('membership_admin',['state'=>$ui->plain($this->active()?'switch_on':'switch_off')]);
        $rows=[[$ui->button('membership_toggle','fj:'.$token.':toggle',['state'=>$ui->plain($this->active()?'switch_on':'switch_off')])],[$ui->button('membership_admin_bypass','fj:'.$token.':bypass',['state'=>$ui->plain($this->store->get('membership.group_admin','1')==='1'?'switch_on':'switch_off')])],[$ui->button('membership_add','fj:'.$token.':add')]];
        foreach($this->channels() as $channel) { $text.="\n• ".Ui::escape($channel['title']); $rows[]=[$ui->button('membership_remove','fj:'.$token.':remove_'.$channel['id'],['title'=>$channel['title']])]; }
        $rows[] = [$ui->button('membership_appearance','custom:section:membership'),$ui->button('back_admin','fj:'.$token.':back')];
        $id=$bot->send($user,($notice!==''?$notice."\n\n":'').$text,$rows,$message);
        $this->store->query("DELETE FROM membership_panels WHERE owner=? AND kind='admin'",[$user]);
        $this->store->query("INSERT INTO membership_panels VALUES(?,?,?,?,?,'admin',?)",[$token,$user,$user,0,$id,time()+3600]);
    }
    private function adminAction(Bot $bot,array $p,string $op): void
    {
        $user=(int)$p['owner']; $message=(int)$p['message_id'];
        if($op==='back') { $this->store->query('DELETE FROM membership_panels WHERE token=?',[$p['token']]);$bot->panel($user,$message);return; }
        if($op==='add') {
            $bot->send($user,$bot->text('membership_add_prompt'),[[$bot->ui->button('back_admin','fj:'.$p['token'].':menu')]],$message);
            $this->store->query('INSERT OR REPLACE INTO states VALUES(?,?,?)',[$user,'membership.add:'.$p['token'],time()+600]);return;
        }
        if($op==='toggle'&&!$this->channels()) { $this->admin($bot,$user,$message,$bot->text('membership_no_channels')); return; }
        if($op==='toggle'&&$this->channels()) $this->store->set('membership.enabled',$this->active()?'0':'1');
        if($op==='bypass') $this->store->set('membership.group_admin',$this->store->get('membership.group_admin','1')==='1'?'0':'1');
        if(preg_match('/^remove_([0-9]+)$/D',$op,$m)) $this->store->query('DELETE FROM membership_channels WHERE id=?',[(int)$m[1]]);
        if(!$this->channels()) $this->store->set('membership.enabled','0');
        $this->bump();$this->store->query('DELETE FROM states WHERE user_id=?',[$user]);$this->admin($bot,$user,$message);
    }
    public function receive(Bot $bot,int $user,array $message,string $state): void
    {
        if(!$bot->admin($user)) return;
        $parts=explode(':',$state);$p=$this->store->query("SELECT * FROM membership_panels WHERE token=? AND owner=? AND kind='admin' AND expires>?",[$parts[1]??'',$user,time()])->fetch();
        if(!$p) return;
        try {
            if(count($this->channels())>=5) throw new \RuntimeException('Channel limit');
            $text=trim((string)($message['text']??''));
            if(!preg_match('~^(@[A-Za-z][A-Za-z0-9_]{3,31}|-100[0-9]{5,14})(?:\s+(https://t\.me/(?:\+[A-Za-z0-9_-]+|joinchat/[A-Za-z0-9_-]+)))?$~D',$text,$m)) throw new \RuntimeException('Invalid channel');
            $this->deadline=microtime(true)+8;
            $chat=$this->call('getChat',['chat_id'=>$m[1]]);$id=(int)($chat['id']??0);
            if(($chat['type']??'')!=='channel'||$id>=0) throw new \RuntimeException('Invalid channel');
            $botId=(int)explode(':',$this->config->token,2)[0];$role=$this->call('getChatMember',['chat_id'=>$id,'user_id'=>$botId]);
            if(!in_array($role['status']??'',['administrator','creator'],true)) throw new \RuntimeException('Channel administrator required');
            $url=!empty($chat['username'])?'https://t.me/'.$chat['username']:($m[2]??'');
            if($url==='') throw new \RuntimeException('Invite link required');
            $this->store->query('INSERT INTO membership_channels(chat_id,title,url) VALUES(?,?,?) ON CONFLICT(chat_id) DO UPDATE SET title=excluded.title,url=excluded.url',[$id,mb_substr((string)($chat['title']??$m[1]),0,48),$url]);
            $this->bump();$this->store->query('DELETE FROM states WHERE user_id=?',[$user]);$this->admin($bot,$user,(int)$p['message_id']);
        } catch(\Throwable) { $bot->send($user,$bot->text('membership_add_invalid')); }
    }
}
