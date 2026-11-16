<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/InstagramChecker.php';

/** Owner/chat/topic/message-bound checker controls; all visible copy uses Ui. */
final class InstagramCheckerPanel
{
    private InstagramChecker $checker;
    public function __construct(private Bot $bot)
    {
        $this->checker=new InstagramChecker($bot->config,$bot->store,$bot->api);
    }
    public static function command(string $text): ?array
    {
        if(!preg_match('~^/(?:insta_checker|checker)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~sD',trim($text),$m)) return null;
        return ['bot'=>$m[1]??'','argument'=>trim($m[2]??'')];
    }
    private function b(string $key,array $p,string $op,?int $id=null,array $vars=[]): array
    {
        return $this->bot->ui->button('instagram_checker_'.$key,'ic:'.$p['token'].':'.$op.($id===null?'':':'.$id),$vars);
    }
    private function item(array $p,int $id): ?array { return (int)$p['chat_id']===(int)$p['owner']?$this->checker->owned($id,(int)$p['owner']):$this->checker->subscription($id,(int)$p['owner'],(int)$p['chat_id'],(int)$p['thread_id']); }
    private function destination(array $sub): string { return (int)$sub['chat_id']>0?$this->bot->ui->plain('instagram_checker_destination_private'):$this->bot->ui->text('instagram_checker_destination_group',['id'=>$sub['chat_id'],'topic'=>$sub['thread_id']]); }
    private function clearInput(int $owner): void { $this->bot->store->query("DELETE FROM states WHERE user_id=? AND key LIKE 'ic:%'",[$owner]); }
    private function permitted(int $owner,int $chat,bool $settings=false): bool { return ($settings&&$chat===$owner&&$this->bot->admin($owner))||$this->checker->allowed($owner,$chat); }
    private function denial(int $owner,int $chat): string
    {
        if(!InstagramChecker::enabled($this->bot->store)) return 'instagram_checker_disabled';
        if(InstagramChecker::everyone($this->bot->store)&&$chat<0&&$this->bot->allowed($owner,$chat)) return 'instagram_checker_group_admin';
        return 'instagram_checker_denied';
    }
    public function open(int $owner,int $chat,int $thread=0,int $message=0,string $view='home',array $data=[]): void
    {
        if(!$this->permitted($owner,$chat,$this->bot->admin($owner)&&$chat===$owner)) { $this->bot->send($chat,$this->bot->text($this->denial($owner,$chat)),[],0,$thread); return; }
        $this->clearInput($owner);
        $s=$this->bot->store; $s->query('DELETE FROM ig_check_panels WHERE expires<?',[time()]);
        $p=['token'=>bin2hex(random_bytes(8)),'owner'=>$owner,'chat_id'=>$chat,'thread_id'=>$chat<0?$thread:0,'message_id'=>$message];
        $s->query('INSERT INTO ig_check_panels(token,owner,chat_id,thread_id,message_id,expires) VALUES(?,?,?,?,?,?)',[$p['token'],$owner,$chat,$p['thread_id'],$message,time()+3600]);
        try { $this->show($p,$view,$data); }
        catch(\Throwable $e) { $s->query('DELETE FROM ig_check_panels WHERE token=?',[$p['token']]); throw $e; }
    }
    private function show(array $p,string $view,array $data=[],string $notice=''): void
    {
        $ui=$this->bot->ui; $owner=(int)$p['owner']; $chat=(int)$p['chat_id']; $rows=[]; $text='';
        $back=[$this->b('back',$p,'home'),$this->b('close',$p,'close')];
        if($view==='home') {
            $page=max(0,(int)($data['page']??0)); $subs=$this->checker->subscriptions($owner,$chat,(int)$p['thread_id'],$chat===$owner); $pages=max(1,(int)ceil(count($subs)/8)); $page=min($page,$pages-1); $data=['page'=>$page];
            $text=$ui->text('instagram_checker_home',['used'=>$this->checker->used($owner),'limit'=>$this->checker->limit($owner)?:$ui->plain('instagram_checker_unlimited')]);
            $text.="\n\n".$ui->text('instagram_checker_menu_hint');
            $slots=['add'=>[$this->b('add',$p,'add')],'close'=>[$this->b('close',$p,'close')]];
            foreach(array_slice($subs,$page*8,8) as $n=>$sub) $slots['entry'.($n+1)]=[$this->b('entry',$p,'item',(int)$sub['id'],['username'=>$sub['username'],'state'=>$ui->plain((int)$sub['paused']?'instagram_checker_paused':'instagram_checker_active'),'destination'=>$this->destination($sub)])];
            if(!$subs) $text.="\n\n".$ui->text('instagram_checker_empty');
            if($page>0) $slots['prev']=[$this->b('previous',$p,'page',$page-1)]; if($page+1<$pages) $slots['next']=[$this->b('next',$p,'page',$page+1)];
            $rows=$ui->layout('instagram','checker_home',$slots,UiRegistry::rows('instagram','checker_home'));
            if($chat===$owner&&$this->bot->admin($owner)) array_splice($rows,max(0,count($rows)-1),0,[[$this->b('settings',$p,'settings')]]);
        } elseif(in_array($view,['new','add'],true)) {
            $name=(string)($data['username']??'');
            $text=$ui->text($view==='new'?'instagram_checker_choose':'instagram_checker_add_prompt',['username'=>$name]);
            if($view==='new') $rows=$ui->layout('instagram','checker_new',['posts'=>[$this->b('mode1',$p,'save',1)],'stories'=>[$this->b('mode2',$p,'save',2)],'both'=>[$this->b('mode3',$p,'save',3)],'back'=>[$this->b('back',$p,'add')],'close'=>[$this->b('close',$p,'close')]],UiRegistry::rows('instagram','checker_new'));
            else $rows[]=$back;
        } elseif(in_array($view,['item','delete','mode'],true)) {
            $id=(int)($data['id']??0); $sub=$this->item($p,$id);
            if(!$sub) { $this->show($p,'home',[],$ui->text('instagram_checker_expired')); return; }
            $state=(int)$sub['paused']?'paused':($sub['state']==='ok'?'ok':($sub['state']==='waiting'?'waiting':'unavailable'));
            $text=$ui->text('instagram_checker_item',['username'=>$sub['username'],'mode'=>$ui->label('instagram_checker_mode'.$sub['mode']),
                'status'=>$ui->plain('instagram_checker_status_'.$state),'destination'=>$this->destination($sub),'checked'=>(int)$sub['checked']?$ui->text('instagram_checker_checked_ago',['minutes'=>max(1,(int)((time()-(int)$sub['checked'])/60))]):$ui->plain('instagram_checker_not_checked')]);
            $slots=['back'=>[$this->b('back',$p,$view==='item'?'home':'item',$view==='item'?null:$id)],'close'=>[$this->b('close',$p,'close')]];
            if($view==='delete') { $text.="\n\n".$ui->text('instagram_checker_delete_prompt'); $slots['yes']=[$this->b('delete_yes',$p,'remove',$id)]; $layout='checker_delete'; }
            elseif($view==='mode') { $slots+=['posts'=>[$this->b('mode1',$p,'mode1',$id)],'stories'=>[$this->b('mode2',$p,'mode2',$id)],'both'=>[$this->b('mode3',$p,'mode3',$id)]]; $layout='checker_new'; }
            else { $slots+=['check'=>[$this->b(isset($data['check'])?'checking':'check',$p,'check',$id)],'toggle'=>[$this->b((int)$sub['paused']?'resume':'pause',$p,'pause',$id)],'mode'=>[$this->b('change_mode',$p,'mode',$id)],'delete'=>[$this->b('delete',$p,'delete',$id)]]; $layout='checker_manage'; }
            $rows=$ui->layout('instagram',$layout,$slots,UiRegistry::rows('instagram',$layout));
        } elseif($view==='settings') {
            if($chat!==$owner||!$this->bot->admin($owner)) return;
            $access=$ui->plain(InstagramChecker::everyone($this->bot->store)?'instagram_checker_access_everyone':'instagram_checker_access_admins');
            $text=$ui->text('instagram_checker_settings_text',['limit'=>$this->bot->store->get('instagram.checker.limit','3'),'minutes'=>(string)($this->checker->interval()/60),'access'=>$access,'state'=>$ui->plain(InstagramChecker::enabled($this->bot->store)?'switch_on':'switch_off')]);
            $rows=[[$this->b('enabled',$p,'enabled',null,['state'=>$ui->plain(InstagramChecker::enabled($this->bot->store)?'switch_on':'switch_off')])],
                [$this->b('public',$p,'public',null,['access'=>$access])],
                [$this->b('default_limit',$p,'limit'),$this->b('user_limit',$p,'userlimit')],
                [$this->b('interval',$p,'interval')],[$this->b('cookies',$p,'cookies')],
                [$this->b('source_toggle',$p,'source',null,['state'=>$ui->plain($this->bot->store->get('instagram.checker.source_button','0')==='1'?'switch_on':'switch_off')])]];
            $rows[]=!empty($data['custom'])?[$ui->button('back',($data['custom']==='service'?'custom:service:instagram':'custom:settings:instagram')),$this->b('close',$p,'close')]:$back;
        } elseif(in_array($view,['limit','userlimit','interval'],true)) {
            if($chat!==$owner||!$this->bot->admin($owner)) return;
            $text=$ui->text('instagram_checker_prompt_'.$view); $rows=[[$this->b('back',$p,'settings'),$this->b('close',$p,'close')]];
        }
        if($text==='') return;
        if($notice!=='') $text=$notice."\n\n".$text;
        $sent=$this->bot->send($chat,$text,$rows,(int)$p['message_id'],(int)$p['thread_id']);
        $this->bot->store->query('UPDATE ig_check_panels SET message_id=?,view=?,data=?,expires=? WHERE token=?',[$sent,$view,json_encode($data,JSON_THROW_ON_ERROR),time()+3600,$p['token']]);
    }
    public function message(array $update,int $owner,int $chat,int $thread): bool
    {
        $message=$update['message']??[]; $text=trim((string)($message['text']??'')); $command=self::command($text);
        if($command!==null||$text===$this->bot->label('instagram_checker')) {
            if($command!==null&&$command['bot']!==''&&strcasecmp($command['bot'],$this->bot->store->get('bot_username'))!==0) return true;
            $name=$command!==null&&$command['argument']!==''?instagramUsername($command['argument']):null;
            if($command!==null&&$command['argument']!==''&&$name===null) { $this->bot->send($chat,$this->bot->text('instagram_checker_invalid'),[],0,$thread); return true; }
            $this->open($owner,$chat,$thread,0,$name===null?'home':'new',$name===null?[]:['username'=>strtolower($name)]); return true;
        }
        $state=$this->bot->store->query('SELECT key FROM states WHERE user_id=? AND expires>?',[$owner,time()])->fetchColumn();
        if(!is_string($state)||!preg_match('/^ic:([a-f0-9]{16})$/D',$state,$match)) return false;
        $p=$this->bot->store->query('SELECT * FROM ig_check_panels WHERE token=? AND owner=? AND chat_id=? AND thread_id=? AND expires>?',[$match[1],$owner,$chat,$chat<0?$thread:0,time()])->fetch();
        if(!$p) return false;
        if(str_starts_with($text,'/')&&$text!=='/cancel') { $this->clearInput($owner); return false; }
        if($text==='/cancel') { $this->clearInput($owner); $this->show($p,'home'); return true; }
        if(!$this->permitted($owner,$chat,$chat===$owner&&$this->bot->admin($owner))) { $this->bot->send($chat,$this->bot->text($this->denial($owner,$chat)),[],0,$thread); return true; }
        $data=json_decode($p['data'],true);
        if($p['view']==='add') {
            $name=instagramUsername($text);
            if($name===null) { $this->show($p,'add',[],$this->bot->text('instagram_checker_invalid')); return true; }
            $this->clearInput($owner); $this->show($p,'new',['username'=>strtolower($name)]); return true;
        }
        if($chat!==$owner||!$this->bot->admin($owner)) return true;
        $text=strtr($text,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹',-1,PREG_SPLIT_NO_EMPTY),range(0,9)));
        if($p['view']==='limit'&&preg_match('/^(?:0|[1-9][0-9]?)$|^100$/D',$text)) $this->bot->store->set('instagram.checker.limit',$text);
        elseif($p['view']==='interval'&&ctype_digit($text)&&(int)$text>=1&&(int)$text<=60) $this->bot->store->set('instagram.checker.seconds',(string)((int)$text*60));
        elseif($p['view']==='userlimit'&&preg_match('/^(@[A-Za-z0-9_]{1,32}|[1-9][0-9]{0,14})\s+(0|[1-9][0-9]?|100)$/D',$text,$m)) {
            $user=$m[1][0]==='@'?(int)$this->bot->store->query('SELECT user_id FROM user_profiles WHERE username=?',[strtolower(substr($m[1],1))])->fetchColumn():(int)$m[1];
            if(!$user) { $this->show($p,'userlimit',$data,$this->bot->text('instagram_checker_user_unknown')); return true; }
            $this->bot->store->set('instagram.checker.limit.'.$user,$m[2]);
        } else { $this->show($p,$p['view'],$data,$this->bot->text('instagram_checker_input_invalid')); return true; }
        $this->clearInput($owner); $this->show($p,'settings',$data,$this->bot->text('instagram_checker_saved')); return true;
    }
    public function callback(array $cb,int $owner,int $chat,int $thread): void
    {
        $answer=function(string $key='',bool $alert=false) use($cb): void { $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$key===''?'':$this->bot->ui->plain($key),'show_alert'=>$alert,'cache_time'=>0]); };
        if(!preg_match('/^ic:([a-f0-9]{16}):([a-z_0-9]+)(?::([0-9]{1,12}))?$/D',(string)$cb['data'],$m)) { $answer('instagram_checker_expired',true); return; }
        $p=$this->bot->store->query('SELECT * FROM ig_check_panels WHERE token=? AND owner=? AND chat_id=? AND message_id=? AND expires>?',[$m[1],$owner,$chat,(int)$cb['message']['message_id'],time()])->fetch();
        $explicitTopic=$chat>=0||array_key_exists('message_thread_id',$cb['message']);
        if(!$p||($explicitTopic&&(int)$p['thread_id']!==($chat<0?$thread:0))) { $answer('instagram_checker_expired',true); return; }
        $thread=(int)$p['thread_id'];
        $op=$m[2]; $id=(int)($m[3]??0); $data=json_decode($p['data'],true);
        $settings=$chat===$owner&&$this->bot->admin($owner);
        if(!$this->permitted($owner,$chat,$settings)) { $answer($this->denial($owner,$chat),true); return; }
        $answer();
        if($op==='close') { $this->clearInput($owner); $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>(int)$p['message_id']]); $this->bot->store->query('DELETE FROM ig_check_panels WHERE token=?',[$p['token']]); return; }
        if(in_array($op,['home','page'],true)) { $this->clearInput($owner); $this->show($p,'home',['page'=>$op==='page'?$id:0]); return; }
        if($op==='add') { $this->show($p,'add'); $this->input($owner,$p['token']); return; }
        if($op==='save'&&$p['view']==='new'&&isset($data['username'])&&in_array($id,[1,2,3],true)) {
            try { $new=$this->checker->add($owner,$chat,$thread,$data['username'],$id); $this->show($p,'item',['id'=>$new],$this->bot->text('instagram_checker_added')); }
            catch(\RuntimeException $e) { $key=in_array($e->getMessage(),['checker_duplicate','checker_limit_reached','checker_denied'],true)?'instagram_'.$e->getMessage():'instagram_checker_invalid'; $this->show($p,'new',$data,$this->bot->text($key)); }
            return;
        }
        if($op==='item') { $this->show($p,'item',['id'=>$id]); return; }
        if($op==='check') {
            if($p['view']!=='item'||(int)($data['id']??0)!==$id) return;
            $sub=$this->item($p,$id);
            if(!$sub) { $this->show($p,'home',[],$this->bot->text('instagram_checker_expired')); return; }
            if(isset($data['check'])) {
                $key='instagram_checker_check_queued';
                if((int)$data['check']['requested']<time()-180) { unset($data['check']); $key='instagram_checker_check_timeout'; }
                $this->show($p,'item',$data,$this->bot->text($key)); return;
            }
            $result=$this->checker->requestCheck($sub,$owner);
            if($result['result']==='queued') $data['check']=$result['check'];
            $key=$result['result']==='denied'?(!InstagramChecker::enabled($this->bot->store)?'instagram_checker_disabled':'instagram_checker_denied'):'instagram_checker_check_'.$result['result'];
            $this->show($p,'item',$data,$this->bot->text($key,['minutes'=>$result['minutes']??1])); return;
        }
        if(in_array($op,['pause','mode','delete','mode1','mode2','mode3','remove'],true)) {
            if(!in_array($p['view'],['item','mode','delete'],true)||(int)($data['id']??0)!==$id) { $this->show($p,'home'); return; }
            if($op==='remove'&&$p['view']!=='delete') return;
            if($op==='delete'||$op==='mode') { $this->show($p,$op,['id'=>$id]); return; }
            $sub=$this->item($p,$id);
            if(!$sub||!$this->checker->change($id,$owner,(int)$sub['chat_id'],(int)$sub['thread_id'],$op==='remove'?'delete':$op,$chat===$owner)) { $this->show($p,'home',[],$this->bot->text('instagram_checker_expired')); return; }
            $this->show($p,$op==='remove'?'home':'item',$op==='remove'?[]:['id'=>$id]); return;
        }
        if(!$settings) return;
        if($op==='settings') { $this->clearInput($owner); $this->show($p,'settings',isset($data['custom'])?['custom'=>$data['custom']]:[]); }
        elseif($p['view']==='settings') {
            if(in_array($op,['enabled','public','source'],true)) {
                $key=match($op) {'enabled'=>'instagram.checker.enabled','public'=>'instagram.checker.public',default=>'instagram.checker.source_button'};
                $store=$this->bot->store; $store->db->exec('BEGIN IMMEDIATE');
                try {
                    $current=$store->get($key,$op==='enabled'?'1':'0'); $store->set($key,$current==='1'?'0':'1');
                    if($op==='enabled') {
                        $store->query('UPDATE ig_check_subs SET posts_ready=0,stories_ready=0,generation=generation+1');
                        $store->query("UPDATE ig_check_deliveries SET status='cancelled' WHERE status IN ('pending','queued','retry')");
                    }
                    $store->db->exec('COMMIT');
                } catch(\Throwable $e) { $store->db->exec('ROLLBACK'); throw $e; }
                if(in_array($op,['enabled','public'],true)) MusicSearch::register($this->bot->api,$this->bot->store,$ui=$this->bot->ui);
                $this->show($p,'settings',$data);
            } elseif(in_array($op,['limit','userlimit','interval'],true)) { $this->show($p,$op,$data); $this->input($owner,$p['token']); }
            elseif($op==='cookies') { $this->clearInput($owner); (new InstagramCheckerCookies($this->bot))->handle($chat,$owner,'cookie_instagram_checker'); }
        }
    }
    private function input(int $owner,string $token): void { $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$owner,'ic:'.$token,time()+600]); }

    /** Finish only an explicitly requested check, while its original view is open. */
    public function refreshChecks(): void
    {
        $store=$this->bot->store;
        $panels=$store->query("SELECT p.* FROM ig_check_panels p JOIN ig_check_subs s ON s.id=json_extract(p.data,'$.id') JOIN ig_check_targets t ON t.id=s.target WHERE p.view='item' AND p.expires>? AND json_type(p.data,'$.check')='object' AND CAST(COALESCE(json_extract(p.data,'$.check.retry_after'),0) AS INTEGER)<=? AND (t.scan_version>json_extract(p.data,'$.check.version') OR CAST(json_extract(p.data,'$.check.requested') AS INTEGER)<?) LIMIT 10",[time(),time(),time()-180])->fetchAll();
        foreach($panels as $p) {
            $data=json_decode($p['data'],true); $check=$data['check']; unset($data['check']);
            $sub=$this->item($p,(int)$data['id']);
            if(!$sub||(int)$sub['generation']!==(int)$check['generation']||!$this->checker->allowed((int)$p['owner'],(int)$sub['chat_id'])) {
                $store->query('UPDATE ig_check_panels SET data=? WHERE token=? AND data=?',[json_encode($data,JSON_THROW_ON_ERROR),$p['token'],$p['data']]); continue;
            }
            $target=$store->query('SELECT * FROM ig_check_targets WHERE id=?',[$sub['target']])->fetch();
            $result=(int)$target['scan_version']<=(int)$check['version']?'timeout':($target['state']==='ok'?'ok':($target['state']==='blocked'?'blocked':'failed'));
            if(!$store->query("SELECT 1 FROM ig_check_panels WHERE token=? AND view='item' AND data=?",[$p['token'],$p['data']])->fetchColumn()) continue;
            try { $this->show($p,'item',$data,$this->bot->text('instagram_checker_check_'.$result,['minutes'=>max(1,(int)ceil(((int)$target['next_check']-time())/60))])); }
            catch(\Throwable) {
                // A result is an edit of the requested message, never a new DM.
                $tries=(int)($check['tries']??0)+1;
                if($tries<3) $data['check']=array_replace($check,['tries'=>$tries,'retry_after'=>time()+30]);
                $store->query('UPDATE ig_check_panels SET data=? WHERE token=? AND data=?',[json_encode($data,JSON_THROW_ON_ERROR),$p['token'],$p['data']]);
            }
        }
    }
}
