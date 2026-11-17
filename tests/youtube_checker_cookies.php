<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function ccCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class CheckerCookieApi implements Zero\FileApi
{
    public array $calls=[]; public string $content=''; public int $next=400;
    public function call(string $method,array $p=[]): mixed
    {
        $this->calls[]=[$method,$p];
        if($method==='getFile') return ['file_path'=>'fixture.txt','file_size'=>strlen($this->content)];
        if($method==='getMe') return ['id'=>123,'username'=>'FixtureBot'];
        return str_starts_with($method,'send')?['message_id'=>++$this->next]:true;
    }
    public function downloadFile(string $path,int $max): string { return $this->content; }
    public function last(string $method): array { foreach(array_reverse($this->calls) as [$m,$p]) if($m===$method) return $p; return []; }
}
$root=sys_get_temp_dir().'/ciro-checker-cookies-'.bin2hex(random_bytes(5));mkdir($root,0700);
try {
    $c=new Zero\Config('123:fixture',[42],$root.'/data','https://api.telegram.org',49000000,$root.'/yt-dlp');$s=new Zero\Store($c->storage);$a=new CheckerCookieApi();$b=new Zero\Bot($c,$s,$a);$serial=0;
    $send=function(string $text,int $owner=42)use($b,&$serial):void{$b->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>$owner],'chat'=>['id'=>$owner,'type'=>'private'],'text'=>$text]]);};
    $tap=function(string $data)use($b,&$serial):void{$b->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'cc'.$serial,'from'=>['id'=>42],'message'=>['message_id'=>500,'chat'=>['id'=>42,'type'=>'private']],'data'=>$data]]);};
    $cookie=fn(string $value)=>"# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSID\t".$value."\n";
    $manual=$cookie('MANUAL_FIXTURE');$checker=$cookie('CHECKER_FIXTURE');Zero\youtubeCookiePool($c)->add($manual);
    Zero\InstagramCheckerMedia::pool($c)->add("# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\tIG_FIXTURE\n");
    $send('/cookies');$labels=array_column(array_merge(...$a->last('sendMessage')['reply_markup']['keyboard']),'text');
    ccCheck(in_array($b->label('cookie_youtube_checker'),$labels,true),'cookie import lists a distinct YouTube checker entry');
    $send($b->label('cookie_youtube_checker'),7);$send($b->label('ytc_cookie_upload'),7);
    ccCheck(Zero\YoutubeCheckerMedia::pool($c)->count()===0,'ordinary users cannot start a checker cookie import');
    $send($b->label('cookie_youtube_checker'));$send($b->label('ytc_cookie_upload'));
    ccCheck($s->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='ytc_cookie.upload','checker imports have a separate pending state');
    $a->content=$checker;$b->handle(['update_id'=>++$serial,'message'=>['message_id'=>$serial,'from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'document'=>['file_id'=>'fixture','file_name'=>'checker.txt','file_size'=>strlen($checker)]]]);
    $path=Zero\YoutubeCheckerMedia::config($c)->cookiePath();
    ccCheck(file_get_contents($path)===$checker&&file_get_contents($c->cookiePath())===$manual&&(fileperms($path)&0777)===0600,'document import stores a private checker jar without changing manual YouTube cookies');
    ccCheck(($a->last('deleteMessage')['message_id']??0)===$serial,'the cookie-bearing document message is deleted');
    $send($b->label('ytc_cookie_back'));$send($b->label('ytc_cookie_view'));$buttons=array_merge(...$a->last('sendMessage')['reply_markup']['inline_keyboard']);$actions=[];
    foreach($buttons as $button) { $data=$button['callback_data']??'';foreach(['ckex','cklb','ckts'] as $op) if(str_starts_with($data,$op.':ytc:')) $actions[$op]=$data; }
    ccCheck(count($actions)===3&&!str_contains($a->last('sendMessage')['text'],'CHECKER_FIXTURE'),'the checker pool offers isolated export, label and test controls without revealing credentials');
    $tap($actions['ckex']);$doc=$a->last('sendDocument');ccCheck(($doc['protect_content']??false)&&$doc['document']->data===$checker,'export includes only the chosen checker jar');
    $tap($actions['cklb']);$send('نشست چکر');ccCheck(Zero\YoutubeCheckerMedia::pool($c)->describe()[0]['label']==='نشست چکر'&&Zero\youtubeCookiePool($c)->describe()[0]['label']==='','labels are isolated between manual and checker pools');
    $send($b->label('ytc_cookie_back'));$send($b->label('ytc_cookie_upload'));$fresh=$checker.".youtube.com\tTRUE\t/\tTRUE\t0\tPREF\tFRESH_FIXTURE\n";$send($fresh);
    ccCheck(file_get_contents($path)===$fresh&&file_get_contents($c->cookiePath())===$manual,'pasted imports also update only the checker pool');
    // Every production request path must pass only the checker jar to its subprocess.
    $info=['title'=>'fixture','formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac','abr'=>96]]];
    $guard='$i=array_search("--cookies",$argv,true);if($i===false||!str_contains(file_get_contents($argv[$i+1]),"CHECKER_FIXTURE")){fwrite(STDERR,"wrong session");exit(1);}';
    file_put_contents($c->ytDlp,"#!/usr/bin/env php\n<?php ".$guard.'echo '.var_export(json_encode($info),true).';');chmod($c->ytDlp,0700);
    $send($b->label('ytc_cookie_back'));$send($b->label('ytc_cookie_view'));
    foreach(array_merge(...$a->last('sendMessage')['reply_markup']['inline_keyboard']) as $button) if(str_starts_with($button['callback_data']??'','ckts:ytc:')) $actions['ckts']=$button['callback_data'];
    $tap($actions['ckts']);ccCheck(str_contains($a->last('editMessageText')['text'],$b->ui->plain('cookie_test_live_ok'))&&file_get_contents($c->cookiePath())===$manual,'live testing and session refresh use the checker jar only');
    $s->set('youtube.checker.enabled','1');$m=new Zero\YoutubeChecker($c,$s,$a);$m->add(42,42,0,'fixture',7);
    $snapshot=['account_id'=>'UC'.str_repeat('A',22),'mode'=>7,'videos'=>[],'shorts'=>[],'posts'=>[]];$snapshotPath=$root.'/snapshot.json';file_put_contents($snapshotPath,json_encode($snapshot));
    file_put_contents($root.'/python',"#!/usr/bin/env php\n<?php ".$guard.'if(str_ends_with($argv[1],"youtube-post.py")){echo json_encode(["description"=>"post","author"=>"fixture","files"=>[]]);}else{echo file_get_contents('.var_export($snapshotPath,true).');}');chmod($root.'/python',0700);
    $m->poll();ccCheck($s->query('SELECT state FROM yt_check_targets')->fetchColumn()==='ok','native channel scans receive the independent checker cookie');
    $snapshot['videos']=[['id'=>'AAAAAAAAAAA','url'=>'https://www.youtube.com/watch?v=AAAAAAAAAAA','time'=>time()]];$snapshot['posts']=[['id'=>'Ug'.str_repeat('P',20),'url'=>'https://www.youtube.com/post/Ug'.str_repeat('P',20),'time'=>time()]];file_put_contents($snapshotPath,json_encode($snapshot));
    $s->query('UPDATE yt_check_targets SET next_check=0');$m->poll();
    foreach($s->query('SELECT * FROM jobs ORDER BY id')->fetchAll() as $job) {$s->query("UPDATE jobs SET status='running' WHERE id=?",[$job['id']]);(new Zero\Worker($c,$s,$a))->process($job);}
    ccCheck((int)$s->query("SELECT COUNT(*) FROM jobs WHERE status='waiting_quality'")->fetchColumn()===1&&(int)$s->query("SELECT COUNT(*) FROM jobs WHERE status='done'")->fetchColumn()===1,'regular covers and community posts both use the independent checker session');
    $pools=(new Zero\CookieHealth($c,$s,$a))->pools();$pools['yt']->markFailed('main');
    ccCheck(isset($pools['ytc'],$pools['igc'])&&!$pools['ytc']->describe()[0]['failed'],'cookie health and failure state stay isolated for all checker pools');
    file_put_contents($c->cookiePath(),str_replace("\t0\t","\t1\t",$manual));$pools['yt']->pruneExpired();ccCheck(is_file($path),'pruning expired manual cookies preserves checker cookies');Zero\youtubeCookiePool($c)->add($manual);
    $send($b->label('ytc_cookie_back'));$send($b->label('ytc_cookie_remove'));Zero\YoutubeCheckerMedia::pool($c)->add($checker);$send($b->label('ytc_cookie_confirm'));
    ccCheck(is_file($path),'a stale confirmation cannot delete a newly imported checker session');
    $send($b->label('ytc_cookie_remove'));$send($b->label('ytc_cookie_confirm'));
    ccCheck(!is_file($path)&&file_get_contents($c->cookiePath())===$manual&&Zero\InstagramCheckerMedia::pool($c)->count()===1,'checker deletion leaves manual YouTube and Instagram checker cookies intact');
    // An absent checker jar must stay anonymous, even while manual cookies exist.
    file_put_contents($root.'/python',"#!/usr/bin/env php\n<?php if(in_array('--cookies',\$argv,true)){exit(1);}echo file_get_contents(".var_export($snapshotPath,true).");");
    $s->query('UPDATE yt_check_targets SET next_check=0');$m->poll();ccCheck($s->query('SELECT state FROM yt_check_targets')->fetchColumn()==='ok','an empty checker pool never falls back to manual YouTube cookies');
} finally { Zero\Download::clean($root); }
