<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function iuCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-ig-users-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    mkdir($temp.'/storage',0700); mkdir($temp.'/storage/downloads',0700);
    file_put_contents($temp.'/calls.jsonl',''); file_put_contents($temp.'/mode.json','{}');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/fixtures/instagram_users_api.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes,null,array_merge(getenv(),['FIXTURE_ROOT'=>$temp]));
    for($i=0;$i<50;$i++) { $conn=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($conn) { fclose($conn); break; } usleep(50000); }
    $config=new Zero\Config('123:fixture',[42],$temp.'/storage'); $config->api='http://'.$address; $config->paceMessages=false;
    $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api); $worker=new Zero\Worker($config,$store,$api);
    $calls=fn()=>array_map(fn($line)=>json_decode($line,true),file($temp.'/calls.jsonl',FILE_IGNORE_NEW_LINES));
    $last=function(string $method) use($calls): ?array { foreach(array_reverse($calls()) as $call) if($call['method']===$method) return $call; return null; };
    $count=function(string $method) use($calls): int { return count(array_filter($calls(),fn($call)=>$call['method']===$method)); };
    $ops=[]; $fail=''; $noPhoto=false; $gate=false;
    $items=[];
    for($i=0;$i<10;$i++) $items[]=['id'=>(string)(100+$i),'username'=>'mehdi'.$i,'name'=>'Mehdi <'.$i.'>','posts'=>$i,'followers'=>12340+$i,'following'=>200,'private'=>$i===1,
        'loaded'=>$i<6,'bio'=>'Bio <b>literal</b> & safe','avatar'=>'https://scontent.cdninstagram.com/avatar.jpg'];
    $worker->instagramAccounts=new Zero\InstagramAccounts($config,function(array $request,string $folder) use(&$ops,&$fail,&$noPhoto,&$gate,$items,$store): array {
        $ops[]=$request;
        if($fail!=='') throw new Zero\MediaFailure($fail,'Fixture only; must never appear in diagnostics');
        if($gate) $store->set('instagram.user_search','0');
        if($request['op']==='search') return ['items'=>$request['query']==='nobody'?[]:$items];
        if($request['op']==='page') return ['items'=>array_map(fn($item)=>array_replace($item,['loaded'=>true]),$request['items'])];
        if(!$noPhoto) file_put_contents($folder.'/instagram-avatar.jpg',base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q=='));
        return ['item'=>array_replace($request['item'],['loaded'=>true]),'photo'=>!$noPhoto];
    });
    $update=0;
    $send=function(string $text,int $user=7,int $chat=7,int $thread=0) use($bot,$store,&$update): void {
        $store->query('UPDATE users SET last_request=0');
        $bot->handle(['update_id'=>++$update,'message'=>['message_id'=>$update,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread,'text'=>$text]]);
    };
    $tap=function(string $data,int $user,int $chat,int $mid,int $thread=0) use($bot,&$update): void {
        $bot->handle(['update_id'=>++$update,'callback_query'=>['id'=>'iu'.$update,'from'=>['id'=>$user,'is_bot'=>false],'data'=>$data,
            'message'=>['message_id'=>$mid,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread,'sender_chat'=>['id'=>$chat]]]]);
    };
    $saved=fn(int $id)=>$store->query('SELECT * FROM jobs WHERE id=?',[$id])->fetch();
    $run=function() use($store,$worker): array { $job=$store->claim('media'); if(!$job) throw new RuntimeException('Expected account worker job'); $worker->process($job); return $job; };
    $panel=function() use($calls): array {
        foreach(array_reverse($calls()) as $call) {
            $p=$call['params']; $media=isset($p['media'])?json_decode($p['media'],true):[];
            $rows=isset($p['reply_markup'])?json_decode($p['reply_markup'],true)['inline_keyboard']??[]:[];
            if(str_contains(json_encode($rows),'iu:')) return ['text'=>$media['caption']??$p['caption']??$p['text']??'','rows'=>$rows,'method'=>$call['method'],'params'=>$p];
        }
        return [];
    };
    $dataButtons=fn(array $p)=>array_column(array_merge(...$p['rows']),'callback_data');

    $send('/user_instagram mehdi'); iuCheck(!$store->query('SELECT 1 FROM jobs')->fetchColumn(),'account search defaults off');
    $tap('custom:igusersearch',7,7,1); iuCheck(!Zero\InstagramUsers::enabled($store),'a non-admin cannot enable search');
    $tap('custom:settings:instagram',42,42,123); $tap('custom:igusersearch',42,42,123);
    iuCheck(Zero\InstagramUsers::enabled($store),'the Instagram settings switch enables account search');
    $send('/user_instagram'); iuCheck(str_contains($last('sendMessage')['params']['text'],'/user_instagram mehdi')&&!$store->query('SELECT 1 FROM jobs')->fetchColumn(),'missing arguments explain the command without queueing');
    $bot->store->set('bot_username','FixtureBot');
    $send('/user_instagram@OtherBot mehdi',7,-100,321); iuCheck(!$store->query('SELECT 1 FROM jobs')->fetchColumn(),'commands addressed to another bot are ignored');
    $send('/user_instagram@FixtureBot @mehdi',7,-100,321); $job=$run(); $id=(int)$job['id']; $row=$saved($id); $mid=(int)$row['message_id']; $state=json_decode($row['options'],true);
    $p=$panel(); $actions=$dataButtons($p);
    iuCheck($row['status']==='done'&&$row['phase']==='instagram_users'&&(int)$row['thread_id']===321&&$state['query']==='mehdi','a group search stays in its original status message and topic');
    iuCheck(count($p['rows'])===8&&count($p['rows'][0])===1&&str_contains($p['rows'][0][0]['text'],'@mehdi0')&&str_contains($p['rows'][0][0]['text'],'12,340'),'each wide result button has username, posts and followers');
    $select=$actions[0]; $before=count($ops);
    foreach([[8,-100,$mid,321],[7,-101,$mid,321],[7,-100,$mid+1,321],[7,-100,$mid,322]] as [$u,$c,$m,$t]) $tap($select,$u,$c,$m,$t);
    iuCheck($saved($id)['status']==='done'&&count($ops)===$before,'other users, chats, topics and copied messages cannot select accounts');
    $next='iu:'.$id.':0:p:1'; $tap($next,7,-100,$mid,321); $tap($next,7,-100,$mid,321);
    iuCheck($saved($id)['status']==='queued'&&str_contains($last('answerCallbackQuery')['params']['text'],'نما'),'duplicate navigation is rejected while its one worker operation is queued');
    $run(); $p=$panel(); $actions=$dataButtons($p);
    iuCheck(in_array('iu:'.$id.':1:u:6',$actions,true)&&$ops[array_key_last($ops)]['op']==='page','the next page loads only its own profiles in the worker');
    $tap('iu:'.$id.':1:u:6',7,-100,$mid,321); $run(); $p=$panel(); $state=json_decode($saved($id)['options'],true);
    iuCheck($p['method']==='editMessageMedia'&&str_contains($p['text'],'@mehdi6')&&str_contains($p['text'],'Bio &lt;b&gt;literal&lt;/b&gt; &amp; safe')&&str_contains($p['text'],'Mehdi &lt;6&gt;'),'selection uploads the avatar with escaped name and bio as the caption');
    iuCheck($last('editMessageMedia')['files']['avatar']['bytes']>0&&!glob($config->storage.'/downloads/job-*')&&!empty($state['items'][6]['file_id']),'only a confirmed Telegram avatar ID is cached and local media is released');
    $tap('iu:'.$id.':2:b',7,-100,$mid,321); $run(); $p=$panel();
    iuCheck(in_array('iu:'.$id.':3:u:6',$dataButtons($p),true),'Back returns to the same results page');
    $before=count($ops); $tap('iu:'.$id.':3:u:6',7,-100,$mid,321); $run();
    iuCheck(count($ops)===$before&&json_decode($last('editMessageMedia')['params']['media'],true)['media']===$state['items'][6]['file_id'],'reopening the same profile reuses its confirmed Telegram avatar');
    $tap('iu:'.$id.':4:b',7,-100,$mid,321); $run();
    $noPhoto=true; $tap('iu:'.$id.':5:u:7',7,-100,$mid,321); $run(); $row=$saved($id); $newMid=(int)$row['message_id'];
    iuCheck($newMid!==$mid&&$row['message_kind']==='text'&&str_contains($panel()['text'],'عکس پروفایل در دسترس نیست')&&(int)$last('deleteMessage')['params']['message_id']===$mid,'an account without an avatar never inherits the previous account photo');
    $tap('iu:'.$id.':6:b',7,-100,$mid,321); iuCheck($saved($id)['status']==='done','the replaced message cannot navigate the new profile');
    $tap('iu:'.$id.':6:b',7,-100,$newMid,321); $run(); $noPhoto=false;
    $fail='cookies'; $tap('iu:'.$id.':7:u:8',7,-100,$newMid,321); $run();
    iuCheck($saved($id)['status']==='done'&&str_contains($panel()['text'],'کوکی ورود معتبر')&&in_array('iu:'.$id.':8:u:8',$dataButtons($panel()),true),'a profile refusal explains cookies and keeps the results usable');
    $fail=''; $gate=true; $tap('iu:'.$id.':8:u:8',7,-100,$newMid,321); $before=$count('editMessageMedia'); $run();
    iuCheck($saved($id)['status']==='failed'&&$count('editMessageMedia')===$before,'turning search off during retrieval prevents profile delivery'); $gate=false;
    $store->set('instagram.user_search','1');
    $send('/user_instagram nobody'); $empty=$run();
    iuCheck($saved((int)$empty['id'])['status']==='done'&&str_contains($last('editMessageText')['params']['text'],'حسابی پیدا نشد'),'a valid empty search is distinct from an Instagram error');
    $fail='blocked'; $send('/user_instagram mehdi'); $failed=$run();
    iuCheck($saved((int)$failed['id'])['status']==='failed'&&str_contains($last('editMessageText')['params']['text'],'محدود کرده'),'a rate limit never appears as no accounts found'); $fail='';
    $send('/user_instagram mehdi'); $expire=$run(); $expired=$saved((int)$expire['id']); $store->query('UPDATE jobs SET expires=? WHERE id=?',[time()-1,$expire['id']]);
    $tap('iu:'.$expire['id'].':0:u:0',7,7,(int)$expired['message_id']);
    iuCheck(str_contains($last('answerCallbackQuery')['params']['text'],'مهلت'),'an expired list has actionable feedback');
    $store->set('groups_enabled','0'); $before=(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn(); $send('/user_instagram mehdi',7,-100,321);
    iuCheck((int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$before,'disabled group use also blocks account search');
    $store->set('groups_enabled','1'); $store->set('admins_only','1'); $send('/user_instagram mehdi');
    iuCheck((int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$before,'the existing administrator-only access setting is preserved');
    foreach(Zero\UiRegistry::items('instagram',Zero\Ui::definitions()) as [$kind,$key]) if(str_contains($key,'instagram_user')) iuCheck($bot->ui->customizable($kind,$key),'Instagram customization exposes '.$key);
    iuCheck(!$bot->ui->customizable('button','instagram_user_toggle')&&$bot->ui->required('instagram_user_back')&&$bot->ui->required('instagram_user_close'),'settings are admin-only and essential navigation cannot be hidden');
    $store->set('button.style.instagram_user_result','primary'); $store->set('button.icon.instagram_user_result','123456789');
    $button=$bot->ui->button('instagram_user_result','iu:1:0:u:0',['username'=>'mehdi','posts'=>'5','followers'=>'20']);
    iuCheck(($button['style']??'')==='primary'&&($button['icon_custom_emoji_id']??'')==='123456789','profile buttons use the shared colour and premium emoji renderer');
    iuCheck(in_array('user_instagram',array_column(Zero\AdminCommands::menu($bot->ui),'command'),true)&&in_array('user_instagram',Zero\MusicSearch::PUBLIC_MENU,true),'both public and administrator command menus include account search');
    $content=(string)file_get_contents($temp.'/calls.jsonl');
    iuCheck(!str_contains($content,'Fixture only;')&&!str_contains($content,'sessionid='),'diagnostics contain neither provider output nor cookie values');
    $errors=(string)file_get_contents($temp.'/server-error.log'); iuCheck(!preg_match('/PHP (?:Warning|Fatal|Notice)/',$errors),'the local API rig has no PHP warnings');
    // Verify the production subprocess boundary and cookie-pool fallback as well as the UI rig.
    $runtime=$temp.'/runtime'; mkdir($runtime,0700); mkdir($runtime.'/bin',0700); mkdir($runtime.'/work',0700);
    $runtimeConfig=new Zero\Config('123:fixture',[42],$runtime); $runtimeConfig->galleryDl=$runtime.'/bin/gallery-dl'; $runtimeConfig->galleryProxy='http://proxy.fixture:8080'; $runtimeConfig->cookieFallback=true;
    file_put_contents($runtime.'/bin/python',"#!/usr/bin/env php\n<?php require ".var_export(__DIR__.'/fixtures/instagram_accounts_runtime.php',true).";\n"); chmod($runtime.'/bin/python',0700);
    $accounts=new Zero\InstagramAccounts($runtimeConfig);
    try { $accounts->fetch(['op'=>'search','query'=>'mehdi'],$runtime.'/work'); throw new RuntimeException('Expected missing-session refusal'); }
    catch(Zero\MediaFailure $error) { iuCheck($error->reason==='cookies','account searches require a signed-in Instagram jar even in cookie-fallback mode'); }
    $cookie=fn(string $value)=>"# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\t".$value."\n";
    Zero\Instagram::saveCookies($runtimeConfig,$cookie('refused_fixture_session'));
    Zero\Instagram::saveCookies($runtimeConfig,$cookie('working_fixture_session'));
    $result=$accounts->fetch(['op'=>'search','query'=>'mehdi'],$runtime.'/work');
    $runtimeCalls=array_map(fn($line)=>json_decode($line,true),file($runtime.'/work/runtime-calls.jsonl',FILE_IGNORE_NEW_LINES));
    iuCheck($result===['items'=>[]]&&count($runtimeCalls)===2&&!array_filter($runtimeCalls,fn($c)=>!$c['request_private']||!$c['cookies_private']||!$c['proxy']),'search rotates refused jars, uses private copies and forwards the gallery proxy');
    iuCheck(!is_file($runtime.'/work/instagram-account-cookies.txt'),'the private session copy is removed after the process finishes');
    try { $accounts->fetch(['op'=>'search','query'=>'blocked'],$runtime.'/work'); throw new RuntimeException('Expected rate-limit refusal'); }
    catch(Zero\MediaFailure $error) { iuCheck($error->reason==='blocked'&&!str_contains($error->getMessage(),'fixture_session'),'provider refusals preserve a safe reason and never expose session values'); }
} finally { if(is_resource($server)) { proc_terminate($server); proc_close($server); } Zero\Download::clean($temp); }
