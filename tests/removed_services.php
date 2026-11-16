<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function removedCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class RemovedServiceApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if(isset($params['text'])) Zero\TextFormat::validate($params['text'],4096);
        return $method==='sendMessage'?['message_id'=>500]:true;
    }
}
$root=sys_get_temp_dir().'/ciro-removed-'.bin2hex(random_bytes(5));
try {
    $config=new Zero\Config('123:fixture',[42],$root);$store=new Zero\Store($root);$api=new RemovedServiceApi();$bot=new Zero\Bot($config,$store,$api);
    $store->set('admins_only','0');$store->set('groups_enabled','1');$store->set('play.enabled','1');
    $url='https://play.google.com/store/apps/details?id=com.zhiliaoapp.musically';
    removedCheck(Zero\mediaUrl($url)===null&&!in_array('play',Zero\UiRegistry::services(),true),'removed app links and downloader are no longer registered');
    $catalog=Zero\Ui::definitions();
    $retired=[];foreach(['texts','buttons']as$bucket)foreach($catalog[$bucket]as$key=>$entry)if(($entry['group']??'')==='play'||in_array($key,['group_play','section_play','source_play','report_name_play'],true))$retired[]=$key;
    removedCheck(!$retired,'all removed service fields are absent from the UI catalog');
    removedCheck(isset($catalog['buttons']['radio_play'])&&Zero\youtubeUrl('https://youtu.be/dQw4w9WgXcQ')!==null,'radio controls and YouTube URL support remain registered');
    $serial=0;
    foreach([[7,7,$url],[7,-100,'/download '.$url]]as[$user,$chat,$text]) {
        $bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?17:0,'text'=>$text]]);
        removedCheck((int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'unsupported app link cannot queue work even with the old enable setting');
    }
    foreach([7,42]as$user)foreach(['gp:12:px_9a','custom:play','custom:playsource']as$action) {
        $api->calls=[];$bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'retired'.$serial,'from'=>['id'=>$user],'message'=>['message_id'=>500,'chat'=>['id'=>-100,'type'=>'supergroup'],'message_thread_id'=>17],'data'=>$action]]);
        removedCheck(count($api->calls)===1&&$api->calls[0][0]==='answerCallbackQuery'&&$api->calls[0][1]['show_alert']===true&&$api->calls[0][1]['text']===$bot->ui->plain('callback_stale'),'old controls only show an expired-menu alert for both users and admins');
    }
    $store->query('INSERT OR IGNORE INTO users(id) VALUES(7)');
    foreach(['queued','running','confirming','waiting_quality','choosing','done']as$n=>$status) {
        $store->query('INSERT INTO jobs(update_id,user_id,chat_id,url,phase,status,created,message_id,options) VALUES(?,?,?,?,?,?,?,?,?)',[100+$n,7,7,$url,'play',$status,time(),500,'{"device":"px_9a"}']);
    }
    $store->query('INSERT INTO jobs(update_id,user_id,chat_id,url,phase,status,created,message_id) VALUES(?,?,?,?,?,?,?,?)',[200,7,7,'https://youtu.be/dQw4w9WgXcQ','inspect','queued',time(),501]);
    $fresh=new Zero\Store($root);
    removedCheck((int)$fresh->query("SELECT COUNT(*) FROM jobs WHERE phase='play' AND status='cancelled' AND options='{}' AND expires=0")->fetchColumn()===5,'upgrade cancels every active retired job without retaining its device options');
    removedCheck($fresh->query('SELECT status FROM jobs WHERE update_id=105')->fetchColumn()==='done'&&$fresh->query('SELECT status FROM jobs WHERE update_id=200')->fetchColumn()==='queued','completed history and other downloader jobs survive retirement');
    $legacy=$fresh->query('SELECT * FROM jobs WHERE update_id=100')->fetch();$fresh->query("UPDATE jobs SET status='running' WHERE id=?",[$legacy['id']]);$api->calls=[];
    (new Zero\Worker($config,$fresh,$api))->process($legacy);
    removedCheck(!$api->calls&&$fresh->query('SELECT status FROM jobs WHERE update_id=100')->fetchColumn()==='cancelled'&&!is_dir($root.'/downloads'),'a stale worker snapshot is cancelled before file preparation or Telegram calls');
} finally { Zero\Download::clean($root); }
