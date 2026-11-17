<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function replyCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class CheckerReplyApi implements Zero\Api
{
    public array $calls=[]; public array $messages=[]; public array $admins=[7=>'administrator',8=>'administrator',42=>'administrator',12=>'administrator'];
    public int $mid=1000; public bool $rejectPrompt=false; public ?Closure $duringEdit=null;
    public function call(string $method,array $params=[]): mixed
    {
        $this->calls[]=[$method,$params];
        if(in_array($method,['sendMessage','editMessageText'],true)) Zero\TextFormat::validate($params['text'],4096);
        foreach($params['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button) if(strlen($button['callback_data']??'')>64) throw new RuntimeException('oversized callback');
        if($method==='getChatMember') return ['status'=>$this->admins[(int)$params['user_id']]??'member'];
        if($method==='getMe') return ['id'=>123,'username'=>'FixtureBot'];
        if($method==='sendMessage'&&!empty($params['reply_markup']['force_reply'])&&$this->rejectPrompt) throw new RuntimeException('rejected prompt',400);
        if($method==='sendMessage') { $id=++$this->mid; $this->messages[$id]=$params; return ['message_id'=>$id]; }
        if($method==='editMessageText') {
            if($this->duringEdit) { $callback=$this->duringEdit; $this->duringEdit=null; $callback(); }
            $this->messages[(int)$params['message_id']]=$params;
        }
        return true;
    }
}
$root=sys_get_temp_dir().'/ciro-checker-replies-'.bin2hex(random_bytes(5));
try {
    $config=new Zero\Config('123:fixture',[42],$root); $store=new Zero\Store($root); $api=new CheckerReplyApi(); $bot=new Zero\Bot($config,$store,$api); $model=new Zero\InstagramChecker($config,$store,$api); $serial=0;
    (new Zero\GroupSupport($bot))->cacheIdentity(['id'=>123,'username'=>'FixtureBot']);
    $send=function(string $text,int $owner,int $chat,int $thread=0,int $reply=0,int $sender=123,bool $isBot=true,bool $topic=true) use($bot,&$serial): void {
        $message=['message_id'=>++$serial,'from'=>['id'=>$owner,'username'=>'user'.$owner,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'text'=>$text];
        if($topic) $message['message_thread_id']=$thread;
        if($reply) $message['reply_to_message']=['message_id'=>$reply,'from'=>['id'=>$sender,'is_bot'=>$isBot]];
        $bot->handle(['update_id'=>$serial,'message'=>$message]);
    };
    $panel=fn(int $owner,int $chat)=>$store->query('SELECT * FROM ig_check_panels WHERE owner=? AND chat_id=? ORDER BY rowid DESC LIMIT 1',[$owner,$chat])->fetch();
    $live=fn(array $p)=>$store->query('SELECT * FROM ig_check_panels WHERE token=?',[$p['token']])->fetch();
    $data=fn(array $p)=>json_decode($live($p)['data'],true);
    $prompt=fn(array $p)=>(int)($data($p)['input_message']??0);
    $tap=function(array $p,string $op,?int $id=null,?int $actor=null) use($bot,&$serial): void {
        $bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'reply'.$serial,'from'=>['id'=>$actor??(int)$p['owner']],
            'message'=>['message_id'=>(int)$p['message_id'],'chat'=>['id'=>(int)$p['chat_id'],'type'=>(int)$p['chat_id']<0?'supergroup':'private'],'message_thread_id'=>(int)$p['thread_id']],
            'data'=>'ic:'.$p['token'].':'.$op.($id===null?'':':'.$id)]]);
    };
    $pending=fn(int $owner)=>$store->query('SELECT key FROM states WHERE user_id=?',[$owner])->fetchColumn();
    $send('/insta_checker',42,42); $admin=$panel(42,42); $tap($admin,'add'); $adminPrompt=$prompt($admin);
    replyCheck($adminPrompt>0&&$api->messages[$adminPrompt]['reply_markup']['force_reply']===true,'administrator Add opens an actual ForceReply prompt');
    $send('@admin_page',42,42,0,$adminPrompt); $tap($admin,'save',3);
    replyCheck($model->used(42)===1,'an administrator adds a page by replying with just its username');
    $store->set('instagram.checker.public','1');
    $send('/insta_checker',7,-100,17); $p=$panel(7,-100); $tap($p,'add'); $first=$prompt($p);
    replyCheck($api->messages[$first]['reply_markup']['selective']===true&&(int)$api->messages[$first]['message_thread_id']===17&&str_contains($api->messages[$first]['text'],'tg://user?id=7'),'a selective owner mention and original forum topic accompany the reply prompt');
    $send('/insta_checker',8,-100,17); $other=$panel(8,-100); $tap($other,'add'); $otherPrompt=$prompt($other); $before=count($api->calls); $ownState=$pending(8);
    $send('hijacked_page',8,-100,17,$first); $send('/checker injected_page',8,-100,17,$first); $send('root_hijack',42,-100,17,$first); $send('/add_admin',42,-100,17,$first);
    replyCheck(count($api->calls)===$before&&$pending(7)==='ic:'.$p['token']&&$pending(8)===$ownState&&$model->used(7)===0,'another user or administrator replying to this prompt is silently ignored, including commands, without consuming either input state');
    $send('raw_page',7,-100,17); $send('wrong_topic',7,-100,18,$first); $send('wrong_bot',7,-100,17,$first,999); $send('human_reply',7,-100,17,$first,123,false);
    $bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>7],'chat'=>['id'=>-100,'type'=>'supergroup'],'text'=>'wrong_nested_topic','reply_to_message'=>['message_id'=>$first,'message_thread_id'=>18,'from'=>['id'=>123,'is_bot'=>true]]]]);
    replyCheck(count($api->calls)===$before&&$prompt($p)===$first,'plain input, another topic, another bot and a human reply target cannot satisfy the request');
    $send('other_page',8,-100,17,$otherPrompt); $tap($other,'save',1);
    replyCheck($model->used(8)===1&&$pending(7)==='ic:'.$p['token'],'a second owner can complete an independent concurrent reply flow');
    $send('bad user name',7,-100,17,$first); $fresh=$prompt($p);
    replyCheck($fresh!==$first&&$pending(7)==='ic:'.$p['token']&&$live($p)['view']==='add','invalid input receives a fresh reply prompt while preserving the owner request');
    $before=count($api->calls); $send('stale_page',7,-100,17,$first);
    replyCheck(count($api->calls)===$before&&$model->used(7)===0,'a superseded reply prompt is ignored');
    $send('@old_page',7,-100,17,$fresh,123,true,false);
    replyCheck($live($p)['view']==='new'&&!$pending(7)&&$data($p)['username']==='old_page','a valid reply recovers omitted topic metadata from its bound prompt and opens content selection');
    $tap($p,'save',3); $id=(int)$data($p)['id']; $sub=$model->owned($id,7);
    $rows=$api->messages[(int)$p['message_id']]['reply_markup']['inline_keyboard']; $sideBySide=false;
    foreach($rows as $row) if(count($row)===2&&str_contains($row[0]['callback_data'],':check:')&&str_contains($row[1]['callback_data'],':rename:')) $sideBySide=($row[0]['style']??'')==='primary'&&($row[1]['style']??'')==='primary';
    replyCheck($sideBySide,'verification and Change ID are blue and adjacent in the existing management layout');
    $tap($p,'rename',$id,42);
    replyCheck($prompt($p)===0&&$api->calls[array_key_last($api->calls)][0]==='answerCallbackQuery','another administrator cannot open the owner’s rename prompt');
    $shared=$model->add(42,42,0,'old_page',1); $oldTarget=(int)$sub['target'];
    $model->change($id,7,-100,17,'pause'); $store->query('UPDATE ig_check_subs SET posts_ready=1,stories_ready=1 WHERE id=?',[$id]);
    $store->query("INSERT INTO ig_check_deliveries(subscription,generation,kind,item,url,created) VALUES(?,?,'post','901','https://www.instagram.com/p/ABC_def12/',?)",[$id,$model->owned($id,7)['generation'],time()]);
    $store->set('instagram.checker.limit.7',(string)$model->used(7)); $tap($p,'item',$id); $tap($p,'rename',$id); $rename=$prompt($p); $generation=(int)$model->owned($id,7)['generation'];
    replyCheck(str_contains($api->messages[$rename]['text'],'@old_page')&&$live($p)['view']==='rename','rename shows the currently selected username and requests a bound reply');
    $before=count($api->calls); $send('@attacker',8,-100,17,$rename); $send('@not_replied',7,-100,17);
    replyCheck(count($api->calls)===$before&&$model->owned($id,7)['username']==='old_page','only the requester’s reply can change the selected checker');
    $send('@new_page',7,-100,17,$rename); $renamed=$model->owned($id,7);
    replyCheck($renamed['username']==='new_page'&&(int)$renamed['mode']===3&&(int)$renamed['paused']===1&&(int)$renamed['chat_id']===-100&&(int)$renamed['thread_id']===17,'renaming at the quota limit preserves content type, pause state and destination');
    replyCheck((int)$renamed['generation']===$generation+1&&!(int)$renamed['posts_ready']&&!(int)$renamed['stories_ready']&&$store->query('SELECT status FROM ig_check_deliveries WHERE subscription=?',[$id])->fetchColumn()==='cancelled','rename invalidates pending old deliveries and resets the new page baseline');
    replyCheck($model->owned($shared,42)['username']==='old_page'&&(int)$model->owned($shared,42)['target']===$oldTarget,'another owner sharing the old page is not renamed');
    $model->change($id,7,-100,17,'pause'); $target=$store->query('SELECT * FROM ig_check_targets WHERE id=?',[$renamed['target']])->fetch();
    $target['lease']='fixture'; $store->query('UPDATE ig_check_targets SET lease=? WHERE id=?',[$target['lease'],$target['id']]);
    $model->apply($target,['account_id'=>'777','mode'=>3,'posts'=>[['id'=>'102','url'=>Zero\instagramUrl('https://instagram.com/p/ABC_def12/'),'time'=>time()-30]],'stories'=>[]]);
    replyCheck((int)$store->query("SELECT COUNT(*) FROM ig_check_deliveries WHERE subscription=? AND status IN ('pending','queued','retry')",[$id])->fetchColumn()===0&&(int)$model->owned($id,7)['posts_ready']===1,'the new page’s first real snapshot establishes its baseline without replaying its history');
    $tap($p,'item',$id); $tap($p,'rename',$id); $r=$prompt($p); $send('other_page',7,-100,17,$r);
    replyCheck($model->owned($id,7)['username']==='new_page'&&$live($p)['view']==='rename'&&$prompt($p)!==$r,'a duplicate group page preserves the old subscription and allows another reply');
    $cancel=$prompt($p); $send('/cancel',8,-100,17,$cancel);
    replyCheck($pending(7)==='ic:'.$p['token'],'another user cannot cancel the owner request by replying');
    $send('/cancel',7,-100,17); replyCheck(!$pending(7)&&$live($p)['view']==='home','the owner may cancel without changing the checker');
    $tap($p,'item',$id); $tap($p,'rename',$id); $stale=$prompt($p); $model->change($id,7,-100,17,'mode1'); $send('stale_change',7,-100,17,$stale);
    replyCheck($model->owned($id,7)['username']==='new_page'&&(int)$model->owned($id,7)['mode']===1&&!$pending(7),'a concurrently changed checker rejects the stale rename generation');
    $tap($p,'add'); $expired=$prompt($p); $store->query('UPDATE states SET expires=? WHERE user_id=7',[time()-1]); $before=count($api->calls); $send('expired_page',7,-100,17,$expired);
    replyCheck(count($api->calls)===$before&&!$store->query("SELECT 1 FROM ig_check_targets WHERE username='expired_page'")->fetchColumn(),'expired reply state cannot register a page');
    $store->set('instagram.checker.limit.7','3'); $send('/insta_checker',7,7); $private=$panel(7,7); $tap($private,'add'); $privateReply=$prompt($private); $send('private_page',7,7,0,$privateReply); $tap($private,'save',2);
    replyCheck($model->subscription((int)$data($private)['id'],7,7,0)!==null,'public users also add a private story checker without a command argument');
    $privateId=(int)$data($private)['id']; $tap($private,'item',$id); $tap($private,'rename',$id); $protected=$prompt($private);
    $api->admins[7]='member'; $store->query("DELETE FROM settings WHERE key='radio.admin.-100.7'"); $send('demoted_change',7,7,0,$protected);
    replyCheck($model->owned($id,7)['username']==='new_page'&&!$pending(7)&&!$store->query("SELECT 1 FROM ig_check_targets WHERE username='demoted_change'")->fetchColumn(),'private management rechecks the original group role before accepting a renamed page');
    $api->admins[7]='administrator'; $store->query("DELETE FROM settings WHERE key='radio.admin.-100.7'"); $tap($private,'item',$privateId);

    $gone=$model->add(11,11,0,'gone_page',1); $model->change($gone,11,11,0,'delete'); $current=$model->add(11,11,0,'current_page',2); $model->change($current,11,11,0,'pause');
    $result=$model->rename($current,11,11,0,'gone_page',(int)$model->owned($current,11)['generation']);
    replyCheck($result===$gone&&$model->owned($current,11)===null&&(int)$model->owned($result,11)['mode']===2&&(int)$model->owned($result,11)['paused']===1&&$model->used(11)===1,'renaming to a previously deleted target safely revives its row without losing configuration or duplicating quota');
    $q=$model->add(12,12,0,'quota_page',1); $model->add(12,-200,19,'quota_page',1); $store->set('instagram.checker.limit.12','1'); $denied=false;
    try { $model->rename($q,12,12,0,'another_quota_page',(int)$model->owned($q,12)['generation']); } catch(RuntimeException $e) { $denied=$e->getMessage()==='checker_limit_reached'; }
    replyCheck($denied&&$model->owned($q,12)['username']==='quota_page'&&!$store->query("SELECT 1 FROM ig_check_targets WHERE username='another_quota_page'")->fetchColumn(),'replacing one destination cannot bypass quota when the owner still tracks the old page elsewhere');
    $denied=false; try { $model->rename($q,42,12,0,'stolen_page',(int)$model->owned($q,12)['generation']); } catch(RuntimeException) { $denied=true; }
    replyCheck($denied&&$model->owned($q,12)['username']==='quota_page','even a bootstrap administrator cannot rename somebody else’s subscription');
    $tap($private,'check',$privateId); $store->query("UPDATE ig_check_targets SET scan_version=scan_version+1,state='ok' WHERE id=?",[$model->owned($privateId,7)['target']]);
    $api->duringEdit=fn()=>$tap($private,'rename',$privateId); (new Zero\InstagramCheckerPanel($bot))->refreshChecks();
    replyCheck($live($private)['view']==='rename'&&$prompt($private)>0&&$pending(7)==='ic:'.$private['token'],'a late verification completion cannot erase a newly opened owner reply request');
    $send('/cancel',7,7); $tap($private,'item',$privateId);
    $tap($private,'rename',(int)$data($private)['id']); $good=$prompt($private); $api->rejectPrompt=true; $failed=false;
    try { $send('invalid name',7,7,0,$good); } catch(RuntimeException) { $failed=true; } $api->rejectPrompt=false;
    replyCheck($failed&&$prompt($private)===$good&&$pending(7)==='ic:'.$private['token'],'a rejected replacement prompt retains the previous confirmed reply binding');
    $send('/cancel',7,7);

    $legacy=new Zero\Store($root.'/legacy'); $defaults=Zero\Ui::definitions()['texts']['instagram_checker_add_prompt']['legacy_values']; $legacy->set('text.instagram_checker_add_prompt',end($defaults));
    $legacy->set('layout.instagram.checker_manage',json_encode([['toggle'],['check','mode'],['delete'],['back','close']])); $legacy->set('button.style.instagram_checker_check','danger'); $legacy->query("DELETE FROM settings WHERE key='ui_checker_reply_v1'");
    new Zero\Store($root.'/legacy'); $ui=new Zero\Ui($legacy);
    replyCheck($ui->text('instagram_checker_add_prompt')===Zero\Ui::definitions()['texts']['instagram_checker_add_prompt']['value']&&$ui->layoutRows('instagram','checker_manage')[1]===['check','rename','mode']&&$ui->button('instagram_checker_check')['style']==='danger','upgrade retires only the old prompt default, inserts rename next to verification and preserves other layout and explicit color choices');
    $legacy->set('text.instagram_checker_add_prompt','راهنمای سفارشی'); $legacy->set('layout.instagram.checker_manage',json_encode([['rename'],['check'],['back','close']])); $legacy->query("DELETE FROM settings WHERE key='ui_checker_reply_v1'"); new Zero\Store($root.'/legacy');
    replyCheck($ui->text('instagram_checker_add_prompt')==='راهنمای سفارشی'&&$ui->layoutRows('instagram','checker_manage')[0]===['rename'],'deliberate prompt text and an already chosen rename position survive migration');
    foreach(['instagram_checker_rename_prompt','instagram_checker_renamed','instagram_checker_username_placeholder'] as $key) replyCheck($ui->customizable('text',$key),'new public copy is available in the existing Personalization editor: '.$key);
    replyCheck($ui->customizable('button','instagram_checker_rename')&&isset(Zero\UiRegistry::layouts('instagram')['checker_manage']['rename']),'Change ID is customizable and movable in the existing layout editor');
} finally { Zero\Download::clean($root); }
