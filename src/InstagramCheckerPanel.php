<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/InstagramChecker.php';

/** Owner/chat/topic/message-bound checker controls; all visible copy uses Ui. */
class InstagramCheckerPanel
{
    protected const SERVICE='instagram', CALLBACK='ic', CHECKER=InstagramChecker::class;
    private InstagramChecker $checker;
    private function query(string $sql,array $args=[]): \PDOStatement { return $this->checker->query($sql,$args); }
    private function modeSlots(array $p,string $op,?int $id=null): array
    {
        $slots=[]; foreach($this->checker->modeChoices() as $slot=>$mode) $slots[$slot]=[$this->b('mode'.$mode,$p,$op==='save'?'save':'mode'.$mode,$op==='save'?$mode:$id)]; return $slots;
    }
    protected function openCookies(int $chat,int $owner): void { (new InstagramCheckerCookies($this->bot))->handle($chat,$owner,'cookie_instagram_checker'); }
    public function __construct(protected Bot $bot)
    {
        $class=static::CHECKER; $this->checker=new $class($bot->config,$bot->store,$bot->api);
    }
    public static function command(string $text): ?array
    {
        if(!preg_match('~^/(?:insta_checker|checker)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~sD',trim($text),$m)) return null;
        return ['bot'=>$m[1]??'','argument'=>trim($m[2]??'')];
    }
    private function b(string $key,array $p,string $op,?int $id=null,array $vars=[]): array
    {
        return $this->bot->ui->button(static::SERVICE.'_checker_'.$key,static::CALLBACK.':'.$p['token'].':'.$op.($id===null?'':':'.$id),$vars);
    }
    private function item(array $p,int $id): ?array { return (int)$p['chat_id']===(int)$p['owner']?$this->checker->owned($id,(int)$p['owner']):$this->checker->subscription($id,(int)$p['owner'],(int)$p['chat_id'],(int)$p['thread_id']); }
    private function destination(array $sub): string { return (int)$sub['chat_id']>0?$this->bot->ui->plain(static::SERVICE.'_checker_destination_private'):$this->bot->ui->text(static::SERVICE.'_checker_destination_group',['id'=>$sub['chat_id'],'topic'=>$sub['thread_id']]); }
    private function forgetReply(array $p): void
    {
        $s=$this->bot->store; $raw=$this->query('SELECT data FROM {checker}panels WHERE token=? AND owner=?',[$p['token'],$p['owner']])->fetchColumn();
        $data=is_string($raw)?json_decode($raw,true):[]; $message=(int)($data['input_message']??0);
        if($message<1) return;
        unset($data['input_message']);
        $this->query('UPDATE {checker}panels SET data=? WHERE token=? AND data=?',[json_encode($data,JSON_THROW_ON_ERROR),$p['token'],$raw]);
        $this->query('DELETE FROM states WHERE user_id=? AND key=?',[$p['owner'],static::CALLBACK.':'.$p['token']]);
        try { $this->bot->api->call('deleteMessage',['chat_id'=>(int)$p['chat_id'],'message_id'=>$message]); } catch(\Throwable) {}
    }
    private function clearInput(int $owner,?string $token=null): void
    {
        $s=$this->bot->store;
        if($token===null) {
            $state=$this->query('SELECT key FROM states WHERE user_id=?',[$owner])->fetchColumn();
            if(!is_string($state)||!preg_match('/^'.static::CALLBACK.':([a-f0-9]{16})$/D',$state,$m)) return;
            $token=$m[1];
        }
        $p=$this->query('SELECT * FROM {checker}panels WHERE token=? AND owner=?',[$token,$owner])->fetch();
        if($p) $this->forgetReply($p);
        $this->query('DELETE FROM states WHERE user_id=? AND key=?',[$owner,static::CALLBACK.':'.$token]);
    }
    private function beginReply(array $p,string $view,array $data=[],string $notice=''): void
    {
        $owner=(int)$p['owner']; $chat=(int)$p['chat_id']; $ui=$this->bot->ui;
        $label=$this->query('SELECT username FROM user_profiles WHERE user_id=?',[$owner])->fetchColumn();
        $mention='<a href="tg://user?id='.$owner.'">'.Ui::escape($label?'@'.$label:(string)$owner).'</a>';
        $text=$mention."\n".$ui->text($view==='rename'?static::SERVICE.'_checker_rename_prompt':static::SERVICE.'_checker_add_prompt',['username'=>$data['username']??'']);
        if($notice!=='') $text=$notice."\n\n".$text;
        $params=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','reply_markup'=>['force_reply'=>true,'selective'=>true,'input_field_placeholder'=>$ui->plain(static::SERVICE.'_checker_username_placeholder')]];
        if($chat<0&&(int)$p['thread_id']>0) $params['message_thread_id']=(int)$p['thread_id'];
        $result=$this->bot->api->call('sendMessage',$params);
        $prompt=(int)($result['message_id']??0); if($prompt<1) throw new \RuntimeException('Checker prompt was not confirmed');
        $this->clearInput($owner);
        $data['input_message']=$prompt;
        $this->query('UPDATE {checker}panels SET view=?,data=?,expires=? WHERE token=?',[$view,json_encode($data,JSON_THROW_ON_ERROR),time()+3600,$p['token']]);
        $this->input($owner,$p['token']);
    }
    private function permitted(int $owner,int $chat,bool $settings=false): bool { return ($settings&&$chat===$owner&&$this->bot->admin($owner))||$this->checker->allowed($owner,$chat); }
    private function denial(int $owner,int $chat): string
    {
        if(!$this->checker::enabled($this->bot->store)) return static::SERVICE.'_checker_disabled';
        if($this->checker::everyone($this->bot->store)&&$chat<0&&$this->bot->allowed($owner,$chat)) return static::SERVICE.'_checker_group_admin';
        return static::SERVICE.'_checker_denied';
    }
    public function open(int $owner,int $chat,int $thread=0,int $message=0,string $view='home',array $data=[]): void
    {
        if(!$this->permitted($owner,$chat,$this->bot->admin($owner)&&$chat===$owner)) { $this->bot->send($chat,$this->bot->text($this->denial($owner,$chat)),[],0,$thread); return; }
        $this->clearInput($owner);
        $s=$this->bot->store; $this->query('DELETE FROM {checker}panels WHERE expires<?',[time()]);
        $p=['token'=>bin2hex(random_bytes(8)),'owner'=>$owner,'chat_id'=>$chat,'thread_id'=>$chat<0?$thread:0,'message_id'=>$message];
        $this->query('INSERT INTO {checker}panels(token,owner,chat_id,thread_id,message_id,expires) VALUES(?,?,?,?,?,?)',[$p['token'],$owner,$chat,$p['thread_id'],$message,time()+3600]);
        try { $this->show($p,$view,$data); }
        catch(\Throwable $e) { $this->query('DELETE FROM {checker}panels WHERE token=?',[$p['token']]); throw $e; }
    }
    private function show(array $p,string $view,array $data=[],string $notice='',bool $passive=false): void
    {
        $ui=$this->bot->ui; $owner=(int)$p['owner']; $chat=(int)$p['chat_id']; $rows=[]; $text='';
        $back=[$this->b('back',$p,'home'),$this->b('close',$p,'close')];
        if($view==='home') {
            $page=max(0,(int)($data['page']??0)); $subs=$this->checker->subscriptions($owner,$chat,(int)$p['thread_id'],$chat===$owner); $pages=max(1,(int)ceil(count($subs)/8)); $page=min($page,$pages-1); $data=['page'=>$page];
            $text=$ui->text(static::SERVICE.'_checker_home',['used'=>$this->checker->used($owner),'limit'=>$this->checker->limit($owner)?:$ui->plain(static::SERVICE.'_checker_unlimited')]);
            $text.="\n\n".$ui->text(static::SERVICE.'_checker_menu_hint');
            $slots=['add'=>[$this->b('add',$p,'add')],'close'=>[$this->b('close',$p,'close')]];
            foreach(array_slice($subs,$page*8,8) as $n=>$sub) $slots['entry'.($n+1)]=[$this->b('entry',$p,'item',(int)$sub['id'],['username'=>$sub['username'],'state'=>$ui->plain((int)$sub['paused']?static::SERVICE.'_checker_paused':static::SERVICE.'_checker_active'),'destination'=>$this->destination($sub)])];
            if(!$subs) $text.="\n\n".$ui->text(static::SERVICE.'_checker_empty');
            if($page>0) $slots['prev']=[$this->b('previous',$p,'page',$page-1)]; if($page+1<$pages) $slots['next']=[$this->b('next',$p,'page',$page+1)];
            $rows=$ui->layout(static::SERVICE,'checker_home',$slots,UiRegistry::rows(static::SERVICE,'checker_home'));
            if($chat===$owner&&$this->bot->admin($owner)) array_splice($rows,max(0,count($rows)-1),0,[[$this->b('settings',$p,'settings')]]);
        } elseif(in_array($view,['new','add'],true)) {
            $name=(string)($data['username']??'');
            $text=$ui->text($view==='new'?static::SERVICE.'_checker_choose':static::SERVICE.'_checker_add_prompt',['username'=>$name]);
            if($view==='new') $rows=$ui->layout(static::SERVICE,'checker_new',$this->modeSlots($p,'save')+['back'=>[$this->b('back',$p,'add')],'close'=>[$this->b('close',$p,'close')]],UiRegistry::rows(static::SERVICE,'checker_new'));
            else $rows[]=$back;
        } elseif(in_array($view,['item','delete','mode'],true)) {
            $id=(int)($data['id']??0); $sub=$this->item($p,$id);
            if(!$sub) { $this->show($p,'home',[],$ui->text(static::SERVICE.'_checker_expired')); return; }
            $state=(int)$sub['paused']?'paused':($sub['state']==='ok'?'ok':($sub['state']==='waiting'?'waiting':'unavailable'));
            $text=$ui->text(static::SERVICE.'_checker_item',['username'=>$sub['username'],'mode'=>$ui->label(static::SERVICE.'_checker_mode'.$sub['mode']),
                'status'=>$ui->plain(static::SERVICE.'_checker_status_'.$state),'destination'=>$this->destination($sub),'checked'=>(int)$sub['checked']?$ui->text(static::SERVICE.'_checker_checked_ago',['minutes'=>max(1,(int)((time()-(int)$sub['checked'])/60))]):$ui->plain(static::SERVICE.'_checker_not_checked')]);
            $slots=['back'=>[$this->b('back',$p,$view==='item'?'home':'item',$view==='item'?null:$id)],'close'=>[$this->b('close',$p,'close')]];
            if($view==='delete') { $text.="\n\n".$ui->text(static::SERVICE.'_checker_delete_prompt'); $slots['yes']=[$this->b('delete_yes',$p,'remove',$id)]; $layout='checker_delete'; }
            elseif($view==='mode') { $slots+=$this->modeSlots($p,'mode',$id); $layout='checker_new'; }
            else { $slots+=['check'=>[$this->b(isset($data['check'])?'checking':'check',$p,'check',$id)],'rename'=>[$this->b('rename',$p,'rename',$id)],'toggle'=>[$this->b((int)$sub['paused']?'resume':'pause',$p,'pause',$id)],'mode'=>[$this->b('change_mode',$p,'mode',$id)],'delete'=>[$this->b('delete',$p,'delete',$id)]]; $layout='checker_manage'; }
            $rows=$ui->layout(static::SERVICE,$layout,$slots,UiRegistry::rows(static::SERVICE,$layout));
        } elseif($view==='settings') {
            if($chat!==$owner||!$this->bot->admin($owner)) return;
            $access=$ui->plain($this->checker::everyone($this->bot->store)?static::SERVICE.'_checker_access_everyone':static::SERVICE.'_checker_access_admins');
            $text=$ui->text(static::SERVICE.'_checker_settings_text',['limit'=>$this->bot->store->get(static::SERVICE.'.checker.limit','3'),'minutes'=>(string)($this->checker->interval()/60),'access'=>$access,'state'=>$ui->plain($this->checker::enabled($this->bot->store)?'switch_on':'switch_off')]);
            $rows=[[$this->b('enabled',$p,'enabled',null,['state'=>$ui->plain($this->checker::enabled($this->bot->store)?'switch_on':'switch_off')])],
                [$this->b('public',$p,'public',null,['access'=>$access])],
                [$this->b('default_limit',$p,'limit'),$this->b('user_limit',$p,'userlimit')],
                [$this->b('interval',$p,'interval')],[$this->b('cookies',$p,'cookies')],
                [$this->b('source_toggle',$p,'source',null,['state'=>$ui->plain($this->bot->store->get(static::SERVICE.'.checker.source_button','0')==='1'?'switch_on':'switch_off')])]];
            if(static::SERVICE==='youtube') $rows[]=[$this->b('quality',$p,'quality',null,['quality'=>$this->bot->store->get('youtube.checker.quality','720')])];
            $rows[]=!empty($data['custom'])?[$ui->button('back',($data['custom']==='service'?'custom:service:'.static::SERVICE:'custom:settings:'.static::SERVICE)),$this->b('close',$p,'close')]:$back;
        } elseif(in_array($view,['limit','userlimit','interval'],true)) {
            if($chat!==$owner||!$this->bot->admin($owner)) return;
            $text=$ui->text(static::SERVICE.'_checker_prompt_'.$view); $rows=[[$this->b('back',$p,'settings'),$this->b('close',$p,'close')]];
        }
        if($text==='') return;
        if($notice!=='') $text=$notice."\n\n".$text;
        $sent=$this->bot->send($chat,$text,$rows,(int)$p['message_id'],(int)$p['thread_id']);
        if(!$passive) $this->forgetReply($p);
        $params=[$sent,$view,json_encode($data,JSON_THROW_ON_ERROR),time()+3600,$p['token']];
        $guard=''; if($passive) { $guard=' AND view=? AND data=? AND message_id=?'; array_push($params,$p['view'],$p['data'],$p['message_id']); }
        $this->query('UPDATE {checker}panels SET message_id=?,view=?,data=?,expires=? WHERE token=?'.$guard,$params);
    }
    public function message(array $update,int $owner,int $chat,int $thread): bool
    {
        $message=$update['message']??[]; $text=trim((string)($message['text']??'')); $command=static::command($text);
        $reply=$message['reply_to_message']??[]; $replyId=(int)($reply['message_id']??0);
        $replyPanel=$replyId>0?$this->query("SELECT * FROM {checker}panels WHERE chat_id=? AND CAST(json_extract(data,'$.input_message') AS INTEGER)=CAST(? AS INTEGER)",[$chat,$replyId])->fetch():false;
        if($replyPanel) {
            $botId=(int)$this->bot->store->get('bot_id','0');
            if((int)$replyPanel['owner']!==$owner||empty($reply['from']['is_bot'])||($botId>0&&(int)($reply['from']['id']??0)!==$botId)
                ||(array_key_exists('message_thread_id',$message)&&(int)$replyPanel['thread_id']!==$thread)
                ||(array_key_exists('message_thread_id',$reply)&&(int)$replyPanel['thread_id']!==(int)$reply['message_thread_id'])) return true;
            $thread=(int)$replyPanel['thread_id'];
        }
        if($command!==null||$text===$this->bot->label(static::SERVICE.'_checker')) {
            if($command!==null&&$command['bot']!==''&&strcasecmp($command['bot'],$this->bot->store->get('bot_username'))!==0) return true;
            $name=$command!==null&&$command['argument']!==''?$this->checker->normalizeName($command['argument']):null;
            if($command!==null&&$command['argument']!==''&&$name===null) { $this->bot->send($chat,$this->bot->text(static::SERVICE.'_checker_invalid'),[],0,$thread); return true; }
            $this->open($owner,$chat,$thread,0,$name===null?'home':'new',$name===null?[]:['username'=>$name]); return true;
        }
        $state=$this->query('SELECT key FROM states WHERE user_id=? AND expires>?',[$owner,time()])->fetchColumn();
        if(!is_string($state)||!preg_match('/^'.static::CALLBACK.':([a-f0-9]{16})$/D',$state,$match)) return (bool)$replyPanel;
        $p=$this->query('SELECT * FROM {checker}panels WHERE token=? AND owner=? AND chat_id=? AND thread_id=? AND expires>?',[$match[1],$owner,$chat,$chat<0?$thread:0,time()])->fetch();
        if(!$p) return $replyId>0||(bool)$replyPanel;
        if(str_starts_with($text,'/')&&$text!=='/cancel') { $this->clearInput($owner); return false; }
        if($text==='/cancel') { $this->clearInput($owner); $this->show($p,'home'); return true; }
        if(!$this->permitted($owner,$chat,$chat===$owner&&$this->bot->admin($owner))) { $this->bot->send($chat,$this->bot->text($this->denial($owner,$chat)),[],0,$thread); return true; }
        $data=json_decode($p['data'],true);
        if(in_array($p['view'],['add','rename'],true)) {
            if(!$replyPanel||$replyPanel['token']!==$p['token']||$replyId!==(int)($data['input_message']??0)) return true;
            $name=$this->checker->normalizeName($text);
            if($name===null) { $this->beginReply($p,$p['view'],$data,$this->bot->text(static::SERVICE.'_checker_invalid')); return true; }
            if($p['view']==='add') { $this->show($p,'new',['username'=>$name]); return true; }
            $sub=$this->item($p,(int)($data['id']??0));
            if(!$sub) { $this->show($p,'home',[],$this->bot->text(static::SERVICE.'_checker_expired')); return true; }
            try {
                $id=$this->checker->rename((int)$sub['id'],$owner,(int)$sub['chat_id'],(int)$sub['thread_id'],$name,(int)($data['generation']??0));
            } catch(\RuntimeException $e) {
                $error=$e->getMessage();
                if(!in_array($error,['checker_duplicate','checker_limit_reached','checker_denied','checker_expired'],true)) throw $e;
                if(in_array($error,['checker_duplicate','checker_limit_reached'],true)) $this->beginReply($p,'rename',$data,$this->bot->text(static::SERVICE.'_'.$error));
                else $this->show($p,'home',[],$this->bot->text($error==='checker_denied'?static::SERVICE.'_checker_denied':static::SERVICE.'_checker_expired'));
                return true;
            }
            $this->show($p,'item',['id'=>$id],$this->bot->text(static::SERVICE.'_checker_renamed')); return true;
        }
        if($chat!==$owner||!$this->bot->admin($owner)) return true;
        $text=strtr($text,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹',-1,PREG_SPLIT_NO_EMPTY),range(0,9)));
        if($p['view']==='limit'&&preg_match('/^(?:0|[1-9][0-9]?)$|^100$/D',$text)) $this->bot->store->set(static::SERVICE.'.checker.limit',$text);
        elseif($p['view']==='interval'&&ctype_digit($text)&&(int)$text>=1&&(int)$text<=60) $this->bot->store->set(static::SERVICE.'.checker.seconds',(string)((int)$text*60));
        elseif($p['view']==='userlimit'&&preg_match('/^(@[A-Za-z0-9_]{1,32}|[1-9][0-9]{0,14})\s+(0|[1-9][0-9]?|100)$/D',$text,$m)) {
            $user=$m[1][0]==='@'?(int)$this->query('SELECT user_id FROM user_profiles WHERE username=?',[strtolower(substr($m[1],1))])->fetchColumn():(int)$m[1];
            if(!$user) { $this->show($p,'userlimit',$data,$this->bot->text(static::SERVICE.'_checker_user_unknown')); return true; }
            if(in_array($user,$this->bot->config->admins,true)) { $this->clearInput($owner); $this->show($p,'settings',$data,$this->bot->text(static::SERVICE.'_checker_root_unlimited')); return true; }
            $this->bot->store->set(static::SERVICE.'.checker.limit.'.$user,$m[2]);
        } else { $this->show($p,$p['view'],$data,$this->bot->text(static::SERVICE.'_checker_input_invalid')); return true; }
        $this->clearInput($owner); $this->show($p,'settings',$data,$this->bot->text(static::SERVICE.'_checker_saved')); return true;
    }
    public function callback(array $cb,int $owner,int $chat,int $thread): void
    {
        $answer=function(string $key='',bool $alert=false) use($cb): void { $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$key===''?'':$this->bot->ui->plain($key),'show_alert'=>$alert,'cache_time'=>0]); };
        if(!preg_match('/^'.static::CALLBACK.':([a-f0-9]{16}):([a-z_0-9]+)(?::([0-9]{1,12}))?$/D',(string)$cb['data'],$m)) { $answer(static::SERVICE.'_checker_expired',true); return; }
        $p=$this->query('SELECT * FROM {checker}panels WHERE token=? AND chat_id=? AND message_id=?',[$m[1],$chat,(int)$cb['message']['message_id']])->fetch();
        if($p&&(int)$p['owner']!==$owner) { $answer('callback_owner',true); return; }
        $explicitTopic=$chat>=0||array_key_exists('message_thread_id',$cb['message']);
        if(!$p||(int)$p['expires']<=time()||($explicitTopic&&(int)$p['thread_id']!==($chat<0?$thread:0))) { $answer(static::SERVICE.'_checker_expired',true); return; }
        $thread=(int)$p['thread_id'];
        $op=$m[2]; $id=(int)($m[3]??0); $data=json_decode($p['data'],true);
        $settings=$chat===$owner&&$this->bot->admin($owner);
        if(!$this->permitted($owner,$chat,$settings)) { $answer($this->denial($owner,$chat),true); return; }
        $answer();
        if($op==='close') { $this->clearInput($owner,$p['token']); $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$p['message_id']]); $this->query('DELETE FROM {checker}panels WHERE token=?',[$p['token']]); return; }
        if(in_array($op,['home','page'],true)) { $this->clearInput($owner,$p['token']); $this->show($p,'home',['page'=>$op==='page'?$id:0]); return; }
        if($op==='add') { $this->beginReply($p,'add'); return; }
        if($op==='save'&&$p['view']==='new'&&isset($data['username'])&&in_array($id,array_values($this->checker->modeChoices()),true)) {
            try { $new=$this->checker->add($owner,$chat,$thread,$data['username'],$id); $this->show($p,'item',['id'=>$new],$this->bot->text(static::SERVICE.'_checker_added')); }
            catch(\RuntimeException $e) { $key=in_array($e->getMessage(),['checker_duplicate','checker_limit_reached','checker_denied'],true)?static::SERVICE.'_'.$e->getMessage():static::SERVICE.'_checker_invalid'; $this->show($p,'new',$data,$this->bot->text($key)); }
            return;
        }
        if($op==='item') { $this->show($p,'item',['id'=>$id]); return; }
        if($op==='rename') {
            if($p['view']!=='item'||(int)($data['id']??0)!==$id) return;
            $sub=$this->item($p,$id);
            if(!$sub) { $this->show($p,'home',[],$this->bot->text(static::SERVICE.'_checker_expired')); return; }
            if(!$this->checker->allowed($owner,(int)$sub['chat_id'])) { $this->show($p,'item',['id'=>$id],$this->bot->text(static::SERVICE.'_checker_denied')); return; }
            $this->beginReply($p,'rename',['id'=>$id,'generation'=>(int)$sub['generation'],'username'=>$sub['username']]); return;
        }
        if($op==='check') {
            if($p['view']!=='item'||(int)($data['id']??0)!==$id) return;
            $sub=$this->item($p,$id);
            if(!$sub) { $this->show($p,'home',[],$this->bot->text(static::SERVICE.'_checker_expired')); return; }
            if(isset($data['check'])) {
                $key=static::SERVICE.'_checker_check_queued';
                if((int)$data['check']['requested']<time()-180) { unset($data['check']); $key=static::SERVICE.'_checker_check_timeout'; }
                $this->show($p,'item',$data,$this->bot->text($key)); return;
            }
            $result=$this->checker->requestCheck($sub,$owner);
            if($result['result']==='queued') $data['check']=$result['check'];
            $key=$result['result']==='denied'?(!$this->checker::enabled($this->bot->store)?static::SERVICE.'_checker_disabled':static::SERVICE.'_checker_denied'):static::SERVICE.'_checker_check_'.$result['result'];
            $this->show($p,'item',$data,$this->bot->text($key,['minutes'=>$result['minutes']??1])); return;
        }
        if(in_array($op,['pause','mode','delete','mode1','mode2','mode3','mode4','mode5','mode6','mode7','remove'],true)) {
            if(!in_array($p['view'],['item','mode','delete'],true)||(int)($data['id']??0)!==$id) { $this->show($p,'home'); return; }
            if($op==='remove'&&$p['view']!=='delete') return;
            if($op==='delete'||$op==='mode') { $this->show($p,$op,['id'=>$id]); return; }
            $sub=$this->item($p,$id);
            if(!$sub||!$this->checker->change($id,$owner,(int)$sub['chat_id'],(int)$sub['thread_id'],$op==='remove'?'delete':$op,$chat===$owner)) { $this->show($p,'home',[],$this->bot->text(static::SERVICE.'_checker_expired')); return; }
            $this->show($p,$op==='remove'?'home':'item',$op==='remove'?[]:['id'=>$id]); return;
        }
        if(!$settings) return;
        if($op==='settings') { $this->clearInput($owner); $this->show($p,'settings',isset($data['custom'])?['custom'=>$data['custom']]:[]); }
        elseif($p['view']==='settings') {
            if(in_array($op,['enabled','public','source'],true)) {
                $key=match($op) {'enabled'=>static::SERVICE.'.checker.enabled','public'=>static::SERVICE.'.checker.public',default=>static::SERVICE.'.checker.source_button'};
                $store=$this->bot->store; $store->db->exec('BEGIN IMMEDIATE');
                try {
                    $current=$store->get($key,$op==='enabled'?($this->checker::enabled($store)?'1':'0'):'0'); $store->set($key,$current==='1'?'0':'1');
                    if($op==='enabled') {
                        $this->query('UPDATE {checker}subs SET posts_ready=0,stories_ready=0,extra_ready=0,generation=generation+1');
                        $this->query("UPDATE {checker}deliveries SET status='cancelled' WHERE status IN ('pending','queued','retry')");
                    }
                    $store->db->exec('COMMIT');
                } catch(\Throwable $e) { $store->db->exec('ROLLBACK'); throw $e; }
                if(in_array($op,['enabled','public'],true)) MusicSearch::register($this->bot->api,$this->bot->store,$ui=$this->bot->ui);
                $this->show($p,'settings',$data);
            } elseif($op==='quality'&&static::SERVICE==='youtube') {
                $choices=[360,480,720,1080]; $current=(int)$this->bot->store->get('youtube.checker.quality','720'); $position=array_search($current,$choices,true); $this->bot->store->set('youtube.checker.quality',(string)$choices[($position===false?0:$position+1)%count($choices)]); $this->show($p,'settings',$data);
            } elseif(in_array($op,['limit','userlimit','interval'],true)) { $this->show($p,$op,$data); $this->input($owner,$p['token']); }
            elseif($op==='cookies') { $this->clearInput($owner); $this->openCookies($chat,$owner); }
        }
    }
    private function input(int $owner,string $token): void { $this->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$owner,static::CALLBACK.':'.$token,time()+600]); }

    /** Finish only an explicitly requested check, while its original view is open. */
    public function refreshChecks(): void
    {
        $store=$this->bot->store;
        $panels=$this->query("SELECT p.* FROM {checker}panels p JOIN {checker}subs s ON s.id=json_extract(p.data,'$.id') JOIN {checker}targets t ON t.id=s.target WHERE p.view='item' AND p.expires>? AND json_type(p.data,'$.check')='object' AND CAST(COALESCE(json_extract(p.data,'$.check.retry_after'),0) AS INTEGER)<=? AND (t.scan_version>json_extract(p.data,'$.check.version') OR CAST(json_extract(p.data,'$.check.requested') AS INTEGER)<?) LIMIT 10",[time(),time(),time()-180])->fetchAll();
        foreach($panels as $p) {
            $data=json_decode($p['data'],true); $check=$data['check']; unset($data['check']);
            $sub=$this->item($p,(int)$data['id']);
            if(!$sub||(int)$sub['generation']!==(int)$check['generation']||!$this->checker->allowed((int)$p['owner'],(int)$sub['chat_id'])) {
                $this->query('UPDATE {checker}panels SET data=? WHERE token=? AND data=?',[json_encode($data,JSON_THROW_ON_ERROR),$p['token'],$p['data']]); continue;
            }
            $target=$this->query('SELECT * FROM {checker}targets WHERE id=?',[$sub['target']])->fetch();
            $result=(int)$target['scan_version']<=(int)$check['version']?'timeout':($target['state']==='ok'?'ok':($target['state']==='blocked'?'blocked':'failed'));
            if(!$this->query("SELECT 1 FROM {checker}panels WHERE token=? AND view='item' AND data=?",[$p['token'],$p['data']])->fetchColumn()) continue;
            try { $this->show($p,'item',$data,$this->bot->text(static::SERVICE.'_checker_check_'.$result,['minutes'=>max(1,(int)ceil(((int)$target['next_check']-time())/60))]),true); }
            catch(\Throwable) {
                // A result is an edit of the requested message, never a new DM.
                $tries=(int)($check['tries']??0)+1;
                if($tries<3) $data['check']=array_replace($check,['tries'=>$tries,'retry_after'=>time()+30]);
                $this->query('UPDATE {checker}panels SET data=? WHERE token=? AND data=?',[json_encode($data,JSON_THROW_ON_ERROR),$p['token'],$p['data']]);
            }
        }
    }
}
