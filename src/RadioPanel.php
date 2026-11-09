<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/RadioPlayerPanel.php';

/**
 * Everything a person sees and presses for the radio (see Radio): the ▶️ button under a sent song, "play all" in
 * a menu, the private /radio panel that sets a station up, and the controls message (skip, stop, clear) in a group or the
 * panel. Only administrators of the chat (and of the bot) may use any of it; the buttons themselves are visible to
 * everybody in a group, so every press is checked again.
 *
 * Callback data is "rd:OP:ARG": p play (ARG a 16-character song token), v station panel, k skip, s stop, x clear,
 * r refresh, z close, u set up (ARG a chat ID), d ask to remove, D remove, a use this station for the private ▶️.
 */
final class RadioPanel
{
    private Radio $radio;
    public function __construct(private Bot $bot) { $this->radio=new Radio($bot->config,$bot->store); }

    // ---- Where a button leads ----------------------------------------------------------------------------------------

    /** The station a button in this chat is for: the group's own, or in a private chat the one this person works with. */
    /** The refusal for somebody who may not use the radio: bot administrators only, or the chat's administrators. */
    private function refusal(): string { return Radio::botOnly($this->bot->store)?'radio_bot_only':'radio_not_admin'; }
    /** May this person set the radio of $target up (and see it)? */
    private function mayManage(int $target,int $user): bool
    {
        if(!Radio::enabled($this->bot->store)) return false;
        return $this->bot->admin($user)||(!Radio::botOnly($this->bot->store)&&Radio::chatAdmin($this->bot->api,$this->bot->store,$target,$user));
    }
    private function stationFor(int $chat,int $user): ?array
    {
        if(!Radio::enabled($this->bot->store)) return null;
        return $chat<0?$this->radio->station($chat):$this->radio->activeFor($this->bot->api,$user,$this->bot->admin($user));
    }
    /** Whether a song sent here gets a ▶️: this group has a radio, or this person runs one. */
    public function available(int $chat,int $user): bool
    {
        if($this->radio->stations()===[]) return false;
        return $this->stationFor($chat,$user)!==null;
    }
    /** Whether this person may queue songs here right now (administrator of that radio's chat). */
    public function allowed(int $chat,int $user): bool
    {
        $station=$this->stationFor($chat,$user);
        return $station!==null&&$this->radio->mayControl($this->bot->api,(int)$station['chat_id'],$user,$this->bot->admin($user));
    }
    /** The ▶️ row under one sent song, or nothing. $source is {"service":..,"u":..} or {"service":..,"m":..}. */
    public function row(array $source,string $title,string $artists,int $duration): array
    {
        $token=$this->radio->pick($source,$title,$artists,$duration);
        return [$this->bot->ui->button('radio_play','rd:p:'.$token)];
    }
    /** Queue the songs of a menu list for a person who just pressed "play all". @return string a text key for the answer */
    public function enqueueMany(int $chat,int $user,array $sources): string
    {
        $station=$this->stationFor($chat,$user);
        if(!Radio::enabled($this->bot->store)) return 'radio_disabled';
        if($station===null) return 'radio_no_station';
        if(!$this->radio->mayControl($this->bot->api,(int)$station['chat_id'],$user,$this->bot->admin($user))) return $this->refusal();
        $added=0;
        foreach($sources as $song) {
            $result=$this->radio->enqueue((int)$station['chat_id'],$user,$song['source'],(string)$song['title'],(string)$song['artists'],(int)$song['duration']);
            if(is_array($result)) $added++; elseif($result==='full') break;
        }
        if($added===0) return 'radio_full';
        $this->answerVars=['count'=>(string)$added,'chat'=>(string)$station['title']];
        return 'radio_added_many';
    }
    /** Variables for the text key returned by enqueueMany. */
    public array $answerVars=[];

    // ---- Callbacks -----------------------------------------------------------------------------------------------------

    private function answer(array $cb,string $key='',array $vars=[],bool $alert=false): void
    {
        $params=['callback_query_id'=>$cb['id'],'cache_time'=>0];
        if($key!=='') { $params['text']=mb_substr($this->bot->ui->plainWith($key,$vars),0,200); $params['show_alert']=$alert; }
        try { $this->bot->api->call('answerCallbackQuery',$params); } catch(\Throwable) { /* A stale query cannot be answered. */ }
    }

