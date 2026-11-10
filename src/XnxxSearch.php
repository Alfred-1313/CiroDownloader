<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Music.php';

/** A search-page menu belongs to the requester, chat, topic and original message. */
final class XnxxSearch
{
    private const PAGE=8, LIMIT=32, TTL=86400;
    public function __construct(private Bot $bot,private ?\Closure $fetch=null) {}

    /** @return list<array{url:string,title:string}> */
    public static function parse(string $html): array
    {
        $dom=new \DOMDocument(); $previous=libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $items=[]; $seen=[];
        foreach($dom->getElementsByTagName('a') as $a) {
            $href=trim($a->getAttribute('href'));
            if(str_starts_with($href,'//')) $href='https:'.$href;
            elseif(str_starts_with($href,'/')) $href='https://www.xnxx.com'.$href;
            $url=xnxxUrl($href); if($url===null) continue;
            preg_match('~/video-([a-z0-9]+)/~i',$url,$match); $id=strtolower($match[1]??'');
            if($id===''||isset($seen[$id])) continue;
            $title=trim($a->getAttribute('title'));
            if($title==='') foreach($a->getElementsByTagName('img') as $img) { $title=trim($img->getAttribute('alt')); if($title!=='') break; }
            if($title==='') $title=trim($a->textContent);
            $title=trim(preg_replace('/[\x00-\x1F\x7F]+|\s+/u',' ',$title)??'');
            if($title==='') $title=rawurldecode(basename(rtrim(parse_url($url,PHP_URL_PATH),'/')));
            $items[]=['url'=>$url,'title'=>mb_substr($title,0,100)]; $seen[$id]=true;
            if(count($items)>=self::LIMIT) break;
        }
        return $items;
    }
    private function render(array $job,array $items,int $page): void
    {
        $pages=max(1,(int)ceil(count($items)/self::PAGE)); $page=max(0,min($pages-1,$page)); $ui=$this->bot->ui; $rows=[];
        foreach(array_slice($items,$page*self::PAGE,self::PAGE,true) as $i=>$item) {
            $rows[]=[$ui->button('xnxx_search_result','xs:'.$job['id'].':i:'.$i,['title'=>mb_substr($item['title'],0,48)])];
        }
        $nav=[];
        if($page>0) $nav[]=$ui->button('xnxx_search_previous','xs:'.$job['id'].':p:'.($page-1));
        if($page+1<$pages) $nav[]=$ui->button('xnxx_search_next','xs:'.$job['id'].':p:'.($page+1));
        if($nav) $rows[]=$nav;
        $rows[]=[$ui->button('xnxx_search_close','xs:'.$job['id'].':x')];
        $query=rawurldecode(basename((string)parse_url($job['url'],PHP_URL_PATH)));
        $this->bot->jobMessage($job,$ui->text('xnxx_search_results',['query'=>$query,'page'=>(string)($page+1),'pages'=>(string)$pages]),$rows);
    }
    public function open(array $job): void
    {
        $url=xnxxSearchUrl((string)$job['url']); if($url===null) throw new MediaFailure('format','Invalid XNXX search URL');
        $this->bot->jobMessage($job,$this->bot->text('xnxx_search_loading'));
        try { $html=$this->fetch?($this->fetch)($url):Music::get($this->bot->config,$url,Music::MAX_PAGE,'XNXX search'); }
        catch(MediaFailure $e) {
            if(preg_match('/^XNXX search unavailable: (403|429)$/D',$e->getMessage())) throw new MediaFailure('blocked','XNXX search refused');
            throw $e;
        }
        if(!is_string($html)||strlen($html)>Music::MAX_PAGE) throw new MediaFailure('format','Invalid XNXX search response');
        if(preg_match('/cf-chl-|<title[^>]*>\s*just a moment/i',$html)) throw new MediaFailure('blocked','XNXX search blocked');
        $items=self::parse($html); $store=$this->bot->store;
        if(!$this->bot->adultAllowed((int)$job['user_id'],(int)$job['chat_id'],'xnxx')) { $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]); $this->bot->jobMessage($job,$this->bot->text('invalid')); return; }
        $store->query("UPDATE jobs SET options='{}' WHERE phase='xnxx_search' AND expires>0 AND expires<?",[time()]);
        if(!$items) { $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]); $this->bot->jobMessage($job,$this->bot->text('xnxx_search_empty')); return; }
        $store->query("UPDATE jobs SET phase='xnxx_search',status='done',expires=?,options=? WHERE id=?",[time()+self::TTL,json_encode(['items'=>$items],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$job['id']]);
        $this->render($job,$items,0);
    }
    public function callback(array $update,int $chat,int $user,int $thread,int $message): void
    {
        $cb=$update['callback_query'];
        if(!preg_match('/^xs:(\d{1,12}):(x|[ip]:\d{1,3})$/D',(string)($cb['data']??''),$m)) return;
        $store=$this->bot->store; $job=$store->query("SELECT * FROM jobs WHERE id=? AND phase='xnxx_search' AND status='done'",[(int)$m[1]])->fetch();
        $key=''; $alert=true;
        if(!$job||(int)$job['expires']<time()) $key='xnxx_search_expired';
        elseif((int)$job['user_id']!==$user||(int)$job['chat_id']!==$chat||(int)$job['message_id']!==$message||($chat<0&&(int)$job['thread_id']!==$thread)) $key='xnxx_search_owner';
        elseif(!$this->bot->adultAllowed($user,$chat,'xnxx')) $key='invalid';
        if($key!=='') { $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->bot->ui->plain($key),'show_alert'=>true]); return; }
        $items=json_decode((string)$job['options'],true)['items']??[]; $op=$m[2];
        if($op==='x') {
            $store->query("UPDATE jobs SET options='{}',expires=0 WHERE id=?",[$job['id']]);
            $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id']]);
            try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); } catch(\Throwable) {}
            return;
        }
        [$kind,$index]=explode(':',$op); $index=(int)$index;
        if($kind==='p') { $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id']]); $this->render($job,$items,$index); return; }
        $url=isset($items[$index]['url'])?xnxxUrl((string)$items[$index]['url']):null;
        if($url===null) { $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->bot->ui->plain('xnxx_search_expired'),'show_alert'=>true]); return; }
        $result=$this->bot->requestVideo((int)$update['update_id'],$user,$chat,$url,$thread,0,null,[],true,['message'=>$message,'kind'=>'text','poster'=>'']);
        if($result==='queued') $store->query("UPDATE jobs SET options='{}',expires=0 WHERE id=?",[$job['id']]);
        $key=$result==='queued'?'xnxx_search_selected':match($result) {'busy'=>'busy','full'=>'queue_full','updating'=>'maintenance',default=>'xnxx_search_expired'};
        $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->bot->ui->plain($key),'show_alert'=>$result!=='queued']);
    }
}
