<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class AdministratorApi implements Zero\Api {
    public array $calls=[];
    public array $profiles=[42=>['id'=>42,'type'=>'private','username'=>'RootOwner'],7=>['id'=>7,'type'=>'private','username'=>'FirstUser'],8=>['id'=>8,'type'=>'private','username'=>'SecondUser']];
    public bool $failLookup=false; private int $message=100;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='getChat') {
            if($this->failLookup) throw new RuntimeException('fixture lookup unavailable');
            return $this->profiles[(int)$params['chat_id']]??null;
        }
        return $method==='sendMessage'?['message_id'=>++$this->message]:true;
    }
}
function administratorCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-administrators-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $store=new Zero\Store($temp); $api=new AdministratorApi(); $config=new Zero\Config('123:fixture',[42],$temp); $bot=new Zero\Bot($config,$store,$api);
    $send=function(int $user,string $text,?int $chat=null) use($bot,$api): void {
        $chat??=$user; $from=['id'=>$user,'is_bot'=>false];
        if(isset($api->profiles[$user]['username'])) $from['username']=$api->profiles[$user]['username'];
        $bot->handle(['update_id'=>random_int(1,1000000),'message'=>['from'=>$from,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'text'=>$text]]);
    };
    $begin=fn()=>$send(42,$bot->label('add_admin'));
    $send(7,'/start'); $send(42,'/admin'); $keys=array_column(array_merge(...end($api->calls)[1]['reply_markup']['keyboard']),'text');
    administratorCheck(in_array($bot->label('add_admin'),$keys,true),'the private administration panel includes Add administrator');
    $send(7,$bot->label('add_admin'));
    administratorCheck(!$store->query('SELECT 1 FROM states WHERE user_id=7')->fetchColumn()&&!$bot->admin(7),'an ordinary user cannot open the administrator-creation flow');
    $begin(); $state=$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn();
    $send(42,$bot->label('add_admin'),-100123);
    administratorCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()===$state,'the group cannot open this private tool or overwrite its pending input');
    $send(42,'123456789');
    administratorCheck(end($api->calls)[1]['text']===$bot->text('admin_add_invalid')&&!$bot->admin(7),'numeric IDs are rejected without granting a role');
    $before=count(array_filter($api->calls,fn($call)=>$call[0]==='getChat')); $send(42,'@UnknownUser');
    administratorCheck(end($api->calls)[1]['text']===$bot->text('admin_add_unknown')&&count(array_filter($api->calls,fn($call)=>$call[0]==='getChat'))===$before,'unknown usernames request a prior Start without attempting unsupported username resolution');
    $send(42,'@fIrStUsEr');
    $grant=$store->query('SELECT * FROM bot_admins WHERE user_id=7')->fetch();
    administratorCheck($bot->admin(7)&&$grant&&(int)$grant['added_by']===42&&$config->admins===[42],'a case-insensitive username grants the verified account immediately without changing bootstrap admins');
    administratorCheck(!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn()&&str_contains(end($api->calls)[1]['text'],'@FirstUser'),'the success edits the input prompt and ends its pending state');
    $reopened=new Zero\Bot($config,new Zero\Store($temp),$api); $store->set('enabled','0'); $store->set('admins_only','1');
    administratorCheck($reopened->admin(7)&&$reopened->allowed(7)&&!$reopened->allowed(8),'the persisted role is recognized by new bot and worker contexts even while public use is disabled');
    $send(7,'/admin');
    administratorCheck(isset(end($api->calls)[1]['reply_markup']['keyboard']),'the added administrator can open the actual management panel');
    $send(7,'/update');
    administratorCheck((int)$store->get('update_menu.7')>0,'the added administrator can use the update panel');
    $api->profiles[6]=['id'=>6,'type'=>'private','username'=>'LockedUser']; $send(6,'/start');
    $begin(); $send(42,'@LockedUser');
    administratorCheck($bot->admin(6),'a user can be identified and added even when public access is disabled');
    $store->set('enabled','1'); $store->set('admins_only','0');
    $send(8,'/start'); $send(7,$bot->label('add_admin')); $send(7,'SecondUser');
    administratorCheck($bot->admin(8)&&(int)$store->query('SELECT added_by FROM bot_admins WHERE user_id=8')->fetchColumn()===7,'an added administrator can grant another verified administrator through the same flow');
    $api->profiles[7]['username']='RenamedUser'; $send(7,'/start');
    $api->profiles[9]=['id'=>9,'type'=>'private','username'=>'FirstUser']; $send(9,'/start');
    administratorCheck($bot->admin(7)&&!$bot->admin(9),'changing or reassigning a username never transfers the existing account role');
    $api->profiles[10]=['id'=>10,'type'=>'private','username'=>'CachedName']; $send(10,'/start'); $api->profiles[10]['username']='ChangedName';
    $begin(); $send(42,'@CachedName');
    administratorCheck(!$bot->admin(10)&&end($api->calls)[1]['text']===$bot->text('admin_add_changed'),'live account verification rejects a stale cached username');
    $api->failLookup=true; $send(42,'@ChangedName'); $api->failLookup=false;
    administratorCheck(!$bot->admin(10)&&end($api->calls)[1]['text']===$bot->text('admin_add_unavailable'),'an unavailable Telegram lookup cannot grant privileges');
    $api->profiles[10]=['id'=>999,'type'=>'channel','username'=>'ChangedName']; $send(42,'@ChangedName');
    administratorCheck(!$bot->admin(10),'a mismatched or non-private lookup cannot grant privileges');
    $send(42,'/cancel');
    administratorCheck(!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'Cancel abandons the administrator input state');
    $store->observeUser(['id'=>12,'username'=>'OtherBot','is_bot'=>true]);
    $begin(); $send(42,'@OtherBot');
    administratorCheck(!$bot->admin(12)&&end($api->calls)[1]['text']===$bot->text('admin_add_unknown'),'bot profiles cannot be used as human administrator targets');
    $begin(); $send(42,'@RootOwner');
    administratorCheck(end($api->calls)[1]['text']===$bot->text('admin_add_exists',['username'=>'@RootOwner'])&&!$store->query('SELECT 1 FROM bot_admins WHERE user_id=42')->fetchColumn(),'adding an existing bootstrap administrator neither duplicates nor rewrites its role');
    $begin(); $store->query('UPDATE states SET expires=1 WHERE user_id=42'); $send(42,'@FirstUser');
    administratorCheck(!$bot->admin(9),'an expired input cannot promote a known account');
    $store->query('INSERT INTO bot_admins VALUES(90,91,0),(91,90,0),(92,999,0)');
    administratorCheck(!$bot->admin(90)&&!$bot->admin(91)&&!$bot->admin(92)&&!$store->isAdmin(7,[]),'orphaned grants, cycles and missing bootstrap roots confer no authority');
    $rejected=false; try { $store->addAdministrator(93,9,[42]); } catch(RuntimeException) { $rejected=true; }
    administratorCheck($rejected&&!$bot->admin(93),'the storage layer rechecks the granting actor inside its transaction');
    foreach(['add_admin'] as $key) administratorCheck(!isset($bot->catalog('button')[$key]),'administrator creation controls stay outside user Personalization');
    foreach(['admin_add_prompt','admin_add_success'] as $key) administratorCheck(!isset($bot->catalog('text')[$key]),'administrator creation notices stay outside user Personalization');
} finally { Zero\Download::clean($temp); }
