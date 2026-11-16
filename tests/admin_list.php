<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';require dirname(__DIR__).'/src/Bot.php';require dirname(__DIR__).'/src/Download.php';
function al(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
final class AdminListApi implements Zero\Api {
    public array $calls=[];
    public function call(string $m,array $p=[]):mixed{$this->calls[]=[$m,$p];if(in_array($m,['sendMessage','editMessageText'],true))Zero\TextFormat::validate($p['text'],4096);return$m==='sendMessage'?['message_id'=>500]:true;}
    public function last():array{foreach(array_reverse($this->calls)as[$m,$p])if(in_array($m,['sendMessage','editMessageText'],true))return$p;return[];}
}
$root=sys_get_temp_dir().'/ciro-admin-list-'.bin2hex(random_bytes(5));
try{
    $c=new Zero\Config('123:fixture',[42,55],$root);$s=new Zero\Store($root);$a=new AdminListApi();$bot=new Zero\Bot($c,$s,$a);$serial=0;$s->set('bot_username','FixtureBot');
    $send=function(string$text='/list_admin',int$actor=42,int$chat=-100,int$topic=17)use($bot,&$serial):void{$bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'text'=>$text,'from'=>['id'=>$actor,'username'=>'Root'.$actor],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$topic]]);};
    $s->addAdministrator(7,42,$c->admins);$s->observeUser(['id'=>7,'username'=>'Seven']);$s->query('INSERT INTO bot_admins VALUES(88,999,0),(90,91,0),(91,90,0)');
    $send();$p=$a->last();al((int)$p['chat_id']===-100&&(int)$p['message_thread_id']===17,'the command works without a reply and retains the group topic');al(str_contains($p['text'],'tg://user?id=42')&&str_contains($p['text'],'tg://user?id=55')&&str_contains($p['text'],'tg://user?id=7')&&str_contains($p['text'],'@seven'),'configured and added administrators have ID-bound account links and cached labels');al(str_contains($p['text'],'اصلی: <b>2</b>')&&str_contains($p['text'],'اضافه‌شده: <b>1</b>')&&!str_contains($p['text'],'id=88')&&!str_contains($p['text'],'id=90'),'counts and rows include effective grants only, excluding orphaned roles and cycles');
    $send('/list_admin',7);al($a->last()['text']===$bot->text('admin_reply_denied'),'delegated administrators cannot request the root-only list');$send('/list_admin',8);al($a->last()['text']===$bot->text('admin_reply_denied'),'ordinary users cannot enumerate administrators');
    $before=count($a->calls);$send('/list_admin@AnotherBot');al(count($a->calls)===$before,'the list command ignores a different bot username');$send('/list_admin@FixtureBot',42,42,0);al((int)$a->last()['chat_id']===42&&!isset($a->last()['message_thread_id']),'the addressed command works privately as well');
    for($id=100;$id<111;$id++)$s->addAdministrator($id,42,$c->admins);$send();al(str_contains($a->last()['text'],'/list_admin 2')&&!str_contains($a->last()['text'],'id=110'),'long lists offer the next command without losing later administrators');$send('/list_admin ۲');al(str_contains($a->last()['text'],'id=110')&&str_contains($a->last()['text'],'صفحهٔ 2 از 2')&&!str_contains($a->last()['text'],'/list_admin 3'),'page two accepts Persian digits and contains the remaining accounts');$send('/list_admin nonsense');al($a->last()['text']===$bot->text('admin_list_usage'),'invalid pagination explains the command');
    $s->replyAdministrator(7,42,$c->admins,false);$send();al(!str_contains($a->last()['text'],'tg://user?id=7'),'revoked administrators disappear from the very next list');
    $s->set('text.admin_list_role_root','<b>مالک</b>');$s->set('text.admin_list_header','<b>فهرست دلخواه {total}</b>');$send();al(str_contains($a->last()['text'],'فهرست دلخواه')&&str_contains($a->last()['text'],'<b>مالک</b>'),'list templates and role formatting honor saved personalization');
    $s->set('text.admin_list_header',str_repeat('ه',3000));$s->set('text.admin_list_row',str_repeat('{user}',100));$s->set('text.admin_list_more',str_repeat('ب',3000));$send();Zero\TextFormat::validate($a->last()['text'],4096);al(true,'oversized custom templates still produce a bounded Telegram message');
    foreach(['header','row','role_root','role_added','more','usage']as$key)al($bot->ui->customizable('text','admin_list_'.$key),'list copy is editable: '.$key);al(in_array('list_admin',array_column(Zero\AdminCommands::menu($bot->ui),'command'),true),'the command is registered in the administrator menu');
    $s->set('groups_enabled','0');$before=count($a->calls);$send();al(count($a->calls)===$before,'disabled groups remain gated');al(!array_filter($a->calls,fn($v)=>$v[0]==='getChat'),'listing never sends one network lookup per administrator');
}finally{Zero\Download::clean($root);}
