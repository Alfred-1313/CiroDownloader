<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/MusicCatalog.php';
require_once __DIR__.'/SoundCloud.php';

/**
 * The menu a user gets for an artist, album, playlist or SoundCloud profile: tabs for each kind of list,
 * paged items, and one tap on a track queues it like a pasted link. One message is edited in place: when
 * there is a picture it is a photo (the artist, then each album's own cover), otherwise text. It belongs to
 * the user, chat and topic that asked, and expires after a day. A list of tracks can also be fetched whole,
 * one audio message per track or in ZIP files.
 *
 * Callback data is "mm:ID:OP[:ARG]": t tab, p page, o open item, b back, x close, n title, a all (each|zip).
 */
final class MusicMenu
{
    public const PAGE=8, KEEP=8, TTL=86400, RECENT=120, VIEWS=6, BATCH_MAX=50;
    private const TABS=['popular','albums','singles','eps','compilations','playlists','related','tracks','sets','reposts','likes'];
    private const ICONS=['artist'=>'🎤','album'=>'💿','playlist'=>'📻','profile'=>'🎧','set'=>'📻','search'=>'🔎'];

    public function __construct(private Bot $bot,private ?MusicCatalog $catalog=null,private ?SoundCloud $soundcloud=null) {}
    private function catalog(): MusicCatalog { return $this->catalog??new MusicCatalog($this->bot->config); }
    private function soundcloud(): SoundCloud { return $this->soundcloud??new SoundCloud($this->bot->config); }
    /** Whether choosing a track (or a whole list) turns the menu into that download's status message. On by default. */
    public static function closesOnChoice(Store $store): bool { return $store->get('music.menu_close','1')==='1'; }

