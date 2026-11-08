<?php
declare(strict_types=1);
namespace Zero;

final class Cookies
{
    public const REPLIES=['cookies','cookie_settings','cookie_youtube','cookie_upload','cookie_view','cookie_remove','cookie_export','cookie_delete_confirm','cookie_back_services','cookie_back_youtube','cookie_back_admin','cookie_mode','cookie_guide','cookie_alerts','cookie_keepalive','cookie_interval','cookie_check_now'];
    public function __construct(private Bot $bot) {}
    private function keyboard(array $keys): array
    {
        return array_map(fn($row)=>array_map(fn($key)=>$this->bot->replyButton($key),$row),$keys);
    }
    /** The import screen: one entry per service, nothing else. */
    private function services(int $chat): void
    {
        $this->bot->sendKeyboard($chat,$this->bot->text('cookie_services',['settings'=>$this->bot->ui->label('cookie_settings')]),$this->keyboard([['cookie_youtube'],['cookie_instagram'],['cookie_pinterest'],['cookie_tiktok'],['cookie_twitter'],['cookie_guide'],['cookie_back_admin']]));
    }
    /** The settings screen: how cookies are used, alerts, keep-alive and the check interval. It has no import controls. */
    private function settings(int $chat,string $notice=''): void
    {
        $ui=$this->bot->ui; $store=$this->bot->store; $minutes=CookieHealth::minutes($store);
        $this->bot->sendKeyboard($chat,$this->bot->text('cookie_settings_panel',[
            'mode'=>$ui->plain($store->get('cookies.fallback','0')==='1'?'cookie_mode_fallback':'cookie_mode_always'),
            'alerts'=>$ui->plain($store->get('cookies.alerts','1')==='1'?'cookie_alerts_on':'cookie_alerts_off'),
            'keepalive'=>$ui->plain($store->get('cookies.keepalive','1')==='1'?'cookie_keepalive_on':'cookie_keepalive_off'),
            'interval'=>$ui->plainWith('cookie_interval_state',['every'=>$minutes<60?$minutes.' دقیقه':($minutes/60).' ساعت']),
        ]).($notice!==''?"\n\n".$notice:''),$this->keyboard([['cookie_mode'],['cookie_alerts'],['cookie_keepalive'],['cookie_interval'],['cookie_check_now'],['cookie_back_admin']]));
    }
    private function youtube(int $chat,string $notice=''): void
    {
        $status=$this->bot->ui->plain(youtubeCookiePool($this->bot->config)->count()>0?'cookie_present':'cookie_missing');
        $this->bot->sendKeyboard($chat,$this->bot->text('cookie_youtube_panel',['status'=>$status]).($notice!==''?"\n\n".$notice:''),$this->keyboard([
            ['cookie_upload'],['cookie_view','cookie_remove'],['cookie_back_services'],
        ]));
    }
    private function remove(int $chat,int $user): void
    {
        $pool=youtubeCookiePool($this->bot->config);
        if($pool->count()<1) { $this->youtube($chat); return; }
        $this->bot->sendKeyboard($chat,$this->bot->text('cookie_remove_confirm'),$this->keyboard([['cookie_delete_confirm'],['cookie_back_youtube']]));
        $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'cookie.delete:'.$pool->digest(),time()+300]);
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
        elseif(in_array($action,['cookie_view','cookie_export'],true)) (new CookieJars($this->bot))->view($chat,'yt');
        elseif($action==='cookie_settings') $this->settings($chat);
        elseif($action==='cookie_alerts') {
            $this->bot->store->set('cookies.alerts',$this->bot->store->get('cookies.alerts','1')==='1'?'0':'1');
            $this->settings($chat);
        }
        elseif($action==='cookie_interval') {
            $all=CookieHealth::INTERVALS; $now=CookieHealth::minutes($this->bot->store);
            $this->bot->store->set('cookies.check_minutes',(string)$all[(array_search($now,$all,true)+1)%count($all)]);
            $this->settings($chat);
        }
        elseif($action==='cookie_check_now') {
            // The worker's next loop sees the pass as due and starts it.
            $this->bot->store->set('cookie_sweep_last','0');
            $this->settings($chat,$this->bot->text('cookie_check_started'));
        }
        elseif($action==='cookie_keepalive') {
            $this->bot->store->set('cookies.keepalive',$this->bot->store->get('cookies.keepalive','1')==='1'?'0':'1');
            $this->settings($chat);
        }
        elseif($action==='cookie_guide') $this->bot->send($chat,$this->bot->text('cookie_guide'));
        elseif($action==='cookie_mode') {
            $this->bot->store->set('cookies.fallback',$this->bot->store->get('cookies.fallback','0')==='1'?'0':'1');
            $this->settings($chat);
        }
        elseif($action==='cookie_remove') $this->remove($chat,$user);
        elseif($action==='cookie_delete_confirm') {
            if(!is_string($previousState)||!preg_match('/^cookie\.delete:([a-f0-9]{64})$/D',$previousState,$match)) $notice='cookie_remove_expired';
            else {
                try { $notice=youtubeCookiePool($this->bot->config)->clear($match[1])?'cookie_removed':'cookie_remove_changed'; }
                catch(\Throwable) { $notice='cookie_remove_failed'; }
            }
            $this->youtube($chat,$this->bot->text($notice));
        }
        return true;
    }
}
