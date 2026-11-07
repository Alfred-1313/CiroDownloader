<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Instagram.php';
require_once __DIR__.'/Pinterest.php';
require_once __DIR__.'/TikTok.php';
require_once __DIR__.'/Twitter.php';

abstract class GalleryCookies
{
    protected const PREFIX='ig_cookie', MEDIA=Instagram::class;
    protected function cookiePath(): string { return $this->bot->config->galleryCookiePath((static::MEDIA)::service()); }
    private function pool(): CookiePool { return (static::MEDIA)::pool($this->bot->config); }
    private function code(): string { return galleryPrefix((static::MEDIA)::service()); }
    public function __construct(private Bot $bot) {}
    private function send(int $chat,string $key,array $rows,array $values=[]): void
    {
        $keyboard=array_map(fn($row)=>array_map(fn($button)=>$this->bot->replyButton($button),$row),$rows);
        $this->bot->sendKeyboard($chat,$this->bot->text($key,$values),$keyboard);
    }
    private function panel(int $chat,string $notice=''): void
    {
        $this->send($chat,static::PREFIX.'_panel',[[static::PREFIX.'_upload'],[static::PREFIX.'_view',static::PREFIX.'_remove'],['cookie_back_services']],
            ['status'=>$this->bot->ui->plain($notice!==''?$notice:($this->pool()->count()>0?'cookie_present':'cookie_missing'))]);
    }
    public function handle(int $chat,int $user,string $action,?string $previousState=null): bool
    {
        if(!in_array($action,static::REPLIES,true)) return false;
        if($chat!==$user||!$this->bot->admin($user)) return true;
        $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        if(in_array($action,['cookie_'.(static::MEDIA)::service(),static::PREFIX.'_back'],true)) $this->panel($chat);
        elseif($action===static::PREFIX.'_upload') {
            $id=$this->bot->send($chat,$this->bot->text(static::PREFIX.'_import'));
            $this->bot->store->set(static::PREFIX.'_menu.'.$user,(string)$id);
            $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,0)',[$user,static::PREFIX.'.upload']);
        } elseif(in_array($action,[static::PREFIX.'_view',static::PREFIX.'_export'],true)) {
            try { (new CookieJars($this->bot))->view($chat,$this->code()); }
            catch(\Throwable) { $this->panel($chat,static::PREFIX.'_failed'); }
        } elseif($action===static::PREFIX.'_remove') {
            if($this->pool()->count()<1) { $this->panel($chat); return true; }
            $digest=$this->pool()->digest();
            $this->send($chat,static::PREFIX.'_delete',[[static::PREFIX.'_confirm'],[static::PREFIX.'_back']]);
            $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,static::PREFIX.'.delete:'.$digest,time()+300]);
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
        // The upload stays open so several exports can follow; Back ends it.
        (new CookieJars($this->bot))->receive($chat,$user,$message,$this->code(),static::PREFIX.'_menu.'.$user,$this->bot->text(static::PREFIX.'_failed'));
    }
}
