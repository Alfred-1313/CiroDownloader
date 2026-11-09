<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function reactionCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-reactions-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    $log=$temp.'/http.jsonl'; file_put_contents($log,''); $fail=$temp.'/reject'; $slow=$temp.'/slow';
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php $method=basename(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH));'.
        'file_put_contents('.var_export($log,true).',json_encode(["method"=>$method,"params"=>$_POST],JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);'.
        'if($method==="setMessageReaction"&&is_file('.var_export($slow,true).')) sleep(8);'.
        'if($method==="setMessageReaction"&&is_file('.var_export($fail,true).')) { header("Content-Type: application/json");echo json_encode(["ok"=>false,"error_code"=>400,"description"=>"fixture reaction unavailable"]);return; }'.
        '$counter='.var_export($temp.'/counter',true).';$next=(int)@file_get_contents($counter)+1;file_put_contents($counter,(string)$next);'.
        'header("Content-Type: application/json");echo json_encode(["ok"=>true,"result"=>$method==="setMessageReaction"?true:["message_id"=>1000+$next]]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes);
    for($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($connection) { fclose($connection); break; } usleep(50000); }
    $config=new Zero\Config('123:fixture',[42],$temp.'/data','http://'.$address); $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api);
    (new Zero\GroupSupport($bot))->cacheIdentity(['id'=>123,'username'=>'CiroDownloaderBot']);
    $calls=fn()=>array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));
    $reacts=fn()=>array_values(array_filter($calls(),fn($call)=>$call['method']==='setMessageReaction'));
    $serial=0;
    $submit=function(int $user,int $source,string $text,?int $chat=null,int $thread=0) use($bot,&$serial): array {
        $chat??=$user; $update=['update_id'=>++$serial,'message'=>['message_id'=>$source,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread,'text'=>$text]];
        $bot->handle($update); return $update;
    };
    $click=fn(string $action,int $user=42,int $chat=42)=>$bot->handle(['callback_query'=>['id'=>'reaction','from'=>['id'=>$user],'message'=>['message_id'=>900,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']],'data'=>$action]]);
    $youtube='https://youtu.be/ybNMMK5Q318';
    $request=$submit(7,77,$youtube); $reaction=$reacts()[0]['params'];
    $job=$store->query('SELECT * FROM jobs WHERE update_id=?',[$request['update_id']])->fetch();
    reactionCheck((int)$reaction['chat_id']===7&&(int)$reaction['message_id']===77&&(int)$job['message_id']!==77&&json_decode($reaction['reaction'],true)===[['type'=>'emoji','emoji'=>'👾']],'a default 👾 reaction targets the original private link message through the real Telegram HTTP client');
    $records=$calls(); reactionCheck($records[0]['method']==='sendMessage'&&$records[1]['method']==='setMessageReaction'&&$job['status']==='queued','the editable status and claimable job exist before the optional reaction');
    $bot->handle($request); reactionCheck(count($reacts())===1,'replaying an update never sends another reaction');
    $submit(8,88,'https://instagram.com/p/ABC_def12/',-100123,17); $records=$reacts(); $reaction=end($records)['params'];
    reactionCheck((int)$reaction['chat_id']===-100123&&(int)$reaction['message_id']===88&&!isset($reaction['message_thread_id']),'an Instagram group reaction uses the user message in its original chat and topic');
    $submit(9,99,'/download@CiroDownloaderBot '.$youtube,-100123,18); $records=$reacts();
    reactionCheck((int)end($records)['params']['message_id']===99,'an addressed group download reacts to the command message containing the link');
    $click('custom:features'); $records=$calls(); $rows=json_decode(end($records)['params']['reply_markup'],true)['inline_keyboard']; $heading=false; $control=false;
    foreach($rows as $row) foreach($row as $button) {
        if(($button['callback_data']??'')==='custom:hint:feat_reaction') $heading=!isset($button['style']);
        if(($button['callback_data']??'')==='custom:reaction') $control=($button['style']??'')==='primary';
    }
    reactionCheck($heading&&$control,'Personalization exposes a white reaction title followed by a settings button');
    $click('custom:reaction:off'); $before=count($reacts()); $submit(10,101,$youtube);
    reactionCheck(count($reacts())===$before&&Zero\Reactions::selected(new Zero\Store($config->storage))==='','disabling reactions persists across stores and still accepts downloads');
    $click('custom:reaction:0',11,11); $click('custom:reaction:0',42,-100123);
    reactionCheck(Zero\Reactions::selected($store)==='','ordinary users and group callbacks cannot change reaction settings');
    $click('custom:reaction:input'); $submit(42,420,'✅');
    reactionCheck(Zero\Reactions::selected($store)===''&&$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'an unsupported emoji preserves the previous setting and pending input');
    $state=$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn(); $click('custom:hint:feat_reaction');
    reactionCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()===$state,'the white title explains itself without cancelling reaction input');
    $submit(42,421,'❤️');
    reactionCheck(Zero\Reactions::selected($store)==='❤'&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'a supported emoji is normalized to its Telegram reaction identifier and saved');
    $submit(12,122,$youtube); $records=$reacts();
    reactionCheck(json_decode(end($records)['params']['reaction'],true)[0]['emoji']==='❤','new links use the selected emoji instead of the default');
    $click('custom:reaction:0'); touch($fail); $request=$submit(13,133,$youtube); unlink($fail);
    $job=$store->query('SELECT * FROM jobs WHERE update_id=?',[$request['update_id']])->fetch();
    reactionCheck($job['status']==='queued'&&(int)$job['message_id']>0&&$store->get('last_job_error')==='','a rejected reaction never fails the download or overwrites media diagnostics');
    $before=count($reacts()); $submit(14,144,'not a link'); $store->set('admins_only','1'); $submit(15,155,$youtube); $store->set('admins_only','0');
    $store->set('groups_enabled','0'); $submit(16,166,$youtube,-100123); $store->set('groups_enabled','1');
    reactionCheck(count($reacts())===$before,'invalid input, restricted users and disabled groups receive no reaction');
    $store->set('processing_limit.private','1'); $submit(17,177,$youtube); $before=count($reacts()); $submit(17,178,$youtube);
    reactionCheck(count($reacts())===$before,'a rejected busy request is not falsely acknowledged with a reaction');
    foreach(['reaction_info','reaction_prompt','reaction_invalid'] as $key) reactionCheck(!isset($bot->catalog('text')[$key]),'administrator reaction controls stay outside user-text editing: '.$key);
    touch($slow); $started=microtime(true); $request=$submit(18,188,$youtube); $elapsed=microtime(true)-$started;
    $job=$store->query('SELECT * FROM jobs WHERE update_id=?',[$request['update_id']])->fetch();
    reactionCheck($elapsed<6&&$job['status']==='queued'&&(int)$job['message_id']>0,'an unresponsive reaction request times out quickly while the download remains queued');
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    Zero\Download::clean($temp);
}
