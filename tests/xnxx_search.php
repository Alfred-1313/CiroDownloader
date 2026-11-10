<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
final class XnSearchApi implements Zero\Api {
    public array $calls=[]; private int $next=500;
    public function call(string $method,array $params=[]): mixed { $this->calls[]=[$method,$params]; if($method==='getMe') return ['id'=>123,'username'=>'FixtureBot']; if(str_starts_with($method,'send')) return ['message_id'=>++$this->next]; return true; }
    public function last(string $method): ?array { foreach(array_reverse($this->calls) as [$name,$params]) if($name===$method) return $params; return null; }
}
function xsCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$input='https://www.xnxx.com/search/stepdaughter?top&id=91579681';
xsCheck(Zero\xnxxSearchUrl($input)===$input&&Zero\adultService($input)==='xnxx','the supplied search URL is accepted by the restricted XNXX route');
foreach(['https://xnxx.com.evil.test/search/test','https://user@xnxx.com/search/test','https://xnxx.com/search/%2fetc','https://xnxx.com/search/test/extra','https://xnxx.com:443/search/test'] as $url) xsCheck(Zero\xnxxSearchUrl($url)===null,'unsafe search URL is refused');
$temp=sys_get_temp_dir().'/ciro-xs-'.bin2hex(random_bytes(6)); mkdir($temp,0700); mkdir($temp.'/downloads',0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $api=new XnSearchApi(); $bot=new Zero\Bot($config,$store,$api); $worker=new Zero\Worker($config,$store,$api);
    $html='<html><a href="https://evil.test/video-abc00/foreign" title="Foreign"></a><a href="/video-abc00/sample_0" title="Title &amp; 0"></a><a href="/video-abc00/sample_0">Duplicate</a>';
    for($i=1;$i<10;$i++) $html.='<a href="//www.xnxx.com/video-abc0'.$i.'/sample_'.$i.'" title="Title '.$i.'"></a>';
    $html.='</html>'; $received=''; $worker->xnxxSearchFetch=function(string $url) use($html,&$received): string { $received=$url; return $html; };
    $message=function(int $id,int $user=42,int $chat=-1001) use($input): array { return ['update_id'=>$id,'message'=>['message_id'=>$id,'from'=>['id'=>$user,'is_bot'=>false],'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0,'text'=>$input]]; };
    $click=function(int $update,string $data,int $user,int $chat,int $message,int $topic=321) use($bot): void { $bot->handle(['update_id'=>$update,'callback_query'=>['id'=>'test'.$update,'from'=>['id'=>$user,'is_bot'=>false],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$topic],'data'=>$data]]); };
    $bot->handle($message(1)); xsCheck(!(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn(),'search is off by default');
    $store->set('adult.xnxx','1'); $bot->handle($message(2)); $job=$store->claim('inspect'); xsCheck($job&&$job['url']===$input,'an authorized group search is queued');
    $worker->process($job); $saved=$store->query('SELECT * FROM jobs WHERE id=?',[$job['id']])->fetch(); $data=json_decode($saved['options'],true);
    xsCheck($saved['status']==='done'&&$saved['phase']==='xnxx_search'&&count($data['items'])===10&&$received===$input,'the worker filters foreign and duplicate results and keeps valid videos');
    $panel=$api->last('editMessageText'); $rows=$panel['reply_markup']['inline_keyboard']; $actions=array_column(array_merge(...$rows),'callback_data'); $id=(int)$saved['id']; $mid=(int)$saved['message_id'];
    xsCheck(count(array_filter($actions,fn($a)=>str_starts_with($a,'xs:'.$id.':i:')))===8&&in_array('xs:'.$id.':p:1',$actions,true),'the result menu is paginated');
    $click(3,'xs:'.$id.':i:0',7,-1001,$mid); xsCheck(!(int)$store->query("SELECT COUNT(*) FROM jobs WHERE status='queued'")->fetchColumn(),'another user cannot select a video');
    $click(4,'xs:'.$id.':i:0',42,-1001,$mid,322); xsCheck(!(int)$store->query("SELECT COUNT(*) FROM jobs WHERE status='queued'")->fetchColumn(),'a button copied to another topic cannot select a video');
    $click(5,'xs:'.$id.':p:1',42,-1001,$mid); $panel=$api->last('editMessageText'); $actions=array_column(array_merge(...$panel['reply_markup']['inline_keyboard']),'callback_data');
    xsCheck(in_array('xs:'.$id.':i:8',$actions,true)&&in_array('xs:'.$id.':p:0',$actions,true),'the second result page has working navigation');
    $store->set('adult.xnxx','0'); $click(6,'xs:'.$id.':i:8',42,-1001,$mid); xsCheck(!(int)$store->query("SELECT COUNT(*) FROM jobs WHERE status='queued'")->fetchColumn(),'turning XNXX off invalidates selection'); $store->set('adult.xnxx','1');
    $click(7,'xs:'.$id.':i:8',42,-1001,$mid); $chosen=$store->query("SELECT * FROM jobs WHERE status='queued'")->fetch();
    xsCheck($chosen&&$chosen['url']==='https://www.xnxx.com/video-abc08/sample_8'&&(int)$chosen['message_id']===$mid&&(int)$chosen['thread_id']===321,'the selected video opens the existing quality flow in the same message and topic');
    $click(8,'xs:'.$id.':i:8',42,-1001,$mid); xsCheck((int)$store->query("SELECT COUNT(*) FROM jobs WHERE status='queued'")->fetchColumn()===1,'the old result button cannot enqueue twice');
    $store->query("UPDATE jobs SET status='cancelled' WHERE id=?",[$chosen['id']]); $store->query('UPDATE users SET last_request=0 WHERE id=42');
    $worker->xnxxSearchFetch=fn(string $url): never=>throw new Zero\MediaFailure('format','XNXX search unavailable: 403');
    $bot->handle($message(9)); $blocked=$store->claim('inspect'); $worker->process($blocked);
    xsCheck(str_contains($api->last('editMessageText')['text'],'محدود')&&$store->query('SELECT status FROM jobs WHERE id=?',[$blocked['id']])->fetchColumn()==='failed','a site refusal explains the access restriction instead of pretending there are no results');
    foreach(['loading','results','empty','expired','owner','selected'] as $key) xsCheck($bot->ui->customizable('text','xnxx_search_'.$key),'search text is customizable: '.$key);
} finally { Zero\Download::clean($temp); }
