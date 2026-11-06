<?php
declare(strict_types=1);
namespace Zero;

final class Cookies
{
    public const REPLIES=['cookies','cookie_youtube','cookie_upload','cookie_view','cookie_remove','cookie_export','cookie_delete_confirm','cookie_back_services','cookie_back_youtube','cookie_back_admin'];
    public function __construct(private Bot $bot) {}
    private function keyboard(array $keys): array
    {
        return array_map(fn($row)=>array_map(fn($key)=>$this->bot->replyButton($key),$row),$keys);
    }
    private function services(int $chat): void
    {
        $this->bot->sendKeyboard($chat,$this->bot->text('cookie_services'),$this->keyboard([['cookie_youtube'],['cookie_instagram'],['cookie_back_admin']]));
    }
    private function youtube(int $chat,string $notice=''): void
    {
        $status=$this->bot->ui->plain(is_readable($this->bot->config->cookiePath())?'cookie_present':'cookie_missing');
        $this->bot->sendKeyboard($chat,$this->bot->text('cookie_youtube_panel',['status'=>$status]).($notice!==''?"\n\n".$notice:''),$this->keyboard([
            ['cookie_upload'],['cookie_view','cookie_remove'],['cookie_back_services'],
        ]));
    }
    private function read(): ?string
    {
        $path=$this->bot->config->cookiePath(); clearstatcache(true,$path);
        if(!is_file($path)) return null;
        $lock=fopen($path.'.lock','c');
        if(!$lock) throw new \RuntimeException('Cannot lock cookie file');
        chmod($path.'.lock',0600);
        try {
            if(!flock($lock,LOCK_SH)) throw new \RuntimeException('Cannot lock cookie file');
            clearstatcache(true,$path);
            if(!is_file($path)) return null;
            if(!is_readable($path)||filesize($path)>1048576) throw new \RuntimeException('Cannot read cookie file');
            $content=(string)file_get_contents($path); validateYouTubeCookies($content); return $content;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private function view(int $chat): void
    {
        try { $content=$this->read(); }
        catch(\Throwable) { $this->youtube($chat,$this->bot->text('cookie_read_failed')); return; }
        if($content===null) { $this->youtube($chat); return; }
        $count=0; $expired=0; $session=0; $domains=[];
        foreach(preg_split('/\r?\n/',$content) as $line) {
            if(str_starts_with($line,'#HttpOnly_')) $line=substr($line,10);
            elseif($line===''||str_starts_with($line,'#')) continue;
            $fields=explode("\t",$line); $count++;
            $domains[ltrim(strtolower($fields[0]),'.')]=true;
            if((int)$fields[4]===0) $session++;
            elseif((int)$fields[4]<=time()) $expired++;
        }
        $this->bot->sendKeyboard($chat,$this->bot->text('cookie_details',[
            'count'=>$count,'active'=>$count-$expired,'expired'=>$expired,'session'=>$session,'domains'=>mb_substr(implode(', ',array_slice(array_keys($domains),0,12)),0,1500),
        ]),$this->keyboard([['cookie_export'],['cookie_back_youtube']]));
    }
    private function export(int $chat): void
    {
        try {
            $content=$this->read();
            if($content===null) { $this->youtube($chat); return; }
            // A bounded in-memory snapshot needs no temporary credential file.
            $this->bot->api->call('sendDocument',['chat_id'=>$chat,'document'=>new \CURLStringFile($content,'youtube-cookies.txt','text/plain'),
                'caption'=>$this->bot->text('cookie_export_caption'),'parse_mode'=>'HTML','protect_content'=>true]);
        } catch(\Throwable) { $this->youtube($chat,$this->bot->text('cookie_export_failed')); }
    }
    private function remove(int $chat,int $user): void
    {
        $path=$this->bot->config->cookiePath(); clearstatcache(true,$path);
        if(!is_file($path)) { $this->youtube($chat); return; }
        $hash=hash_file('sha256',$path);
        if(!is_string($hash)) { $this->youtube($chat,$this->bot->text('cookie_read_failed')); return; }
        $this->bot->sendKeyboard($chat,$this->bot->text('cookie_remove_confirm'),$this->keyboard([['cookie_delete_confirm'],['cookie_back_youtube']]));
        $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'cookie.delete:'.$hash,time()+300]);
    }
    public function handle(int $chat,int $user,string $action,?string $previousState=null): bool
    {
        if(!in_array($action,self::REPLIES,true)) return false;
        if($chat!==$user||!$this->bot->admin($user)) return true;
        $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        if(in_array($action,['cookies','cookie_back_services'],true)) $this->services($chat);
        elseif(in_array($action,['cookie_youtube','cookie_back_youtube'],true)) $this->youtube($chat);
        elseif($action==='cookie_back_admin') $this->bot->panel($chat);
        elseif($action==='cookie_upload') $this->bot->beginCookieImport($chat,$user);
        elseif($action==='cookie_view') $this->view($chat);
        elseif($action==='cookie_export') $this->export($chat);
        elseif($action==='cookie_remove') $this->remove($chat,$user);
        elseif($action==='cookie_delete_confirm') {
            if(!is_string($previousState)||!preg_match('/^cookie\.delete:([a-f0-9]{64})$/D',$previousState,$match)) $notice='cookie_remove_expired';
            else {
                try { $notice=deleteYouTubeCookies($this->bot->config,$match[1])?'cookie_removed':'cookie_remove_changed'; }
                catch(\Throwable) { $notice='cookie_remove_failed'; }
            }
            $this->youtube($chat,$this->bot->text($notice));
        }
        return true;
    }
}
