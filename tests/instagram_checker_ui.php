<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';require dirname(__DIR__).'/src/Bot.php';require dirname(__DIR__).'/src/Download.php';
function uc(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
final class CheckerUiApi implements Zero\FileApi {
    public array $calls=[];public array $admins=[];public int $mid=500;public string $file='';
    public function call(string $m,array $p=[]): mixed {
        $this->calls[]=[$m,$p]; if(in_array($m,['sendMessage','editMessageText'],true))Zero\TextFormat::validate($p['text'],4096);
        foreach($p['reply_markup']['inline_keyboard']??[] as $row)foreach($row as $b)if(strlen($b['callback_data']??'')>64)throw new RuntimeException('Oversized callback');
        return match($m){'sendMessage'=>['message_id'=>++$this->mid],'getMe'=>['id'=>123,'username'=>'FixtureBot'],'getChatMember'=>['status'=>$this->admins[(int)$p['user_id']]??'member'],'getFile'=>['file_path'=>'fixture.txt'],default=>true};
    }
    public function downloadFile(string $p,int $limit): string{return $this->file;}
    public function last(): array {foreach(array_reverse($this->calls)as[$m,$p])if(in_array($m,['sendMessage','editMessageText'],true))return$p;return[];}
    public function button(string $suffix): ?string {foreach($this->last()['reply_markup']['inline_keyboard']??[]as$row)foreach($row as$b)if(str_contains($b['callback_data']??'',':'.$suffix))return$b['callback_data'];return null;}
}
$root=sys_get_temp_dir().'/ciro-checker-ui-'.bin2hex(random_bytes(5));
try{
$c=new Zero\Config('123:fixture',[42],$root);$s=new Zero\Store($root);$a=new CheckerUiApi();$bot=new Zero\Bot($c,$s,$a);$serial=0;$s->set('bot_username','FixtureBot');
$send=function(string$text,int$u=42,int$chat=42,int$thread=0)use($bot,&$serial):void{$bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$u,'username'=>'user'.$u],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread,'text'=>$text]]);};
$tap=function(string$action,int$u=42,int$chat=42,int$thread=0,?int$message=null)use($bot,$a,&$serial):void{$p=$a->last();$bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'ui'.$serial,'from'=>['id'=>$u],'message'=>['message_id'=>$message??(int)($p['message_id']??$a->mid),'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread],'data'=>$action]]);};
$send('/start');$keyboard=$a->last()['reply_markup']['keyboard'];uc(str_contains(json_encode($keyboard,JSON_UNESCAPED_UNICODE),'چکر'),'administrators have a checker reply-keyboard button');
$send('/start',7,7);uc(!str_contains(json_encode($a->last()['reply_markup'],JSON_UNESCAPED_UNICODE),'چکر'),'ordinary users do not see the default restricted checker button');
$send('/insta_checker fixture',7,7);uc(str_contains($a->last()['text'],'ادمین'),'the command also enforces default administrator-only access');
$send('/insta_checker@FixtureBot fixture');$save=$a->button('save:3');uc($save!==null,'the addressed command opens type selection');$tap($save);$id=(int)$s->query('SELECT id FROM ig_check_subs')->fetchColumn();uc($id>0&&str_contains($a->last()['text'],'fixture'),'choosing both saves a page and displays its detail in the same panel');
$pause=$a->button('pause:'.$id);$panel=$s->query('SELECT * FROM ig_check_panels ORDER BY expires DESC,rowid DESC LIMIT 1')->fetch();
$tap($pause,7,42,0,(int)$panel['message_id']);uc((int)$s->query('SELECT paused FROM ig_check_subs WHERE id=?',[$id])->fetchColumn()===0,'a forwarded or stolen owner callback cannot pause the checker');
$tap($pause,42,42,0,(int)$panel['message_id']+1);uc((int)$s->query('SELECT paused FROM ig_check_subs WHERE id=?',[$id])->fetchColumn()===0,'callbacks are bound to the actual panel message');
$tap($pause,42,42,0,(int)$panel['message_id']);uc((int)$s->query('SELECT paused FROM ig_check_subs WHERE id=?',[$id])->fetchColumn()===1,'the owner can pause from its own live message');
$send('/insta_checker');$settings=$a->button('settings');$tap($settings);$tap($a->button('userlimit'));$send('@user7 5');uc($s->get('instagram.checker.limit.7')==='5','a known Telegram username can receive an individual quota');
$tap($a->button('public'));uc(Zero\InstagramChecker::everyone($s),'the manager can enable public access');
$menu=array_values(array_filter($a->calls,fn($call)=>$call[0]==='setMyCommands'));uc(in_array('insta_checker',array_column(end($menu)[1]['commands'],'command'),true),'the public command list refreshes when public access is enabled');
$send('/insta_checker fixture',7,-100,17);uc(str_contains($a->last()['text'],'مدیران همان گروه'),'public group members cannot register checkers');
$a->admins[7]='administrator';$s->query("DELETE FROM settings WHERE key='radio.admin.-100.7'");$send('/insta_checker fixture',7,-100,17);$tap($a->button('save:1'),7,-100,17);
$gid=(int)$s->query('SELECT id FROM ig_check_subs WHERE chat_id=-100')->fetchColumn();uc($gid>0,'a group administrator registers the checker to that exact forum topic');
$gpanel=$s->query('SELECT * FROM ig_check_panels WHERE chat_id=-100 ORDER BY rowid DESC LIMIT 1')->fetch();$pause=$a->button('pause:'.$gid);$tap($pause,7,-100,18,(int)$gpanel['message_id']);uc((int)$s->query('SELECT paused FROM ig_check_subs WHERE id=?',[$gid])->fetchColumn()===0,'a panel cannot be moved to another topic');
$send('/insta_checker',7,7);$entry=$a->button('item:'.$gid);uc($entry!==null,'private management lists the owner’s group subscriptions too');$tap($entry,7,7);uc(str_contains($a->last()['text'],'-100')&&str_contains($a->last()['text'],'17')&&!str_contains($a->last()['text'],'\\n'),'subscription details display the saved destination and topic with real newlines');
$a->admins[7]='member';$s->query("DELETE FROM settings WHERE key='radio.admin.-100.7'");$tap($a->button('pause:'.$gid),7,7);uc((int)$s->query('SELECT paused FROM ig_check_subs WHERE id=?',[$gid])->fetchColumn()===1,'a demoted group administrator can still stop their checker privately');$tap($a->button('pause:'.$gid),7,7);uc((int)$s->query('SELECT paused FROM ig_check_subs WHERE id=?',[$gid])->fetchColumn()===1,'private management cannot resume sending into a group without current group rights');
$send('/insta_checker',7,7);$tap($a->button('item:'.$gid),7,7);$tap($a->button('delete:'.$gid),7,7);$tap($a->button('remove:'.$gid),7,7);uc((int)$s->query('SELECT deleted FROM ig_check_subs WHERE id=?',[$gid])->fetchColumn()===1,'the owner can remove an inaccessible group destination and reclaim quota');
$send('/insta_checker',42,42);$tap($a->button('settings'));$tap($a->button('limit'));$send('101');uc($s->get('instagram.checker.limit','3')==='3'&&str_starts_with((string)$s->query('SELECT key FROM states WHERE user_id=42')->fetchColumn(),'ic:'),'invalid quota input preserves the saved value and the pending prompt');$send('۴');uc($s->get('instagram.checker.limit')==='4','Persian digits work in quota settings');
$tap($a->button('interval'));$send('۷');uc($s->get('instagram.checker.seconds')==='420','the interval is editable in minutes');
$send($bot->label('cookie_instagram_checker'));$send($bot->label('igc_cookie_upload'));$a->file="# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\tCHECKER_ONLY\n";
$bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'document'=>['file_id'=>'fixture','file_name'=>'checker.txt','file_size'=>strlen($a->file)]]]);
uc(Zero\InstagramCheckerMedia::pool($c)->count()===1&&!is_file($c->instagramCookiePath())&&!is_file($c->instagramStoriesCookiePath()),'checker imports have their own private pool and never modify post or story cookies');
$send($bot->label('igc_cookie_remove'));$send($bot->label('igc_cookie_confirm'));uc(Zero\InstagramCheckerMedia::pool($c)->count()===0,'the checker cookie confirmation removes only its selected independent jar');
$ui=new Zero\Ui($s);uc($ui->customizable('text','instagram_checker_post_caption')&&!$ui->customizable('text','instagram_checker_settings_text'),'only public checker appearance is editable through Personalization');
$send('/cancel');$s->set('instagram.stories','1');$send('/insta fixture');$job=$s->query("SELECT url FROM jobs ORDER BY id DESC LIMIT 1")->fetchColumn();uc($job==='https://www.instagram.com/stories/fixture/','the existing insta command still means story download');
}finally{Zero\Download::clean($root);}
