<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Music.php';

/** A search-page menu belongs to the requester, chat, topic and original message. */
final class XnxxSearch
{
    private const PAGE=8, LIMIT=32, TTL=86400;
    public function __construct(private Bot $bot,private ?\Closure $fetch=null,private string $site='xnxx')
    {
        if(!in_array($site,['xnxx','xvideos'],true)) throw new \InvalidArgumentException('Invalid search site');
    }

    /** @return list<array{url:string,title:string}> */
    public static function parse(string $html,string $site='xnxx'): array
    {
        $dom=new \DOMDocument(); $previous=libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $items=[]; $seen=[];
        foreach($dom->getElementsByTagName('a') as $a) {
            $href=trim($a->getAttribute('href'));
            if(str_starts_with($href,'//')) $href='https:'.$href;
            elseif(str_starts_with($href,'/')) $href='https://www.'.$site.'.com'.$href;
            $url=$site==='xvideos'?xvideosUrl($href):xnxxUrl($href); if($url===null) continue;
            preg_match('~/(?:video(?:-|\.)?|embedframe/)([a-z0-9]+)/?~i',$url,$match); $id=strtolower($match[1]??'');
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
        $site=$this->site; $prefix=$site==='xvideos'?'vs':'xs';
        foreach(array_slice($items,$page*self::PAGE,self::PAGE,true) as $i=>$item) {
            $rows[]=[$ui->button($site.'_search_result',$prefix.':'.$job['id'].':i:'.$i,['title'=>mb_substr($item['title'],0,48)])];
        }
        $nav=[];
        if($page>0) $nav[]=$ui->button($site.'_search_previous',$prefix.':'.$job['id'].':p:'.($page-1));
        if($page+1<$pages) $nav[]=$ui->button($site.'_search_next',$prefix.':'.$job['id'].':p:'.($page+1));
        if($nav) $rows[]=$nav;
        $rows[]=[$ui->button($site.'_search_close',$prefix.':'.$job['id'].':x')];
        if($site==='xvideos') { parse_str((string)parse_url($job['url'],PHP_URL_QUERY),$args); $query=(string)($args['k']??''); }
        else $query=rawurldecode(basename((string)parse_url($job['url'],PHP_URL_PATH)));
        $this->bot->jobMessage($job,$ui->text($site.'_search_results',['query'=>$query,'page'=>(string)($page+1),'pages'=>(string)$pages]),$rows);
    }
    public function open(array $job): void
    {
        $site=$this->site; $name=strtoupper($site); $url=$site==='xvideos'?xvideosSearchUrl((string)$job['url']):xnxxSearchUrl((string)$job['url']);
        if($url===null) throw new MediaFailure('format','Invalid adult search URL');
        $this->bot->jobMessage($job,$this->bot->text($site.'_search_loading'));
        try { $html=$this->fetch?($this->fetch)($url):Music::get($this->bot->config,$url,Music::MAX_PAGE,$name.' search'); }
        catch(MediaFailure $e) {
            if(preg_match('/^(?:XNXX|XVIDEOS) search unavailable: (403|429)$/D',$e->getMessage())) throw new MediaFailure('blocked',$name.' search refused');
            throw $e;
        }
        if(!is_string($html)||strlen($html)>Music::MAX_PAGE) throw new MediaFailure('format','Invalid XNXX search response');
        if(preg_match('/cf-chl-|<title[^>]*>\s*just a moment/i',$html)) throw new MediaFailure('blocked','XNXX search blocked');
        $items=self::parse($html,$site); $store=$this->bot->store;
        if(!$this->bot->adultAllowed((int)$job['user_id'],(int)$job['chat_id'],$site)) { $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]); $this->bot->jobMessage($job,$this->bot->text('invalid')); return; }
        $store->query("UPDATE jobs SET options='{}' WHERE phase=? AND expires>0 AND expires<?",[$site.'_search',time()]);
        if(!$items) { $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]); $this->bot->jobMessage($job,$this->bot->text($site.'_search_empty')); return; }
        $store->query("UPDATE jobs SET phase=?,status='done',expires=?,options=? WHERE id=?",[$site.'_search',time()+self::TTL,json_encode(['items'=>$items],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$job['id']]);
        $this->render($job,$items,0);
    }
    public function callback(array $update,int $chat,int $user,int $thread,int $message): void
    {
        $cb=$update['callback_query'];
        if(!preg_match('/^(xs|vs):(\d{1,12}):(x|[ip]:\d{1,3})$/D',(string)($cb['data']??''),$m)) return;
        $site=$m[1]==='vs'?'xvideos':'xnxx'; $this->site=$site;
        $store=$this->bot->store; $job=$store->query("SELECT * FROM jobs WHERE id=? AND phase=? AND status='done'",[(int)$m[2],$site.'_search'])->fetch();
        $key=''; $alert=true;
        if(!$job||(int)$job['expires']<time()) $key=$site.'_search_expired';
        elseif((int)$job['user_id']!==$user||(int)$job['chat_id']!==$chat||(int)$job['message_id']!==$message||($chat<0&&(int)$job['thread_id']!==$thread)) $key=$site.'_search_owner';
        elseif(!$this->bot->adultAllowed($user,$chat,$site)) $key='invalid';
        if($key!=='') { $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->bot->ui->plain($key),'show_alert'=>true]); return; }
        $items=json_decode((string)$job['options'],true)['items']??[]; $op=$m[3];
        if($op==='x') {
            $store->query("UPDATE jobs SET options='{}',expires=0 WHERE id=?",[$job['id']]);
            $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id']]);
            try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); } catch(\Throwable) {}
            return;
        }
        [$kind,$index]=explode(':',$op); $index=(int)$index;
        if($kind==='p') { $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id']]); $this->render($job,$items,$index); return; }
        $candidate=(string)($items[$index]['url']??''); $url=$site==='xvideos'?xvideosUrl($candidate):xnxxUrl($candidate);
        if($url===null) { $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->bot->ui->plain($site.'_search_expired'),'show_alert'=>true]); return; }
        $result=$this->bot->requestVideo((int)$update['update_id'],$user,$chat,$url,$thread,0,null,[],true,['message'=>$message,'kind'=>'text','poster'=>'']);
        if($result==='queued') $store->query("UPDATE jobs SET options='{}',expires=0 WHERE id=?",[$job['id']]);
        $key=$result==='queued'?$site.'_search_selected':match($result) {'busy'=>'busy','full'=>'queue_full','updating'=>'maintenance',default=>$site.'_search_expired'};
        $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->bot->ui->plain($key),'show_alert'=>$result!=='queued']);
    }
}
