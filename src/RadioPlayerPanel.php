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
    public function leave(int $chat,int $message,string $text,array $rows): void
    {
        $kind=$this->bot->store->query('SELECT kind FROM radio_panels WHERE chat_id=? AND message_id=?',[$chat,$message])->fetchColumn();
        if($kind==='photo') $this->bot->api->call('editMessageCaption',['chat_id'=>$chat,'message_id'=>$message,'caption'=>Ui::fitCaption($text),'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>$rows]]);
        else $this->bot->send($chat,$text,$rows,$message);
        $this->bot->store->query("UPDATE radio_panels SET view='static',station_id=0 WHERE chat_id=? AND message_id=?",[$chat,$message]);
    }
    public function menu(int $chat,int $message): string
    {
        $view=$this->bot->store->query('SELECT view FROM radio_panels WHERE chat_id=? AND message_id=?',[$chat,$message])->fetchColumn();
        return $view==='menu'?'player':'menu';
    }
    public function show(int $chat,int $user,int $message,array $station,int $thread=0,?string $view=null,bool $force=true): void
    {
        $store=$this->bot->store; $ui=$this->bot->ui; $id=(int)$station['chat_id'];
        $station=$this->radio->station($id)??$station;
        if($message===0) $message=(int)$store->query("SELECT message_id FROM radio_panels WHERE chat_id=? AND station_id=? AND thread_id=? AND view IN ('player','menu') AND expires>? ORDER BY updated DESC LIMIT 1",[$chat,$id,$thread,time()])->fetchColumn();
        $saved=$message>0?$store->query('SELECT * FROM radio_panels WHERE chat_id=? AND message_id=?',[$chat,$message])->fetch():false;
        $view??=in_array($saved['view']??'',['player','menu'],true)?$saved['view']:'player';
        $thread=$thread>0?$thread:(int)($saved['thread_id']??0);
        $data=$this->radio->progress($id); $duration=max(0,(int)($data['duration']??0));
        $elapsed=max(0,min($duration,(float)($data['elapsed']??0))); $paused=$this->radio->paused($id);
        if(!$paused&&!empty($data['id'])) $elapsed=min($duration,$elapsed+max(0,min(1.5,microtime(true)-(float)($data['at']??microtime(true)))));
        $track=!empty($data['id'])?$ui->text('radio_player_track',['artist'=>mb_substr((string)$data['artists'],0,80),'title'=>mb_substr((string)$data['title'],0,100)]):$ui->text('radio_player_idle');
        $left=Ui::duration((int)$elapsed); $right=$duration>0?Ui::duration($duration):$ui->plain('radio_player_unknown');
        $done=$duration>0?max(0,min(20,(int)floor(20*$elapsed/$duration))):0;
        $bar=str_repeat('━',$done).'●'.str_repeat('─',20-$done);
        $lines=[]; $queue=array_values(array_filter($this->radio->queue($id,100),fn($entry)=>(int)$entry['id']!==(int)($data['id']??0)));
        foreach(array_slice($queue,0,8) as $entry) $lines[]=$ui->text('radio_player_queue_row',['artist'=>mb_substr($entry['artists'],0,25),'title'=>mb_substr($entry['title'],0,35)]);
        if(count($queue)>8) $lines[]=$ui->text('radio_player_more',['count'=>count($queue)-8]);
        $queueText=$lines?$ui->text(count($queue)>3?'radio_player_queue_collapsed':'radio_player_queue',['tracks'=>implode("\n",$lines)],['tracks']):$ui->text('radio_player_empty');
        $state=$paused?$ui->text('radio_player_paused'):((int)$station['live']===0?$ui->text('radio_status_off'):'');
        $text=Ui::fitCaption($ui->text('radio_player_caption',['track'=>$track,'bar'=>$bar,'times'=>$left.str_repeat(' ',max(1,21-strlen($left)-strlen($right))).$right,'queue'=>$queueText,'state'=>$state],['track','queue','state']));
        $mode=$this->radio->mode($id); $playing=!$paused&&($station['command']??'')!=='stop'&&!empty($data['id']);
        $row=[$this->button('radio_repeat_'.$mode,'rd:o:'.$id),$this->button('radio_previous','rd:b:'.$id),$this->button($playing?'radio_pause':'radio_resume','rd:t:'.$id),$this->button('radio_next','rd:k:'.$id),$this->button('radio_menu','rd:m:'.$id)];
        $rows=[$row];
        if($view==='menu') {
            $rows[]=[$this->button((int)$station['live']===1?'radio_mark_off':'radio_mark_live','rd:l:'.$id)];
            $rows[]=[$this->button('radio_stop','rd:s:'.$id),$this->button('radio_clear','rd:x:'.$id)];
            $rows[]=[$this->button('radio_close','rd:z:'.$id),$this->button('radio_refresh','rd:v:'.$id)];
            if($chat>0) { $rows[]=[$this->button('radio_use','rd:a:'.$id),$this->button('radio_remove','rd:d:'.$id)]; $rows[]=[$this->button('radio_back','rd:r:'.$id)]; }
        }
        $cover=(string)($data['cover']??''); $root=realpath($this->radio->root()); $path=$cover!==''?realpath($cover):false;
        if($path===false||$root===false||!str_starts_with($path,$root.'/files/')||is_link($cover)||!is_file($path)||filesize($path)>1048576) $cover=$this->radio->root().'/panel-background.jpg';
        $hasCover=is_file($cover)&&filesize($cover)>0&&filesize($cover)<1048576;
        $photoKey=$hasCover?$cover.':'.(string)filemtime($cover):'';
        $hash=hash('sha256',json_encode([$text,$rows,$photoKey],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if(!$force&&$saved&&$hash===$saved['hash']) { $store->query('UPDATE radio_panels SET updated=? WHERE chat_id=? AND message_id=?',[time(),$chat,$message]); return; }
        $kind=$saved['kind']??'text'; $params=['chat_id'=>$chat,'reply_markup'=>['inline_keyboard'=>$rows]];
        if($chat<0&&$thread>0&&$message===0) $params['message_thread_id']=$thread;
        try {
            if($hasCover&&($message===0||$kind!=='photo'||$photoKey!==($saved['photo_key']??''))) {
                $file=new \CURLFile($cover,'image/jpeg','cover.jpg');
                if($message>0) $result=$this->bot->api->call('editMessageMedia',$params+['message_id'=>$message,'cover'=>$file,'media'=>['type'=>'photo','media'=>'attach://cover','caption'=>$text,'parse_mode'=>'HTML']]);
                else { $result=$this->bot->api->call('sendPhoto',$params+['photo'=>$file,'caption'=>$text,'parse_mode'=>'HTML']); $message=(int)($result['message_id']??0); }
                $kind='photo';
            } elseif($kind==='photo') $this->bot->api->call('editMessageCaption',$params+['message_id'=>$message,'caption'=>$text,'parse_mode'=>'HTML']);
            else $message=$this->bot->send($chat,$text,$rows,$message,$thread);
        } catch(\RuntimeException $e) {
            if($e->getCode()!==400||!$hasCover) throw $e;
            if($kind==='photo') {
                // A rejected replacement picture must not disable an otherwise working player.
                if($photoKey===($saved['photo_key']??'')) throw $e;
                $this->bot->api->call('editMessageCaption',$params+['message_id'=>$message,'caption'=>$text,'parse_mode'=>'HTML']);
                $photoKey=(string)($saved['photo_key']??'');
            } else { $message=$this->bot->send($chat,$text,$rows,$message,$thread); $photoKey=''; }
            $hash=hash('sha256',json_encode([$text,$rows,$photoKey],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        }
        if($message<1) throw new \RuntimeException('Missing radio panel message ID');
        $store->query("INSERT INTO radio_panels(chat_id,message_id,station_id,user_id,thread_id,kind,photo_key,view,hash,updated,expires) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(chat_id,message_id) DO UPDATE SET station_id=excluded.station_id,user_id=excluded.user_id,thread_id=excluded.thread_id,kind=excluded.kind,photo_key=excluded.photo_key,view=excluded.view,hash=excluded.hash,updated=excluded.updated,expires=excluded.expires",[$chat,$message,$id,$user,$thread,$kind,$photoKey,$view,$hash,time(),time()+86400]);
    }
    public function refresh(): void
    {
        $store=$this->bot->store; $store->query('DELETE FROM radio_panels WHERE expires<?',[time()]);
        $touched=[];
        foreach($store->query("SELECT * FROM radio_panels WHERE view IN ('player','menu') AND updated<=? ORDER BY updated LIMIT 8",[time()-4])->fetchAll() as $panel) {
            $chat=(int)$panel['chat_id']; if(isset($touched[$chat])) continue; $touched[$chat]=true;
            $store->query('UPDATE radio_panels SET updated=? WHERE chat_id=?',[time(),$chat]);
            $message=(int)$panel['message_id']; $user=(int)$panel['user_id']; $station=$this->radio->station((int)$panel['station_id']);
            if(!$station||!$this->radio->mayControl($this->bot->api,(int)$station['chat_id'],$user,$this->bot->admin($user))) { $this->forget($chat,$message); continue; }
            try { $this->show($chat,$user,$message,$station,(int)$panel['thread_id'],$panel['view'],false); }
            catch(\RuntimeException $e) {
                if(in_array($e->getCode(),[400,403,404],true)) $this->forget($chat,$message);
                else $store->query('UPDATE radio_panels SET updated=? WHERE chat_id=? AND message_id=?',[time(),$chat,$message]);
            }
        }
    }
}
