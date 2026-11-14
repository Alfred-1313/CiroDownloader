<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class LimitApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        return $method==='sendMessage'?['message_id'=>900]:true;
    }
}
function limitCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function limitClick(string $action,int $user=42,int $chat=42): array {
    return ['callback_query'=>['id'=>'limits','from'=>['id'=>$user],'message'=>['message_id'=>900,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']],'data'=>$action]];
}
function limitInput(string $text,int $user=42): array {
    return ['message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]];
}
function limitRows(LimitApi $api): array { return end($api->calls)[1]['reply_markup']['inline_keyboard']??[]; }
function limitActions(LimitApi $api): array { return array_column(array_merge(...limitRows($api)),'callback_data'); }
$temp=sys_get_temp_dir().'/ciro-limits-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $store=new Zero\Store($temp); $api=new LimitApi(); $bot=new Zero\Bot(new Zero\Config('123:fake',[42],$temp),$store,$api);
    $url='https://youtu.be/ybNMMK5Q318'; $options=json_encode([360=>[['id'=>'18','audio'=>true]],'audio'=>[['id'=>'18']]]);
    limitCheck($store->processingLimit(7)===0&&$store->processingLimit(-100)===0,'private and group limits default off');
    $store->query('INSERT INTO users(id) VALUES(7)');
    limitCheck($store->enqueue(1,7,7,$url)==='queued','first link enters the queue');
    $store->query("UPDATE jobs SET status='running' WHERE update_id=1");
    limitCheck($store->enqueue(2,7,7,$url)==='queued','a new link is accepted immediately while the first file is uploading');
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,created,options) VALUES(3,7,7,?,'waiting_quality',?,?)",[$url,time(),$options]);
    $waiting=(int)$store->db->lastInsertId();
    limitCheck($store->choose($waiting,7,7,'audio')!==null,'another quality or audio menu can be selected while work is active with limits off');
    $store->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS one_active ON jobs(user_id) WHERE status IN ('queued','running','confirming')");
    $store=new Zero\Store($temp); $bot=new Zero\Bot($bot->config,$store,$api);
    limitCheck((int)$store->query("SELECT COUNT(*) FROM jobs WHERE user_id=7 AND status IN ('queued','running','confirming')")->fetchColumn()===3,'restart and the previous release index initialization preserve multiple active jobs');
    $store->set('processing_limit.private','2');
    limitCheck($store->enqueue(4,7,7,$url)==='busy','lowering a limit blocks new work without cancelling existing jobs');
    limitCheck((int)$store->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running','confirming')")->fetchColumn()===3,'lowering the limit keeps existing jobs intact');
    $store->query("UPDATE jobs SET status='done' WHERE update_id IN (1,2)");
    limitCheck($store->enqueue(4,7,7,$url)==='queued'&&$store->enqueue(5,7,7,$url)==='busy','a configured limit of two admits exactly two active jobs');
    limitCheck($store->enqueue(4,7,7,$url)==='duplicate','duplicate update IDs remain idempotent');
    $store->set('processing_limit.group','1');
    limitCheck($store->enqueue(10,7,-100,$url,77)==='queued','group limit is independent from private work');
    limitCheck($store->enqueue(11,7,-100,$url,88)==='busy','group topics share the same per-user group limit');
    limitCheck($store->enqueue(12,8,-100,$url)==='queued'&&$store->enqueue(13,7,-200,$url)==='queued','other users and other groups have independent allowances');
    $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,status,created,options) VALUES(14,7,-100,?,'waiting_quality',?,?)",[$url,time(),$options]);
    $groupWaiting=(int)$store->db->lastInsertId();
    $bot->handle(limitClick('quality:'.$groupWaiting.':360',7,-100));
    limitCheck(end($api->calls)[0]==='answerCallbackQuery'&&end($api->calls)[1]['text']===$bot->ui->plain('quality_busy'),'group quality callbacks enforce the group limit and show the editable notice');
    limitCheck($store->choose($groupWaiting,8,-100,360)===null&&$store->choose($groupWaiting,7,-200,360)===null,'quality ownership and original chat remain enforced');
    $store->query("UPDATE jobs SET status='done' WHERE update_id=10");
    limitCheck($store->choose($groupWaiting,7,-100,360)!==null,'a waiting menu remains usable after a slot opens');

    $bot->handle(limitClick('custom:section:access')); // Reproduce a stale prior screen.
    $bot->handle(limitInput('/customize'));
    $home=limitRows($api); $titles=0;
    foreach($home as $row) foreach($row as $button) {
        if(str_starts_with($button['callback_data'],'custom:hint:')) { $titles++; limitCheck(!isset($button['style']),'section titles are white and only explain themselves'); }
    }
    limitCheck($titles===4&&in_array('custom:category:general',limitActions($api),true),'the main screen groups sections under white titles and links to the general settings category');
    $bot->handle(limitClick('custom:features'));
    $switches=[]; foreach(limitRows($api) as $row) foreach($row as $button) if(in_array($button['callback_data'],['custom:groups_mode','custom:access'],true)) $switches[$button['callback_data']]=$button['style']??'';
    limitCheck($switches==['custom:groups_mode'=>'primary','custom:access'=>'primary'],'feature switches are blue; their state is the ✅ / ❌ in the text');
    $bot->handle(limitClick('custom:item:text:admin_only_notice'));
    limitCheck(in_array('custom:features',limitActions($api),true),'private-mode notice opened from the features page returns there despite a stale screen');
    $bot->handle(limitClick('custom:edit:text:admin_only_notice')); $bot->handle(limitInput('<b>پیام اختصاصی تازه</b>'));
    limitCheck(in_array('custom:features',limitActions($api),true),'saving the private-mode notice preserves its return destination');
    $bot->handle(limitClick('custom:features'));
    limitCheck(in_array('custom:limits',limitActions($api),true),'Back actually reopens the features page');
    $bot->handle(limitClick('custom:section:access')); $bot->handle(limitClick('custom:item:text:admin_only_notice'));
    limitCheck(in_array('custom:section:access',limitActions($api),true),'the same notice opened from a section returns to that section');
    $bot->handle(limitClick('custom:list:text:public:0')); $bot->handle(limitClick('custom:item:text:admin_only_notice'));
    limitCheck(in_array('custom:list:text:public:0',limitActions($api),true),'the same notice opened from a catalog returns to that catalog');

    $bot->handle(limitClick('custom:limit:private')); $bot->handle(limitInput('۳'));
    limitCheck($store->processingLimit(7)===3&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'Persian numeric limits save and close the input session');
    $bot->handle(limitClick('custom:limit:group')); $bot->handle(limitInput('٢'));
    limitCheck($store->processingLimit(-100)===2,'Arabic numeric limits save independently for groups');
    foreach(['-1','21','1.5','abc',''] as $invalid) {
        $bot->handle(limitClick('custom:limit:private')); $bot->handle(limitInput($invalid));
        limitCheck($store->processingLimit(7)===3&&$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn()!==false,'invalid input keeps the previous limit and allows retry: '.$invalid);
    }
    $bot->handle(limitClick('custom:noop')); $bot->handle(limitClick('custom:hint:feat_limits'));
    limitCheck($store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn()!==false&&end($api->calls)[0]==='answerCallbackQuery'&&end($api->calls)[1]['show_alert']===true,'tapping a title explains it in an alert and does not cancel pending input');
    $bot->handle(limitClick('custom:limits'));
    limitCheck(!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'Back cancels pending numeric input');
    $bot->handle(limitClick('custom:limit:private')); $bot->handle(limitInput('0'));
    $bot->handle(limitClick('custom:limitoff:group'));
    limitCheck((new Zero\Store($temp))->processingLimit(7)===0&&(new Zero\Store($temp))->processingLimit(-100)===0,'both number zero and the off button persist disabled limits across restart');
    foreach(['custom:limit:private','custom:limitoff:private','custom:limits','custom:limit:unknown'] as $action) $bot->handle(limitClick($action,7,7));
    $bot->handle(limitClick('custom:limit:group',42,-100));
    limitCheck(!$store->query('SELECT 1 FROM states WHERE user_id IN (7,42)')->fetchColumn(),'ordinary users and group chats cannot configure limits');
    limitCheck(!$bot->ui->customizable('button','processing_limits')&&$bot->ui->customizable('text','busy')&&$bot->ui->customizable('text','quality_busy'),'admin controls stay private while both public limit notices remain editable');
    $store->query("UPDATE jobs SET status='done'");
    // Upgrade a real legacy unique index, then race two independent connections.
    $store->db->exec("DROP INDEX one_active; CREATE UNIQUE INDEX one_active ON jobs(user_id) WHERE status IN ('queued','running','choosing','confirming')");
    $store=new Zero\Store($temp); $store->set('processing_limit.private','1');
    $processes=[];
    for($i=0;$i<2;$i++) {
        $script='require '.var_export(dirname(__DIR__).'/src/Core.php',true).'; $s=new Zero\\Store('.var_export($temp,true).'); echo $s->enqueue('.(100+$i).',90,90,'.var_export($url,true).');';
        $process=proc_open([PHP_BINARY,'-r',$script],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)) throw new RuntimeException('Cannot start competing queue request');
        $processes[]=[$process,$pipes];
    }
    $results=[];
    foreach($processes as [$process,$pipes]) {
        $results[]=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        limitCheck(proc_close($process)===0,'competing request completes without SQLite conflicts: '.$error);
    }
    sort($results);
    limitCheck($results===['busy','queued'],'atomic admission prevents competing connections from exceeding the configured limit');
    $store->set('processing_limit.private','0');
    $store->query("UPDATE jobs SET status='done'");
    for($i=200;$i<220;$i++) limitCheck($store->enqueue($i,91,91,$url)==='queued','disabled user limit admits work within global capacity');
    limitCheck($store->enqueue(220,91,91,$url)==='full','disabling user limits preserves the global queue bound');
} finally { Zero\Download::clean($temp); }
