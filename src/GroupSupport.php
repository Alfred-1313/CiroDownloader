<?php
declare(strict_types=1);
namespace Zero;

final class GroupSupport
{
    public function __construct(private Bot $bot) {}
    public function cacheIdentity(mixed $profile): void
    {
        if(!is_array($profile)) return;
        $username=(string)($profile['username']??'');
        if(preg_match('/^[A-Za-z0-9_]{1,64}$/D',$username)) $this->bot->store->set('bot_username',$username);
        if((int)($profile['id']??0)>0) $this->bot->store->set('bot_id',(string)$profile['id']);
    }
    private function username(): string
    {
        $username=$this->bot->store->get('bot_username');
        if(preg_match('/^[A-Za-z0-9_]{1,64}$/D',$username)) return $username;
        try { $this->cacheIdentity($this->bot->api->call('getMe')); } catch(\Throwable) {}
        $username=$this->bot->store->get('bot_username');
        return preg_match('/^[A-Za-z0-9_]{1,64}$/D',$username)?$username:'';
    }
    private function buttons(): array
    {
        if(!$this->bot->store->groupsEnabled()||($username=$this->username())==='') return [];
        $button=$this->bot->ui->button('add_to_group');
        $button['url']='https://t.me/'.$username.'?startgroup=download&admin=manage_chat';
        return [[$button]];
    }
    private function helpText(int $chat,array $rows): string
    {
        $key=$chat<0?($this->bot->store->groupsEnabled()?'group_help':'groups_disabled'):'help';
        $text=$this->bot->text($key,['username'=>$chat<0?$this->username():$this->bot->store->get('bot_username')]);
        if($chat>0&&$rows&&$this->helpFits()) $text.="\n\n".$this->bot->text('help_groups_hint');
        return $text;
    }
    public function helpFits(): bool
    {
        $combined=$this->bot->text('help')."\n\n".$this->bot->text('help_groups_hint');
        return TextFormat::length(html_entity_decode(strip_tags($combined),ENT_QUOTES|ENT_HTML5,'UTF-8'))<=3900;
    }
    public function help(int $chat,int $thread=0): void
    {
        if($chat<0&&!$this->bot->store->groupsEnabled()) return;
        $thread=$chat<0?max(0,$thread):0;
        $saved=$this->bot->store->query('SELECT message_id FROM help_messages WHERE chat_id=? AND thread_id=?',[$chat,$thread])->fetchColumn();
        if($saved) {
            try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$saved]); }
            catch(\Throwable) {
                try { $this->bot->api->call('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>(int)$saved,'reply_markup'=>['inline_keyboard'=>[]]]); } catch(\Throwable) {}
            }
        }
        $rows=$this->buttons();
        $message=$this->bot->send($chat,$this->helpText($chat,$rows),$rows,0,$thread);
        $this->bot->store->query('INSERT INTO help_messages(chat_id,thread_id,message_id,groups_enabled) VALUES(?,?,?,?) ON CONFLICT(chat_id,thread_id) DO UPDATE SET message_id=excluded.message_id,groups_enabled=excluded.groups_enabled',[$chat,$thread,$message,$this->bot->store->groupsEnabled()?1:0]);
    }
    public function syncHelpButtons(int $limit=5): void
    {
        $enabled=$this->bot->store->groupsEnabled()?1:0;
        $pending=$this->bot->store->query('SELECT * FROM help_messages WHERE groups_enabled<>? ORDER BY chat_id,thread_id LIMIT ?',[$enabled,max(1,min(20,$limit))])->fetchAll();
        if(!$pending) return;
        $rows=$this->buttons();
        foreach($pending as $message) {
            try {
                $this->bot->send((int)$message['chat_id'],$this->helpText((int)$message['chat_id'],$rows),$rows,(int)$message['message_id']);
                $this->bot->store->query('UPDATE help_messages SET groups_enabled=? WHERE chat_id=? AND thread_id=? AND message_id=?',[$enabled,$message['chat_id'],$message['thread_id'],$message['message_id']]);
            } catch(\RuntimeException $error) {
                if(in_array($error->getCode(),[400,403,404],true)) $this->bot->store->query('DELETE FROM help_messages WHERE chat_id=? AND thread_id=? AND message_id=?',[$message['chat_id'],$message['thread_id'],$message['message_id']]);
                else break;
            }
        }
    }
    public function welcome(int $chat,int $thread=0): void
    {
        if($chat>=0||!$this->bot->store->groupsEnabled()) return;
        $key='group_welcomed.'.$chat;
        if((int)$this->bot->store->get($key,'0')>0) return;
        $message=$this->bot->send($chat,$this->bot->text('group_welcome',['username'=>$this->username()]),[],0,$thread);
        if($message<1) throw new \RuntimeException('Missing group welcome message ID');
        $this->bot->store->set($key,(string)$message);
    }
    public function membership(array $change): void
    {
        $chat=$change['chat']??[];
        if(!in_array($chat['type']??'',['group','supergroup'],true)||(int)($chat['id']??0)>=0) return;
        if((int)$this->bot->store->get('bot_id','0')<1) $this->username();
        $botId=(int)$this->bot->store->get('bot_id','0');
        $next=$change['new_chat_member']??[]; $previous=$change['old_chat_member']??[];
        if($botId<1||(int)($next['user']['id']??0)!==$botId) return;
        $member=static fn(array $state): bool=>in_array($state['status']??'',['creator','administrator','member'],true)||(($state['status']??'')==='restricted'&&!empty($state['is_member']));
        if(!$member($next)) $this->bot->store->query('DELETE FROM settings WHERE key=?',['group_welcomed.'.(int)$chat['id']]);
        elseif(!$member($previous)) $this->welcome((int)$chat['id']);
    }
    public function message(array $update,int $user,int $chat,int $thread): void
    {
        $message=$update['message']; $text=trim((string)($message['text']??''));
        $command=''; $argument='';
        if(preg_match('~^/([A-Za-z_]+)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~sD',$text,$parts)) {
            if(!empty($parts[2])&&strcasecmp($parts[2],$this->username())!==0) return;
            $command=strtolower($parts[1]); $argument=trim($parts[3]??'');
            if(!in_array($command,['start','help','download'],true)) return;
        }
        $added=false; $botId=(int)$this->bot->store->get('bot_id','0');
        foreach($message['new_chat_members']??[] as $member) if($botId>0&&(int)$member['id']===$botId) $added=true;
        if($added||($command==='start'&&$argument==='download')) {
            $this->bot->store->query('INSERT OR IGNORE INTO users(id) VALUES(?)',[$user]);
            $this->welcome($chat,$thread); return;
        }
        if(in_array($command,['start','help'],true)) {
            $this->bot->store->query('INSERT OR IGNORE INTO users(id) VALUES(?)',[$user]);
            if($this->bot->allowed($user,$chat)) $this->help($chat,$thread);
            else $this->bot->send($chat,$this->bot->denial($chat),[],0,$thread);
            return;
        }
        $url=youtubeUrl($command==='download'?$argument:$text);
        if(!$url) {
            if($command==='download') $this->bot->send($chat,$this->bot->text('invalid'),[],0,$thread);
            return;
        }
        $this->bot->requestVideo((int)$update['update_id'],$user,$chat,$url,$thread);
    }
}
