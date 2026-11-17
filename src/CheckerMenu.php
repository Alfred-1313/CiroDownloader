<?php
declare(strict_types=1);
namespace Zero;

/** One registry for the shared command and its owner/chat/topic-bound choices. */
final class CheckerMenu
{
    private static ?\WeakMap $schemas=null;
    public const SERVICES=[
        'youtube'=>['checker'=>YoutubeChecker::class,'panel'=>YoutubeCheckerPanel::class],
        'instagram'=>['checker'=>InstagramChecker::class,'panel'=>InstagramCheckerPanel::class],
    ];
    public function __construct(private Bot $bot)
    {
        // A surviving callback may return here before the other checker has been opened after an upgrade.
        foreach(self::SERVICES as $entry) { $class=$entry['checker']; new $class($bot->config,$bot->store,$bot->api); }
        self::$schemas??=new \WeakMap();
        if(isset(self::$schemas[$bot->store])) return;
        $bot->store->db->exec('CREATE TABLE IF NOT EXISTS checker_menus(token TEXT PRIMARY KEY,owner INTEGER NOT NULL,chat_id INTEGER NOT NULL,thread_id INTEGER NOT NULL,message_id INTEGER NOT NULL DEFAULT 0,expires INTEGER NOT NULL)');
        self::$schemas[$bot->store]=true;
    }
    public function message(array $message,int $owner,int $chat,int $thread): bool
    {
        $text=trim((string)($message['text']??''));
        if($text!==$this->bot->label('checker')&&!preg_match('~^/checker(?:@([A-Za-z0-9_]+))?(?:\s+.*)?$~sD',$text,$m)) return false;
        if(!empty($m[1])&&strcasecmp($m[1],$this->bot->store->get('bot_username'))!==0) return true;
        $this->open($owner,$chat,$thread); return true;
    }
    public function open(int $owner,int $chat,int $thread=0,int $message=0): void
    {
        if(!$this->bot->allowed($owner,$chat)) { $this->bot->send($chat,$this->bot->denial($chat),[],0,$thread); return; }
        $s=$this->bot->store; $s->query('DELETE FROM checker_menus WHERE expires<?',[time()]);
        $token=bin2hex(random_bytes(8)); $slots=[];
        foreach(self::SERVICES as $service=>$entry) $slots[$service]=[$this->bot->ui->button('checker_menu_'.$service,'ch:'.$token.':'.$service)];
        $photo='';
        if($message>0) foreach(self::SERVICES as $entry) {
            $class=$entry['checker']; $model=new $class($this->bot->config,$s,$this->bot->api);
            $photo=$model->query('SELECT photo FROM {checker}panels WHERE owner=? AND chat_id=? AND message_id=?',[$owner,$chat,$message])->fetchColumn();
            if($photo) break;
        }
        $id=CheckerMessages::text($this->bot,['chat_id'=>$chat,'message_id'=>$message,'thread_id'=>$thread,'photo'=>$photo],$this->bot->text('checker_menu'),array_values($slots));
        $s->query('DELETE FROM checker_menus WHERE owner=? AND chat_id=? AND message_id=?',[$owner,$chat,$id]);
        $s->query('INSERT INTO checker_menus(token,owner,chat_id,thread_id,message_id,expires) VALUES(?,?,?,?,?,?)',[$token,$owner,$chat,$chat<0?$thread:0,$id,time()+3600]);
    }
    public function callback(array $cb,int $owner,int $chat,int $thread): void
    {
        $answer=fn(string $key)=>$this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$key===''?'':$this->bot->ui->plain($key),'show_alert'=>$key!=='','cache_time'=>0]);
        if(!preg_match('/^ch:([a-f0-9]{16}):([a-z_]+)$/D',(string)$cb['data'],$m)) { $answer('callback_stale'); return; }
        $p=$this->bot->store->query('SELECT * FROM checker_menus WHERE token=?',[$m[1]])->fetch();
        if($p&&(int)$p['owner']!==$owner) { $answer('callback_owner'); return; }
        $message=$cb['message']??[];
        if(!$p||(int)$p['expires']<=time()||(int)$p['chat_id']!==$chat||(int)$p['message_id']!==(int)($message['message_id']??0)
            ||(array_key_exists('message_thread_id',$message)&&(int)$p['thread_id']!==($chat<0?$thread:0))||!isset(self::SERVICES[$m[2]])) { $answer('callback_stale'); return; }
        if(!$this->bot->allowed($owner,$chat)) { $answer('callback_access'); return; }
        $answer('');
        $panel=self::SERVICES[$m[2]]['panel'];
        if((new $panel($this->bot))->open($owner,$chat,(int)$p['thread_id'],(int)$p['message_id']))
            $this->bot->store->query('DELETE FROM checker_menus WHERE token=?',[$p['token']]);
    }
}
