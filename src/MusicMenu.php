<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/MusicCatalog.php';
require_once __DIR__.'/SoundCloud.php';

/**
 * The menu a user gets for an artist, album, playlist or SoundCloud profile: tabs for each kind of list,
 * paged items, and one tap on a track queues it like a pasted link. One message is edited in place;
 * it belongs to the user, chat and topic that asked, and expires after a day.
 *
 * Callback data is "mm:ID:OP[:ARG]": t tab, p page, o open item, b back, x close, n title.
 */
final class MusicMenu
{
    public const PAGE=8, KEEP=8, TTL=86400, RECENT=120, VIEWS=6;
    private const TABS=['popular','albums','singles','eps','compilations','playlists','related','tracks','sets','reposts','likes'];
    private const ICONS=['artist'=>'🎤','album'=>'💿','playlist'=>'📻','profile'=>'🎧','set'=>'📻'];

    public function __construct(private Bot $bot,private ?MusicCatalog $catalog=null,private ?SoundCloud $soundcloud=null) {}
    private function catalog(): MusicCatalog { return $this->catalog??new MusicCatalog($this->bot->config); }
    private function soundcloud(): SoundCloud { return $this->soundcloud??new SoundCloud($this->bot->config); }

    /** Worker side: read the page and turn the job's status message into the menu. */
    public function open(array $job,array $collection,string $folder): void
    {
        $bot=$this->bot; $store=$bot->store;
        $bot->jobMessage($job,$bot->text('menu_loading'));
        $data=$this->catalog()->build($collection,fn(string $url)=>$this->soundcloud()->listing($url,$folder));
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
        }
        $tabs=array_keys($data['tabs']);
        $first=$tabs[0]??'tracks';
        if($collection['service']==='soundcloud'&&$collection['kind']==='profile'&&str_contains($collection['id'],'/')) $first=explode('/',$collection['id'])[1];
        $view=['tab'=>isset($data['tabs'][$first])?$first:$tabs[0],'page'=>0,'stack'=>[]];
        $store->db->exec('BEGIN IMMEDIATE');
        try {
            $store->query('DELETE FROM music_menus WHERE expires<?',[time()]);
            $store->query('DELETE FROM music_menus WHERE user_id=? AND id NOT IN (SELECT id FROM music_menus WHERE user_id=? ORDER BY id DESC LIMIT ?)',[(int)$job['user_id'],(int)$job['user_id'],self::KEEP-1]);
            $store->query('INSERT INTO music_menus(user_id,chat_id,thread_id,service,kind,data,view,created,expires) VALUES(?,?,?,?,?,?,?,?,?)',
                [(int)$job['user_id'],(int)$job['chat_id'],(int)($job['thread_id']??0),$collection['service'],$collection['kind'],
                 json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($view,JSON_THROW_ON_ERROR),time(),time()+self::TTL]);
            $id=(int)$store->db->lastInsertId();
            $store->db->exec('COMMIT');
        } catch(\Throwable $e) { $store->db->exec('ROLLBACK'); throw $e; }
        $menu=$store->query('SELECT * FROM music_menus WHERE id=?',[$id])->fetch();
        [$text,$rows]=$this->render($menu);
        $bot->jobMessage($job,$text,$rows);
        $message=(int)$store->query('SELECT message_id FROM jobs WHERE id=?',[$job['id']])->fetchColumn();
        $store->query('UPDATE music_menus SET message_id=? WHERE id=?',[$message,$id]);
        $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
    }

    private function listOf(array $data,array $view): array
    {
        if($view['stack']) {
            $top=end($view['stack']); $sub=$data['views'][$top['ref']]??['items'=>[],'title'=>'','sub'=>''];
            return ['items'=>$sub['items'],'title'=>$sub['title'],'sub'=>$sub['sub']??'','tab'=>''];
        }
        $tab=$data['tabs'][$view['tab']]??['items'=>[]];
        return ['items'=>$tab['items']??[],'title'=>$data['title'],'sub'=>$data['subtitle']??'','tab'=>$view['tab']];
    }
    private function itemLabel(array $item,int $index,bool $withArtist): string
    {
        $label=$item['n']!==''?$item['n']:$this->bot->ui->plainWith('menu_untitled',['n'=>(string)($index+1)]);
        if(($item['k']??'')==='t') {
            // Only a list of different artists (a playlist) names the artist on every row.
            $label=$withArtist&&($item['a']??'')!==''&&mb_strlen($item['a'])<=24?$item['a'].' – '.$label:$label;
            if(($item['d']??0)>0) $label.=' · '.Ui::duration((int)$item['d']);
        } elseif(($item['k']??'')==='a'&&($item['y']??'')!=='') $label.=' · '.$item['y'];
        return mb_strlen($label)>56?mb_substr($label,0,55).'…':$label;
    }

    /** @return array{0:string,1:list<list<array>>} message text and keyboard */
    public function render(array $menu): array
    {
        $ui=$this->bot->ui; $id=(int)$menu['id'];
        $data=json_decode($menu['data'],true); $view=json_decode($menu['view'],true);
        $now=$this->listOf($data,$view); $items=$now['items'];
        $pages=max(1,(int)ceil(count($items)/self::PAGE)); $page=max(0,min($pages-1,(int)$view['page']));
        $rows=[];
        $visible=array_filter($data['tabs'],fn($tab,$key)=>$tab['items']!==[]||$key===$view['tab'],ARRAY_FILTER_USE_BOTH);
        if(!$view['stack']&&count($visible)>1) {
            $row=[];
            foreach($visible as $key=>$tab) {
                if(!in_array($key,self::TABS,true)) continue;
                $count=$tab['items']===null?'':(string)count($tab['items']);
                $button=$ui->button('menu_tab_'.$key,'mm:'.$id.':t:'.$key,['count'=>$count]);
                $button['text']=trim(preg_replace('/\s*\(\s*\)/u','',$button['text'])??$button['text']);
                if($key===$view['tab']) $button['style']='success';
                $row[]=$button;
                if(count($row)===2) { $rows[]=$row; $row=[]; }
            }
            if($row) $rows[]=$row;
        }
        if($pages>1) $rows[]=[$ui->button('menu_page','mm:'.$id.':n',['page'=>(string)($page+1),'pages'=>(string)$pages])];
        $keys=['t'=>'menu_track','a'=>'menu_album','p'=>'menu_playlist','s'=>'menu_playlist','r'=>'menu_artist'];
        foreach(array_slice($items,$page*self::PAGE,self::PAGE,true) as $index=>$item) {
            $rows[]=[$ui->button($keys[$item['k']]??'menu_track','mm:'.$id.':o:'.$index,['label'=>$this->itemLabel($item,$index,$data['kind']==='playlist'||($view['stack']&&str_starts_with((string)end($view['stack'])['ref'],'p')))])];
        }
        if(!$items) $rows[]=[$ui->button('menu_page','mm:'.$id.':n',['page'=>'0','pages'=>'0'])];
        if($pages>1) {
            $nav=[];
            if($page>0) $nav[]=$ui->button('menu_prev','mm:'.$id.':p:'.($page-1));
            if($page+1<$pages) $nav[]=$ui->button('menu_next','mm:'.$id.':p:'.($page+1));
            $rows[]=$nav;
        }
        $footer=[];
        if($view['stack']) $footer[]=$ui->button('menu_back','mm:'.$id.':b');
        $footer[]=$ui->button('menu_close','mm:'.$id.':x');
        $rows[]=$footer;
        $icon=$view['stack']?'💿':(self::ICONS[$data['kind']]??'🎧');
        $tabLabel=$now['tab']!==''?preg_replace('/\s*\(\s*\)/u','',$ui->button('menu_tab_'.$now['tab'],'x',['count'=>''])['text'])??'':'';
        $info=trim($ui->text('menu_info',['label'=>$tabLabel!==''?$tabLabel:$now['sub'],'count'=>(string)count($items),'page'=>(string)($page+1),'pages'=>(string)$pages]));
        $note=!$view['stack']&&($data['note']??'')==='limited'?"\n\n".$ui->text('menu_limited'):'';
        $text=$ui->text('menu_head',['icon'=>$icon,'title'=>$now['title'],'info'=>$info],['info']).$note;
        if(!$items) $text.="\n\n".$ui->text('menu_empty');
        return [$text,$rows];
    }

    private function answer(array $cb,string $key='',bool $alert=false): void
    {
        $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$key!==''?mb_substr($this->bot->ui->plain($key),0,200):'','show_alert'=>$alert&&$key!=='','cache_time'=>0]);
    }
    private function save(array $menu,array $data,array $view): array
    {
        $this->bot->store->query('UPDATE music_menus SET data=?,view=? WHERE id=?',[json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($view,JSON_THROW_ON_ERROR),$menu['id']]);
        $menu['data']=json_encode($data,JSON_UNESCAPED_UNICODE); $menu['view']=json_encode($view);
        return $menu;
    }
    private function show(array $menu,int $chat): void
    {
        [$text,$rows]=$this->render($menu);
        try { $this->bot->send($chat,$text,$rows,(int)$menu['message_id']); }
        catch(\RuntimeException $e) { if($e->getCode()!==404) throw $e; $this->bot->store->query('DELETE FROM music_menus WHERE id=?',[$menu['id']]); }
    }

    /** Bot side: one tap on a menu button. */
    public function callback(array $update,int $chat,int $user,int $thread,int $message): void
    {
        // The bot process loads the downloader only when it needs it.
        require_once __DIR__.'/Download.php';
        $cb=$update['callback_query'];
        if(!preg_match('/^mm:(\d{1,12}):([a-z])(?::([A-Za-z0-9_-]{1,24}))?$/D',(string)($cb['data']??''),$match)) { $this->answer($cb); return; }
        [, $id, $op]=$match; $arg=$match[3]??'';
        $store=$this->bot->store;
        $menu=$store->query('SELECT * FROM music_menus WHERE id=?',[(int)$id])->fetch();
        if(!$menu||(int)$menu['expires']<time()||((int)$menu['message_id']>0&&(int)$menu['message_id']!==$message)) {
            if($menu) $store->query('DELETE FROM music_menus WHERE id=?',[$menu['id']]);
            $this->answer($cb,'menu_expired',true);
            try { $this->bot->api->call('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>$message,'reply_markup'=>['inline_keyboard'=>[]]]); } catch(\Throwable) {}
            return;
        }
        if((int)$menu['user_id']!==$user||(int)$menu['chat_id']!==$chat||($chat<0&&(int)$menu['thread_id']!==$thread)) { $this->answer($cb,'menu_owner',true); return; }
        $data=json_decode($menu['data'],true); $view=json_decode($menu['view'],true);
        try {
            if($op==='n') { $this->answer($cb); return; }
            if($op==='x') {
                $store->query('DELETE FROM music_menus WHERE id=?',[$menu['id']]);
                $this->answer($cb);
                try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); }
                catch(\Throwable) { try { $this->bot->api->call('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>$message,'reply_markup'=>['inline_keyboard'=>[]]]); } catch(\Throwable) {} }
                return;
            }
            if($op==='t') {
                if(!isset($data['tabs'][$arg])) { $this->answer($cb); return; }
                if($data['tabs'][$arg]['items']===null) $data['tabs'][$arg]['items']=$this->soundcloud()->listing((string)$data['tabs'][$arg]['src'],$this->scratch())['items'];
                $view=['tab'=>$arg,'page'=>0,'stack'=>[]];
            } elseif($op==='p') {
                $view['page']=max(0,(int)$arg);
            } elseif($op==='b') {
                $top=array_pop($view['stack']); $view['page']=(int)($top['back']??0);
            } elseif($op==='o') {
                $items=$this->listOf($data,$view)['items'];
                if(!ctype_digit($arg)||!isset($items[(int)$arg])) { $this->answer($cb); return; }
                $item=$items[(int)$arg];
                if(($item['k']??'')==='t'||($item['k']??'')==='r') {
                    $key=$this->request($menu,$item,(int)$update['update_id'],$thread);
                    $this->answer($cb,$key,!in_array($key,['menu_sent','menu_again'],true)); return;
                }
                $ref=($item['k']??'').($item['id']??substr(hash('sha256',(string)($item['u']??'')),0,10));
                $sub=['title'=>$item['n'],'sub'=>trim(($item['y']??'')!==''?(string)$item['y']:'')];
                if($item['k']==='a') $sub['items']=$this->catalog()->deezerTracks('album',(int)$item['id'],$item);
                elseif($item['k']==='p') $sub['items']=$this->catalog()->deezerTracks('playlist',(int)$item['id']);
                else $sub['items']=$this->soundcloud()->listing((string)$item['u'],$this->scratch())['items'];
                $data['views'][$ref]=$sub;
                $view['stack'][]=['ref'=>$ref,'back'=>(int)$view['page']]; $view['page']=0;
                $view['stack']=array_slice($view['stack'],-2);
                // Keep only the lists still reachable, so a long visit does not grow the row.
                $keep=array_column($view['stack'],'ref');
                $data['views']=array_slice(array_filter($data['views'],fn($key)=>in_array($key,$keep,true),ARRAY_FILTER_USE_KEY),-self::VIEWS,null,true);
            } else { $this->answer($cb); return; }
        } catch(MediaFailure|\RuntimeException $e) {
            error_log('Music menu '.$id.' action '.$op.' failed: '.diagnosticMessage($e->getMessage()));
            $this->answer($cb,'menu_failed',true); return;
        }
        $menu=$this->save($menu,$data,$view);
        $this->answer($cb);
        $this->show($menu,$chat);
    }

    private function scratch(): string
    {
        $folder=$this->bot->config->storage.'/downloads/menu-'.bin2hex(random_bytes(4));
        if(!is_dir(dirname($folder))) @mkdir(dirname($folder),0700,true);
        mkdir($folder,0700);
        register_shutdown_function(fn()=>Download::clean($folder));
        return $folder;
    }

    /**
     * Queue a tapped track like a pasted link, or open a similar artist as a new menu. Returns the key of the text
     * to show as the tap's answer.
     */
    private function request(array $menu,array $item,int $update,int $thread): string
    {
        $store=$this->bot->store; $phase=null; $options=[];
        if(($item['k']??'')==='r') $address='https://www.deezer.com/artist/'.(int)$item['id'];
        elseif(($item['u']??'')!=='') $address=(string)$item['u'];
        else {
            // Tracks listed by Deezer carry their details; the audio is looked up from them like any Spotify track.
            $meta=is_array($item['m']??null)?$item['m']:[]; $artists=array_values(array_filter((array)($meta['artists']??[]),'is_string'));
            if(!$artists) return 'menu_failed';
            $address='https://open.spotify.com/search/'.rawurlencode($artists[0].' '.$item['n']);
            $phase='spotify';
            $options=['track'=>['title'=>(string)$item['n'],'artists'=>$artists,'duration'=>(int)($item['d']??0),'year'=>(string)($meta['year']??''),'cover'=>(string)($meta['cover']??'')]];
        }
        $key=substr(hash('sha256',$address),0,12);
        $sent=array_filter(json_decode($menu['sent'],true)?:[],fn($time)=>(int)$time>time()-self::RECENT);
        if(isset($sent[$key])) return 'menu_again';
        $result=$this->bot->requestVideo($update,(int)$menu['user_id'],(int)$menu['chat_id'],$address,$thread,0,$phase,$options,true);
        if($result==='duplicate') return '';
        if($result!=='queued') return match($result) {'busy'=>'busy','full'=>'queue_full','cooldown'=>'cooldown','updating'=>'maintenance','denied'=>'denied',default=>'failed'};
        $sent[$key]=time();
        $store->query('UPDATE music_menus SET sent=? WHERE id=?',[json_encode($sent,JSON_THROW_ON_ERROR),$menu['id']]);
        return 'menu_sent';
    }
}
