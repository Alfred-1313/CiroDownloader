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
    /** One inline switch: green when on, red when off, with its state in the label. */
    private function switchButton(string $key,string $data,bool $on): array
    {
        $ui=$this->bot->ui; $button=$ui->button($key,$data,['state'=>$ui->plain($on?'switch_on':'switch_off')]);
        $button['style']=$on?'success':'danger';
        return $button;
    }
    /** The settings screen: real inline controls (switches, the interval choice, check now), edited in place by `cs:` callbacks. */
    public function settings(int $chat,string $notice='',int $message=0): void
    {
        $ui=$this->bot->ui; $store=$this->bot->store; $minutes=CookieHealth::minutes($store);
        $rows=[
            [$this->switchButton('cs_mode','cs:m',$store->get('cookies.fallback','0')==='1')],
            [$this->switchButton('cs_alerts','cs:a',$store->get('cookies.alerts','1')==='1')],
            [$this->switchButton('cs_keepalive','cs:k',$store->get('cookies.keepalive','1')==='1')],
        ];
        $row=[];
        foreach(CookieHealth::INTERVALS as $every) {
            $button=$ui->button('cs_every_'.$every,'cs:i:'.$every);
            if($every===$minutes) { $button['text']=$ui->plainWith('mark_chosen',['label'=>$button['text']]); $button['style']='success'; }
            $row[]=$button;
            if(count($row)===3) { $rows[]=$row; $row=[]; }
        }
        if($row) $rows[]=$row;
        $rows[]=[$ui->button('cs_check_now','cs:n')];
        $rows[]=[$ui->button('back_admin','admin'),$ui->button('cs_close','cs:x')];
        $this->bot->send($chat,$this->bot->text('cookie_settings_panel').($notice!==''?"\n\n".$notice:''),$rows,$message);
    }
    /** A tap on the settings screen. Only administrators in a private chat get here (the caller enforces it). */
    public function callback(int $chat,string $op,int $message): void
    {
        $store=$this->bot->store;
        if($op==='x') { try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); } catch(\Throwable) {} return; }
        $notice='';
        if($op==='m') $store->set('cookies.fallback',$store->get('cookies.fallback','0')==='1'?'0':'1');
        elseif($op==='a') $store->set('cookies.alerts',$store->get('cookies.alerts','1')==='1'?'0':'1');
        elseif($op==='k') $store->set('cookies.keepalive',$store->get('cookies.keepalive','1')==='1'?'0':'1');
        elseif(preg_match('/^i:(\d{2,4})$/D',$op,$match)&&in_array((int)$match[1],CookieHealth::INTERVALS,true)) $store->set('cookies.check_minutes',$match[1]);
        elseif($op==='n') {
            // The worker's next loop sees the pass as due and starts it.
            $store->set('cookie_sweep_last','0'); $notice=$this->bot->text('cookie_settings_notice');
        } else return;
        $this->settings($chat,$notice,$message);
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
            // Old keyboards still show this label; it behaves like the inline button.
            $this->bot->store->set('cookie_sweep_last','0');
            $this->settings($chat,$this->bot->text('cookie_settings_notice'));
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
