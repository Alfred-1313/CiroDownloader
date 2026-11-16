<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';require dirname(__DIR__).'/src/Bot.php';require dirname(__DIR__).'/src/Download.php';
function vc(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
final class VerifyCheckerApi implements Zero\Api {
    public array $calls=[];public int $mid=500;public bool $failEdit=false;
    public function call(string $m,array $p=[]): mixed {
        $this->calls[]=[$m,$p];
        if(in_array($m,['sendMessage','editMessageText'],true))Zero\TextFormat::validate($p['text'],4096);
        if($m==='editMessageText'&&$this->failEdit)throw new RuntimeException('Fixture edit failure');
        return $m==='sendMessage'?['message_id'=>++$this->mid]:true;
    }
    public function last(): array {foreach(array_reverse($this->calls)as[$m,$p])if(in_array($m,['sendMessage','editMessageText'],true))return$p;return[];}
    public function button(string $op): string {foreach($this->last()['reply_markup']['inline_keyboard']??[]as$row)foreach($row as$b)if(preg_match('/^ic:[a-f0-9]{16}:'.preg_quote($op,'/').'(?::\d+)?$/D',$b['callback_data']??''))return$b['callback_data'];throw new RuntimeException('Missing button '.$op);}
}
$root=sys_get_temp_dir().'/ciro-checker-checks-'.bin2hex(random_bytes(5));
try {
    $c=new Zero\Config('123:fixture',[42,55],$root);$s=new Zero\Store($root);$a=new VerifyCheckerApi();$bot=new Zero\Bot($c,$s,$a);$model=new Zero\InstagramChecker($c,$s,$a);$panels=new Zero\InstagramCheckerPanel($bot);$serial=0;
    $tap=function(string $data,int $user=42,int $chat=42,?int $message=null,int $thread=0)use($bot,$a,&$serial):void{$bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'verify'.$serial,'from'=>['id'=>$user],'data'=>$data,'message'=>['message_id'=>$message??(int)($a->last()['message_id']??$a->mid),'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread]]]);};
    $id=$model->add(42,42,0,'fixture',3);$panels->open(42,42,0,0,'item',['id'=>$id]);$check=$a->button('check');$message=$a->mid;
    $tap($check,7);vc((int)$s->query('SELECT manual_at FROM ig_check_targets')->fetchColumn()===0,'a stolen verification button cannot schedule a scan');
    $tap($check,42,42,$message+1);vc((int)$s->query('SELECT manual_at FROM ig_check_targets')->fetchColumn()===0,'verification is bound to the original message');
    $tap($check,42,42,$message);$stamp=(int)$s->query('SELECT manual_at FROM ig_check_targets')->fetchColumn();vc($stamp>0&&str_contains($a->last()['text'],'در حال صحت‌سنجی'),'a click schedules a fresh scan without blocking the bot');$tap($check);vc((int)$s->query('SELECT manual_at FROM ig_check_targets')->fetchColumn()===$stamp,'repeated pending clicks share the same request');
    $reads=0;$reader=function()use(&$reads):array{$reads++;return['account_id'=>'7','mode'=>3,'posts'=>[['id'=>'100','url'=>'https://www.instagram.com/p/CHECK100/']],'stories'=>[['id'=>'200','url'=>'https://www.instagram.com/stories/fixture/200/']]];};
    $model->poll($reader);vc($model->checksReady(),'a completed scan wakes the result renderer');$panels->refreshChecks();vc($reads===1&&(int)$a->last()['message_id']===$message&&str_contains($a->last()['text'],'بررسی تازه موفق بود'),'the result edits the same message only after a real successful scan');vc((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'first verification baselines existing media without sending history');
    $clear=function()use($s):void{$s->query("UPDATE ig_check_targets SET manual_at=0,state='ok',next_check=?",[time()+300]);$s->set('instagram.checker.manual.42','0');};
    $tap($a->button('check'));vc(str_contains($a->last()['text'],'یک دقیقه'),'manual checks have a shared one-minute cooldown');
    $clear();$tap($a->button('check'));$panels->refreshChecks();vc(str_contains($a->last()['text'],'در حال صحت‌سنجی'),'an older successful state cannot confirm a new pending check');
    $model->poll(function()use(&$reads){$reads++;throw new Zero\MediaFailure('blocked','Fixture refusal');});$panels->refreshChecks();vc(str_contains($a->last()['text'],'محدود کرده')&&!str_contains($a->last()['text'],'بررسی تازه موفق بود'),'a fresh refusal is reported instead of reusing the old success');$next=(int)$s->query('SELECT next_check FROM ig_check_targets')->fetchColumn();$tap($a->button('check'));vc((int)$s->query('SELECT next_check FROM ig_check_targets')->fetchColumn()===$next&&$next>=time()+890,'manual clicks cannot bypass Instagram rate-limit backoff');
    $clear();$sub=$model->owned($id,42);$result=$model->requestCheck($sub,42);$other=$model->add(55,55,0,'fixture',3);vc($result['result']==='queued'&&$model->requestCheck($model->owned($other,55),55)['result']==='cooldown','two owners cannot repeatedly probe the same shared page');
    $model->change($id,42,42,0,'pause');vc($model->requestCheck($model->owned($id,42),42)['result']==='paused','verification never resumes a paused subscription');$model->change($id,42,42,0,'pause');
    $clear();$tap($a->button('check'));$tap($a->button('home'));$before=count($a->calls);$model->poll($reader);$panels->refreshChecks();vc(count($a->calls)===$before,'a completed check cannot overwrite a panel after the user navigates away');
    $panels->open(42,42,0,0,'item',['id'=>$id]);$clear();$tap($a->button('check'));$p=$s->query("SELECT * FROM ig_check_panels WHERE view='item' AND json_type(data,'$.check')='object' ORDER BY rowid DESC LIMIT 1")->fetch();$data=json_decode($p['data'],true);$data['check']['requested']=time()-181;$s->query('UPDATE ig_check_panels SET data=? WHERE token=?',[json_encode($data),$p['token']]);$panels->refreshChecks();vc(str_contains($a->last()['text'],'هنوز نتیجه')&&!str_contains($a->last()['text'],'بررسی تازه موفق بود'),'an unavailable worker times out without claiming the old result as a fresh success');
    $clear();$tap($a->button('check'));$model->poll($reader);$a->failEdit=true;$panels->refreshChecks();vc(!$model->checksReady(),'failed completion edits wait before a bounded retry');$a->failEdit=false;
    $group=$model->add(42,-100,17,'group_page',1);$s->set('instagram.checker.manual.42','0');$panels->open(42,-100,17,0,'item',['id'=>$group]);$gcheck=$a->button('check');$tap($gcheck,42,-100,null,18);vc((int)$s->query("SELECT manual_at FROM ig_check_targets WHERE username='group_page'")->fetchColumn()===0,'a copied verification button cannot move to a different topic');
    vc((new Zero\Ui($s))->customizable('button','instagram_checker_check')&&(new Zero\Ui($s))->customizable('text','instagram_checker_check_ok')&&isset(Zero\UiRegistry::layouts('instagram')['checker_manage']['check']),'verification messages, button and layout are in Instagram Personalization');
    $legacyConfig=new Zero\Config('123:fixture',[42],$root.'/legacy');$legacyStore=new Zero\Store($legacyConfig->storage);$legacy=new Zero\InstagramChecker($legacyConfig,$legacyStore,$a);$legacy->add(42,42,0,'existing_page',1);
    $legacyStore->db->exec('ALTER TABLE ig_check_targets DROP COLUMN scan_version; ALTER TABLE ig_check_targets DROP COLUMN manual_at');
    $reopened=new Zero\Store($legacyConfig->storage);$upgraded=new Zero\InstagramChecker($legacyConfig,$reopened,$a);$target=$reopened->query('SELECT username,scan_version,manual_at FROM ig_check_targets')->fetch();vc($target['username']==='existing_page'&&(int)$target['scan_version']===0&&(int)$target['manual_at']===0&&$upgraded->used(42)===1,'upgrading an existing checker database preserves subscriptions and adds verification cursors');
}finally{Zero\Download::clean($root);}
