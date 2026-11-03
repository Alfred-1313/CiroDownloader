<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class WaitingApi implements Zero\Api {
    public array $calls=[]; private int $message=500;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        return $method==='sendMessage'?['message_id'=>++$this->message]:true;
    }
}
function waitingCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function waitingLink(int $update,string $url): array { return ['update_id'=>$update,'message'=>['from'=>['id'=>7],'chat'=>['id'=>7,'type'=>'private'],'text'=>$url]]; }
function waitingChoice(int $job,int $quality,int $message): array { return ['callback_query'=>['id'=>'choice','from'=>['id'=>7],'message'=>['message_id'=>$message,'chat'=>['id'=>7,'type'=>'private']],'data'=>'quality:'.$job.':'.$quality]]; }
$temp=sys_get_temp_dir().'/ciro-waiting-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $store=new Zero\Store($temp); $api=new WaitingApi(); $config=new Zero\Config('123:fake',[42],$temp);
    $bot=new Zero\Bot($config,$store,$api);
    $options=[360=>[['id'=>'18','audio'=>true]],480=>[['id'=>'135','audio'=>false]],'audio'=>[['id'=>'18']]];
    $first='https://youtu.be/ybNMMK5Q318'; $second='https://youtu.be/oXWMmELDOq0';
    $bot->handle(waitingLink(1,$first)); $firstJob=$store->claim();
    $bot->qualityMenu($firstJob,['title'=>'First video','options'=>$options]);
    $firstId=(int)$firstJob['id']; $firstMessage=(int)$firstJob['message_id'];
    $menu=end($api->calls)[1];
    waitingCheck(count($menu['reply_markup']['inline_keyboard'])===2&&!str_contains(json_encode($menu),':cancel'),'quality menus contain only available media choices, without a cancel row');
    waitingCheck((int)$store->query('SELECT expires FROM jobs WHERE id=?',[$firstId])->fetchColumn()===0,'new quality menus have no selection deadline');

    // Recreate the previous release's index and deadline before reopening the DB.
    $store->db->exec("DROP INDEX one_active; CREATE UNIQUE INDEX one_active ON jobs(user_id) WHERE status IN ('queued','running','choosing','confirming')");
    $store->query("UPDATE jobs SET status='choosing',created=?,expires=? WHERE id=?",[time()-31*86400,time()-30*86400,$firstId]);
    $store=new Zero\Store($temp); $bot=new Zero\Bot($config,$store,$api);
    $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$firstId])->fetch();
    waitingCheck($saved['status']==='waiting_quality'&&(int)$saved['expires']===0,'upgrading removes an old deadline while preserving the waiting menu');
    $before=count($api->calls);
    $bot->handle(waitingLink(2,$second));
    $secondJob=$store->query('SELECT * FROM jobs WHERE update_id=2')->fetch();
    waitingCheck($secondJob&&$secondJob['status']==='queued'&&count($api->calls)===$before+1&&end($api->calls)[0]==='sendMessage','a second link is accepted immediately without busy or cooldown errors');
    waitingCheck((int)$secondJob['message_id']!==$firstMessage&&$store->query('SELECT status FROM jobs WHERE id=?',[$firstId])->fetchColumn()==='waiting_quality','the new link gets its own status message and does not cancel or edit the previous menu');

    $bot->handle(waitingChoice($firstId,360,$firstMessage));
    waitingCheck(end($api->calls)[0]==='answerCallbackQuery'&&end($api->calls)[1]['text']===$bot->ui->plain('quality_busy'),'selecting an older menu during active processing is answered without a database conflict');
    waitingCheck($store->query('SELECT status FROM jobs WHERE id=?',[$firstId])->fetchColumn()==='waiting_quality','a busy selection leaves the old menu available for later');
    $claimed=$store->claim();
    waitingCheck((int)$claimed['id']===(int)$secondJob['id'],'the worker claims the new link instead of downloading an unchosen video');
    $bot->qualityMenu($claimed,['title'=>'Second video','options'=>$options]);
    $bot->handle(waitingChoice((int)$secondJob['id'],480,(int)$secondJob['message_id']));
    waitingCheck($store->query('SELECT status FROM jobs WHERE id=?',[$secondJob['id']])->fetchColumn()==='queued','the second video can be selected independently');
    $store->query("UPDATE jobs SET status='done' WHERE id=?",[$secondJob['id']]);
    $bot->handle(waitingChoice($firstId,360,$firstMessage));
    $old=$store->query('SELECT * FROM jobs WHERE id=?',[$firstId])->fetch();
    waitingCheck($old['status']==='queued'&&(int)$old['quality']===360,'the first video remains selectable even after a month and after processing a newer link');
    $before=count($api->calls); $bot->handle(waitingChoice($firstId,360,$firstMessage));
    waitingCheck(count($api->calls)===$before+1&&$store->query('SELECT status FROM jobs WHERE id=?',[$firstId])->fetchColumn()==='queued','repeat taps do not duplicate a download');
    $store->query("UPDATE jobs SET status='done' WHERE id=?",[$firstId]);

    for($i=100;$i<125;$i++) $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,created,options) VALUES(?,8,8,?,'waiting_quality',?,?)",[$i,$first,time()-31*86400,json_encode($options)]);
    waitingCheck($store->enqueue(3,9,9,$second)==='queued','abandoned quality menus do not consume download queue capacity');
    // Reproduce the older Store's index recreation with multiple waiting menus
    // and an active job for the same user, then reopen the current Store.
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,created) VALUES(4,8,8,?,'queued',?)",[$first,time()]);
    $store->db->exec("DROP INDEX one_active; CREATE UNIQUE INDEX one_active ON jobs(user_id) WHERE status IN ('queued','running','choosing','confirming')");
    waitingCheck((int)$store->query("SELECT COUNT(*) FROM jobs WHERE user_id=8 AND status='waiting_quality'")->fetchColumn()===25,'the previous release can recreate its active-job index without losing waiting menus');
    $store=new Zero\Store($temp);
    waitingCheck((int)$store->query("SELECT COUNT(*) FROM jobs WHERE user_id=8 AND status='waiting_quality'")->fetchColumn()===25&&$store->choose((int)$store->query('SELECT id FROM jobs WHERE update_id=100')->fetchColumn(),8,8,360)===null,'upgrading again preserves waiting menus and safely handles their active job');
    $store->query("UPDATE jobs SET status='done' WHERE update_id=4");
    $store->query("UPDATE jobs SET status='done' WHERE update_id=3");
    for($i=200;$i<220;$i++) $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,created) VALUES(?,?,?,?,'queued',?)",[$i,$i,$i,$first,time()]);
    $waiting=(int)$store->query("SELECT id FROM jobs WHERE user_id=8 AND status='waiting_quality' LIMIT 1")->fetchColumn();
    waitingCheck($store->choose($waiting,8,8,360)===null&&$store->query('SELECT status FROM jobs WHERE id=?',[$waiting])->fetchColumn()==='waiting_quality','selecting a waiting video still respects the actual download queue capacity');
} finally { Zero\Download::clean($temp); }
