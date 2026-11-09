<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
final class PlayerPanelApi implements Zero\Api {
    public array $calls=[], $kinds=[], $admins=[55]; public bool $rejectCover=false; private int $next=900;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='getChatMember') return ['status'=>in_array((int)$params['user_id'],$this->admins,true)?'administrator':'member'];
        $id=(int)($params['message_id']??0);
        if($method==='editMessageText'&&($this->kinds[$id]??'text')==='photo') throw new RuntimeException('Cannot edit photo as text');
        if($method==='editMessageCaption'&&($this->kinds[$id]??'text')!=='photo') throw new RuntimeException('Cannot edit text as photo');
        if($method==='sendMessage'||$method==='sendPhoto') { $id=++$this->next; $this->kinds[$id]=$method==='sendPhoto'?'photo':'text'; }
        if($method==='editMessageMedia') { if($this->rejectCover) throw new RuntimeException('Rejected fixture photo',400); $this->kinds[$id]='photo'; }
        if(str_starts_with($method,'send')||str_starts_with($method,'edit')) return ['message_id'=>$id,'photo'=>[['file_id'=>'PANEL_PHOTO']]];
        return true;
    }
    public function last(string $method): ?array { foreach(array_reverse($this->calls) as [$name,$params]) if($name===$method) return $params; return null; }
}
function panelCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$temp=sys_get_temp_dir().'/ciro-radio-panel-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $api=new PlayerPanelApi(); $bot=new Zero\Bot($config,$store,$api); $radio=new Zero\Radio($config,$store);
    $radio->save(-1001,'Radio','group',55,'rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf'); $radio->setLive(-1001,true);
    mkdir($temp.'/radio/files',0700); $cover=$temp.'/radio/files/1.jpg'; $second=$temp.'/radio/files/2.jpg';
    foreach([$cover=>'red',$second=>'blue'] as $path=>$colour) (new Zero\Download($config))->run(['ffmpeg','-v','error','-y','-filter_threads','1','-f','lavfi','-i','color=c='.$colour.':s=640x360','-frames:v','1','-threads','1',$path],$temp,30);
    $song=['service'=>'spotify','m'=>['title'=>'Track','artists'=>['Artist'],'duration'=>194]];
    $ids=[]; for($i=0;$i<6;$i++) { $song['m']['title']='Track '.$i; $ids[]=$radio->enqueue(-1001,55,$song,'Track '.$i,'Artist <&>',194)['id']; }
    $store->query("UPDATE radio_queue SET status='playing',file='fixture',cover=? WHERE id=?",[$cover,$ids[0]]);
    $snapshot=['id'=>$ids[0],'title'=>'اثر <جدید>','artists'=>'خواننده & نام','elapsed'=>190,'duration'=>194,'cover'=>$cover,'paused'=>false,'at'=>microtime(true)];
    $save=function() use($store,&$snapshot): void { $snapshot['at']=microtime(true); $store->set('radio.progress.-1001',json_encode($snapshot,JSON_UNESCAPED_UNICODE)); }; $save();
    $panel=new Zero\RadioPanel($bot); $view=new Zero\RadioPlayerPanel($bot); $panel->commandGroup(-1001,55,321);
    $photo=$api->last('sendPhoto'); $message=(int)$store->query('SELECT message_id FROM radio_panels')->fetchColumn();
    panelCheck($photo!==null&&$photo['photo'] instanceof CURLFile&&(int)$photo['message_thread_id']===321,'the station opens as a cover photo with its caption in the original forum topic');
    $caption=$photo['caption']; $row=$photo['reply_markup']['inline_keyboard'][0];
    panelCheck(str_contains($caption,'<blockquote><b>خواننده &amp; نام')&&str_contains($caption,'اثر &lt;جدید&gt;')&&str_contains($caption,'<blockquote expandable>'),'artist/work and a long upcoming queue use safe native blockquotes');
    panelCheck(str_contains($caption,'03:10')&&str_contains($caption,'03:14')&&str_contains($caption,'●'),'elapsed, total duration and the moving progress bar are present');
    panelCheck(array_column($row,'text')===['⟳','⏮','⏸','⏭','☰']&&!array_filter($row,fn($button)=>isset($button['style'])),'the five transport controls have neutral inline styling');
    $click=function(string $op,int $user=55,int $chat=-1001,int $id=0) use($bot,$message): void {
        $bot->handle(['callback_query'=>['id'=>'panel'.random_int(1,99999),'from'=>['id'=>$user],'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$chat<0?321:0],'data'=>'rd:'.$op.':'.($id?:-1001)]]);
    };
    $click('t'); panelCheck($radio->paused(-1001)&&str_contains($api->last('editMessageCaption')['caption'],'موقتاً'),'pause toggles without replacing the photo or sending a new message');
    $before=count($api->calls); $store->query('UPDATE radio_panels SET updated=0'); $view->refresh();
    panelCheck(!array_filter(array_slice($api->calls,$before),fn($call)=>str_starts_with($call[0],'edit')),'an unchanged paused panel does not send redundant Telegram edits');
    $click('t'); panelCheck(!$radio->paused(-1001),'play resumes the same station');
    $click('o'); panelCheck($radio->mode(-1001)==='all','the mode switch enables playlist repeat');
    $click('o'); panelCheck($radio->mode(-1001)==='one','the next mode repeats one track');
    $click('o'); panelCheck($radio->mode(-1001)==='off','the next mode returns to normal playback');
    $click('b'); panelCheck($radio->station(-1001)['command']==='previous','Previous is passed to the player');
    $click('k'); panelCheck($radio->station(-1001)['command']==='skip','Next is passed to the player');
    $snapshot['id']=$ids[1]; $snapshot['title']='Next work'; $snapshot['duration']=240; $snapshot['elapsed']=3; $snapshot['cover']=$second; $save();
    $store->query('UPDATE radio_panels SET updated=0'); $view->refresh(); $edit=$api->last('editMessageMedia');
    panelCheck($edit!==null&&(int)$edit['message_id']===$message&&$edit['cover'] instanceof CURLFile&&str_contains($edit['media']['caption'],'04:00'),'a new track changes the cover, title and both times in the same message');
    $snapshot['cover']=$cover; $save(); $api->rejectCover=true;
    $view->show(-1001,55,$message,$radio->station(-1001),321);
    panelCheck($store->query('SELECT kind FROM radio_panels WHERE chat_id=-1001')->fetchColumn()==='photo'&&str_contains($api->last('editMessageCaption')['caption'],'Next work'),'a refused replacement cover preserves the photo panel and updates its controls and caption');
    $api->rejectCover=false;
    $view->show(-1001,55,$message,$radio->station(-1001),321);
    panelCheck($api->last('editMessageMedia')['cover']->getFilename()===$cover,'a later refresh retries the replacement cover after a transient refusal');
    $other=$view->show(-1001,55,0,$radio->station(-1001),322); $snapshot['elapsed']=80; $save();
    $store->query('UPDATE radio_panels SET updated=0 WHERE chat_id=-1001'); $view->refresh(); $before=count($api->calls); $view->refresh();
    panelCheck(count($api->calls)===$before,'multiple topic panels still share the four-second per-chat edit limit');
    $store->query('UPDATE radio_panels SET updated=? WHERE chat_id=-1001 AND message_id=?',[time()-5,$message]); $view->refresh();
    panelCheck((int)$api->last('editMessageCaption')['message_id']===$other,'the next refresh reaches the other topic instead of starving it behind the first panel');
    $view->forget(-1001,$other);
    $click('m'); $rows=$api->last('editMessageCaption')['reply_markup']['inline_keyboard']; $actions=array_column(array_merge(...$rows),'callback_data');
    panelCheck(in_array('rd:x:-1001',$actions,true)&&in_array('rd:z:-1001',$actions,true)&&in_array('rd:v:-1001',$actions,true),'the menu exposes clear, close and refresh alongside the player');
    $paused=$radio->paused(-1001); $mode=$radio->mode(-1001); $click('t',66); $click('o',66); $click('b',66);
    panelCheck($paused===$radio->paused(-1001)&&$mode===$radio->mode(-1001),'ordinary members cannot steer the player or change repeat mode');
    $radio->save(-1002,'Other','group',55,'rtmps://dc4-1.rtmp.t.me/s/1234567890:FakeKeyForTestsOnly_AbCdEf'); $click('t',55,-1001,-1002);
    panelCheck(!$radio->paused(-1002),'forged cross-chat controls cannot steer another radio');
    $click('z'); panelCheck((int)$store->query('SELECT COUNT(*) FROM radio_panels WHERE chat_id=-1001')->fetchColumn()===0,'closing unsubscribes the panel from background updates');
    $view->show(42,42,0,$radio->station(-1001)); $privateMessage=(int)$store->query('SELECT message_id FROM radio_panels WHERE chat_id=42')->fetchColumn();
    (new Zero\RadioPanel($bot))->panel(42,42,$privateMessage);
    panelCheck($api->last('editMessageCaption')!==null&&$store->query('SELECT view FROM radio_panels WHERE chat_id=42')->fetchColumn()==='static','Back renders the private station list as a photo caption and stops timer edits');
    $view->show(-1001,55,0,$radio->station(-1001),321); $api->admins=[]; $store->query('DELETE FROM settings WHERE key=?',['radio.admin.-1001.55']); $store->query('UPDATE radio_panels SET updated=0'); $view->refresh();
    panelCheck((int)$store->query("SELECT COUNT(*) FROM radio_panels WHERE chat_id=-1001")->fetchColumn()===0,'background refresh rechecks authorization and removes revoked panels');
    foreach(['radio_player_caption','radio_player_track','radio_player_queue_collapsed'] as $key) panelCheck($bot->ui->customizable('text',$key),'the new public caption is editable: '.$key);
    foreach(['radio_pause','radio_resume','radio_previous','radio_next','radio_menu','radio_repeat_all'] as $key) panelCheck($bot->ui->customizable('button',$key),'the public transport button is editable: '.$key);
    $clock=new Zero\RadioPlayback($store,-1001); $item=$store->query('SELECT * FROM radio_queue WHERE id=?',[$ids[0]])->fetch(); $clock->start($item,0); $clock->silence(3,2); $clock->tick(6,false);
    panelCheck(abs($radio->progress(-1001)['elapsed']-4)<0.01,'the presentation clock excludes paused silence from elapsed track time');
} finally { Zero\Download::clean($temp); }
