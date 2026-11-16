<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function ck(bool $yes,string $label): void { if(!$yes) throw new RuntimeException($label); echo "PASS $label\n"; }
final class CheckerApi implements Zero\Api {
    public array $calls=[]; public array $admins=[]; public int $mid=500;
    public function call(string $method,array $p=[]): mixed {
        $this->calls[]=[$method,$p];
        if(isset($p['text'])) Zero\TextFormat::validate($p['text'],4096);
        foreach($p['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $b) ck(strlen($b['callback_data']??'')<=64,'callback fits Telegram');
        if($method==='getChatMember') return ['status'=>$this->admins[(int)$p['user_id']]??'member'];
        if($method==='getMe') return ['id'=>123,'username'=>'FixtureBot'];
        return $method==='sendMessage'?['message_id'=>++$this->mid]:true;
    }
    public function last(string $method): ?array { foreach(array_reverse($this->calls) as [$m,$p]) if($m===$method) return $p; return null; }
}
$root=sys_get_temp_dir().'/ciro-checker-'.bin2hex(random_bytes(5));
try {
    $c=new Zero\Config('123:fixture',[55],$root);$s=new Zero\Store($root);$s->addAdministrator(42,55,$c->admins);$api=new CheckerApi();$model=new Zero\InstagramChecker($c,$s,$api);
    ck(Zero\InstagramChecker::enabled($s)&&!Zero\InstagramChecker::everyone($s),'checker starts available to bot administrators and public access starts off');
    $denied=false;try{$model->add(7,7,0,'fixture',3);}catch(RuntimeException $e){$denied=$e->getMessage()==='checker_denied';}ck($denied,'ordinary users cannot register a default checker');
    $one=$model->add(42,42,0,'Fixture',3); $group=$model->add(42,-100,17,'@fixture',3);
    ck($model->used(42)===1&&count($model->subscriptions(42,-100,17))===1,'one page in private and group counts once and keeps the original topic');
    $duplicate=false;try{$model->add(55,-100,17,'fixture',3);}catch(RuntimeException $e){$duplicate=$e->getMessage()==='checker_duplicate';}ck($duplicate,'two administrators cannot create duplicate sends for the same group topic and page');
    $model->add(42,42,0,'second_page',1);$model->add(42,42,0,'third_page',2);
    $full=false;try{$model->add(42,42,0,'fourth_page',3);}catch(RuntimeException $e){$full=$e->getMessage()==='checker_limit_reached';}ck($full&&$model->used(42)===3,'the default quota counts three unique pages across destinations');
    ck(!$model->change($one,55,42,0,'delete')&&!$model->change($group,42,-100,18,'delete'),'another owner or topic cannot mutate a checker');
    $model->change($one,42,42,0,'pause');ck($model->used(42)===3,'pausing never bypasses the quota');
    $model->change($one,42,42,0,'delete');ck($model->used(42)===3,'deleting one destination retains the same page registered elsewhere');
    $model->change($group,42,-100,17,'delete');ck($model->used(42)===2,'deleting its last destination frees exactly one quota slot');
    $s->set('instagram.checker.public','1'); $api->admins[7]='member'; ck(!$model->allowed(7,-100),'public access never grants ordinary group members registration permission');
    $api->admins[7]='administrator';$s->query("DELETE FROM settings WHERE key='radio.admin.-100.7'"); ck($model->allowed(7,-100),'public registration is available to that group administrator');
    $s->set('instagram.checker.limit.7','1');$model->add(7,7,0,'fixture',3);$full=false;try{$model->add(7,7,0,'other_page',1);}catch(RuntimeException){$full=true;}ck($full,'an individual quota overrides the global default');
    for($i=1;$i<=3;$i++)$model->add(8,8,0,'ordinary_page_'.$i,1);$full=false;try{$model->add(8,8,0,'ordinary_page_4',1);}catch(RuntimeException $e){$full=$e->getMessage()==='checker_limit_reached';}ck($full&&$model->limit(8)===3,'ordinary users keep the three-page default when public access is enabled');
    $s->set('instagram.checker.limit.55','1');for($i=1;$i<=5;$i++)$model->add(55,55,0,'root_page_'.$i,1);ck($model->limit(55)===0&&$model->used(55)===5,'a configured root exceeds the ordinary quota even with an old individual cap');
    ck($model->limit(42)===3,'an administrator added through the panel does not inherit the root quota exemption');

    $data=$root.'/state';$c=new Zero\Config('123:fixture',[42,55],$data);$s=new Zero\Store($data);$model=new Zero\InstagramChecker($c,$s,$api);$private=$model->add(42,42,0,'fixture',3);$group=$model->add(42,-100,17,'fixture',3);$other=$model->add(55,55,0,'fixture',1);
    $reads=0; $posts=[['id'=>'100','url'=>'https://www.instagram.com/p/ABC_def12/']];$stories=[['id'=>'200','url'=>'https://www.instagram.com/stories/fixture/200/']];
    $reader=function($target,$mode) use(&$reads,&$posts,&$stories): array {$reads++;return ['account_id'=>'7','mode'=>$mode,'posts'=>$posts,'stories'=>$stories];};
    $claimed=$model->claimTarget();$second=new Zero\InstagramChecker($c,new Zero\Store($data),$api);ck($second->claimTarget()===null,'a target lease prevents parallel scanners fetching the same page');$s->query("UPDATE ig_check_targets SET lease='',lease_until=0 WHERE id=?",[$claimed['id']]);
    $model->poll($reader);ck($reads===1&&(int)$s->query('SELECT COUNT(*) FROM ig_check_deliveries')->fetchColumn()===0,'one shared initial scan baselines all existing media without sending history');
    $posts[]=['id'=>'101','url'=>'https://www.instagram.com/p/NEW_def12/'];$stories[]=['id'=>'201','url'=>'https://www.instagram.com/stories/fixture/201/'];$s->query('UPDATE ig_check_targets SET next_check=0');$model->poll($reader);
    ck((int)$s->query('SELECT COUNT(*) FROM ig_check_deliveries')->fetchColumn()===5,'new post and story IDs are distributed according to every subscription mode');
    ck((int)$s->query("SELECT COUNT(*) FROM jobs WHERE phase='instagram'")->fetchColumn()>0,'new content enters the existing bounded media job queue');
    $s->query('UPDATE ig_check_targets SET next_check=0');$model->poll($reader);ck((int)$s->query('SELECT COUNT(*) FROM ig_check_deliveries')->fetchColumn()===5,'the same IDs and edited or pinned posts never create duplicate deliveries');
    $posts[]=['id'=>'999','url'=>'https://www.instagram.com/p/OLD_def12/','time'=>time()-86400];$s->query('UPDATE ig_check_targets SET next_check=0');$model->poll($reader);
    ck((int)$s->query('SELECT COUNT(*) FROM ig_check_deliveries')->fetchColumn()===5,'pinning an older previously unseen post does not send old content');
    $job=$s->query("SELECT * FROM jobs WHERE status='queued' ORDER BY id LIMIT 1")->fetch();$model->beginSend($job);$model->failure($job);$id=Zero\InstagramChecker::deliveryId($job);
    ck($s->query('SELECT status FROM ig_check_deliveries WHERE id=?',[$id])->fetchColumn()==='uncertain','an uncertain Telegram send is retained without automatic resending');
    $model->queuePending();ck((int)$s->query('SELECT COUNT(*) FROM jobs WHERE update_id=?',[$job['update_id']])->fetchColumn()===1,'queue sweeps cannot recreate the uncertain upload');
    $model->change($group,42,-100,17,'pause');$gjob=$s->query('SELECT * FROM jobs WHERE chat_id=-100 ORDER BY id DESC LIMIT 1')->fetch();if($gjob)ck(!$model->canDeliver($gjob),'pausing invalidates queued deliveries before any media is uploaded');
    $s->query('UPDATE ig_check_targets SET next_check=0');$before=$reads;$model->poll(function() use(&$reads){$reads++;throw new Zero\MediaFailure('blocked','fixture refusal');});
    $target=$s->query('SELECT * FROM ig_check_targets')->fetch();ck($reads===$before+1&&$target['state']==='blocked'&&(int)$target['next_check']>=time()+890,'a rate refusal backs off without trying more APIs or jars');
    ck(!$model->due(),'the idle supervisor avoids checker helper forks until work is due');
    ck(!array_filter($api->calls,fn($call)=>$call[0]==='sendMessage'),'scans, quota changes and failures never emit technical messages');
} finally {Zero\Download::clean($root);}