    /** Worker side: read the page and turn the job's status message into the menu. */
    public function open(array $job,array $collection,string $folder): void
    {
        $bot=$this->bot; $store=$bot->store;
        $search=$collection['service']==='search';
        $bot->jobMessage($job,$search?$bot->text('search_loading',['query'=>$collection['id']]):$bot->text('menu_loading'));
        try { $data=$this->catalog()->build($collection,fn(string $url)=>$this->soundcloud()->listing($url,$folder),$folder); }
        catch(MediaFailure $e) {
            // A search that finds nothing is an answer, not an error: say so, with the way to write it.
            if(!$search||$e->reason!=='search') throw $e;
            $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
            $bot->jobMessage($job,$bot->text('search_empty',['query'=>$collection['id']])); return;
        }
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) {
            $store->query("UPDATE jobs SET status='failed' WHERE id=?",[$job['id']]);
            $bot->jobMessage($job,$bot->denial((int)$job['chat_id'])); return;
        }
        $tabs=array_keys($data['tabs']);
        $first=$tabs[0]??'tracks';
        if($collection['service']==='soundcloud'&&$collection['kind']==='profile'&&str_contains($collection['id'],'/')) $first=explode('/',$collection['id'])[1];
        $view=['tab'=>isset($data['tabs'][$first])?$first:$tabs[0],'page'=>0,'stack'=>[]];
        $data['photo']=false; $data['shown']='';
        self::purge($bot->config,$store);
        $store->db->exec('BEGIN IMMEDIATE');
        try {
            $store->query('DELETE FROM music_menus WHERE expires<?',[time()]);
            $store->query('DELETE FROM music_menus WHERE user_id=? AND id NOT IN (SELECT id FROM music_menus WHERE user_id=? ORDER BY id DESC LIMIT ?)',[(int)$job['user_id'],(int)$job['user_id'],self::KEEP-1]);
            $store->query('INSERT INTO music_menus(user_id,chat_id,thread_id,service,kind,data,view,created,expires) VALUES(?,?,?,?,?,?,?,?,?)',
                [(int)$job['user_id'],(int)$job['chat_id'],(int)($job['thread_id']??0),$data['service'],$data['kind'],
                 json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($view,JSON_THROW_ON_ERROR),time(),time()+self::TTL]);
            $id=(int)$store->db->lastInsertId();
            $store->db->exec('COMMIT');
        } catch(\Throwable $e) { $store->db->exec('ROLLBACK'); throw $e; }
        $menu=$store->query('SELECT * FROM music_menus WHERE id=?',[$id])->fetch();
        [$text,$rows]=$this->render($menu);
        // A picture makes the menu a photo; Telegram refusing it (a bad address) leaves the text menu.
        if($data['image']!=='') $bot->jobPoster($job,$data['image'],$text,$rows);
        else $bot->jobMessage($job,$text,$rows);
        $saved=$store->query('SELECT message_id,message_kind FROM jobs WHERE id=?',[$job['id']])->fetch();
        if($saved['message_kind']==='photo') { $data['photo']=true; $data['shown']=$data['image']; }
        $store->query('UPDATE music_menus SET message_id=?,data=? WHERE id=?',[(int)$saved['message_id'],json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id]);
        $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
    }

    private function listOf(array $data,array $view): array
    {
        if($view['stack']) {
            $top=end($view['stack']); $sub=$data['views'][$top['ref']]??['items'=>[],'title'=>'','sub'=>''];
            return ['items'=>$sub['items'],'title'=>$sub['title'],'sub'=>$sub['sub']??'','tab'=>'','image'=>$sub['image']??'','url'=>$sub['url']??'','single'=>!empty($sub['single']),'album'=>str_starts_with((string)$top['ref'],'a')];
        }
        $tab=$data['tabs'][$view['tab']]??['items'=>[]];
        return ['items'=>$tab['items']??[],'title'=>$data['title'],'sub'=>$data['subtitle']??'','tab'=>$view['tab'],'image'=>$data['image']??'','url'=>$data['url']??'','single'=>false,'album'=>($data['kind']??'')==='album'];
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
    /** @return list<array> the tracks of a list that can be fetched whole */
    private static function tracksOf(array $items): array { return array_values(array_filter($items,fn($item)=>($item['k']??'')==='t')); }

    /** @return array{0:string,1:list<list<array>>} message text and keyboard */
    public function render(array $menu): array
    {
        $ui=$this->bot->ui; $id=(int)$menu['id'];
        $data=json_decode($menu['data'],true); $view=json_decode($menu['view'],true);
        $batch=is_array($data['batch']??null)?$data['batch']:[];
        // After a whole list was sent: a summary and the way back, which deletes what was sent.
        if(($batch['state']??'')==='done') {
            $text=$ui->text('batch_done',['title'=>(string)$batch['title'],'done'=>(string)$batch['count'],'failed'=>(string)$batch['failed']]);
            return [$text,[[$ui->button('batch_back','mm:'.$id.':r')]]];
        }
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
        // A list of tracks inside an album, playlist or set (or a page that is one) can be fetched whole.
        // A single needs no "download all": its track is one tap away.
        // (Search results are different songs, so they offer no "download all".)
        $whole=$data['kind']!=='search'&&($view['stack']||$data['kind']!=='artist'&&$data['kind']!=='profile')&&count(self::tracksOf($items))>1&&!$now['single'];
        $radioAllowed=(new RadioPanel($this->bot))->allowed((int)$menu['chat_id'],(int)$menu['user_id']);
        // Choosing tracks: a tap marks a track (blue), and "send the chosen" fetches only those.
        $picking=$whole&&is_array($view['pick']??null); $picked=$picking?array_values(array_map('intval',$view['pick'])):[];
        if($whole) {
            // A stopped download of this very list can be continued where it stopped.
            if(($batch['state']??'')==='paused'&&($batch['address']??'')===$now['url']) $rows[]=[$ui->button('batch_resume','mm:'.$id.':c',['done'=>(string)$batch['ready'],'total'=>(string)$batch['total']])];
            if($picking) {
                $row=[];
                if($picked) $row[]=$ui->button('menu_pick_send','mm:'.$id.':d',['count'=>(string)count($picked)]);
                $radioRows=$picked&&$radioAllowed?[[$ui->button('radio_play_chosen','mm:'.$id.':y:p',['count'=>(string)count($picked)])]]:[];
                $row[]=$ui->button('menu_pick_cancel','mm:'.$id.':m:0');
                $rows[]=$row;
                array_push($rows,...$radioRows);
            } else {
                $rows[]=[$ui->button('menu_all','mm:'.$id.':a:each'),$ui->button('menu_pick','mm:'.$id.':m:1')];
                $rows[]=[$ui->button('menu_zip','mm:'.$id.':a:zip')];
            }
        }
        // Play the whole list, first to last, on the radio of this chat (or the person's own): for any list of several songs, for the people who run one.
        if(!$picking&&count(self::tracksOf($items))>1&&!$now['single']&&$radioAllowed) $rows[]=[$ui->button('radio_play_all','mm:'.$id.':y')];
        if($pages>1) $rows[]=[$ui->button('menu_page','mm:'.$id.':n',['page'=>(string)($page+1),'pages'=>(string)$pages])];
        $keys=['t'=>'menu_track','a'=>'menu_album','p'=>'menu_playlist','s'=>'menu_playlist','r'=>'menu_artist'];
        foreach(array_slice($items,$page*self::PAGE,self::PAGE,true) as $index=>$item) {
            $label=$this->itemLabel($item,$index,$data['kind']==='playlist'||($view['stack']&&str_starts_with((string)end($view['stack'])['ref'],'p')));
            if($picking&&($item['k']??'')==='t') {
                $chosen=in_array($index,$picked,true);
                $button=$ui->button('menu_track','mm:'.$id.':k:'.$index,['label'=>$label]);
                if($chosen) { $button['text']=$ui->plainWith('menu_picked',['label'=>$label]); $button['style']='primary'; }
                $rows[]=[$button]; continue;
            }
            $rows[]=[$ui->button($keys[$item['k']]??'menu_track','mm:'.$id.':o:'.$index,['label'=>$label])];
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
        $icon=$view['stack']?(str_starts_with((string)end($view['stack'])['ref'],'a')?'💿':'📻'):(self::ICONS[$data['kind']]??'🎧');
        $tabLabel=$now['tab']!==''?preg_replace('/\s*\(\s*\)/u','',$ui->button('menu_tab_'.$now['tab'],'x',['count'=>''])['text'])??'':'';
        $info=trim($ui->text('menu_info',['label'=>$tabLabel!==''?$tabLabel:$now['sub'],'count'=>(string)count($items),'page'=>(string)($page+1),'pages'=>(string)$pages]));
        $note=!$view['stack']&&($data['note']??'')==='limited'?"\n\n".$ui->text('menu_limited'):'';
        $text=$ui->text('menu_head',['icon'=>$icon,'title'=>$now['title'],'info'=>$info],['info']).$note;
        if(!$items) $text.="\n\n".$ui->text('menu_empty');
        if($picking) $text.="\n\n".$ui->text('menu_pick_info',['count'=>(string)count($picked)]);
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
    /** Shows the current view: a new cover when the list has its own, otherwise only the caption and buttons change. */
    private function show(array $menu,int $chat): void
    {
        $api=$this->bot->api; [$text,$rows]=$this->render($menu);
        $data=json_decode($menu['data'],true); $view=json_decode($menu['view'],true);
        try {
            if(empty($data['photo'])) { $this->bot->send($chat,$text,$rows,(int)$menu['message_id']); return; }
            $markup=['inline_keyboard'=>$rows]; $caption=Ui::fitCaption($text);
            $want=$this->listOf($data,$view)['image']?:($data['image']??'');
            if($want!==''&&$want!==($data['shown']??'')) {
                try {
                    $api->call('editMessageMedia',['chat_id'=>$chat,'message_id'=>(int)$menu['message_id'],'media'=>['type'=>'photo','media'=>$want,'caption'=>$caption,'parse_mode'=>'HTML'],'reply_markup'=>$markup]);
                    $data['shown']=$want; $this->bot->store->query('UPDATE music_menus SET data=? WHERE id=?',[json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$menu['id']]);
                    return;
                } catch(\RuntimeException $e) { if($e->getCode()!==400) throw $e; /* Telegram could not fetch the cover: keep the old picture. */ }
            }
            $api->call('editMessageCaption',['chat_id'=>$chat,'message_id'=>(int)$menu['message_id'],'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>$markup]);
        } catch(\RuntimeException $e) {
            if($e->getCode()!==404) throw $e;
            $this->bot->store->query('DELETE FROM music_menus WHERE id=?',[$menu['id']]);
        }
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
            // Only time ends a menu: a button on an older message of it (now elsewhere, for example under a sent track) just goes quiet.
            if($menu&&(int)$menu['expires']<time()) $store->query('DELETE FROM music_menus WHERE id=?',[$menu['id']]);
            $this->answer($cb,'menu_expired',true);
            try { $this->bot->api->call('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>$message,'reply_markup'=>['inline_keyboard'=>[]]]); } catch(\Throwable) {}
            return;
        }
        if((int)$menu['user_id']!==$user||(int)$menu['chat_id']!==$chat||($chat<0&&(int)$menu['thread_id']!==$thread)) { $this->answer($cb,'menu_owner',true); return; }
        $data=json_decode($menu['data'],true); $view=json_decode($menu['view'],true);
        if(($data['batch']['state']??'')==='done'&&!in_array($op,['r','x','n'],true)) { $this->answer($cb); return; }
        try {
            if($op==='n') { $this->answer($cb); return; }
            if($op==='x') {
                self::forgetBatches($this->bot->config,(int)$menu['id']);
                $store->query('DELETE FROM music_menus WHERE id=?',[$menu['id']]);
                $this->answer($cb);
                try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); }
                catch(\Throwable) { try { $this->bot->api->call('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>$message,'reply_markup'=>['inline_keyboard'=>[]]]); } catch(\Throwable) {} }
                return;
            }
            if($op==='r') { $this->giveBack($menu,$data,$chat); $this->answer($cb); return; }
            if(in_array($op,['a','c','d'],true)&&($data['batch']['state']??'')==='running') { $this->answer($cb,'menu_again'); return; }
            if($op==='m') {
                // Start or leave choosing tracks.
                if($arg==='1') $view['pick']=[]; else unset($view['pick']);
                $menu=$this->save($menu,$data,$view); $this->answer($cb); $this->show($menu,$chat); return;
            }
            if($op==='k'||($op==='o'&&is_array($view['pick']??null)&&(($this->listOf($data,$view)['items'][(int)$arg]['k']??'')==='t'))) {
                $items=$this->listOf($data,$view)['items'];
                if(!is_array($view['pick']??null)||!ctype_digit($arg)||($items[(int)$arg]['k']??'')!=='t') { $this->answer($cb); return; }
                $picked=array_values(array_map('intval',$view['pick'])); $index=(int)$arg;
                if(in_array($index,$picked,true)) $picked=array_values(array_diff($picked,[$index]));
                elseif(count($picked)>=self::BATCH_MAX) { $this->answer($cb,'menu_pick_full',true); return; }
                else $picked[]=$index;
                sort($picked); $view['pick']=$picked;
                $menu=$this->save($menu,$data,$view); $this->answer($cb); $this->show($menu,$chat); return;
            }
            if($op==='d') {
                if(!array_filter((array)($view['pick']??[]),'is_int')) { $this->answer($cb,'menu_pick_empty',true); return; }
                $key=$this->requestAll($menu,$data,$view,'pick',(int)$update['update_id'],$thread);
                // The choice is used up; the batch itself was saved by requestAll.
                if($key==='menu_sent') { unset($view['pick']); $this->bot->store->query('UPDATE music_menus SET view=? WHERE id=?',[json_encode($view,JSON_THROW_ON_ERROR),$menu['id']]); }
                $this->answer($cb,$key,!in_array($key,['menu_sent','menu_again'],true)); return;
            }
            if($op==='y') {
                // "Play all": every song of the open list goes into the radio queue, first to last.
                $now=$this->listOf($data,$view); $songs=[];
                $source=$arg==='p'?array_intersect_key($now['items'],array_flip(array_map('intval',(array)($view['pick']??[])))):$now['items'];
                foreach(array_slice(self::tracksOf($source),0,self::BATCH_MAX) as $item) {
                    $track=self::trackJob($item); if($track===null) continue;
                    $service=isset($track['u'])&&musicService($track['u'])==='soundcloud'?'soundcloud':'spotify';
                    $songs[]=['source'=>['service'=>$service]+$track,'title'=>(string)$item['n'],'artists'=>(string)($item['a']??''),'duration'=>(int)($item['d']??0)];
                }
                $panel=new RadioPanel($this->bot); $key=$songs?$panel->enqueueMany($chat,$user,$songs):'menu_pick_empty';
                $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>mb_substr($this->bot->ui->plainWith($key,$key==='radio_added_many'?$panel->answerVars:[]),0,200),'show_alert'=>$key!=='radio_added_many','cache_time'=>0]);
                return;
            }
            if($op==='a'||$op==='c') {
                $key=$this->requestAll($menu,$data,$view,$op==='c'?'resume':$arg,(int)$update['update_id'],$thread);
                $this->answer($cb,$key,!in_array($key,['menu_sent','menu_again'],true)); return;
            }
            if(in_array($op,['t','b','o'],true)) unset($view['pick']);
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
                $ref=($item['k']??'').($item['src']??'').($item['id']??substr(hash('sha256',(string)($item['u']??'')),0,10));
                $sub=['title'=>$item['n'],'sub'=>trim(($item['y']??'')!==''?(string)$item['y']:''),'image'=>(string)($item['c']??'')];
                if(!$view['stack']&&($view['tab']??'')==='singles') $sub['single']=true;
                if($item['k']==='a'&&($item['src']??'')==='it') { $sub['items']=$this->catalog()->itunesTracks((int)$item['id'],$item); $sub['url']='https://music.apple.com/album/'.(int)$item['id']; }
                elseif($item['k']==='a') { $sub['items']=$this->catalog()->deezerTracks('album',(int)$item['id'],$item); $sub['url']='https://www.deezer.com/album/'.(int)$item['id']; }
                elseif($item['k']==='p') { $sub['items']=$this->catalog()->deezerTracks('playlist',(int)$item['id'],['c'=>$item['c']??'']); $sub['url']='https://www.deezer.com/playlist/'.(int)$item['id']; }
                else {
                    $sub['items']=$this->soundcloud()->listing((string)$item['u'],$this->scratch())['items']; $sub['url']=(string)$item['u'];
                    $sub['image']=$this->catalog()->soundcloudImage((string)$item['u']);
                }
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

    /** The details a worker needs for one track of a menu: its address, or what Deezer or iTunes said about it. */
    private static function trackJob(array $item): ?array
    {
        if(($item['u']??'')!=='') return ['u'=>(string)$item['u']];
        $meta=is_array($item['m']??null)?$item['m']:[]; $artists=array_values(array_filter((array)($meta['artists']??[]),'is_string'));
        if(!$artists||(int)($item['d']??0)<1) return null;
        return ['m'=>['title'=>(string)$item['n'],'artists'=>$artists,'duration'=>(int)$item['d'],'year'=>(string)($meta['year']??''),'cover'=>(string)($meta['cover']??'')]];
    }
    /** The menu message, handed to a download that replaces the menu, or null when menus stay open. */
    private function adoption(array $menu): ?array
    {
        if(!self::closesOnChoice($this->bot->store)||(int)$menu['message_id']<1) return null;
        $data=json_decode($menu['data'],true);
        return ['message'=>(int)$menu['message_id'],'kind'=>!empty($data['photo'])?'photo':'text','poster'=>(string)($data['shown']??'')];
    }
    private function queued(array $menu,string $key,array $sent,string $result,?array $adopt): string
    {
        if($result==='duplicate') return '';
        if($result!=='queued') return match($result) {'busy'=>'busy','full'=>'queue_full','cooldown'=>'cooldown','updating'=>'maintenance','denied'=>'denied',default=>'failed'};
        // The menu became the download's status message. Its row stays: the delivered track gets a button back to it.
        if($adopt!==null) return 'menu_sent';
        $sent[$key]=time();
        $this->bot->store->query('UPDATE music_menus SET sent=? WHERE id=?',[json_encode($sent,JSON_THROW_ON_ERROR),$menu['id']]);
        return 'menu_sent';
    }

    /**
     * Queue a tapped track like a pasted link, or open a similar artist as a new menu. Returns the key of the text
     * to show as the tap's answer.
     */
    private function request(array $menu,array $item,int $update,int $thread): string
    {
        $phase=null; $options=[]; $adopt=null;
        if(($item['k']??'')==='r') $address='https://www.deezer.com/artist/'.(int)$item['id'];
        else {
            $track=self::trackJob($item);
            if($track===null) return 'menu_failed';
            if(isset($track['u'])) $address=$track['u'];
            else {
                // Tracks listed by Deezer or iTunes carry their details; the audio is looked up from them like any Spotify track.
                $address='https://open.spotify.com/search/'.rawurlencode($track['m']['artists'][0].' '.$track['m']['title']);
                $phase='spotify'; $options=['track'=>$track['m']];
            }
            $adopt=$this->adoption($menu);
            // The caption names the release the track comes from; a menu that closes gives the track a way back to it.
            $now=$this->listOf(json_decode($menu['data'],true),json_decode($menu['view'],true));
            if($now['album']&&$now['title']!=='') $options['album']=mb_substr((string)$now['title'],0,100);
            if($adopt!==null) $options['menu']=(int)$menu['id'];
        }
        $key=substr(hash('sha256',$address),0,12);
        $sent=array_filter(json_decode($menu['sent'],true)?:[],fn($time)=>(int)$time>time()-self::RECENT);
        if(isset($sent[$key])) return 'menu_again';
        $result=$this->bot->requestVideo($update,(int)$menu['user_id'],(int)$menu['chat_id'],$address,$thread,0,$phase,$options,true,$adopt);
        return $this->queued($menu,$key,$sent,$result,$adopt);
    }

    /**
     * Queue every track of the open list (up to BATCH_MAX) as one job on the menu's own message, or continue a stopped
     * run ($mode "resume"). The menu row stays: stopping or finishing brings the menu back.
     */
    private function requestAll(array $menu,array $data,array $view,string $mode,int $update,int $thread): string
    {
        if($mode==='resume') {
            $options=$data['batch']['options']??null;
            if(!is_array($options)||($data['batch']['state']??'')!=='paused') return 'menu_failed';
            $address=(string)$data['batch']['address'];
        } else {
            if(!in_array($mode,['each','zip','pick'],true)) return '';
            $now=$this->listOf($data,$view); $tracks=[];
            // "pick": only the tracks chosen in the list, in the list's order.
            $source=$mode==='pick'?array_intersect_key($now['items'],array_flip(array_map('intval',(array)($view['pick']??[])))):$now['items'];
            foreach(self::tracksOf($source) as $item) if(($track=self::trackJob($item))!==null) $tracks[]=$track+['n'=>(string)$item['n']];
            $tracks=array_slice($tracks,0,self::BATCH_MAX);
            $address=$now['url']!==''?$now['url']:(string)($data['url']??'');
            if(count($tracks)<1||$address==='') return 'menu_failed';
            $options=['batch'=>['service'=>$data['service']==='soundcloud'?'soundcloud':'spotify','mode'=>$mode==='pick'?'each':$mode,'title'=>mb_substr($now['title'],0,100),'tracks'=>$tracks,
                'menu'=>(int)$menu['id'],'key'=>$menu['id'].'-'.substr(hash('sha256',$address.($mode==='pick'?'#'.implode(',',array_column($tracks,'n')):'')),0,8)]];
        }
        $message=['message'=>(int)$menu['message_id'],'kind'=>!empty($data['photo'])?'photo':'text','poster'=>(string)($data['shown']??'')];
        if($message['message']<1) return 'menu_failed';
        $result=$this->bot->requestVideo($update,(int)$menu['user_id'],(int)$menu['chat_id'],$address,$thread,0,'batch',$options,true,$message);
        if($result==='duplicate') return '';
        if($result!=='queued') return match($result) {'busy'=>'busy','full'=>'queue_full','cooldown'=>'cooldown','updating'=>'maintenance','denied'=>'denied',default=>'failed'};
        $data['batch']=['state'=>'running','options'=>$options,'address'=>$address,'ready'=>0,'total'=>count($options['batch']['tracks'])];
        $this->bot->store->query('UPDATE music_menus SET data=? WHERE id=?',[json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$menu['id']]);
        return 'menu_sent';
    }

    private function load(int $id): ?array
    {
        $menu=$this->bot->store->query('SELECT * FROM music_menus WHERE id=?',[$id])->fetch();
        return $menu?:null;
    }
    /** Worker: the run was stopped. Nothing was sent; the menu returns with a "continue" button. */
    public function paused(int $id,array $batch,int $ready,int $total): void
    {
        if(!($menu=$this->load($id))) return;
        $data=json_decode($menu['data'],true);
        $data['batch']=['state'=>'paused','options'=>['batch'=>$batch],'address'=>$data['batch']['address']??'','ready'=>$ready,'total'=>$total];
        $this->save($menu,$data,json_decode($menu['view'],true));
        $this->show($this->load($id),(int)$menu['chat_id']);
    }
    /**
     * Worker: everything was sent. When the last sent message carries the way back ($carrier), the old menu message is
     * deleted and that message becomes the menu's anchor; otherwise the menu message itself turns into a short summary
     * with the way back.
     */
    public function delivered(int $id,array $batch,array $ids,int $count,int $failed,int $carrier=0): void
    {
        if(!($menu=$this->load($id))) return;
        $chat=(int)$menu['chat_id']; $data=json_decode($menu['data'],true);
        $data['batch']=['state'=>'done','title'=>(string)$batch['title'],'count'=>$count,'failed'=>$failed,'delivered'=>$ids,'key'=>(string)$batch['key'],'address'=>$data['batch']['address']??''];
        if($carrier>0) {
            $data['batch']['carrier']=$carrier; $old=(int)$menu['message_id'];
            $this->bot->store->query('UPDATE music_menus SET message_id=?,data=? WHERE id=?',[$carrier,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id]);
            if($old>0&&$old!==$carrier) {
                try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$old]); }
                catch(\Throwable) { try { $this->bot->api->call('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>$old,'reply_markup'=>['inline_keyboard'=>[]]]); } catch(\Throwable) {} }
            }
            return;
        }
        $this->save($menu,$data,json_decode($menu['view'],true));
        $this->show($this->load($id),$chat);
    }
    /** Worker: a tapped track arrived with the way back; the menu row now points at the audio message. */
    public function trackDelivered(int $id,int $user,int $chat,int $audio): void
    {
        if(!($menu=$this->load($id))||(int)$menu['user_id']!==$user||(int)$menu['chat_id']!==$chat||$audio<1) return;
        $data=json_decode($menu['data'],true);
        $data['batch']=['state'=>'done','title'=>'','count'=>1,'failed'=>0,'delivered'=>[$audio],'key'=>'','address'=>'','carrier'=>$audio];
        $this->bot->store->query('UPDATE music_menus SET message_id=?,data=? WHERE id=?',[$audio,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id]);
    }
    /** Worker: whether a tapped track may offer the way back (its menu still exists and belongs to the same person and chat). */
    public function owns(int $id,int $user,int $chat): bool
    {
        $menu=$this->load($id);
        return $menu!==null&&(int)$menu['user_id']===$user&&(int)$menu['chat_id']===$chat&&(int)$menu['expires']>time();
    }
    /** "Back to the previous menu": the sent tracks are deleted and the menu is shown again where it was. */
    private function giveBack(array $menu,array $data,int $chat): void
    {
        $ids=array_values(array_filter(array_map('intval',(array)($data['batch']['delivered']??[])),fn($id)=>$id>0));
        foreach(array_chunk($ids,100) as $chunk) {
            try { $this->bot->api->call('deleteMessages',['chat_id'=>$chat,'message_ids'=>$chunk]); }
            catch(\Throwable) { foreach($chunk as $messageId) { try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$messageId]); } catch(\Throwable) {} } }
        }
        self::forgetBatches($this->bot->config,(int)$menu['id']);
        $anchored=(int)($data['batch']['carrier']??0)>0;
        unset($data['batch']);
        $menu=$this->save($menu,$data,json_decode($menu['view'],true));
        if($anchored) $this->recreate($menu,$data,$chat); else $this->show($menu,$chat);
    }
    /** The old menu message was deleted when the tracks arrived, so the menu comes back as a new message (a photo when it had one). */
    private function recreate(array $menu,array $data,int $chat): void
    {
        [$text,$rows]=$this->render($menu); $markup=['inline_keyboard'=>$rows];
        $topic=$chat<0&&(int)$menu['thread_id']>0?['message_thread_id'=>(int)$menu['thread_id']]:[]; $result=null;
        if(!empty($data['photo'])&&($data['shown']??'')!=='') {
            try { $result=$this->bot->api->call('sendPhoto',['chat_id'=>$chat,'photo'=>$data['shown'],'caption'=>Ui::fitCaption($text),'parse_mode'=>'HTML','reply_markup'=>$markup]+$topic); }
            catch(\RuntimeException $e) { if($e->getCode()!==400) throw $e; $data['photo']=false; $data['shown']=''; }
        }
        if(!is_array($result)||empty($result['message_id'])) $result=$this->bot->api->call('sendMessage',['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true,'reply_markup'=>$markup]+$topic);
        $this->bot->store->query('UPDATE music_menus SET message_id=?,data=? WHERE id=?',[(int)($result['message_id']??0),json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$menu['id']]);
    }
    /** Removes the prepared tracks of a menu's batches. */
    public static function forgetBatches(Config $config,int $menu): void
    {
        foreach(glob($config->storage.'/music-batches/'.$menu.'-*',GLOB_ONLYDIR)?:[] as $folder) Download::clean($folder);
    }
    /** Prepared tracks older than the menus themselves are removed. */
    public static function purge(Config $config,?Store $store=null): void
    {
        foreach(glob($config->storage.'/music-batches/*',GLOB_ONLYDIR)?:[] as $folder) {
            if(is_link($folder)||!preg_match('/^(\d+)-[a-f0-9]{8}$/D',basename($folder),$match)) continue;
            // Never touch a live preparation or a queued continuation, however old its folder is.
            if($store&&$store->query("SELECT 1 FROM jobs WHERE phase='batch' AND status IN ('queued','running','confirming') AND json_extract(options,'$.batch.key')=? LIMIT 1",[basename($folder)])->fetchColumn()) continue;
            $data=$store?$store->query('SELECT data FROM music_menus WHERE id=?',[(int)$match[1]])->fetchColumn():false;
            $done=$data!==false&&(json_decode((string)$data,true)['batch']['state']??'')==='done';
            if($done||filemtime($folder)<time()-self::TTL-3600) Download::clean($folder);
        }
    }

    /** A tap on "stop" under a running download of a whole list. Only its owner can stop it. */
    public static function stop(Bot $bot,array $cb,int $chat,int $user,int $job): void
    {
        $row=$bot->store->query("SELECT * FROM jobs WHERE id=? AND phase='batch'",[$job])->fetch();
        $mine=$row&&(int)$row['user_id']===$user&&(int)$row['chat_id']===$chat;
        if($mine&&in_array($row['status'],['queued','running'],true)) $bot->store->set('batch.stop.'.$job,'1');
        $bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>mb_substr($bot->ui->plain($mine?'batch_stopping':'menu_owner'),0,200),'show_alert'=>!$mine,'cache_time'=>0]);
    }
}
