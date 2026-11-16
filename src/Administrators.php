<?php
declare(strict_types=1);
namespace Zero;

final class Administrators
{
    public function __construct(private Bot $bot) {}
    public function replyCommand(array $message,int $actor,int $chat,int $thread): bool
    {
        $text=trim((string)($message['text']??''));
        if(!preg_match('~^/(add_admin|delete_admin)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~sD',$text,$m)) return false;
        if(($m[2]??'')!==''&&strcasecmp($m[2],$this->bot->store->get('bot_username'))!==0) return true;
        $send=function(string $key,array $values=[])use($chat,$thread):void{$this->bot->send($chat,$this->bot->text($key,$values),[],0,$thread);};
        if(!in_array($actor,$this->bot->config->admins,true)) { $send('admin_reply_denied'); return true; }
        $reply=$message['reply_to_message']??null; $target=is_array($reply)?($reply['from']??null):null;
        $command='/'.$m[1];
        if(trim($m[3]??'')!==''||!is_array($reply)) { $send('admin_reply_usage',['command'=>$command]); return true; }
        if(!is_array($target)||(int)($target['id']??0)<1||!empty($target['is_bot'])||!empty($reply['sender_chat'])||(int)($reply['chat']['id']??0)!==$chat) {
            $send('admin_reply_invalid'); return true;
        }
        $user=(int)$target['id'];
        $label=trim((string)($target['first_name']??'').' '.(string)($target['last_name']??''));
        if(!empty($target['username'])) $label='@'.$target['username'];
        if($label==='') $label=(string)$user;
        $values=['user'=>mb_substr($label,0,100),'id'=>$user];
        $this->bot->store->observeUser($target);
        $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$actor]);
        (new CookieJars($this->bot))->cancelLabel($actor);
        try { $result=$this->bot->store->replyAdministrator($user,$actor,$this->bot->config->admins,$m[1]==='add_admin'); }
        catch(\Throwable) { $send('admin_reply_failed'); return true; }
        $key='admin_reply_'.$result['status'];
        if($result['status']==='deleted'&&$result['count']>1) { $key='admin_reply_deleted_dependents'; $values['count']=$result['count']-1; }
        $send($key,$values); return true;
    }
    public function begin(int $chat,int $user): void
    {
        if($chat!==$user||!$this->bot->admin($user)) return;
        $message=$this->bot->send($chat,$this->bot->text('admin_add_prompt'),[[$this->bot->button('back_admin','admins:cancel')]]);
        $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'admins.add:'.$message,time()+600]);
    }
    private function notice(int $chat,int $user,int $message,string $key,array $values=[],bool $complete=false): void
    {
        $rows=$complete?[]:[[$this->bot->button('back_admin','admins:cancel')]];
        $text=$this->bot->text($key,$values);
        try { $this->bot->send($chat,$text,$rows,$message); }
        catch(\RuntimeException $e) {
            if($e->getCode()!==404) throw $e;
            $message=$this->bot->send($chat,$text,$rows);
            if(!$complete) $this->bot->store->query('UPDATE states SET key=? WHERE user_id=?',['admins.add:'.$message,$user]);
        }
        if($complete) $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
    }
    public function receive(int $chat,int $user,array $input,string $state): void
    {
        if($chat!==$user||!$this->bot->admin($user)||!preg_match('/^admins\.add:(\d+)$/D',$state,$match)) return;
        $message=(int)$match[1]; $text=trim((string)($input['text']??''));
        if(!preg_match('/^@?([A-Za-z0-9_]{1,32})$/D',$text,$parts)||ctype_digit($parts[1])) {
            $this->notice($chat,$user,$message,'admin_add_invalid'); return;
        }
        $username=strtolower($parts[1]);
        $target=(int)$this->bot->store->query('SELECT user_id FROM user_profiles WHERE username=?',[$username])->fetchColumn();
        if($target<1) { $this->notice($chat,$user,$message,'admin_add_unknown'); return; }
        try { $profile=$this->bot->api->call('getChat',['chat_id'=>$target]); }
        catch(\Throwable) { $this->notice($chat,$user,$message,'admin_add_unavailable'); return; }
        if(!is_array($profile)||($profile['type']??'')!=='private'||(int)($profile['id']??0)!==$target) {
            $this->notice($chat,$user,$message,'admin_add_unavailable'); return;
        }
        // Verify the current account before granting: private usernames cannot
        // be resolved arbitrarily by Bot API and may have changed owners.
        $this->bot->store->observeUser(['id'=>$target,'username'=>$profile['username']??'']);
        if(strtolower((string)($profile['username']??''))!==$username) {
            $this->notice($chat,$user,$message,'admin_add_changed'); return;
        }
        try { $added=$this->bot->store->addAdministrator($target,$user,$this->bot->config->admins); }
        catch(\Throwable) { $this->notice($chat,$user,$message,'admin_add_unavailable'); return; }
        $this->notice($chat,$user,$message,$added?'admin_add_success':'admin_add_exists',['username'=>'@'.$profile['username']],true);
    }
}
