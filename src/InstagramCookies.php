<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Instagram.php';

final class InstagramCookies
{
    public const REPLIES=['cookie_instagram','ig_cookie_upload','ig_cookie_view','ig_cookie_export','ig_cookie_remove','ig_cookie_confirm','ig_cookie_back'];
    public function __construct(private Bot $bot) {}
    private function send(int $chat,string $key,array $rows,array $values=[]): void
    {
        $keyboard=array_map(fn($row)=>array_map(fn($button)=>$this->bot->replyButton($button),$row),$rows);
        $this->bot->sendKeyboard($chat,$this->bot->text($key,$values),$keyboard);
    }
    private function panel(int $chat,string $notice=''): void
    {
        $this->send($chat,'ig_cookie_panel',[['ig_cookie_upload'],['ig_cookie_view','ig_cookie_remove'],['cookie_back_services']],
            ['status'=>$this->bot->ui->plain($notice!==''?$notice:(is_readable($this->bot->config->instagramCookiePath())?'cookie_present':'cookie_missing'))]);
    }
    private function read(): ?string
    {
        $path=$this->bot->config->instagramCookiePath(); clearstatcache(true,$path);
        if(!is_file($path)) return null;
        if(!is_readable($path)||filesize($path)>1048576) throw new \RuntimeException('Cannot read Instagram cookie file');
        return (string)file_get_contents($path);
    }
    public function handle(int $chat,int $user,string $action,?string $previousState=null): bool
    {
        if(!in_array($action,self::REPLIES,true)) return false;
        if($chat!==$user||!$this->bot->admin($user)) return true;
        $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        if(in_array($action,['cookie_instagram','ig_cookie_back'],true)) $this->panel($chat);
        elseif($action==='ig_cookie_upload') {
            $id=$this->bot->send($chat,$this->bot->text('ig_cookie_import'));
            $this->bot->store->set('ig_cookie_menu.'.$user,(string)$id);
            $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,0)',[$user,'ig_cookie.upload']);
        } elseif($action==='ig_cookie_view') {
            try {
                $content=$this->read();
                if($content===null) { $this->panel($chat); return true; }
                $count=0; $expired=0;
                foreach(preg_split('/\r?\n/',$content) as $line) {
                    if(str_starts_with($line,'#HttpOnly_')) $line=substr($line,10);
                    elseif($line===''||str_starts_with($line,'#')) continue;
                    $fields=explode("\t",$line);
                    if(count($fields)!==7) throw new \RuntimeException('Invalid Instagram cookie file');
                    $count++; if((int)$fields[4]>0&&(int)$fields[4]<=time()) $expired++;
                }
                $this->send($chat,'ig_cookie_details',[['ig_cookie_export'],['ig_cookie_back']],['count'=>$count,'expired'=>$expired]);
            } catch(\Throwable) { $this->panel($chat,'ig_cookie_failed'); }
        } elseif($action==='ig_cookie_export') {
            try {
                $content=$this->read();
                if($content===null) { $this->panel($chat); return true; }
                $this->bot->api->call('sendDocument',['chat_id'=>$chat,'document'=>new \CURLStringFile($content,'instagram-cookies.txt','text/plain'),
                    'caption'=>$this->bot->text('ig_cookie_export_caption'),'parse_mode'=>'HTML','protect_content'=>true]);
            } catch(\Throwable) { $this->panel($chat,'ig_cookie_failed'); }
        } elseif($action==='ig_cookie_remove') {
            $path=$this->bot->config->instagramCookiePath();
            if(!is_file($path)) { $this->panel($chat); return true; }
            $hash=hash_file('sha256',$path);
            $this->send($chat,'ig_cookie_delete',[['ig_cookie_confirm'],['ig_cookie_back']]);
            $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'ig_cookie.delete:'.$hash,time()+300]);
        } elseif($action==='ig_cookie_confirm') {
            $notice='cookie_remove_expired';
            if(is_string($previousState)&&preg_match('/^ig_cookie\.delete:([a-f0-9]{64})$/D',$previousState,$match)) {
                try { $notice=Instagram::deleteCookies($this->bot->config,$match[1])?'ig_cookie_removed':'cookie_remove_changed'; }
                catch(\Throwable) { $notice='ig_cookie_failed'; }
            }
            $this->panel($chat,$notice);
        }
        return true;
    }
    public function document(int $chat,int $user,array $message): void
    {
        if($chat!==$user||!$this->bot->admin($user)) return;
        $state=$this->bot->store->query('SELECT key FROM states WHERE user_id=?',[$user])->fetchColumn();
        if($state!=='ig_cookie.upload') return;
        $notice='ig_cookie_failed';
        try {
            $doc=$message['document'];
            if(strtolower(pathinfo($doc['file_name']??'',PATHINFO_EXTENSION))!=='txt'||(int)($doc['file_size']??0)>1048576||empty($doc['file_id'])||!($this->bot->api instanceof FileApi)) throw new \RuntimeException('Invalid cookie document');
            $file=$this->bot->api->call('getFile',['file_id'=>$doc['file_id']]);
            if(empty($file['file_path'])||(int)($file['file_size']??0)>1048576) throw new \RuntimeException('Cookie file unavailable');
            $content=$this->bot->api->downloadFile($file['file_path'],1048576);
            Instagram::saveCookies($this->bot->config,$content);
            $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $notice='ig_cookie_saved';
        } catch(\Throwable) { /* Keep the current jar and the upload state. */ }
        $id=(int)$this->bot->store->get('ig_cookie_menu.'.$user,'0');
        try { $this->bot->send($chat,$this->bot->text($notice),[],$id); }
        catch(\Throwable) { $this->bot->send($chat,$this->bot->text($notice)); }
        if(isset($message['message_id'])) {
            try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$message['message_id']]); }
            catch(\Throwable) { $this->bot->send($chat,$this->bot->text('cookie_delete_failed')); }
        }
    }
}
