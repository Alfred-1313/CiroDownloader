<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Instagram.php';
require_once __DIR__.'/Pinterest.php';

abstract class GalleryCookies
{
    protected const PREFIX='ig_cookie', MEDIA=Instagram::class;
    protected function cookiePath(): string { return static::MEDIA===Pinterest::class?$this->bot->config->pinterestCookiePath():$this->bot->config->instagramCookiePath(); }
    public function __construct(private Bot $bot) {}
    private function send(int $chat,string $key,array $rows,array $values=[]): void
    {
        $keyboard=array_map(fn($row)=>array_map(fn($button)=>$this->bot->replyButton($button),$row),$rows);
        $this->bot->sendKeyboard($chat,$this->bot->text($key,$values),$keyboard);
    }
    private function panel(int $chat,string $notice=''): void
    {
        $this->send($chat,static::PREFIX.'_panel',[[static::PREFIX.'_upload'],[static::PREFIX.'_view',static::PREFIX.'_remove'],['cookie_back_services']],
            ['status'=>$this->bot->ui->plain($notice!==''?$notice:(is_readable($this->cookiePath())?'cookie_present':'cookie_missing'))]);
    }
    private function read(): ?string
    {
        $path=$this->cookiePath(); clearstatcache(true,$path);
        if(!is_file($path)) return null;
        if(!is_readable($path)||filesize($path)>1048576) throw new \RuntimeException('Cannot read gallery cookie file');
        return (string)file_get_contents($path);
    }
    public function handle(int $chat,int $user,string $action,?string $previousState=null): bool
    {
        if(!in_array($action,static::REPLIES,true)) return false;
        if($chat!==$user||!$this->bot->admin($user)) return true;
        $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        if(in_array($action,[static::MEDIA===Pinterest::class?'cookie_pinterest':'cookie_instagram',static::PREFIX.'_back'],true)) $this->panel($chat);
        elseif($action===static::PREFIX.'_upload') {
            $id=$this->bot->send($chat,$this->bot->text(static::PREFIX.'_import'));
            $this->bot->store->set(static::PREFIX.'_menu.'.$user,(string)$id);
            $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,0)',[$user,static::PREFIX.'.upload']);
        } elseif($action===static::PREFIX.'_view') {
            try {
                $content=$this->read();
                if($content===null) { $this->panel($chat); return true; }
                $count=0; $expired=0;
                foreach(preg_split('/\r?\n/',$content) as $line) {
                    if(str_starts_with($line,'#HttpOnly_')) $line=substr($line,10);
                    elseif($line===''||str_starts_with($line,'#')) continue;
                    $fields=explode("\t",$line);
                    if(count($fields)!==7) throw new \RuntimeException('Invalid gallery cookie file');
                    $count++; if((int)$fields[4]>0&&(int)$fields[4]<=time()) $expired++;
                }
                $this->send($chat,static::PREFIX.'_details',[[static::PREFIX.'_export'],[static::PREFIX.'_back']],['count'=>$count,'expired'=>$expired]);
            } catch(\Throwable) { $this->panel($chat,static::PREFIX.'_failed'); }
        } elseif($action===static::PREFIX.'_export') {
            try {
                $content=$this->read();
                if($content===null) { $this->panel($chat); return true; }
                $this->bot->api->call('sendDocument',['chat_id'=>$chat,'document'=>new \CURLStringFile($content,(static::MEDIA===Pinterest::class?'pinterest':'instagram').'-cookies.txt','text/plain'),
                    'caption'=>$this->bot->text(static::PREFIX.'_export_caption'),'parse_mode'=>'HTML','protect_content'=>true]);
            } catch(\Throwable) { $this->panel($chat,static::PREFIX.'_failed'); }
        } elseif($action===static::PREFIX.'_remove') {
            $path=$this->cookiePath();
            if(!is_file($path)) { $this->panel($chat); return true; }
            $hash=hash_file('sha256',$path);
            $this->send($chat,static::PREFIX.'_delete',[[static::PREFIX.'_confirm'],[static::PREFIX.'_back']]);
            $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,static::PREFIX.'.delete:'.$hash,time()+300]);
        } elseif($action===static::PREFIX.'_confirm') {
            $notice='cookie_remove_expired';
            if(is_string($previousState)&&preg_match('/^'.static::PREFIX.'\.delete:([a-f0-9]{64})$/D',$previousState,$match)) {
                try { $notice=(static::MEDIA)::deleteCookies($this->bot->config,$match[1])?static::PREFIX.'_removed':'cookie_remove_changed'; }
                catch(\Throwable) { $notice=static::PREFIX.'_failed'; }
            }
            $this->panel($chat,$notice);
        }
        return true;
    }
    public function document(int $chat,int $user,array $message): void
    {
        if($chat!==$user||!$this->bot->admin($user)) return;
        $state=$this->bot->store->query('SELECT key FROM states WHERE user_id=?',[$user])->fetchColumn();
        if($state!==static::PREFIX.'.upload') return;
        $notice=static::PREFIX.'_failed';
        try {
            $doc=$message['document'];
            if(strtolower(pathinfo($doc['file_name']??'',PATHINFO_EXTENSION))!=='txt'||(int)($doc['file_size']??0)>1048576||empty($doc['file_id'])||!($this->bot->api instanceof FileApi)) throw new \RuntimeException('Invalid cookie document');
            $file=$this->bot->api->call('getFile',['file_id'=>$doc['file_id']]);
            if(empty($file['file_path'])||(int)($file['file_size']??0)>1048576) throw new \RuntimeException('Cookie file unavailable');
            $content=$this->bot->api->downloadFile($file['file_path'],1048576);
            (static::MEDIA)::saveCookies($this->bot->config,$content);
            $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $notice=static::PREFIX.'_saved';
        } catch(\Throwable) { /* Keep the current jar and the upload state. */ }
        $id=(int)$this->bot->store->get(static::PREFIX.'_menu.'.$user,'0');
        try { $this->bot->send($chat,$this->bot->text($notice),[],$id); }
        catch(\Throwable) { $this->bot->send($chat,$this->bot->text($notice)); }
        if(isset($message['message_id'])) {
            try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$message['message_id']]); }
            catch(\Throwable) { $this->bot->send($chat,$this->bot->text('cookie_delete_failed')); }
        }
    }
}
