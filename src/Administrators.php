<?php
declare(strict_types=1);
namespace Zero;

final class Administrators
{
    public function __construct(private Bot $bot) {}
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
