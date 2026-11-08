<?php
declare(strict_types=1);
namespace Zero;

// The bot only queues fixed actions. A separate host service owns Docker/Git access.
final class Updates
{
    private Ui $ui;
    public function __construct(private Config $config, private Store $store, private Api $api,private int $thread=0) { $this->ui=new Ui($store); }

    private function authorized(int $chat,int $user): bool
    {
        return $this->store->isAdmin($user,$this->config->admins)&&($chat===$user||($chat<0&&$this->store->groupsEnabled()));
    }
    private function scope(int $chat,int $user): string { return $chat<0?$user.'.'.$chat.'.'.max(0,$this->thread):(string)$user; }
    private function key(string $name,int $chat,int $user): string { return 'update_'.$name.'.'.$this->scope($chat,$user); }
    public function ownsMenu(int $chat,int $user,int $message): bool
    {
        return $this->authorized($chat,$user)&&$message>0&&$message===(int)$this->store->get($this->key('menu',$chat,$user),'0');
    }
    /** Groups only get Close (it deletes the menu); the private menu has Back to the panel and Close. */
    private function back(int $chat): array
    {
        return $chat<0?[$this->ui->button('close_update','update:back')]:[$this->ui->button('back_admin','update:back'),$this->ui->button('update_close','update:close')];
    }
    public function close(int $chat,int $user,int $message): void
    {
        if(!$this->ownsMenu($chat,$user,$message)||$this->active()) return;
        $this->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]);
        $this->store->set($this->key('menu',$chat,$user),'0');
        $this->store->set($this->key('menu_hash',$chat,$user),'');
    }

    public static function version(): string
    {
        $file=dirname(__DIR__).'/VERSION';
        return is_readable($file)?trim((string)file_get_contents($file)):'نامشخص';
    }

    private function active(): array|false
    {
        return $this->store->query("SELECT * FROM update_requests WHERE status IN ('queued','running') ORDER BY id LIMIT 1")->fetch();
    }

    private function view(int $chat, int $user, string $text, array $rows, int $message = 0, bool $new = false): int
    {
        $message=$new?0:($message?:((int)$this->store->get($this->key('menu',$chat,$user),'0')));
        $params=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true,
            'reply_markup'=>['inline_keyboard'=>$rows]];
        $hash=hash('sha256',json_encode($params,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if($message>0&&$message===(int)$this->store->get($this->key('menu',$chat,$user),'0')&&$hash===$this->store->get($this->key('menu_hash',$chat,$user))) return $message;
        if($message>0) {
            $params['message_id']=$message;
            try { $this->api->call('editMessageText',$params); }
            catch(\RuntimeException $e) {
                if($e->getCode()!==404) throw $e;
                $message=0; unset($params['message_id']); // The user deleted the old menu.
            }
        }
        if($message===0) {
            if($chat<0&&$this->thread>0) $params['message_thread_id']=$this->thread;
            $result=$this->api->call('sendMessage',$params);
            if(!is_array($result)||empty($result['message_id'])) throw new \RuntimeException('Missing update menu message ID');
            $message=(int)$result['message_id'];
        }
        $this->store->set($this->key('menu',$chat,$user),(string)$message);
        $this->store->set($this->key('menu_hash',$chat,$user),$hash);
        return $message;
    }

    /** @return list<string|array{title:string,what?:string,example?:string,default?:string,path?:list<string>}> */
    private function freshChanges(): array
    {
        // Rollback metadata is not a reliable record of the version being upgraded.
        $data=json_decode($this->store->get('update_new_changes'),true);
        if(!is_array($data)||($data['to']??'')!==self::version()||!is_array($data['changes']??null)) return [];
        return array_values(array_filter($data['changes'],fn($item)=>is_string($item)||(is_array($item)&&is_string($item['title']??null)&&$item['title']!=='')));
    }
    /** One entry of the what's-new list: what it is, an example, its default and the steps to find it. */
    public static function renderChange(Ui $ui,string|array $change,int $position): string
    {
        if(is_string($change)) return '• '.Ui::escape(mb_substr($change,0,240));
        $keycaps=['1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣'];
        $lines=[$ui->text('update_item',['n'=>$keycaps[$position-1]??'▫️','title'=>mb_substr($change['title'],0,80),'what'=>mb_substr((string)($change['what']??''),0,220)])];
        if(!empty($change['example'])&&is_string($change['example'])) $lines[]=$ui->text('update_item_example',['example'=>mb_substr($change['example'],0,200)]);
        if(in_array($change['default']??'',['on','off'],true)) $lines[]=$ui->text('update_item_default',['state'=>$ui->plain($change['default']==='on'?'update_state_on':'update_state_off')]);
        $steps=[]; foreach(array_slice(array_values(array_filter($change['path']??[],'is_string')),0,6) as $index=>$label) $steps[]=$ui->text('update_path_step',['n'=>$keycaps[$index],'label'=>mb_substr($label,0,40)]);
        if($steps) $lines[]=$ui->text('update_item_path',['path'=>implode($ui->plain('update_path_sep'),$steps)],['path']);
        return implode("\n",array_filter($lines,fn($line)=>trim(strip_tags($line))!==''));
    }
    public static function renderChanges(Ui $ui,array $changes,int $limit=6): string
    {
        $blocks=[]; foreach(array_slice($changes,0,$limit) as $index=>$change) $blocks[]=self::renderChange($ui,$change,$index+1);
        return implode("\n\n",$blocks);
    }
    private function resume(array $active,int $chat,int $user,int $message,bool $fresh): void
    {
        $percent=max(0,min(99,(int)$this->store->get('update_percent','0')));
        $stage=$this->store->get('update_stage','update_wait');
        if(!$this->ui->definition('text',$stage)) $stage='update_wait';
        $bar=str_repeat('▰',intdiv($percent,10)).str_repeat('▱',10-intdiv($percent,10)).' '.$percent.'%';
        $old=(int)$active['message_id'];
        $menu=$this->view($chat,$user,$this->ui->text('update_progress',['bar'=>$bar,'stage'=>$this->ui->text($stage)],['stage']),
            [[$this->ui->button('update_progress','update:progress',['percent'=>(string)$percent])]],$message,$fresh&&$message===0);
        $bound=$this->store->query("UPDATE update_requests SET message_id=? WHERE id=? AND status IN ('queued','running')",[$menu,$active['id']])->rowCount();
        if(!$bound) { $this->menu($chat,$user,$menu); return; }
        if($old>0&&$old!==$menu) {
            try { $this->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$old]); } catch(\Throwable) {}
        }
    }

    public function menu(int $chat, int $user, int $message = 0, string $notice = '', bool $fresh = false): void
    {
        if(!$this->authorized($chat,$user)) return;
        $active=$this->active();
        if($active&&(int)$active['user_id']===$user&&(int)$active['chat_id']===$chat&&(int)($active['thread_id']??0)===($chat<0?max(0,$this->thread):0)) {
            $this->resume($active,$chat,$user,$message,$fresh); return;
        }
        if($fresh) $this->store->set('update_new_changes','');
        $notice=$notice!==''?$this->ui->text('update_notice',['title'=>$notice,'detail'=>''],['title']):'';
        $previous=$this->store->get('update_previous_version');
        $text=$this->ui->text('update_menu',['version'=>self::version(),'checked'=>$this->store->get('last_update_at',$this->ui->plain('not_checked')),'notice'=>$notice,'previous'=>$previous!==''?$previous:$this->ui->plain('update_none')],['notice']);
        $rows=[]; $secondary=[];
        if($active) $text.="\n\n".$this->ui->text('update_other_admin');
        else {
            $rows[]=[$this->ui->button('update_start','update:start')];
            if($this->store->get('update_previous_version')!=='') $secondary[]=$this->ui->button('update_rollback','update:rollback');
        }
        if($this->freshChanges()) array_unshift($secondary,$this->ui->button('update_changes','update:changes'));
        if($secondary) $rows[]=$secondary;
        $rows[]=$this->back($chat);
        $this->view($chat,$user,$text,$rows,$message,$fresh&&$message===0);
    }

    public function changes(int $chat, int $user, int $message): void
    {
        if(!$this->authorized($chat,$user)) return;
        if($this->active()) return;
        $notes=$this->freshChanges();
        $text=$notes?$this->ui->text('update_whatsnew',['changes'=>self::renderChanges($this->ui,$notes)],['changes']):$this->ui->text('update_no_changes');
        $this->view($chat,$user,$text,[[$this->ui->button('back_update','update:menu'),$this->ui->button('update_close','update:close')]],$message);
    }

    public function confirmRollback(int $chat, int $user, int $message): void
    {
        if(!$this->authorized($chat,$user)) return;
        if($this->active()) return;
        if($this->store->get('update_previous_version')==='') { $this->menu($chat,$user,$message,$this->ui->text('update_no_previous')); return; }
        $this->view($chat,$user,$this->ui->text('update_rollback_confirm',['version'=>$this->store->get('update_previous_version')]),[
            [$this->ui->button('update_confirm','update:rollback:confirm')],
            [$this->ui->button('back_update','update:menu'),$this->ui->button('update_close','update:close')],
        ],$message);
    }

    public function request(int $chat, int $user, string $action, int $message): void
    {
        if(!$this->ownsMenu($chat,$user,$message)||!in_array($action,['update','rollback'],true)||$this->active()) return;
        if(time()-(int)$this->store->get('updater_heartbeat','0')>60) {
            $this->menu($chat,$user,$message,$this->ui->text('update_unavailable'));
            return;
        }
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $active=$this->store->query("SELECT 1 FROM update_requests WHERE status IN ('queued','running')")->fetchColumn();
            if(!$active) $this->store->query('INSERT INTO update_requests(user_id,chat_id,thread_id,action,created) VALUES(?,?,?,?,?)',[$user,$chat,$chat<0?max(0,$this->thread):0,$action,time()]);
            $id=(int)$this->store->db->lastInsertId();
            $this->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
        if($active) return;
        $this->store->set('update_new_changes','');
        $this->store->set('update_percent','0');
        $this->store->set('update_stage','update_wait');
        try {
            $message=$this->view($chat,$user,$this->ui->text('update_progress',['bar'=>'▱▱▱▱▱▱▱▱▱▱ 0%','stage'=>$this->ui->text('update_wait')],['stage']),
                [[$this->ui->button('update_progress','update:progress',['percent'=>'0'])]],$message);
            $this->store->query('UPDATE update_requests SET message_id=? WHERE id=?',[$message,$id]);
        } catch(\Throwable $e) {
            $this->store->query("UPDATE update_requests SET status='failed' WHERE id=?",[$id]);
            throw $e;
        }
    }
}
