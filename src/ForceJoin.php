<?php
declare(strict_types=1);
namespace Zero;

/** Required-channel membership, with one qualifying human administrator sponsoring a group. */
final class ForceJoin
{
    private static ?\WeakMap $schemas=null;
    private Api $checks;
    private float $deadline=0;
    public function __construct(private Config $config,private Store $store,private Api $api)
    {
        $this->checks=$api instanceof Telegram?new Telegram($config,2):$api;
        self::$schemas??=new \WeakMap(); if(isset(self::$schemas[$store])) return;
        $store->db->exec("CREATE TABLE IF NOT EXISTS membership_channels(id INTEGER PRIMARY KEY,chat_id INTEGER NOT NULL UNIQUE,title TEXT NOT NULL,url TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS membership_cache(key TEXT PRIMARY KEY,value TEXT NOT NULL,expires INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS membership_panels(token TEXT PRIMARY KEY,owner INTEGER NOT NULL,chat_id INTEGER NOT NULL,thread_id INTEGER NOT NULL,message_id INTEGER NOT NULL,kind TEXT NOT NULL,expires INTEGER NOT NULL)");
        self::$schemas[$store]=true;
        $store->query('DELETE FROM membership_cache WHERE expires<=?',[time()]);
        $store->query('DELETE FROM membership_panels WHERE expires<=?',[time()]);
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
    public function guard(Bot $bot,int $user,int $chat,int $thread=0,?array $callback=null): bool
    {
        $result=$this->verdict($user,$chat); if($result==='ok') return true;
        if($callback) $this->api->call('answerCallbackQuery',['callback_query_id'=>$callback['id'],'text'=>$bot->ui->plain('membership_needed'),'show_alert'=>true,'cache_time'=>0]);
        $this->prompt($bot,$user,$chat,$thread,$result); return false;
    }
    public function prompt(Bot $bot,int $user,int $chat,int $thread,string $result='join',?array $job=null): void
    {
        $old=$this->store->query("SELECT * FROM membership_panels WHERE owner=? AND chat_id=? AND thread_id=? AND kind IN ('user','user_photo') AND expires>? ORDER BY rowid DESC LIMIT 1",[$user,$chat,$thread,time()])->fetch();
        $p=$old?:['token'=>bin2hex(random_bytes(8)),'message_id'=>0];
        if($job) $p['message_id']=(int)$job['message_id'];
        $photo=$job?($job['message_kind']??'text')==='photo':($old&&$old['kind']==='user_photo');
        $ui=$bot->ui; $rows=[];
        foreach($this->channels() as $channel) { $button=$ui->button('membership_channel','',['title'=>$channel['title']]); $button['url']=$channel['url']; unset($button['callback_data']); $rows[]=[$button]; }
        $rows[]=[$ui->button('membership_check','fj:'.$p['token'].':check')];
        if(!$old&&!$job&&($sticker=$this->store->get('membership.sticker'))!=='') try { $args=['chat_id'=>$chat,'sticker'=>$sticker]; if($chat<0&&$thread>0) $args['message_thread_id']=$thread; $this->api->call('sendSticker',$args); } catch(\Throwable) {}
        $key=$result==='unavailable'?'membership_unavailable':($chat<0&&$this->store->get('membership.group_admin','1')==='1'?'membership_group_prompt':'membership_prompt');
        try {
            if($photo) { $this->api->call('editMessageCaption',['chat_id'=>$chat,'message_id'=>(int)$p['message_id'],'caption'=>Ui::fitCaption($ui->text($key)),'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>$rows]]); $id=(int)$p['message_id']; }
            else $id=$bot->send($chat,$ui->text($key),$rows,(int)$p['message_id'],$thread);
        }
        catch(\RuntimeException $e) { if($e->getCode()!==404) throw $e; $photo=false; $id=$bot->send($chat,$ui->text($key),$rows,0,$thread); }
        $this->store->query('INSERT OR REPLACE INTO membership_panels VALUES(?,?,?,?,?,?,?)',[$p['token'],$user,$chat,$thread,$id,$photo?'user_photo':'user',time()+900]);
    }
    public function callback(Bot $bot,array $cb,int $user,int $chat,?int $thread): void
    {
        $parts=explode(':',(string)$cb['data']);$p=$this->store->query('SELECT * FROM membership_panels WHERE token=?',[$parts[1]??''])->fetch();
        $error=!$p?'callback_stale':((int)$p['owner']!==$user?'callback_owner':(((int)$p['chat_id']!==$chat||(int)$p['message_id']!==(int)$cb['message']['message_id']||($thread!==null&&(int)$p['thread_id']!==$thread)||(int)$p['expires']<=time())?'callback_stale':''));
        if($error===''&&$p['kind']!=='admin'&&!$bot->allowed($user,$chat)) $error='callback_access';
        if($error===''&&$p['kind']!=='admin') {
            $key='membership.tap.'.$user; $last=(int)$this->store->get($key,'0');
            if($last>=time()) $error='membership_wait'; else $this->store->set($key,(string)time());
        }
        if($error!=='') { $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$bot->ui->plain($error),'show_alert'=>true]); return; }
        $this->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id']]);
        if($p['kind']==='admin') { if($chat!==$user||!$bot->admin($user)) return; $this->adminAction($bot,$p,$parts[2]??''); return; }
        $result=$this->verdict($user,$chat,true);
        if($result==='ok') {
            if($p['kind']==='user_photo') $this->api->call('editMessageCaption',['chat_id'=>$chat,'message_id'=>(int)$p['message_id'],'caption'=>Ui::fitCaption($bot->text('membership_success')),'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>[]]]);
            else $bot->send($chat,$bot->text('membership_success'),[],(int)$p['message_id']);
            $this->store->query('DELETE FROM membership_panels WHERE token=?',[$p['token']]);
            if($chat===$user) $bot->home($chat,$user);
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
