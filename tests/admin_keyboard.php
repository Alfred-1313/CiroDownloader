<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class AdminKeyboardApi implements Zero\Api {
    public array $calls=[]; public bool $failDelete=false; private int $message=100;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if(str_starts_with($method,'edit')&&isset($params['reply_markup']['keyboard'])) throw new RuntimeException('Telegram edit methods accept inline markup only');
        if($method==='deleteMessage'&&$this->failDelete) throw new RuntimeException('Message too old to delete',400);
        return $method==='sendMessage'?['message_id'=>++$this->message]:true;
    }
}
function adminCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function adminMessage(string $text,int $user=42): array { return ['update_id'=>random_int(1,1000000),'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]; }
function adminCallback(string $data,int $message=900): array { return ['callback_query'=>['id'=>'admin','from'=>['id'=>42],'message'=>['message_id'=>$message,'chat'=>['id'=>42,'type'=>'private']],'data'=>$data]]; }
function adminLabels(AdminKeyboardApi $api): array {
    $params=end($api->calls)[1]; $labels=[];
    foreach($params['reply_markup']['keyboard']??[] as $row) foreach($row as $button) {
        if(isset($button['callback_data'])) throw new RuntimeException('Reply controls must not contain callback data');
        $labels[]=$button['text'];
    }
    return $labels;
}
$temp=sys_get_temp_dir().'/ciro-admin-keyboard-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $store=new Zero\Store($temp); $api=new AdminKeyboardApi(); $bot=new Zero\Bot(new Zero\Config('123:fake',[42],$temp),$store,$api);
    $bot->handle(adminMessage('/start'));
    adminCheck(adminLabels($api)===[$bot->label('help'),$bot->label('admin')],'the main keyboard contains Help and the administrator entrance');
    $bot->handle(adminMessage($bot->label('admin')));
    $expected=array_map(fn($key)=>$bot->label($key),['customize','health','cookies','update','add_admin','off','back_home']);
    adminCheck(adminLabels($api)===$expected&&!isset(end($api->calls)[1]['reply_markup']['inline_keyboard']),'entering administration replaces the main reply keyboard with the administration tools');
    adminCheck(end($api->calls)[1]['reply_markup']['resize_keyboard']&&end($api->calls)[1]['reply_markup']['is_persistent'],'the administration keyboard stays available while navigating tools');
    foreach(['customize','health','cookies','update','add_admin','on','off','back_home'] as $key) adminCheck(!$bot->ui->customizable('button',$key),'administrator reply control stays outside user Personalization: '.$key);

    $bot->handle(adminMessage($bot->label('customize')));
    adminCheck(str_contains(json_encode(end($api->calls)[1]['reply_markup']),'custom:section:cover'),'the Personalization reply control opens the grouped user-only editor');
    $bot->handle(adminCallback('custom:back'));
    adminCheck(adminLabels($api)===$expected,'returning from Personalization restores the administration keyboard');
    $bot->handle(adminMessage($bot->label('health')));
    adminCheck(str_contains(end($api->calls)[1]['text'],'کاربران ثبت‌شده'),'the status reply control opens the private statistics');
    $bot->handle(adminMessage($bot->label('cookies')));
    adminCheck(adminLabels($api)===[$bot->label('cookie_youtube'),$bot->label('cookie_instagram'),$bot->label('cookie_pinterest'),$bot->label('cookie_tiktok'),$bot->label('cookie_twitter'),$bot->label('cookie_mode'),$bot->label('cookie_guide'),$bot->label('cookie_back_admin')],'the cookie control opens the independent service reply keyboards');
    $bot->handle(adminMessage($bot->label('cookie_youtube')));
    adminCheck(in_array($bot->label('cookie_view'),adminLabels($api),true)&&in_array($bot->label('cookie_remove'),adminLabels($api),true),'the YouTube service exposes separate import, view and delete controls');
    $bot->handle(adminMessage($bot->label('cookie_upload')));
    adminCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='cookie.upload','the cookie reply control starts import');
    $bot->handle(adminMessage($bot->label('update')));
    adminCheck(!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn()&&str_contains(end($api->calls)[1]['text'],'مدیریت به‌روزرسانی')&&(int)$store->query('SELECT COUNT(*) FROM update_requests')->fetchColumn()===0,'the update reply control leaves cookie import and opens the update menu without starting an update');
    $bot->handle(adminCallback('update:back',(int)$store->get('update_menu.42')));
    adminCheck(adminLabels($api)===$expected,'returning from the update menu restores the administration keyboard');
    $bot->handle(adminMessage($bot->label('cookies')));
    $bot->handle(adminCallback('admin'));
    adminCheck(adminLabels($api)===$expected&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'Back from cookie import restores the administration keyboard and clears import state');

    $bot->handle(adminMessage($bot->label('off')));
    $stopped=adminLabels($api);
    adminCheck(!$store->enabled()&&in_array($bot->label('on'),$stopped,true)&&!in_array($bot->label('off'),$stopped,true),'Stop replaces itself with the activation reply control');
    $bot->handle(adminMessage($bot->label('on')));
    adminCheck($store->enabled()&&adminLabels($api)===$expected,'activation works while the public bot is stopped');
    $bot->handle(adminMessage($bot->label('cookies')));
    $bot->handle(adminMessage($bot->label('back_home')));
    adminCheck(adminLabels($api)===[$bot->label('help'),$bot->label('admin')]&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'Back to main replaces the administration keyboard and clears the current input flow');
    $bot->handle(adminMessage('/admin'));
    adminCheck(adminLabels($api)===$expected,'the administrator command opens the same reply panel');
    $api->failDelete=true; $before=count($api->calls);
    $bot->handle(adminCallback('admin'));
    adminCheck(adminLabels($api)===$expected&&in_array('editMessageReplyMarkup',array_column(array_slice($api->calls,$before),0),true),'an older inline panel still opens the reply panel when its message cannot be deleted');

    $bot->handle(adminMessage('/start',7));
    adminCheck(adminLabels($api)===[$bot->label('help')],'ordinary users receive only their own keyboard');
    foreach(['admin','customize','health','cookies','update','add_admin','off','on','back_home'] as $key) {
        $before=count($api->calls); $bot->handle(adminMessage($bot->label($key),7));
        foreach(array_slice($api->calls,$before) as $call) if(isset($call[1]['reply_markup']['keyboard'])&&count($call[1]['reply_markup']['keyboard'])>1) throw new RuntimeException('Administrator keyboard leaked through '.$key);
    }
    adminCheck($store->enabled()&&!$store->query('SELECT 1 FROM states WHERE user_id=7')->fetchColumn()&&(int)$store->query('SELECT COUNT(*) FROM update_requests')->fetchColumn()===0,'forged administrator reply labels cannot operate the controls');
} finally { Zero\Download::clean($temp); }
