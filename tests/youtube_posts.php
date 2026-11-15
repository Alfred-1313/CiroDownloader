<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function postCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-youtube-posts-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $server=null;
try {
    mkdir($temp.'/data',0700); mkdir($temp.'/data/downloads',0700); file_put_contents($temp.'/http.jsonl',''); file_put_contents($temp.'/backend.jsonl','');
    $config=new Zero\Config('123:fixture',[42],$temp.'/data'); $config->paceMessages=false; $runner=new Zero\Download($config);
    $runner->run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=160x288','-frames:v','1','-update','1','-threads','1',$temp.'/photo.jpg'],$temp,30);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/fixtures/youtube_posts_api.php'],[0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/server-error.log','w']],$pipes,null,array_merge(getenv(),['FIXTURE_ROOT'=>$temp]));
    for($i=0;$i<50;$i++) { $conn=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($conn) { fclose($conn); break; } usleep(50000); }
    $config->api='http://'.$address; file_put_contents($temp.'/base',$config->api);
    $python=getenv('GALLERY_DL_PYTHON')?:(is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'/usr/bin/python3');
    mkdir($temp.'/backend',0700);
    $stub=$temp.'/backend/python'; file_put_contents($stub,'#!'.$python."\nimport runpy,sys\nsys.argv.insert(1,".json_encode($temp,JSON_UNESCAPED_SLASHES).")\nrunpy.run_path(".json_encode(__DIR__.'/fixtures/youtube_posts_runtime.py',JSON_UNESCAPED_SLASHES).",run_name='__main__')\n"); chmod($stub,0700); $config->ytDlp=$temp.'/backend/yt-dlp';
    $store=new Zero\Store($config->storage); $api=new Zero\Telegram($config); $bot=new Zero\Bot($config,$store,$api); $worker=new Zero\Worker($config,$store,$api); $update=0;
    $mode=fn(array $data)=>file_put_contents($temp.'/mode.json',json_encode($data)); $mode(['images'=>1]);
    $calls=fn()=>array_map(fn($line)=>json_decode($line,true),file($temp.'/http.jsonl',FILE_IGNORE_NEW_LINES));
    $last=function(string $name) use($calls): ?array { foreach(array_reverse($calls()) as $call) if($call['method']===$name) return $call; return null; };
    $send=function(string $text,int $user=7,int $chat=-100,int $topic=321) use($store,$bot,&$update): void { $store->query('UPDATE users SET last_request=0'); $bot->handle(['update_id'=>++$update,'message'=>['message_id'=>$update,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$topic,'text'=>$text]]); };
    $run=function() use($store,$worker): array { $job=$store->claim('media'); if(!$job) throw new RuntimeException('Expected YouTube post job'); $worker->process($job); return $job; };
    $saved=fn(int $id)=>$store->query('SELECT * FROM jobs WHERE id=?',[$id])->fetch();
    $tap=function(string $data,int $user,int $message,int $topic=321) use($bot,&$update): void { $bot->handle(['update_id'=>++$update,'callback_query'=>['id'=>'post'.$update,'from'=>['id'=>$user,'is_bot'=>false],'data'=>$data,'message'=>['message_id'=>$message,'chat'=>['id'=>-100,'type'=>'supergroup'],'message_thread_id'=>$topic]]]); };
    $url='https://www.youtube.com/post/UgkxcNOh_kIeoLnJzcsUSE6xIg1-fz_PrvBY';
    foreach([$url.'?surface=shorts&data=tracking&si=tracking','http://m.youtube.com/post/UgkxcNOh_kIeoLnJzcsUSE6xIg1-fz_PrvBY','youtube.com/post/UgkxcNOh_kIeoLnJzcsUSE6xIg1-fz_PrvBY'] as $input)
        postCheck(Zero\mediaUrl($input)===$url&&Zero\galleryService($input)==='youtube','community post links normalize and discard share tracking');
    foreach(['https://youtube.com.evil/post/Ug1234567890','https://u:p@youtube.com/post/Ug1234567890','https://youtube.com:443/post/Ug1234567890','https://youtube.com/post/Ug1234567890/extra','https://youtube.com/@channel/community'] as $input) postCheck(Zero\youtubePostUrl($input)===null,'unsafe and channel links are not individual posts');
    postCheck(Zero\youtubeUrl('https://youtu.be/ybNMMK5Q318')==='https://www.youtube.com/watch?v=ybNMMK5Q318'&&Zero\galleryService('https://youtu.be/ybNMMK5Q318')===null,'ordinary videos retain their existing quality-selection route');
    $send($url); postCheck(!$store->query('SELECT 1 FROM jobs')->fetchColumn()&&$last('sendMessage')['params']['text']===$bot->text('youtube_post_disabled'),'community downloads are off by default with an editable notice');
    $adminTap=function(string $action) use($bot,&$update): void { $bot->handle(['update_id'=>++$update,'callback_query'=>['id'=>'settings'.$update,'from'=>['id'=>42],'data'=>$action,'message'=>['message_id'=>900,'chat'=>['id'=>42,'type'=>'private']]]]); };
    $adminTap('custom:settings:youtube'); $adminTap('custom:ytposts');
    postCheck($store->get('youtube.posts')==='1','the YouTube settings switch enables community posts');
    Zero\youtubeCookiePool($config)->add("# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSID\tTEST_ONLY\n");
    $config->youtubeProxy='http://fixture-proxy.invalid:8080';
    $send($url); $single=$run();
    $photo=$last('sendPhoto');
    postCheck($saved((int)$single['id'])['status']==='done'&&$photo&&str_contains($photo['params']['caption'],'&lt;b&gt;بدون تفسیر HTML&lt;/b&gt;')&&!str_contains($photo['params']['caption'],'NEIGHBOUR'),'a single photo is sent with the requested post text safely escaped, never a neighbouring post');
    postCheck((int)$photo['params']['message_thread_id']===321&&json_decode($photo['params']['reply_markup'],true)['inline_keyboard']===[],'single posts retain the original topic and hide their source button by default');
    $trace=array_map(fn($line)=>json_decode($line,true),file($temp.'/backend.jsonl',FILE_IGNORE_NEW_LINES));
    postCheck($trace[0]['private_cookie']&&$trace[0]['session']&&$trace[0]['proxy'],'community posts use a private copy of the existing YouTube cookies and its proxy');
    $store->set('youtube.carousel_mode','article'); $mode(['images'=>3]); $send($url); $multi=$run();
    $state=json_decode($saved((int)$multi['id'])['options'],true)['pages']??[]; $mid=(int)($state['message']??0);
    $rows=json_decode($last('sendPhoto')['params']['reply_markup'],true)['inline_keyboard']; $next=$rows[0][0]['callback_data'];
    postCheck(count($state['items']??[])===3&&$mid>0&&!isset($rows[0][0]['style'])&&!str_contains(json_encode($calls()),'quality:'),'multiple images use wide white navigation without quality selection or external articles');
    $tap($next,8,$mid); postCheck($saved((int)$multi['id'])['status']==='done','only the original requester can turn post pages');
    $tap($next,7,$mid,999); postCheck($saved((int)$multi['id'])['status']==='done','post controls cannot be moved to another topic');
    $tap($next,7,$mid); $tap($next,7,$mid); $page=$run();
    $edited=$last('editMessageMedia'); $media=json_decode($edited['params']['media'],true); $rows=json_decode($edited['params']['reply_markup'],true)['inline_keyboard'];
    postCheck((int)$edited['params']['message_id']===$mid&&str_contains($media['caption'],'عکس 2 از 3')&&count($rows[0])===2,'Next edits the same message and the middle image offers Previous and Next');
    $tap($rows[0][0]['callback_data'],7,$mid); $run(); $back=json_decode($last('editMessageMedia')['params']['media'],true);
    postCheck(!str_starts_with($back['media'],'attach://'),'Previous reuses the confirmed Telegram file ID after the local first image is released');
    $store->set('youtube.posts','0'); $latest=json_decode($last('editMessageMedia')['params']['reply_markup'],true)['inline_keyboard'][0][0]['callback_data']; $tap($latest,7,$mid);
    postCheck($saved((int)$multi['id'])['status']==='done','turning the feature off also blocks existing page controls'); $store->set('youtube.posts','1');
    $adminTap('custom:settings:youtube'); $adminTap('custom:ytpsource');
    $mode(['images'=>0,'text'=>'متن مستقل پست','video'=>true]); $send($url); $text=$run();
    $message=$last('editMessageText');
    postCheck($saved((int)$text['id'])['status']==='done'&&str_contains($message['params']['text'],'متن مستقل پست')&&str_contains($message['params']['text'],'https://www.youtube.com/watch?v=ybNMMK5Q318'),'text posts are delivered and attached video links retain the separate video download flow');
    $source=json_decode($message['params']['reply_markup'],true)['inline_keyboard'][0][0]??[];
    postCheck(($source['url']??'')===$url,'the source button switch uses the canonical post link');
    foreach([['images'=>2,'bad_second'=>true],['wrong_id'=>true],['images'=>21]] as $failureMode) {
        $mode($failureMode); $before=count(array_filter($calls(),fn($call)=>$call['method']==='sendPhoto')); $send($url); $failed=$run();
        postCheck($saved((int)$failed['id'])['status']==='failed'&&count(array_filter($calls(),fn($call)=>$call['method']==='sendPhoto'))===$before,'incomplete, oversized and mismatched posts never send partial or unrelated images');
    }
    $mode(['http'=>429]); $store->set('cookies.fallback','1'); $before=count(file($temp.'/backend.jsonl')); $send($url); $limited=$run();
    postCheck($saved((int)$limited['id'])['status']==='failed'&&count(file($temp.'/backend.jsonl'))===$before+1&&str_contains($last('editMessageText')['params']['text'],'موقتاً'),'rate limiting stops after one attempt with a public notice and no cookie fanout');
    $before=count(file($temp.'/backend.jsonl')); $mode(['images'=>1]); $send($url); $store->set('youtube.posts','0'); $off=$run();
    postCheck($saved((int)$off['id'])['status']==='failed'&&count(file($temp.'/backend.jsonl'))===$before,'disabling posts after intake prevents processing and uploading');
    postCheck(Zero\youtubeCookiePool($config)->count()===1&&!glob($config->storage.'/downloads/job-*'),'post failures leave the video session intact and release temporary files');
    postCheck(!preg_match('/PHP (?:Warning|Fatal|Notice)/',(string)file_get_contents($temp.'/server-error.log')),'the media fixture has no PHP warnings');
} finally { if(is_resource($server)) { proc_terminate($server); proc_close($server); } Zero\Download::clean($temp); }
