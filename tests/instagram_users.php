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
    $ops=[]; $fail=''; $noPhoto=false; $gate=false; $wrong=false;
    $worker->instagramAccounts=new Zero\InstagramAccounts($config,function(array $request,string $folder) use(&$ops,&$fail,&$noPhoto,&$gate,&$wrong,$store): array {
        $ops[]=$request;
        if(($request['op']??'')!=='direct') throw new RuntimeException('The retired search route was used');
        if($fail!=='') throw new Zero\MediaFailure($fail,'Instagram account request refused [instagram=profile, http=429]');
        if($gate) $store->set('instagram.user_search','0');
        if(!$noPhoto) file_put_contents($folder.'/instagram-avatar.jpg',base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q=='));
        return ['item'=>['id'=>'123','username'=>$wrong?'different_account':$request['username'],'name'=>'Mehdi <test>','posts'=>581,'followers'=>16200,'following'=>326,'private'=>false,'bio'=>'Bio <b>literal</b> & safe','loaded'=>true], 'photo'=>!$noPhoto];
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


    $send('/user_instagram eliyas_sport'); iuCheck(!$store->query('SELECT 1 FROM jobs')->fetchColumn(),'profile display retains the previous default-off switch');
    $tap('custom:igusersearch',7,7,1); iuCheck(!Zero\InstagramUsers::enabled($store),'only an administrator can enable profile display');
    $tap('custom:settings:instagram',42,42,123); $tap('custom:igusersearch',42,42,123);
    iuCheck(Zero\InstagramUsers::enabled($store),'the renamed profile setting works');
    $send('/user_instagram not a name'); iuCheck(str_contains($last('sendMessage')['params']['text'],'آیدی دقیق')&&!$store->query('SELECT 1 FROM jobs')->fetchColumn(),'profile accepts one exact username rather than a search query');
    $store->set('bot_username','FixtureBot');
    $send('/user_instagram@OtherBot eliyas_sport',7,-100,321); iuCheck(!$store->query('SELECT 1 FROM jobs')->fetchColumn(),'commands addressed to another bot are ignored');
    $send('/user_instagram@FixtureBot @eliyas_sport',7,-100,321); $job=$run(); $id=(int)$job['id']; $row=$saved($id); $mid=(int)$row['message_id']; $p=$panel();
    iuCheck($row['status']==='done'&&$ops[0]===['op'=>'direct','username'=>'eliyas_sport']&&(int)$row['thread_id']===321,'the command queues exactly one direct lookup in the original topic');
    iuCheck($p['method']==='editMessageMedia'&&str_contains($p['text'],'@eliyas_sport')&&str_contains($p['text'],'Bio &lt;b&gt;literal&lt;/b&gt; &amp; safe')&&str_contains($p['text'],'Mehdi &lt;test&gt;'),'the profile photo and escaped biography appear directly with no result list');
    iuCheck($dataButtons($p)===['iu:'.$id.':0:x']&&!glob($config->storage.'/downloads/job-*'),'only Close is present while source and stories are off, and local media is released');
    $tap('iu:'.$id.':0:u:0',7,-100,$mid,321); iuCheck($saved($id)['status']==='done','old list-selection callbacks cannot reopen a search');
    $send('/story_instagram eliyas_sport'); iuCheck(str_contains($last('sendMessage')['params']['text'],'فعلاً خاموش'),'stories default off with their own switch');
    $tap('custom:igstories',7,7,1); iuCheck(!Zero\InstagramUsers::stories($store),'ordinary users cannot enable stories');
    $tap('custom:igstories',42,42,123); iuCheck(Zero\InstagramUsers::stories($store),'the administrator can enable stories independently');
    $send('https://www.instagram.com/eliyas_sport/?igsh=fixture',7,-100,321); $profile=$run(); $profileId=(int)$profile['id']; $profileMid=(int)$profile['message_id']; $p=$panel();
    iuCheck(in_array('iu:'.$profileId.':0:s',$dataButtons($p),true),'a profile link opens the profile directly with its stories button');
    foreach([[8,-100,$profileMid,321],[7,-101,$profileMid,321],[7,-100,$profileMid+1,321],[7,-100,$profileMid,322]] as [$u,$c,$m,$t]) $tap('iu:'.$profileId.':0:s',$u,$c,$m,$t);
    iuCheck(!(int)$store->query("SELECT COUNT(*) FROM jobs WHERE status='queued'")->fetchColumn(),'other users, chats, topics and copied messages cannot request stories');
    $tap('iu:'.$profileId.':0:s',7,-100,$profileMid,321); $tap('iu:'.$profileId.':0:s',7,-100,$profileMid,321);
    $story=$store->query("SELECT * FROM jobs WHERE status='queued'")->fetch();
    iuCheck($story&&$story['url']==='https://www.instagram.com/stories/eliyas_sport/'&&$story['phase']==='instagram'&&(int)$story['thread_id']===321&&(int)$store->query("SELECT COUNT(*) FROM jobs WHERE status='queued'")->fetchColumn()===1,'the owner queues one stories job; duplicate taps cannot queue it twice');
    iuCheck((int)$saved($profileId)['message_id']===$profileMid&&$saved($profileId)['status']==='done','requesting stories leaves the original profile usable');
    $store->query("UPDATE jobs SET status='cancelled' WHERE id=?",[$story['id']]);
    $send('@eliyas_sport'); $private=$run(); iuCheck($private['chat_id']==7&&$ops[array_key_last($ops)]['username']==='eliyas_sport','a bare @username works privately');
    $send('eliyas_sport'); $run(); iuCheck($ops[array_key_last($ops)]['op']==='direct','a bare username privately uses the same direct path');
    $noPhoto=true; $send('/user_instagram no_avatar'); $missing=$run();
    iuCheck($saved((int)$missing['id'])['message_kind']==='text'&&str_contains($panel()['text'],'عکس پروفایل در دسترس نیست'),'a missing avatar still displays the biography as text'); $noPhoto=false;
    $fail='blocked'; $send('/user_instagram limited'); $limited=$run();
    iuCheck($saved((int)$limited['id'])['status']==='failed'&&str_contains($last('editMessageText')['params']['text'],'دریافت این پروفایل')&&!str_contains($last('editMessageText')['params']['text'],'جست‌وجو'),'a profile 429 is accurately labelled without reviving search'); $fail='';
    $wrong=true; $send('/user_instagram requested'); $mismatch=$run(); iuCheck($saved((int)$mismatch['id'])['status']==='failed','another account can never replace the requested profile'); $wrong=false;
    $gate=true; $send('/user_instagram disabled_during_lookup'); $before=$count('editMessageMedia'); $blocked=$run();
    iuCheck($saved((int)$blocked['id'])['status']==='failed'&&$count('editMessageMedia')===$before,'access is rechecked after profile retrieval'); $gate=false; $store->set('instagram.user_search','1');
    $store->query('UPDATE jobs SET expires=? WHERE id=?',[time()-1,$id]); $tap('iu:'.$id.':0:x',7,-100,$mid,321);
    iuCheck(str_contains($last('answerCallbackQuery')['params']['text'],'مهلت'),'an expired profile has editable guidance');
    $store->set('groups_enabled','0'); $before=(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn(); $send('/user_instagram eliyas_sport',7,-100,321);
    iuCheck((int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$before,'group access also protects direct profiles'); $store->set('groups_enabled','1');
    $store->set('admins_only','1'); $send('/user_instagram eliyas_sport'); iuCheck((int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$before,'administrator-only access remains enforced'); $store->set('admins_only','0');
    $tap('custom:service:instagram',42,42,123);
    $hub=json_decode($last('editMessageText')['params']['reply_markup'],true)['inline_keyboard']; $hubActions=array_column(array_merge(...$hub),'callback_data');
    iuCheck(in_array('custom:items:instagram:profile:0',$hubActions,true)&&in_array('custom:items:instagram:stories:0',$hubActions,true),'Personalization separates profile and story fields in their own Instagram sections');
    foreach(['instagram_user_caption','instagram_story_caption','instagram_story_page_caption','instagram_story_empty','instagram_story_error_blocked'] as $key) iuCheck($bot->ui->customizable('text',$key),'active text is editable: '.$key);
    iuCheck(!$bot->ui->customizable('button','instagram_user_result')&&!$bot->ui->customizable('text','instagram_user_results'),'retired search controls are absent from Personalization');
    $store->set('button.style.instagram_user_stories','primary'); $store->set('button.icon.instagram_user_stories','123456789'); $button=$bot->ui->button('instagram_user_stories','iu:1:0:s');
    iuCheck(($button['style']??'')==='primary'&&($button['icon_custom_emoji_id']??'')==='123456789','the stories button uses shared colour and premium emoji settings');
    iuCheck(in_array('story_instagram',array_column(Zero\AdminCommands::menu($bot->ui),'command'),true)&&in_array('story_instagram',Zero\MusicSearch::PUBLIC_MENU,true),'public and administrator command menus include stories');
    iuCheck(!str_contains((string)file_get_contents($temp.'/calls.jsonl'),'sessionid='),'no cookie values reach Telegram');
    iuCheck(!preg_match('/PHP (?:Warning|Fatal|Notice)/',(string)file_get_contents($temp.'/server-error.log')),'the HTTP fixture has no PHP warnings');
    // Verify the production subprocess boundary and cookie-pool fallback as well as the UI rig.
    $runtime=$temp.'/runtime'; mkdir($runtime,0700); mkdir($runtime.'/bin',0700); mkdir($runtime.'/work',0700);
    $runtimeConfig=new Zero\Config('123:fixture',[42],$runtime); $runtimeConfig->galleryDl=$runtime.'/bin/gallery-dl'; $runtimeConfig->galleryProxy='http://proxy.fixture:8080'; $runtimeConfig->cookieFallback=true;
    file_put_contents($runtime.'/bin/python',"#!/usr/bin/env php\n<?php require ".var_export(__DIR__.'/fixtures/instagram_accounts_runtime.php',true).";\n"); chmod($runtime.'/bin/python',0700);
    $accounts=new Zero\InstagramAccounts($runtimeConfig);
    try { $accounts->fetch(['op'=>'direct','username'=>'mehdi'],$runtime.'/work'); throw new RuntimeException('Expected missing-session refusal'); }
    catch(Zero\MediaFailure $error) { iuCheck($error->reason==='cookies','direct profile lookup uses the signed-in Instagram pool'); }
    $cookie=fn(string $value)=>"# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\t".$value."\n";
    Zero\Instagram::saveCookies($runtimeConfig,$cookie('refused_fixture_session'));
    Zero\Instagram::saveCookies($runtimeConfig,$cookie('working_fixture_session'));
    $result=$accounts->fetch(['op'=>'direct','username'=>'mehdi'],$runtime.'/work');
    $runtimeCalls=array_map(fn($line)=>json_decode($line,true),file($runtime.'/work/runtime-calls.jsonl',FILE_IGNORE_NEW_LINES));
    iuCheck($result===['items'=>[]]&&count($runtimeCalls)===2&&!array_filter($runtimeCalls,fn($c)=>!$c['request_private']||!$c['cookies_private']||!$c['proxy']),'profile lookup rotates refused jars, uses private copies and forwards the gallery proxy');
    iuCheck(!is_file($runtime.'/work/instagram-account-cookies.txt'),'the private session copy is removed after the process finishes');
    try { $accounts->fetch(['op'=>'direct','username'=>'blocked'],$runtime.'/work'); throw new RuntimeException('Expected rate-limit refusal'); }
    catch(Zero\MediaFailure $error) { iuCheck($error->reason==='blocked'&&str_contains($error->getMessage(),'instagram=profile, http=429')&&!str_contains($error->getMessage(),'fixture_session'),'provider refusals preserve a safe stage and HTTP status without exposing session values'); }
    try { $accounts->fetch(['op'=>'direct','username'=>'unavailable'],$runtime.'/work'); throw new RuntimeException('Expected unavailable route'); }
    catch(Zero\MediaFailure $error) { iuCheck($error->reason==='unavailable'&&str_contains($error->getMessage(),'instagram=search, http=404'),'a missing search route stays distinct from an unavailable account'); }
    iuCheck(Zero\InstagramAccounts::diagnostic(['stage'=>'secret.invalid','http'=>'429 cookie=bad'])==='instagram=runtime, http=0','diagnostic fields reject arbitrary strings');
} finally { if(is_resource($server)) { proc_terminate($server); proc_close($server); } Zero\Download::clean($temp); }
