<?php
declare(strict_types=1);
namespace Zero;

/** The self-refreshing station message. Telegram I/O belongs to the poller/helper, never the player. */
final class RadioPlayerPanel
{
    private Radio $radio;
    public function __construct(private Bot $bot) { $this->radio=new Radio($bot->config,$bot->store); }
    private function button(string $key,string $data): array
    {
        $button=$this->bot->ui->button($key,$data);
        if($this->bot->store->get('button.style.'.$key)==='') unset($button['style']);
        return $button;
    }
    public function forget(int $chat,int $message): void { $this->bot->store->query('DELETE FROM radio_panels WHERE chat_id=? AND message_id=?',[$chat,$message]); }
    public function leave(int $chat,int $message,string $text,array $rows,int $station=0): void
    {
        $kind=$this->bot->store->query('SELECT kind FROM radio_panels WHERE chat_id=? AND message_id=?',[$chat,$message])->fetchColumn();
        if($kind==='photo') $this->bot->api->call('editMessageCaption',['chat_id'=>$chat,'message_id'=>$message,'caption'=>Ui::fitCaption($text),'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>$rows]]);
        else $this->bot->send($chat,$text,$rows,$message);
        $this->bot->store->query("UPDATE radio_panels SET view='static',station_id=? WHERE chat_id=? AND message_id=?",[$station,$chat,$message]);
    }
    public function menu(int $chat,int $message): string
    {
        $view=$this->bot->store->query('SELECT view FROM radio_panels WHERE chat_id=? AND message_id=?',[$chat,$message])->fetchColumn();
        return $view==='menu'?'player':'menu';
    }
    /** Open the player before removing the delivered audio. Its thumbnail is kept by Telegram, never as a disk cache. */
    public function openFromAudio(int $chat,int $user,array $station,array $original,int $thread,int $item): void
    {
        $preview=null; $api=$this->bot->api;
        $thumbnail=$original['audio']['thumbnail']??$original['audio']['thumb']??null;
        $display=(int)($this->radio->progress((int)$station['chat_id'])['id']??0);
        if($display===0) $display=(int)($this->radio->queue((int)$station['chat_id'],1)[0]['id']??0);
        if($item>0&&$display===$item&&$api instanceof FileApi&&is_array($thumbnail)&&is_string($thumbnail['file_id']??null)&&(int)($thumbnail['file_size']??0)<=1048576) {
            try {
                $file=$api->call('getFile',['file_id'=>$thumbnail['file_id']]);
                if(is_string($file['file_path']??null)&&(int)($file['file_size']??0)<=1048576) {
                    $bytes=$api->downloadFile($file['file_path'],1048576); $size=@getimagesizefromstring($bytes);
                    if($size&&in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)) $preview=['id'=>$item,'key'=>'thumbnail:'.$thumbnail['file_id'],'file'=>new \CURLStringFile($bytes,$size[2]===IMAGETYPE_JPEG?'cover.jpg':'cover.png',$size['mime'])];
                }
            } catch(\Throwable) { /* The prepared cover replaces the preview as soon as it is available. */ }
        }
        $message=$this->show($chat,$user,0,$station,$thread,'player',true,$preview);
        $photo=$this->bot->store->query('SELECT kind FROM radio_panels WHERE chat_id=? AND message_id=?',[$chat,$message])->fetchColumn()==='photo';
        if($photo&&isset($original['audio'])&&(int)($original['message_id']??0)>0&&(int)$original['message_id']!==$message) {
            try { $api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$original['message_id']]); }
            catch(\Throwable) { /* Keep the confirmed player if Telegram cannot remove the older audio. */ }
        }
    }
    public function show(int $chat,int $user,int $message,array $station,int $thread=0,?string $view=null,bool $force=true,?array $preview=null): int
    {
        $store=$this->bot->store; $ui=$this->bot->ui; $id=(int)$station['chat_id'];
        $station=$this->radio->station($id)??$station; $session=$this->radio->session($id);
        $rd=fn(string $op): string=>'rd:'.$op.':'.$id.':'.$session;
        $opening=$message===0;
        // Explicit requests must appear at the bottom of the chat. Reuse old artwork, never an old message ID.
        $previous=$opening?$store->query("SELECT * FROM radio_panels WHERE chat_id=? AND station_id=? AND thread_id=? AND view IN ('player','menu') AND expires>? ORDER BY updated DESC LIMIT 1",[$chat,$id,$thread,time()])->fetch():false;
        $saved=$message>0?$store->query('SELECT * FROM radio_panels WHERE chat_id=? AND message_id=?',[$chat,$message])->fetch():false;
        $reference=$saved?:$previous;
        $view??=in_array($saved['view']??'',['player','menu'],true)?$saved['view']:'player';
        if(!$force&&(!$saved||!in_array($saved['view'],['player','menu'],true))) return $message;
        if($opening&&$chat===$id&&(int)$store->query('SELECT pending FROM radio_sessions WHERE chat_id=? AND token=?',[$id,$session])->fetchColumn()===1) $view='menu';
        $thread=$thread>0?$thread:(int)($saved['thread_id']??0);
        $data=$this->radio->progress($id); $queue=$this->radio->queue($id,100);
        if(($data['session']??'0')!==$session) $data=[];
        if(!empty($data['id'])&&microtime(true)-(float)($data['at']??0)>15) $data=[];
        $preparing=empty($data['id'])&&!empty($queue);
        if($preparing) $data=$queue[0]+['elapsed'=>0,'at'=>microtime(true)];
        $duration=max(0,(int)($data['duration']??0));
        $elapsed=max(0,min($duration,(float)($data['elapsed']??0))); $paused=$this->radio->paused($id);
        if(!$preparing&&!$paused&&!empty($data['id'])) $elapsed=min($duration,$elapsed+max(0,min(1.5,microtime(true)-(float)($data['at']??microtime(true)))));
        $track=!empty($data['id'])?$ui->text('radio_player_track',['artist'=>mb_substr((string)$data['artists'],0,80),'title'=>mb_substr((string)$data['title'],0,100)]):$ui->text('radio_player_idle');
        $left=Ui::duration((int)$elapsed); $right=$duration>0?Ui::duration($duration):$ui->plain('radio_player_unknown');
        $done=$duration>0?max(0,min(14,(int)floor(14*$elapsed/$duration))):0;
        $bar=str_repeat('━',$done).'●'.str_repeat('─',14-$done);
        $lines=[]; $queue=array_values(array_filter($queue,fn($entry)=>(int)$entry['id']!==(int)($data['id']??0)));
        foreach(array_slice($queue,0,8) as $entry) $lines[]=$ui->text('radio_player_queue_row',['artist'=>mb_substr($entry['artists'],0,25),'title'=>mb_substr($entry['title'],0,35)]);
        if(count($queue)>8) $lines[]=$ui->text('radio_player_more',['count'=>count($queue)-8]);
        $queueText=$lines?$ui->text(count($queue)>3?'radio_player_queue_collapsed':'radio_player_queue',['tracks'=>implode("\n",$lines)],['tracks']):$ui->text('radio_player_empty');
        $state=$paused?$ui->text('radio_player_paused'):((int)$station['live']===0?$ui->text('radio_player_off'):($preparing?$ui->text('radio_player_preparing'):$ui->text(!empty($data['id'])?'radio_player_playing':'radio_player_waiting')));
        $mode=$this->radio->mode($id);
        // Frame values are plain text inside <pre>; custom track/status HTML must never become nested entities.
        $plain=fn(string $html): string=>html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $frame=$ui->text('radio_player_frame',['track'=>$plain($track),'elapsed'=>$left,'bar'=>$bar,'duration'=>$right,'state'=>$plain($state)]);
        $text=Ui::fitCaption($ui->text('radio_player_mode_'.$mode)."\n\n".$ui->text('radio_player_caption',['frame'=>$frame,'track'=>$track,'bar'=>$bar,'elapsed'=>$left,'duration'=>$right,'times'=>$left.str_repeat(' ',max(1,21-strlen($left)-strlen($right))).$right,'queue'=>$queueText,'state'=>$state],['frame','track','queue','state']));
        $row=[$this->button('radio_repeat_'.$mode,$rd('o')),$this->button('radio_previous',$rd('b')),$this->button($paused?'radio_resume':'radio_pause',$rd('t')),$this->button('radio_next',$rd('k')),$this->button('radio_menu',$rd('m'))];
        $rows=[$row];
        if($view==='menu') {
            $rows[]=[$this->button('radio_player_restart',$rd('j'))];
            $manager=$this->radio->mayManage($this->bot->api,$id,$user,$this->bot->admin($user));
            if($manager) $rows[]=[$this->button((int)$station['live']===1?'radio_mark_off':'radio_mark_live',$rd('l'))];
            $rows[]=[$this->button('radio_stop',$rd('s')),$this->button('radio_clear',$rd('x'))];
            $rows[]=[$this->button('radio_close',$rd('z')),$this->button('radio_refresh',$rd('v'))];
            if($chat>0) { $private=[$this->button('radio_use',$rd('a'))]; if($manager) $private[]=$this->button('radio_remove',$rd('d')); $rows[]=$private; $rows[]=[$this->button('radio_back',$rd('r'))]; }
        }
        $cover=(string)($data['cover']??''); $root=realpath($this->radio->root()); $path=$cover!==''?realpath($cover):false; $artworkId=(int)($reference['artwork_id']??0); $artwork=(string)($reference['artwork']??'');
        $display=(int)($data['id']??0);
        if($path!==false&&$root!==false&&str_starts_with($path,$root.'/files/')&&!is_link($cover)&&is_file($path)&&filesize($path)>0&&filesize($path)<=1048576) {
            $picture=new \CURLFile($path,'image/jpeg','cover.jpg'); $photoKey=$path.':'.(string)filemtime($path);
        } elseif($preview!==null&&(int)$preview['id']===$display) { $picture=$preview['file']; $photoKey=$preview['key']; }
        elseif($artwork!==''&&$artworkId===$display) { $picture=$artwork; $photoKey=preg_replace('/^blocked:/','',(string)$reference['photo_key']); }
        else { $cover=dirname(__DIR__).'/resources/radio-background.jpg'; $picture=new \CURLFile($cover,'image/jpeg','cover.jpg'); $photoKey='background'; }
        $blocked=!$force&&($saved['kind']??'')==='text'&&($saved['photo_key']??'')==='blocked:'.$photoKey;
        if($blocked) $photoKey='blocked:'.$photoKey;
        $hash=hash('sha256',json_encode([$text,$rows,$photoKey],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if(!$force&&$saved&&$hash===$saved['hash']) { $store->query('UPDATE radio_panels SET updated=? WHERE chat_id=? AND message_id=?',[time(),$chat,$message]); return $message; }
        $kind=$saved['kind']??'text'; $params=['chat_id'=>$chat,'reply_markup'=>['inline_keyboard'=>$rows]];
        if($chat<0&&$thread>0&&$message===0) $params['message_thread_id']=$thread;
        try {
            if(!$blocked&&($message===0||$kind!=='photo'||$photoKey!==($saved['photo_key']??''))) {
                $media=is_string($picture)?$picture:'attach://cover';
                if($message>0) $result=$this->bot->api->call('editMessageMedia',$params+['message_id'=>$message]+(is_string($picture)?[]:['cover'=>$picture])+['media'=>['type'=>'photo','media'=>$media,'caption'=>$text,'parse_mode'=>'HTML']]);
                else { $result=$this->bot->api->call('sendPhoto',$params+['photo'=>$picture,'caption'=>$text,'parse_mode'=>'HTML']); $message=(int)($result['message_id']??0); }
                $kind='photo';
                $photos=is_array($result)?($result['photo']??[]):[]; $artwork=$photos?(string)(end($photos)['file_id']??''):''; $artworkId=$display;
            } elseif($kind==='photo') $this->bot->api->call('editMessageCaption',$params+['message_id'=>$message,'caption'=>$text,'parse_mode'=>'HTML']);
            else $message=$this->bot->send($chat,$text,$rows,$message,$thread);
        } catch(\RuntimeException $e) {
            if($e->getCode()===404&&$message>0&&$force) {
                $this->forget($chat,$message);
                $preview??=$artwork!==''&&$artworkId===$display?['id'=>$display,'key'=>$photoKey,'file'=>$artwork]:null;
                return $this->show($chat,$user,0,$station,$thread,$view,true,$preview);
            }
            if(!in_array($e->getCode(),[400,403],true)) throw $e;
            error_log('Radio panel image rejected (code '.(int)$e->getCode().')');
            if($kind==='photo') {
                // A rejected replacement picture must not disable an otherwise working player.
                if($photoKey===($saved['photo_key']??'')) throw $e;
                $this->bot->api->call('editMessageCaption',$params+['message_id'=>$message,'caption'=>$text,'parse_mode'=>'HTML']);
                $photoKey=(string)($saved['photo_key']??'');
            } else { $message=$this->bot->send($chat,$text,$rows,$message,$thread); $photoKey='blocked:'.$photoKey; }
            $hash=hash('sha256',json_encode([$text,$rows,$photoKey],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        }
        if($message<1) throw new \RuntimeException('Missing radio panel message ID');
        if($this->radio->session($id)!==$session) {
            try { $this->bot->api->call('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>$message,'reply_markup'=>['inline_keyboard'=>[]]]); } catch(\Throwable) {}
            return $message;
        }
        $store->query("INSERT INTO radio_panels(chat_id,message_id,station_id,user_id,thread_id,kind,photo_key,view,hash,updated,expires) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(chat_id,message_id) DO UPDATE SET station_id=excluded.station_id,user_id=excluded.user_id,thread_id=excluded.thread_id,kind=excluded.kind,photo_key=excluded.photo_key,view=excluded.view,hash=excluded.hash,updated=excluded.updated,expires=excluded.expires",[$chat,$message,$id,$user,$thread,$kind,$photoKey,$view,$hash,time(),time()+86400]);
        $store->query('UPDATE radio_panels SET artwork_id=?,artwork=? WHERE chat_id=? AND message_id=?',[$artworkId,$artwork,$chat,$message]);
        if($opening) {
            // Retire older controls only after the new panel is confirmed. Other topics keep their own panels.
            $old=$store->query("SELECT message_id FROM radio_panels WHERE chat_id=? AND station_id=? AND thread_id=? AND message_id<>? AND view IN ('player','menu')",[$chat,$id,$thread,$message])->fetchAll(\PDO::FETCH_COLUMN);
            foreach($old as $older) {
                $this->forget($chat,(int)$older);
                try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$older]); } catch(\Throwable) {}
            }
        }
        if($opening&&$chat===$id&&$view==='menu') $store->query('UPDATE radio_sessions SET pending=0,due=0,tries=0 WHERE chat_id=? AND token=?',[$id,$session]);
        return $message;
    }
    public static function due(Store $store): bool
    {
        if($store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='radio_sessions'")->fetchColumn()
            &&$store->query('SELECT 1 FROM radio_sessions WHERE pending=1 AND due<=? LIMIT 1',[time()])->fetchColumn()) return true;
        return (bool)$store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='radio_panels'")->fetchColumn()
            &&(bool)$store->query("SELECT 1 FROM radio_panels WHERE view IN ('player','menu','expired') AND expires>? AND updated<=? LIMIT 1",[time(),time()-4])->fetchColumn();
    }
    public function refresh(): void
    {
        $lock=fopen($this->radio->root().'/panel.lock','c');
        if($lock===false) return;
        try { if(flock($lock,LOCK_EX|LOCK_NB)) $this->refreshPanels(); }
        finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private function refreshPanels(): void
    {
        $store=$this->bot->store; $store->query('DELETE FROM radio_panels WHERE expires<?',[time()]);
        $this->announceStreams();
        // Old keyboards are cosmetic cleanup: their session-bound callbacks are already invalid.
        foreach($store->query("SELECT chat_id,message_id FROM radio_panels WHERE view='expired' AND updated<=? LIMIT 4",[time()-4])->fetchAll() as $old) {
            try {
                $this->bot->api->call('editMessageReplyMarkup',['chat_id'=>(int)$old['chat_id'],'message_id'=>(int)$old['message_id'],'reply_markup'=>['inline_keyboard'=>[]]]);
                $store->query("UPDATE radio_panels SET view='static',station_id=0,updated=? WHERE chat_id=? AND message_id=? AND view='expired'",[time(),$old['chat_id'],$old['message_id']]);
            } catch(\RuntimeException $e) {
                if(in_array($e->getCode(),[400,403,404],true)) $this->forget((int)$old['chat_id'],(int)$old['message_id']);
                else $store->query('UPDATE radio_panels SET updated=? WHERE chat_id=? AND message_id=?',[time()+10,$old['chat_id'],$old['message_id']]);
            }
        }
        $touched=[];
        foreach($store->query("SELECT * FROM radio_panels WHERE view IN ('player','menu') AND updated<=? ORDER BY updated LIMIT 8",[time()-4])->fetchAll() as $panel) {
            $chat=(int)$panel['chat_id']; if(isset($touched[$chat])) continue; $touched[$chat]=true;
            $last=(int)$store->query("SELECT MAX(updated) FROM radio_panels WHERE chat_id=? AND view IN ('player','menu') AND expires>?",[$chat,time()])->fetchColumn();
            if($last>time()-4) continue;
            // Throttle the chat, but update only the chosen panel so other forum topics get their turn next time.
            $store->query('UPDATE radio_panels SET updated=? WHERE chat_id=? AND message_id=?',[time(),$chat,$panel['message_id']]);
            $message=(int)$panel['message_id']; $user=(int)$panel['user_id']; $station=$this->radio->station((int)$panel['station_id']);
            if(!$station||!$this->radio->mayControl($this->bot->api,(int)$station['chat_id'],$user,$this->bot->admin($user))) { $this->forget($chat,$message); continue; }
            try { $this->show($chat,$user,$message,$station,(int)$panel['thread_id'],$panel['view'],false); }
            catch(\RuntimeException $e) {
                if(in_array($e->getCode(),[400,403,404],true)) $this->forget($chat,$message);
                else $store->query('UPDATE radio_panels SET updated=? WHERE chat_id=? AND message_id=?',[time(),$chat,$message]);
            }
        }
    }
    /** Start announcements run in the worker helper, so picture/Telegram latency never blocks the service event. */
    private function announceStreams(): void
    {
        $store=$this->bot->store;
        foreach($store->query('SELECT * FROM radio_sessions WHERE pending=1 AND due<=? ORDER BY due LIMIT 4',[time()])->fetchAll() as $stream) {
            $id=(int)$stream['chat_id']; $station=$this->radio->station($id);
            if(!$station||!Radio::enabled($store)||!$store->groupsEnabled()) {
                $store->query('UPDATE radio_sessions SET pending=0 WHERE chat_id=? AND token=?',[$id,$stream['token']]); continue;
            }
            $owner=0;
            foreach(array_unique([(int)$station['owner'],...$store->administratorIds($this->bot->config->admins)]) as $user) {
                if($user>0&&$this->radio->mayControl($this->bot->api,$id,$user,$this->bot->admin($user))) { $owner=$user; break; }
            }
            if($this->radio->session($id)!==$stream['token']) continue;
            if($owner===0) { $store->query('UPDATE radio_sessions SET pending=0 WHERE chat_id=? AND token=?',[$id,$stream['token']]); continue; }
            try { $this->show($id,$owner,0,$station,(int)$stream['thread_id'],'menu'); }
            catch(\Throwable $e) {
                error_log('Radio stream panel failed: '.get_class($e).' (code '.(int)$e->getCode().')');
                $tries=(int)$stream['tries']+1;
                $store->query('UPDATE radio_sessions SET tries=?,due=?,pending=? WHERE chat_id=? AND token=?',[$tries,time()+min(60,5*(2**min(4,$tries))),$tries<5?1:0,$id,$stream['token']]);
            }
        }
    }
}
