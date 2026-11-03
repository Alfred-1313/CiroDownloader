<?php
declare(strict_types=1);
namespace Zero;

// The bot only queues fixed actions. A separate host service owns Docker/Git access.
final class Updates
{
    private Ui $ui;
    public function __construct(private Config $config, private Store $store, private Api $api) { $this->ui=new Ui($store); }

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
        $message=$new?0:($message?:((int)$this->store->get('update_menu.'.$user,'0')));
        $params=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true,
            'reply_markup'=>['inline_keyboard'=>$rows]];
        $hash=hash('sha256',json_encode($params,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if($message>0&&$message===(int)$this->store->get('update_menu.'.$user,'0')&&$hash===$this->store->get('update_menu_hash.'.$user)) return $message;
        if($message>0) {
            $params['message_id']=$message;
            try { $this->api->call('editMessageText',$params); }
            catch(\RuntimeException $e) {
                if($e->getCode()!==404) throw $e;
                $message=0; unset($params['message_id']); // The user deleted the old menu.
            }
        }
        if($message===0) {
            $result=$this->api->call('sendMessage',$params);
            if(!is_array($result)||empty($result['message_id'])) throw new \RuntimeException('Missing update menu message ID');
            $message=(int)$result['message_id'];
        }
        $this->store->set('update_menu.'.$user,(string)$message);
        $this->store->set('update_menu_hash.'.$user,$hash);
        return $message;
    }

    private function freshChanges(): array
    {
        // Rollback metadata is not a reliable record of the version being upgraded.
        $data=json_decode($this->store->get('update_new_changes'),true);
        return is_array($data)&&($data['to']??'')===self::version()&&is_array($data['changes']??null)?array_values(array_filter($data['changes'],'is_string')):[];
    }

    public function menu(int $chat, int $user, int $message = 0, string $notice = '', bool $fresh = false): void
    {
        if(!in_array($user,$this->config->admins,true)||$chat!==$user) return;
        $active=$this->active();
        if($active&&(int)$active['user_id']===$user) return; // Preserve the live progress screen.
        if($fresh) $this->store->set('update_new_changes','');
        $notice=$notice!==''?$this->ui->text('update_notice',['title'=>$notice,'detail'=>''],['title']):'';
        $text=$this->ui->text('update_menu',['version'=>self::version(),'checked'=>$this->store->get('last_update_at',$this->ui->plain('not_checked')),'notice'=>$notice],['notice']);
        $rows=[];
        if($active) $text.="\n\n".$this->ui->text('update_other_admin');
        else {
            $rows[]=[$this->ui->button('update_start','update:start')];
            if($this->store->get('update_previous_version')!=='') $rows[]=[$this->ui->button('update_rollback','update:rollback')];
        }
        if($this->freshChanges()) $rows[]=[$this->ui->button('update_changes','update:changes')];
        $rows[]=[$this->ui->button('back_admin','update:back')];
        $this->view($chat,$user,$text,$rows,$message,$fresh&&$message===0);
    }

    public function changes(int $chat, int $user, int $message): void
    {
        if(!in_array($user,$this->config->admins,true)||$chat!==$user) return;
        if($this->active()) return;
        $notes=array_slice($this->freshChanges(),0,12);
        $text=$notes?$this->ui->text('update_changes',['changes'=>implode("\n",array_map(fn($note)=>'• '.Ui::escape(mb_substr($note,0,240)),$notes))],['changes']):$this->ui->text('update_no_changes');
        $this->view($chat,$user,$text,[[$this->ui->button('back_update','update:menu')]],$message);
    }

    public function confirmRollback(int $chat, int $user, int $message): void
    {
        if(!in_array($user,$this->config->admins,true)||$chat!==$user) return;
        if($this->active()) return;
        if($this->store->get('update_previous_version')==='') { $this->menu($chat,$user,$message,$this->ui->text('update_no_previous')); return; }
        $this->view($chat,$user,$this->ui->text('update_rollback_confirm',['version'=>$this->store->get('update_previous_version')]),[
            [$this->ui->button('update_confirm','update:rollback:confirm')],
            [$this->ui->button('cancel','update:menu')],
        ],$message);
    }

    public function request(int $chat, int $user, string $action, int $message): void
    {
        if(!in_array($user,$this->config->admins,true)||$chat!==$user||!in_array($action,['update','rollback'],true)) return;
        if($message<1||$message!==(int)$this->store->get('update_menu.'.$user,'0')||$this->active()) return;
        if(time()-(int)$this->store->get('updater_heartbeat','0')>60) {
            $this->menu($chat,$user,$message,$this->ui->text('update_unavailable'));
            return;
        }
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $active=$this->store->query("SELECT 1 FROM update_requests WHERE status IN ('queued','running')")->fetchColumn();
            if(!$active) $this->store->query('INSERT INTO update_requests(user_id,chat_id,action,created) VALUES(?,?,?,?)',[$user,$chat,$action,time()]);
            $id=(int)$this->store->db->lastInsertId();
            $this->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
        if($active) return;
        $this->store->set('update_new_changes','');
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
