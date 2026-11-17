<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
function ipCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class InstagramProfileApi implements Zero\Api
{
    public array $calls=[],$types=[];public int $next=400;public bool $reject=false;
    public function call(string $method,array $p=[]): mixed {
        $this->calls[]=[$method,$p];$id=(int)($p['message_id']??0);$caption=$p['caption']??$p['media']['caption']??null;if($caption!==null)Zero\TextFormat::validate($caption,1024);
        if($method==='getChatMember')return ['status'=>'administrator'];
        if($method==='editMessageText'&&($this->types[$id]??'')==='photo')throw new RuntimeException('Cannot edit photo as text',400);
        if(in_array($method,['sendMessage','sendPhoto'],true)){$id=++$this->next;$this->types[$id]=$method==='sendPhoto'?'photo':'text';}
        if(in_array($method,['sendPhoto','editMessageMedia'],true)){
            if($this->reject)throw new RuntimeException('Fixture photo refusal',400);
            $this->types[$id]='photo';return ['message_id'=>$id,'photo'=>[['file_id'=>'ig-profile-'.$id]]];
        }
        if($method==='sendMessage')return ['message_id'=>$id];
        if($method==='deleteMessage')unset($this->types[$id]);
        return true;
    }
    public function last():array{return end($this->calls)?:[];}
}
$root=sys_get_temp_dir().'/ciro-ig-profile-'.bin2hex(random_bytes(5));mkdir($root,0700);
try {
    $c=new Zero\Config('123:fixture',[42,55],$root.'/data');$s=new Zero\Store($c->storage);$a=new InstagramProfileApi();$b=new Zero\Bot($c,$s,$a);$m=new Zero\InstagramChecker($c,$s,$a);$panels=new Zero\InstagramCheckerPanel($b);$yt=new Zero\YoutubeChecker($c,$s,$a);$serial=100;
    $s->set('bot_id','123');$s->set('bot_username','FixtureBot');
    $send=function(string $text,int $owner=42,?int $reply=null)use($b,&$serial):void{
        $message=['message_id'=>++$serial,'from'=>['id'=>$owner],'chat'=>['id'=>-100,'type'=>'supergroup'],'message_thread_id'=>321,'text'=>$text];if($reply)$message['reply_to_message']=['message_id'=>$reply,'from'=>['id'=>123,'is_bot'=>true]];
        $b->handle(['update_id'=>$serial,'message'=>$message]);
    };
    $current=function()use($s):array{return $s->query('SELECT * FROM ig_check_panels WHERE owner=42 ORDER BY rowid DESC LIMIT 1')->fetch();};
    $tap=function(string $op,?int $id=null,?array $p=null,int $owner=42,?int $topic=321)use($b,$current,&$serial):void{
        $p??=$current();$message=['message_id'=>(int)$p['message_id'],'chat'=>['id'=>-100,'type'=>'supergroup']];if($topic!==null)$message['message_thread_id']=$topic;
        $b->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'ip'.$serial,'from'=>['id'=>$owner],'message'=>$message,'data'=>'ic:'.$p['token'].':'.$op.($id===null?'':':'.$id)]]);
    };
    $cookie=fn(string $v)=>"# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\t$v\n";
    Zero\Instagram::pool($c)->add($cookie('POST_ONLY'));Zero\InstagramStories::pool($c)->add($cookie('STORY_ONLY'));Zero\InstagramCheckerMedia::pool($c)->add($cookie('CHECKER_ONLY'));
    $python=getenv('GALLERY_DL_PYTHON')?:(is_executable('/opt/yt/bin/python')?'/opt/yt/bin/python':'/usr/bin/python3');mkdir($root.'/backend',0700);$c->galleryDl=$root.'/backend/gallery-dl';
    file_put_contents($root.'/backend/python','#!'.$python."\nimport runpy,sys\nsys.argv.insert(1,".json_encode($root,JSON_UNESCAPED_SLASHES).")\nrunpy.run_path(".json_encode(__DIR__.'/fixtures/instagram_checker_runtime.py',JSON_UNESCAPED_SLASHES).",run_name='__main__')\n");chmod($root.'/backend/python',0700);file_put_contents($root.'/base','http://127.0.0.1');
    $mode=function(array $value)use($root):void{file_put_contents($root.'/mode.json',json_encode($value));};$trace=function(string $name='backend.jsonl')use($root):array{return is_file($root.'/'.$name)?array_map(fn($line)=>json_decode($line,true),file($root.'/'.$name,FILE_IGNORE_NEW_LINES)):[];};
    $image='https://scontent.cdninstagram.com/checker-avatar.jpg';$mode(['profile_image'=>$image]);
    $send('/insta_checker fixture');$p=$current();$mid=(int)$p['message_id'];
    ipCheck($p['view']==='new'&&(int)$s->query('SELECT COUNT(*) FROM ig_check_subs')->fetchColumn()===0&&(int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'Instagram content selection requests an avatar before registration without creating a subscription or media job');
    ipCheck($m->pollProfile(),'the real Instagram profile subprocess runs in the shared bounded checker lane');$panels->refreshProfiles();$p=$current();$last=$a->last();
    ipCheck($last[0]==='editMessageMedia'&&(int)$last[1]['message_id']===$mid&&$last[1]['media']['media']===$image&&str_contains($last[1]['media']['caption'],'چه محتوایی دریافت شود'),'the exact Instagram account avatar appears above the selection caption on its original group/topic message');
    $buttons=array_merge(...$last[1]['reply_markup']['inline_keyboard']);ipCheck(count(array_filter($buttons,fn($button)=>str_contains($button['callback_data']??'',':save:')))===3,'posts, stories and both choices keep their existing callbacks beneath the profile photo');
    $invocations=$trace();ipCheck(count($invocations)===1&&$invocations[0]['checker_session']&&$invocations[0]['private']&&in_array('--profile-only',$invocations[0]['args'],true)&&!in_array('--mode',$invocations[0]['args'],true)&&count($trace('profile-requests.jsonl'))===1,'one native canonical page lookup uses only a private igc cookie copy and no full-profile or content API');
    ipCheck((int)$s->query('SELECT COUNT(*) FROM ig_check_seen')->fetchColumn()===0&&$yt->used(42)===0,'profile metadata changes neither Instagram content baselines nor YouTube subscriptions');
    $before=count($a->calls);$tap('save',3,$p,55);$tap('save',3,$p,42,322);ipCheck(count($a->calls)===$before+2&&(int)$s->query('SELECT COUNT(*) FROM ig_check_subs')->fetchColumn()===0,'another administrator and a copied topic cannot confirm an Instagram photo selection');
    $tap('save',3,$p,42,null);$p=$current();$subId=(int)json_decode($p['data'],true)['id'];
    ipCheck($p['view']==='item'&&$p['photo']!==''&&(int)$p['message_id']===$mid&&$a->last()[0]==='editMessageCaption','registration preserves the same photo and recovers missing topic metadata');
    $m->poll();ipCheck((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&(int)$m->owned($subId,42)['posts_ready']===1&&(int)$m->owned($subId,42)['stories_ready']===1,'the first real post/story scan still baselines all existing media after the profile preview');
    $tap('check',$subId);$m->poll();$panels->refreshChecks();ipCheck($a->last()[0]==='editMessageCaption'&&str_contains($a->last()[1]['caption'],'بررسی تازه موفق بود'),'real checker verification updates the photo caption and retains its controls');
    $old=$current();$tap('rename',$subId);$prompt=(int)json_decode($current()['data'],true)['input_message'];$send('replacement',42,$prompt);$replacement=$current();
    ipCheck($replacement['photo']===''&&!isset($a->types[(int)$old['message_id']]),'renaming removes the old account photo while resolving the replacement');
    $mode(['username'=>'replacement','account_id'=>'8','profile_image'=>'https://scontent.cdninstagram.com/replacement.jpg']);$s->set('instagram.checker.profile.42','0');$m->pollProfile();$panels->refreshProfiles();
    ipCheck($current()['photo']!==''&&$a->last()[1]['media']['media']==='https://scontent.cdninstagram.com/replacement.jpg','a renamed subscription receives only its replacement account avatar');
    $photo=$current();$tap('home');$list=$current();ipCheck((int)$list['message_id']!==(int)$photo['message_id']&&$list['photo']===''&&!isset($a->types[(int)$photo['message_id']]),'Back confirms a text list before retiring the photo panel');
    $tap('item',$subId);ipCheck(str_starts_with($a->last()[1]['media']['media'],'ig-profile-'),'reopening a saved Instagram checker reuses its confirmed Telegram photo ID');
    $photo=$current();$tap('hub');$hub=$s->query('SELECT * FROM checker_menus WHERE owner=42 ORDER BY rowid DESC LIMIT 1')->fetch();ipCheck($hub&&(int)$hub['thread_id']===321&&(int)$hub['message_id']!==(int)$photo['message_id']&&!isset($a->types[(int)$photo['message_id']]),'returning directly to all checkers safely replaces an Instagram photo and preserves owner/topic binding');
    $before=count($a->calls);$tap('pause',$subId,$photo);ipCheck(count($a->calls)===$before+1&&$a->last()[1]['show_alert'],'a retired Instagram photo cannot mutate a subscription through old buttons');
    // Both profile failures and vanished menus stay silent and never fan out to another jar/API.
    $send('/insta_checker limited');$p=$current();$target=(int)json_decode($p['data'],true)['profile_target'];$mode(['profile_http'=>429]);$s->set('instagram.checker.profile.42','0');$before=count($trace());$m->pollProfile();$panels->refreshProfiles();$record=$s->query('SELECT * FROM ig_check_targets WHERE id=?',[$target])->fetch();
    ipCheck(count($trace())===$before+1&&$record['state']==='blocked'&&(int)$record['next_check']>=time()+899&&$current()['photo']==='','a real profile HTTP 429 stops after one checker jar and keeps a usable text selection');
    $s->query('UPDATE ig_check_targets SET avatar_checked=0 WHERE id=?',[$target]);$s->set('instagram.checker.profile.42','0');$send('/insta_checker limited');$before=count($trace());ipCheck(!$m->pollProfile()&&count($trace())===$before,'reopening selection cannot bypass Instagram rate-limit backoff');$tap('close');
    $send('/insta_checker abandoned');$p=$current();$tap('close');$before=count($a->calls);ipCheck(!$m->pollProfile()&&count($a->calls)===$before,'closed previews do not start metadata lookups or send late messages');
    // Another known target reuses neither the manual post jar nor the story jar when igc is empty.
    $checkerPool=Zero\InstagramCheckerMedia::pool($c);$checkerPool->clear($checkerPool->digest());$send('/insta_checker nocookie');$s->set('instagram.checker.profile.42','0');$before=count($trace());$m->pollProfile();
    ipCheck(count($trace())===$before&&Zero\Instagram::pool($c)->count()===1&&Zero\InstagramStories::pool($c)->count()===1,'an empty igc pool never borrows Instagram post or story credentials');
    ipCheck(!glob($c->storage.'/downloads/checker-*'),'successful and refused profile lookups remove their private temporary cookie copies');
    foreach(['https://evil.test/a','https://scontent.cdninstagram.com.evil.test/a','http://scontent.cdninstagram.com/a','https://user@scontent.cdninstagram.com/a','https://scontent.cdninstagram.com:443/a','https://yt3.ggpht.com/a']as$url)ipCheck(!Zero\InstagramChecker::avatarUrl($url),'Instagram rejects foreign or malformed avatar addresses');
    ipCheck(!Zero\YoutubeChecker::avatarUrl($image)&&(int)$s->query("SELECT COUNT(*) FROM yt_check_targets WHERE avatar<>''")->fetchColumn()===0,'Instagram profile cache, accepted hosts and results remain separate from YouTube');
    $send('@bare_profile');$send('/user_instagram bare_profile');ipCheck((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'checker profile photos do not restore removed bare-ID or standalone profile downloads');
} finally { Zero\Download::clean($root); }