    public function callback(array $update,int $chat,int $user,int $thread,int $message): void
    {
        $cb=$update['callback_query'];
        if(preg_match('/^rd:([A-Za-z]):(-?\d{1,14}|[A-Za-z0-9_-]{16})$/D',(string)($cb['data']??''),$match)!==1) { $this->answer($cb); return; }
        [, $op, $arg]=$match; $botAdmin=$this->bot->admin($user);
        try {
            if($op==='p') { $this->play($cb,$chat,$user,$arg,$thread); return; }
            if(!preg_match('/^-?\d+$/D',$arg)) { $this->answer($cb); return; }
            $target=(int)$arg;
            // Closing a message: in a private chat it is the person's own; in a group only the radio's administrators may.
            if($op==='z'&&$chat>0) { (new RadioPlayerPanel($this->bot))->forget($chat,$message); $this->answer($cb); try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); } catch(\Throwable) {} return; }
            if($op==='r'&&$chat>0) { $this->answer($cb); $this->panel($chat,$user,$message); return; }
            // Setting up and the private panel belong to a private chat; the controls to the chat of the station or the panel.
            $station=$this->radio->station($target);
            if($op==='u') {
                if(!Radio::enabled($this->bot->store)) { $this->answer($cb,'radio_disabled',[],true); return; }
                if($chat<0||!$this->mayManage($target,$user)) { $this->answer($cb,$this->refusal(),[],true); return; }
                $this->askKey($cb,$chat,$user,$target); return;
            }
            if(!Radio::enabled($this->bot->store)) { $this->answer($cb,'radio_disabled',[],true); return; }
            if($station===null||!$this->radio->mayControl($this->bot->api,$target,$user,$botAdmin)) { $this->answer($cb,$station===null?'radio_no_station':$this->refusal(),[],true); return; }
            if($chat<0&&$target!==$chat) { $this->answer($cb,$this->refusal(),[],true); return; }
            switch($op) {
                case 't': $this->radio->togglePause($target); $this->answer($cb,'radio_done'); break;
                case 'o': $mode=$this->radio->cycleMode($target); $this->answer($cb,'radio_repeat_notice_'.$mode); break;
                case 'b': $this->radio->previous($target,$user); $this->answer($cb,'radio_done'); break;
                case 'm': $this->answer($cb); (new RadioPlayerPanel($this->bot))->show($chat,$user,$message,$station,$thread,(new RadioPlayerPanel($this->bot))->menu($chat,$message)); return;
                case 'k': $this->radio->command($target,'skip'); $this->answer($cb,'radio_done'); break;
                case 's': $this->radio->command($target,'stop'); $this->answer($cb,'radio_done'); break;
                case 'x': $this->radio->clear($target); $this->answer($cb,'radio_done'); break;
                case 'a': $this->radio->choose($user,$target); $this->answer($cb,'radio_done'); break;
                // When the bot missed the start (or end) of the stream, an administrator says so by hand.
                case 'l': $this->radio->setLive($target,(int)$station['live']!==1); $this->answer($cb,'radio_done'); break;
                case 'z': (new RadioPlayerPanel($this->bot))->forget($chat,$message); $this->answer($cb); try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); } catch(\Throwable) {} return;
                case 'd': $this->answer($cb); $this->confirmRemove($chat,$message,$station); return;
                case 'D': $this->radio->remove($target); $this->answer($cb,'radio_removed'); $this->panel($chat,$user,$message); return;
                default: $this->answer($cb);
            }
            $this->status($chat,$user,$message,$station,$thread);
        } catch(\Throwable $e) {
            error_log('Radio callback failed: '.get_class($e));
            $this->answer($cb,'radio_failed',[],true);
        }
    }

    /** A ▶️ under a song: queue it for this chat's radio (or the person's own radio in a private chat). */
    private function play(array $cb,int $chat,int $user,string $token,int $thread): void
    {
        if(!Radio::enabled($this->bot->store)) { $this->answer($cb,'radio_disabled',[],true); return; }
        $station=$this->stationFor($chat,$user);
        if($station===null) { $this->answer($cb,'radio_no_station',[],true); return; }
        if(!$this->radio->mayControl($this->bot->api,(int)$station['chat_id'],$user,$this->bot->admin($user))) { $this->answer($cb,$this->refusal(),[],true); return; }
        $song=$this->radio->picked($token);
        if($song===null) { $this->answer($cb,'radio_expired',[],true); return; }
        $result=$this->radio->enqueue((int)$station['chat_id'],$user,$song['source'],$song['title'],$song['artists'],$song['duration']);
        if(is_string($result)&&$result!=='queued') { $this->answer($cb,match($result) {'long'=>'radio_long','full'=>'radio_full',default=>'radio_expired'},[],true); return; }
        if($result==='queued') $this->answer($cb,'radio_already');
        else $this->answer($cb,(int)$station['live']===1?'radio_added':'radio_added_idle',['chat'=>(string)$station['title'],'position'=>(string)$result['position']],false);
        $item=is_array($result)?(int)$result['id']:0;
        if($item===0) foreach($this->radio->queue((int)$station['chat_id'],100) as $entry) if(json_decode($entry['source'],true)===$song['source']) { $item=(int)$entry['id']; break; }
        (new RadioPlayerPanel($this->bot))->openFromAudio($chat,$user,$station,$cb['message']??[],$thread,$item);
    }

    // ---- Screens -------------------------------------------------------------------------------------------------------

    private function stateText(array $station): string { return $this->bot->ui->plain((int)$station['live']===1?'radio_state_live':'radio_state_off'); }

    /** The private /radio panel: the radios this person runs, and chats where a stream was seen that they could set up. */
    public function panel(int $chat,int $user,int $message=0): void
    {
        $ui=$this->bot->ui; $botAdmin=$this->bot->admin($user); $rows=[]; $lines=[];
        foreach($this->radio->stationsOf($this->bot->api,$user,$botAdmin) as $station) {
            $lines[]=$ui->text('radio_panel_row',['state'=>$this->stateText($station),'chat'=>(string)$station['title'],'count'=>(string)$this->radio->queued((int)$station['chat_id'])]);
            $rows[]=[$ui->button('radio_open','rd:v:'.$station['chat_id'],['state'=>$this->stateText($station),'chat'=>(string)$station['title']])];
        }
        foreach(Radio::enabled($this->bot->store)?$this->radio->candidates():[] as $seen) {
            if(!$this->mayManage((int)$seen['chat_id'],$user)) continue;
            $rows[]=[$ui->button('radio_setup','rd:u:'.$seen['chat_id'],['chat'=>(string)$seen['title']])];
        }
        $rows[]=[$ui->button('radio_close','rd:z:'.$chat)];
        $text=$ui->text('radio_panel',['list'=>$lines?implode("\n",$lines):$ui->plain('radio_panel_none'),'hint'=>$lines?'':$ui->text('radio_panel_hint')],['list','hint']);
        (new RadioPlayerPanel($this->bot))->leave($chat,$message,$text,$rows);
    }
    /** The controls of one radio in place of $message (or as a new message when it is 0). */
    public function status(int $chat,int $user,int $message,array $station,int $thread=0): void
    {
        (new RadioPlayerPanel($this->bot))->show($chat,$user,$message,$station,$thread);
    }
    private function confirmRemove(int $chat,int $message,array $station): void
    {
        $ui=$this->bot->ui;
        (new RadioPlayerPanel($this->bot))->leave($chat,$message,$ui->text('radio_remove_confirm',['chat'=>(string)$station['title']]),[[$ui->button('radio_remove_yes','rd:D:'.$station['chat_id'])],[$ui->button('radio_back','rd:v:'.$station['chat_id'])]]);
    }

    // ---- Commands --------------------------------------------------------------------------------------------------------

    /** /radio in a private chat. */
    public function commandPrivate(int $chat,int $user): void
    {
        if(!Radio::enabled($this->bot->store)) { $this->bot->send($chat,$this->bot->text('radio_disabled')); return; }
        $this->panel($chat,$user,0);
    }
    /** /radio in a group: the controls to the group's administrators, a short word to anyone else. */
    public function commandGroup(int $chat,int $user,int $thread): void
    {
        if(!Radio::enabled($this->bot->store)) { $this->bot->send($chat,$this->bot->text('radio_disabled'),[],0,$thread); return; }
        $station=$this->radio->station($chat);
        if($station===null) { $this->bot->send($chat,$this->bot->text('radio_group_none'),[],0,$thread); return; }
        if(!$this->radio->mayControl($this->bot->api,$chat,$user,$this->bot->admin($user))) { $this->bot->send($chat,$this->bot->text($this->refusal()),[],0,$thread); return; }
        $this->status($chat,$user,0,$station,$thread);
    }

    // ---- Setting a station up --------------------------------------------------------------------------------------------

    private function askKey(array $cb,int $chat,int $user,int $target): void
    {
        $seen=$this->bot->store->query('SELECT title FROM radio_seen WHERE chat_id=?',[$target])->fetchColumn();
        $title=is_string($seen)&&$seen!==''?$seen:(string)($this->radio->station($target)['title']??'');
        $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'radio.key:'.$target,time()+600]);
        $this->answer($cb);
        $this->bot->send($chat,$this->bot->text('radio_key_prompt',['chat'=>$title]));
    }
    /** The message that follows "set up": the stream address and key. It is deleted at once, valid or not. */
    public function receiveKey(int $chat,int $user,array $message,int $target): void
    {
        $text=(string)($message['text']??'');
        try { if(isset($message['message_id'])) $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$message['message_id']]); } catch(\Throwable) { /* It stays if Telegram refuses; the key is still not logged. */ }
        $address=Radio::parseTarget($text);
        if($address===null) { $this->bot->send($chat,$this->bot->text('radio_key_invalid')); return; }
        if(!$this->mayManage($target,$user)) { $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]); $this->bot->send($chat,$this->bot->text(Radio::enabled($this->bot->store)?$this->refusal():'radio_disabled')); return; }
        $seen=$this->bot->store->query('SELECT * FROM radio_seen WHERE chat_id=?',[$target])->fetch();
        $title=(string)($seen['title']??''); $type=(string)($seen['type']??'group');
        if($seen===false) {
            try { $info=$this->bot->api->call('getChat',['chat_id'=>$target]); $title=(string)($info['title']??''); $type=($info['type']??'')==='channel'?'channel':'group'; } catch(\Throwable) {}
        }
        $this->radio->save($target,$title!==''?$title:(string)$target,$type,$user,$address);
        $this->radio->choose($user,$target);
        $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        $this->bot->send($chat,$this->bot->text('radio_key_saved',['chat'=>$title]));
        $this->panel($chat,$user,0);
    }
}
