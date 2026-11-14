<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
final class RadioAccessApi implements Zero\Api {
    public array $calls=[]; private int $next=700;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='getChatMember') return ['status'=>(int)$params['user_id']===55?'administrator':'member'];
        if(str_starts_with($method,'send')) return ['message_id'=>++$this->next,'photo'=>[['file_id'=>'FIXTURE']]];
        return true;
    }
    public function last(string $method): ?array { foreach(array_reverse($this->calls) as [$name,$params]) if($name===$method) return $params; return null; }
}
function accessCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-radio-access-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $api=new RadioAccessApi(); $bot=new Zero\Bot($config,$store,$api); $radio=new Zero\Radio($config,$store);
    $radio->save(-1001,'Station','group',42,'rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf'); $radio->setLive(-1001,true);
    $panel=new Zero\RadioPanel($bot);
    accessCheck(Zero\Radio::botOnly($store)&&!Zero\Radio::allUsers($store)&&$panel->available(-1001,42)&&!$panel->available(-1001,55)&&!$panel->available(-1001,66)&&!$panel->available(66,66),'the default exposes radio buttons only for bot administrators');
    $bot->handle(['update_id'=>10,'message'=>['message_id'=>10,'from'=>['id'=>66,'is_bot'=>false],'chat'=>['id'=>66,'type'=>'private'],'text'=>'/radio']]);
    accessCheck(str_contains($api->last('sendMessage')['text']??'','فقط مدیرهای ربات'),'a private /radio request explains the default restriction');
    $click=function(string $action,int $user=42,int $chat=42,int $message=700) use($bot): void {
        $bot->handle(['update_id'=>random_int(1,999999),'callback_query'=>['id'=>'access'.random_int(1,999999),'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']],'data'=>$action]]);
    };
    $click('custom:section:radio'); $rows=$api->last('editMessageText')['reply_markup']['inline_keyboard']; $buttons=array_merge(...$rows);
    $switch=array_values(array_filter($buttons,fn($b)=>($b['callback_data']??'')==='custom:radioaccess'))[0]??[];
    accessCheck(str_contains($switch['text']??'','❌')&&str_contains($switch['text']??'','کاربران'),'Personalization shows the all-users switch as off by default');
    $click('custom:radioaccess',66,66); accessCheck(!Zero\Radio::allUsers($store),'an ordinary user cannot change the access switch');
    $click('custom:radioaccess'); accessCheck($store->get('radio.access')==='all'&&$panel->available(-1001,66)&&$panel->available(66,66),'an administrator can enable radio use for all users in groups and private chats');
    $source=['service'=>'spotify','m'=>['title'=>'Fixture','artists'=>['Artist'],'duration'=>90]]; $token=$radio->pick($source,'Fixture','Artist',90);
    $click('rd:p:'.$token,66,-1001); accessCheck($radio->queued(-1001)===1,'an ordinary user can add a song to the station after access is enabled');
    $click($radio->callback('t',-1001),66,-1001); accessCheck($radio->paused(-1001),'an ordinary user can control playback after access is enabled');
    $station=$radio->station(-1001); $click($radio->callback('l',-1001),66,-1001); $click($radio->callback('D',-1001),66,66);
    accessCheck($radio->station(-1001)!==null&&(int)$radio->station(-1001)['live']===(int)$station['live'],'ordinary users cannot alter live configuration or delete the station');
    accessCheck($radio->mayManage($api,-1001,55,false)&&!$radio->mayManage($api,-1001,66,false),'only station or bot administrators may configure the station');
    $store->set('groups_enabled','0'); $radio->clear(-1001); $click('rd:p:'.$token,66,-1001); accessCheck($radio->queued(-1001)===0,'the group switch still blocks radio use'); $store->set('groups_enabled','1');
    $click('custom:radioaccess'); accessCheck(Zero\Radio::botOnly($store)&&!$panel->available(-1001,66),'the administrator can revoke public access again');
    $store->set('radio.access','chat'); accessCheck($panel->available(-1001,55)&&!$panel->available(-1001,66),'an existing explicit chat-administrator setting is preserved');
} finally { Zero\Download::clean($temp); }
