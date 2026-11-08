<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class UpdateUiApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed { $this->calls[]=[$method,$params]; return $method==='sendMessage'?['message_id'=>700+count($this->calls)]:true; }
}
function updCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function updClick(string $data,int $message,int $user=42,int $chat=42): array {
    return ['callback_query'=>['id'=>'u','from'=>['id'=>$user],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']]+($chat<0?['message_thread_id'=>0]:[]),'data'=>$data]];
}
function updRows(UpdateUiApi $api): array { return end($api->calls)[1]['reply_markup']['inline_keyboard']??[]; }
function updActions(UpdateUiApi $api): array { return array_values(array_filter(array_column(array_merge(...updRows($api)),'callback_data'))); }
function updText(UpdateUiApi $api): string { return (string)(end($api->calls)[1]['text']??''); }
$temp=sys_get_temp_dir().'/ciro-update-ui-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $store=new Zero\Store($temp); $api=new UpdateUiApi(); $bot=new Zero\Bot(new Zero\Config('123:fake',[42],$temp),$store,$api); $ui=$bot->ui;
    $updater=new Zero\Updates($bot->config,$store,$api);

    // The menu: title, state lines, one explanation, a green start, red rollback, back and close.
    $store->set('update_previous_version','1.23.0');
    $updater->menu(42,42);
    $text=updText($api);
    updCheck(str_contains($text,'🔄 <b>به‌روزرسانی ربات</b>')&&str_contains($text,'➖➖➖➖➖➖➖➖➖➖')&&str_contains($text,'1.23.0')&&str_contains($text,Zero\Updates::version())&&str_contains($text,'<blockquote>'),'the menu shows a title, the current and rollback versions and one short explanation');
    $buttons=array_merge(...updRows($api)); $start=array_values(array_filter($buttons,fn($b)=>$b['callback_data']==='update:start'))[0]; $rollback=array_values(array_filter($buttons,fn($b)=>$b['callback_data']==='update:rollback'))[0];
    updCheck(($start['style']??'')==='success'&&($rollback['style']??'')==='danger','start is green and rollback is red');
    updCheck(updActions($api)===['update:start','update:rollback','update:back','update:close'],'the private menu ends with Back to the panel and Close');
    $store->set('update_previous_version','');
    $updater->menu(42,42);
    updCheck(str_contains(updText($api),$ui->plain('update_none'))&&!in_array('update:rollback',updActions($api),true),'without a previous version the menu says none and offers no rollback');

    // Group menu: only Close.
    $store->set('groups_enabled','1'); $group=new Zero\Updates($bot->config,$store,$api,0);
    $group->menu(-100123,42);
    updCheck(array_slice(updActions($api),-1)===['update:back']&&!in_array('update:close',updActions($api),true),'a group menu has a single Close button');

    // What's new: structured entries written for a beginner.
    $changes=[['title'=>'ویرایشگر دکمه‌ها','what'=>'هر دکمه ایموجی دارد.','example'=>'Audio را آبی کن.','default'=>'off','path'=>['🎨 شخصی‌سازی','کاور','Audio']],['title'=>'دوم','what'=>'توضیح','default'=>'on'],'یادداشت ساده <b>'];
    $store->set('update_new_changes',json_encode(['to'=>Zero\Updates::version(),'changes'=>$changes],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $updater->menu(42,42);
    updCheck(in_array('update:changes',updActions($api),true),'the menu offers the what\'s-new button when there is something new');
    $store->set('update_menu.42','801'); $menuId=801;
    $bot->handle(updClick('update:changes',$menuId));
    $text=updText($api);
    updCheck(str_contains($text,'✨ <b>تازه‌های این نسخه</b>')&&str_contains($text,'<b>1️⃣ ویرایشگر دکمه‌ها</b>')&&str_contains($text,'💡 مثال: Audio را آبی کن.')&&str_contains($text,'🔘 پیش‌فرض: <b>خاموش'),'each entry says what it is, gives an example and its default');
    updCheck(str_contains($text,'📍 مسیر: 1️⃣ 🎨 شخصی‌سازی ← 2️⃣ کاور ← 3️⃣ Audio')&&str_contains($text,'<b>2️⃣ دوم</b>')&&str_contains($text,'🔘 پیش‌فرض: <b>روشن</b>'),'the path is numbered step by step and the second entry is numbered too');
    updCheck(str_contains($text,'• یادداشت ساده &lt;b&gt;')&&substr_count($text,'📍')===1,'a plain note stays a bullet and nothing is injected into the HTML');
    updCheck(updActions($api)===['update:menu','update:close'],'the what\'s-new screen has Back and Close');
    $store->set('update_new_changes','');
    $updater->menu(42,42);
    updCheck(!in_array('update:changes',updActions($api),true),'no what\'s-new button without new entries');

    // Rollback confirmation.
    $store->set('update_previous_version','1.20.0'); $store->set('update_menu.42','802');
    $bot->handle(updClick('update:rollback',802));
    $buttons=array_merge(...updRows($api)); $confirm=array_values(array_filter($buttons,fn($b)=>$b['callback_data']==='update:rollback:confirm'))[0];
    updCheck(($confirm['style']??'')==='success'&&str_contains(updText($api),'1.20.0')&&array_slice(updActions($api),-2)===['update:menu','update:close'],'the confirmation has a green confirm, Back and Close');

    // Close deletes only the administrator's own menu.
    $store->set('update_menu.42','803'); $before=count($api->calls);
    $bot->handle(updClick('update:close',803,7,7)); $bot->handle(updClick('update:close',999));
    updCheck(!array_filter(array_slice($api->calls,$before),fn($c)=>$c[0]==='deleteMessage'),'an ordinary user or a stale menu cannot close the menu');
    $bot->handle(updClick('update:close',803));
    $deleted=array_values(array_filter(array_slice($api->calls,$before),fn($c)=>$c[0]==='deleteMessage'));
    updCheck(count($deleted)===1&&$deleted[0][1]['message_id']===803&&$store->get('update_menu.42')==='0','Close deletes the administrator\'s menu and forgets it');

    // The release list holds only the latest version.
    $log=json_decode((string)file_get_contents(dirname(__DIR__).'/CHANGELOG.json'),true,512,JSON_THROW_ON_ERROR);
    updCheck(count($log)===1&&$log[0]['version']===Zero\Updates::version(),'the changelog keeps only the latest version');
    updCheck(!array_filter($log[0]['changes'],fn($c)=>!is_array($c)||empty($c['title'])||empty($c['what'])),'every entry of the latest version has a title and an explanation');
} finally { Zero\Download::clean($temp); }
