<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';require dirname(__DIR__).'/src/Bot.php';require dirname(__DIR__).'/src/Download.php';
function ar(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
final class ReplyAdminApi implements Zero\Api {
    public array $calls=[];private int $mid=500;
    public function call(string $m,array $p=[]):mixed {
        $this->calls[]=[$m,$p];
        if(in_array($m,['sendMessage','editMessageText'],true))Zero\TextFormat::validate($p['text'],4096);
        return $m==='sendMessage'?['message_id'=>++$this->mid]:true;
    }
    public function last():array {foreach(array_reverse($this->calls)as[$m,$p])if(in_array($m,['sendMessage','editMessageText'],true))return$p;return[];}
}
$root=sys_get_temp_dir().'/ciro-reply-admin-'.bin2hex(random_bytes(5));
try {
    $c=new Zero\Config('123:fixture',[42,55],$root);$s=new Zero\Store($root);$a=new ReplyAdminApi();$bot=new Zero\Bot($c,$s,$a);$serial=0;$s->set('bot_username','FixtureBot');
    $profile=fn(int $id)=>['id'=>$id,'is_bot'=>false,'first_name'=>$id===9?'Nine <b>&':('User '.$id)];
    $send=function(string $command,int $actor=42,?int $target=7,int $chat=-100,int $topic=17,array $extra=[])use($bot,$profile,&$serial):void {
        $message=['message_id'=>++$serial,'from'=>$profile($actor),'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$topic,'text'=>$command];
        if($target!==null)$message['reply_to_message']=['message_id'=>100,'from'=>$profile($target),'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']];
        $bot->handle(['update_id'=>$serial,'message'=>array_replace_recursive($message,$extra)]);
    };
    $send('/add_admin');$grant=$s->query('SELECT * FROM bot_admins WHERE user_id=7')->fetch();ar($bot->admin(7)&&$grant&&(int)$grant['added_by']===42,'a root reply grants the actual human account without Start or username');ar((int)$a->last()['chat_id']===-100&&(int)$a->last()['message_thread_id']===17&&str_contains($a->last()['text'],'tg://user?id=7'),'the result stays in the original group topic and links the selected account');
    $s->query('UPDATE bot_admins SET created=123 WHERE user_id=7');$send('/add_admin',55);$again=$s->query('SELECT * FROM bot_admins WHERE user_id=7')->fetch();ar((int)$again['added_by']===42&&(int)$again['created']===123&&str_contains($a->last()['text'],'از قبل ادمین'),'duplicate promotion gives a clear result without rewriting the grant');
    $send('/add_admin',7,8);ar(!$bot->admin(8)&&$a->last()['text']===$bot->text('admin_reply_denied'),'a delegated administrator cannot use the root-only reply command');$send('/delete_admin',8,7);ar($bot->admin(7),'an ordinary user cannot remove an administrator');
    $send('/add_admin',42,null);ar(str_contains($a->last()['text'],'/add_admin'),'a missing reply explains the exact add command');$send('/delete_admin',42,null);ar(str_contains($a->last()['text'],'/delete_admin'),'a missing reply explains the exact removal command');
    $send('/add_admin @Someone',42,8);ar(!$bot->admin(8)&&str_contains($a->last()['text'],'/add_admin'),'arguments never silently select a different reply target');
    $before=count($a->calls);$send('/add_admin@AnotherBot',42,8);ar(!$bot->admin(8)&&count($a->calls)===$before,'commands addressed to another bot are ignored');
    foreach([['reply_to_message'=>['from'=>['is_bot'=>true]]],['reply_to_message'=>['sender_chat'=>['id'=>-100]]],['reply_to_message'=>['chat'=>['id'=>-999]]]]as$bad){$send('/add_admin',42,8,-100,17,$bad);ar(!$bot->admin(8)&&$a->last()['text']===$bot->text('admin_reply_invalid'),'bot, anonymous/channel and foreign-chat reply targets are rejected');}
    $send('/add_admin',42,9,-100,17,['reply_to_message'=>['forward_origin'=>['type'=>'user','sender_user'=>['id'=>88]]]]);ar($bot->admin(9)&&!$bot->admin(88)&&str_contains($a->last()['text'],'Nine &lt;b&gt;&amp;'),'forwarded-origin metadata cannot replace the actual sender and names remain escaped');
    $s->addAdministrator(10,7,$c->admins);$s->addAdministrator(11,10,$c->admins);$s->query('INSERT INTO bot_admins VALUES(55,7,0)');$s->addAdministrator(12,55,$c->admins);
    $send('/delete_admin@FixtureBot');ar(!$bot->admin(7)&&!$bot->admin(10)&&!$bot->admin(11)&&$bot->admin(55)&&$bot->admin(12),'revocation removes dependent grants and preserves an independent root branch');ar(str_contains($a->last()['text'],'دسترسی 2 ادمین وابسته'),'the removal result explains additional dependent revocations');$fresh=new Zero\Bot($c,new Zero\Store($root),$a);ar(!$fresh->admin(7)&&!$fresh->admin(10),'reopened bot and worker contexts observe revoked access immediately');
    $send('/delete_admin');ar(str_contains($a->last()['text'],'این کاربر ادمین نیست'),'removing a non-administrator produces the requested clear warning');$send('/add_admin');ar($bot->admin(7)&&!$bot->admin(10)&&!$bot->admin(11),'adding the account again never resurrects old delegated grants');
    $send('/delete_admin',42,55);ar($bot->admin(55)&&$c->admins===[42,55]&&$a->last()['text']===$bot->text('admin_reply_protected'),'configured roots cannot be removed through a reply command');
    $s->set('enabled','0');$send('/add_admin',42,13);ar($bot->admin(13),'root reply commands remain usable while public bot use is stopped');$s->set('enabled','1');$s->set('groups_enabled','0');$send('/add_admin',42,14);ar(!$bot->admin(14),'the group feature gate still blocks reply grants in disabled groups');$s->set('groups_enabled','1');
    $send('/delete_admin',42,42,42,0);ar($a->last()['text']===$bot->text('admin_reply_protected'),'private reply commands retain root protection');
    $rejected=false;try{$s->replyAdministrator(15,7,$c->admins,true);}catch(RuntimeException){$rejected=true;}ar($rejected&&!$bot->admin(15),'storage independently enforces the configured-root requirement');
    $s->db->exec("CREATE TRIGGER reject_reply_admin BEFORE INSERT ON bot_admins WHEN NEW.user_id=16 BEGIN SELECT RAISE(ABORT,'Fixture rejection'); END");$send('/add_admin',42,16);ar(!$bot->admin(16)&&$a->last()['text']===$bot->text('admin_reply_failed'),'a failed storage change gives an editable notice without claiming success');$s->db->exec('DROP TRIGGER reject_reply_admin');$send('/add_admin',42,17);ar($bot->admin(17),'a failed grant rolls back cleanly before the next authorized command');
    $s->set('text.admin_reply_exists','<b>تکراری: {user}</b>');$send('/add_admin');ar(str_contains($a->last()['text'],'<b>تکراری: User 7</b>'),'saved personalization is used by real reply-command results');
    $click=function(string $data,int $actor=42)use($bot,&$serial):void{$bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'admin'.$serial,'from'=>['id'=>$actor],'data'=>$data,'message'=>['message_id'=>900,'chat'=>['id'=>$actor,'type'=>'private']]]]);};
    $click('custom:category:general');ar(in_array('custom:section:admin_replies',array_column(array_merge(...$a->last()['reply_markup']['inline_keyboard']),'callback_data'),true),'General Personalization contains an independent administrator-reply section');$click('custom:section:admin_replies');$actions=array_column(array_merge(...$a->last()['reply_markup']['inline_keyboard']),'callback_data');foreach(['usage','denied','invalid','added','exists','deleted','not_admin','protected','deleted_dependents','failed']as$key)ar(in_array('custom:item:text:admin_reply_'.$key,$actions,true),'reply message is editable: '.$key);
    $click('custom:reset:text:admin_reply_exists');ar($s->get('text.admin_reply_exists')===''||$s->get('text.admin_reply_exists')===$bot->ui->definition('text','admin_reply_exists')['value'],'Reset restores the default reply-result text');
    ar(!$bot->ui->customizable('text','admin_add_prompt')&&!$bot->ui->customizable('text','admin_panel'),'the editable exception does not expose unrelated administrator panels');ar((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&!array_filter($a->calls,fn($call)=>$call[0]==='getChat'),'reply commands create no download work and need no username lookup');
}finally{Zero\Download::clean($root);}
