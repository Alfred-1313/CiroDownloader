<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function copyCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class CopyApi implements Zero\Api
{
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed
    {
        $this->calls[]=[$method,$params];
        if(isset($params['text'])) Zero\TextFormat::validate($params['text'],4096);
        return $method==='sendMessage'?['message_id'=>500]:true;
    }
    public function text(): string
    {
        foreach(array_reverse($this->calls) as [$method,$params]) if(in_array($method,['sendMessage','editMessageText'],true)) return $params['text'];
        return '';
    }
}
$root=sys_get_temp_dir().'/ciro-user-copy-'.bin2hex(random_bytes(5));
try {
    $store=new Zero\Store($root); $ui=new Zero\Ui($store); $definitions=Zero\Ui::definitions()['texts'];
    foreach($definitions as $key=>$item) if(($item['audience']??'')==='user'&&empty($item['hidden'])) {
        copyCheck(!preg_match('/کوکی|ایمپورت کوکی|Local Bot API|پراکسی|نشست ربات|جزئیات برای مدیر|فضای خالی سرور|اتصال سرور|این سرور/u',$item['value']),'public copy avoids internal setup: '.$key);
    }
    $keys=['instagram_story_empty','instagram_story_error_identity','instagram_story_error_cookies','instagram_story_error_restricted',
        'youtube__error_cookies','beeg__error_cookies','instagram_error_format','spotify_error_cookies','batch_reason_cookies','failed'];
    foreach($keys as $n=>$key) {
        $old=$definitions[$key]['legacy_values']; $old=end($old);
        $store->set('text.'.$key,$n%2?Zero\Ui::escape($old):$old);
    }
    $custom='<b>پیام دلخواه من</b> <tg-emoji emoji-id="5368324170671202286">🙂</tg-emoji>';
    $store->set('text.instagram_story_error_blocked',$custom); $store->set('text.pornhub__failed',$custom);
    $admin='راهنمای اختصاصی واردکردن کوکی'; $store->set('text.igs_cookie_import',$admin);
    $store->set('instagram.stories','1'); $store->set('cookies.fallback','1');
    $store->query("DELETE FROM settings WHERE key='ui_user_messages_v1'"); new Zero\Store($root);
    foreach($keys as $key) copyCheck($store->get('text.'.$key,'missing')==='missing','a saved default upgrades without retaining internal copy: '.$key);
    copyCheck($ui->forService('youtube')->text('error_cookies')===$definitions['error_cookies']['value'],'scoped resolution also upgrades the inherited YouTube default');
    copyCheck($ui->forService('pornhub')->text('failed')===$custom&&$ui->text('instagram_story_error_blocked')===$custom,'deliberate per-service and story captions retain HTML and premium emoji');
    copyCheck($store->get('text.igs_cookie_import')===$admin&&$store->get('instagram.stories')==='1'&&$store->get('cookies.fallback')==='1','public copy migration preserves administrator guidance and feature/session settings');
    $store->set('text.instagram_story_error_cookies',$custom); new Zero\Store($root);
    copyCheck($ui->text('instagram_story_error_cookies')===$custom,'later custom edits survive repeated startup');

    $api=new CopyApi(); $bot=new Zero\Bot(new Zero\Config('123:fixture',[42],$root),$store,$api); $serial=0;
    $tap=function(string $action) use($bot,&$serial): void {
        $bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'copy'.$serial,'from'=>['id'=>42],
            'message'=>['message_id'=>500,'chat'=>['id'=>42,'type'=>'private']],'data'=>$action]]);
    };
    $tap('custom:items:instagram:stories:0'); $tap('custom:edit:text:instagram_story_not_found');
    copyCheck(str_contains($api->text(),'پیج پیدا نشد'),'the missing-account text opens in the existing Instagram story editor');
    $bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>501,'from'=>['id'=>42],
        'chat'=>['id'=>42,'type'=>'private'],'text'=>'<b>این پیج پیدا نشد.</b> آیدی دیگری بفرست.']]);
    copyCheck($ui->text('instagram_story_not_found')==='<b>این پیج پیدا نشد.</b> آیدی دیگری بفرست.','the new result can be previewed and saved through Personalization');
    $tap('custom:reset:text:instagram_story_not_found');
    copyCheck($ui->text('instagram_story_not_found')===$definitions['instagram_story_not_found']['value'],'Reset restores the new friendly default');
} finally { Zero\Download::clean($root); }
