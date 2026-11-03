<?php
declare(strict_types=1);
namespace Zero;

final class Personalization
{
    public function __construct(private Bot $bot) {}
    private function send(int $chat,string $text,array $rows,int $message): void { $this->bot->send($chat,$text,$rows,$message); }
    public function home(int $chat,int $message=0): void
    {
        $ui=$this->bot->ui;
        $state=$ui->plain($this->bot->store->get('admins_only','0')==='1'?'state_on':'state_off');
        $this->send($chat,$ui->text('custom_panel',['state'=>$state]),[
            [$ui->button('texts','custom:groups:text'),$ui->button('captions','custom:groups:caption')],
            [$ui->button('buttons','custom:groups:button')],
            [$ui->button('admins_only','custom:access',['state'=>$state])],
            [$ui->button('access_message','custom:item:text:admin_only_notice')],
            [$ui->button('back_admin','custom:back')],
        ],$message);
    }
    private function entries(string $kind,string $group=''): array
    {
        $result=[];
        foreach(Ui::definitions()[$kind==='button'?'buttons':'texts'] as $key=>$item) {
            if(!empty($item['hidden'])||($group!==''&&$item['group']!==$group)) continue;
            if($kind==='caption'&&empty($item['caption'])) continue;
            if($kind==='text'&&!empty($item['caption'])) continue;
            $result[$key]=$item;
        }
        return $result;
    }
    public function groups(int $chat,string $kind,int $message): void
    {
        if(!in_array($kind,['text','caption','button'],true)) return;
        $ui=$this->bot->ui; $rows=[];
        foreach(Ui::definitions()['groups'] as $key=>$label) if($this->entries($kind,$key)) $rows[]=[$ui->button('group_'.$key,'custom:list:'.$kind.':'.$key.':0')];
        $rows[]=[$ui->button('back_custom','custom:home')];
        $this->send($chat,$ui->text('custom_catalog',['title'=>$ui->label($kind==='button'?'buttons':($kind==='caption'?'captions':'texts')),'page'=>1,'pages'=>1]),$rows,$message);
    }
    public function listing(int $chat,string $kind,string $group,int $page,int $message): void
    {
        if(!in_array($kind,['text','caption','button'],true)||!isset(Ui::definitions()['groups'][$group])) return;
        $ui=$this->bot->ui; $items=$this->entries($kind,$group); $pages=max(1,(int)ceil(count($items)/8));
        $page=max(0,min($pages-1,$page)); $rows=[];
        foreach(array_slice($items,$page*8,8,true) as $key=>$item) $rows[]=[$ui->button('catalog_item','custom:item:'.($kind==='caption'?'text':$kind).':'.$key,['label'=>$item['label']])];
        $navigation=[];
        if($page>0) $navigation[]=$ui->button('previous',"custom:list:$kind:$group:".($page-1));
        if($page+1<$pages) $navigation[]=$ui->button('next',"custom:list:$kind:$group:".($page+1));
        if($navigation) $rows[]=$navigation;
        $rows[]=[$ui->button('back','custom:groups:'.$kind)];
        $this->send($chat,$ui->text('custom_catalog',['title'=>$ui->label('group_'.$group),'page'=>$page+1,'pages'=>$pages]),$rows,$message);
    }
    private function samples(): array
    {
        return ['title'=>'عنوان نمونهٔ ویدیو','label'=>'تنظیم نمونه','quality'=>'1080p','format'=>'MP3','duration'=>'05:32','url'=>'https://youtu.be/ybNMMK5Q318','max_size'=>'49','id'=>'123456',
            'state'=>$this->bot->ui->plain('state_on'),'poller'=>'آنلاین','worker'=>'آنلاین','queued'=>'0','running'=>'0','users'=>'120','completed'=>'34','failed'=>'2','cookies'=>'تنظیم شده','proxy'=>'تنظیم نشده','error'=>'خطای نمونه',
            'page'=>'1','pages'=>'1','variables'=>'{title}, {quality}, {url}','value'=>'متن نمونه','notice'=>'','checked'=>'2026-11-03 12:00','version'=>Updates::version(),'bar'=>'▰▰▰▰▰▱▱▱▱▱ 50%','stage'=>'در حال بررسی','changes'=>'• یک قابلیت تازه','status'=>'کوکی قبلی موجود است.','percent'=>'50'];
    }
    public function item(int $chat,string $kind,string $key,int $message=0,string $notice=''): void
    {
        $ui=$this->bot->ui; $definition=$ui->definition($kind,$key);
        if(!$definition||!in_array($kind,['text','button'],true)) return;
        $value=$kind==='button'?Ui::escape($ui->label($key,$this->samples())):$ui->text($key,$this->samples());
        $text=$ui->text('custom_item',['title'=>$definition['label'],'value'=>$value],['value']).($notice!==''?"\n\n".$notice:'');
        $rows=[];
        if($kind==='button') $rows[]=[$ui->button($key,'custom:noop',$this->samples())];
        $rows[]=[$ui->button('edit',"custom:edit:$kind:$key")];
        if($kind==='button') {
            $rows[]=[$ui->button('color','custom:color:'.$key),$ui->button('icon','custom:icon:'.$key)];
            $rows[]=[$ui->button('remove_icon','custom:clearicon:'.$key)];
        }
        $rows[]=[$ui->button('reset',"custom:reset:$kind:$key")];
        $listKind=!empty($definition['caption'])?'caption':$kind;
        $rows[]=[$ui->button('back',"custom:list:$listKind:".$definition['group'].':0')];
        $this->send($chat,$text,$rows,$message);
    }
    public function begin(int $chat,int $user,string $kind,string $key,int $message=0): void
    {
        $ui=$this->bot->ui; $definition=$ui->definition($kind==='icon'?'button':$kind,$key);
        if(!$definition) return;
        $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'ui:'.json_encode(['kind'=>$kind,'key'=>$key,'message'=>$message],JSON_THROW_ON_ERROR),time()+600]);
        $variables=empty($definition['variables'])?'':$ui->text('custom_variables',['variables'=>implode(', ',array_map(fn($name)=>'{'.$name.'}',$definition['variables']))]);
        if(!empty($definition['inline'])) $variables.="\n".$ui->text('custom_inline_hint');
        $text=$kind==='icon'?$ui->text('custom_icon_prompt'):$ui->text('custom_prompt',['title'=>$definition['label'],'variables'=>$variables],['variables']);
        $this->send($chat,$text,[[$ui->button('cancel','custom:item:'.($kind==='icon'?'button':$kind).':'.$key)]],$message);
    }
    private function commit(int $chat,string $kind,string $key,int $message,array $values,array $remove=[]): bool
    {
        $ui=$this->bot->ui;
        try {
            $ui->preview($values);
            $this->item($chat,$kind,$key,$message,$ui->text('custom_saved'));
        } catch(\Throwable) {
            $ui->preview([]);
            $this->item($chat,$kind,$key,$message,$ui->text('custom_preview_failed'));
            return false;
        } finally { $ui->preview([]); }
        if($kind==='button'&&in_array($key,Ui::REPLY_KEYS,true)&&isset($values['button.'.$key])) {
            $aliases=json_decode($this->bot->store->get('button.aliases.'.$key,'[]'),true);
            $aliases=array_slice(array_values(array_unique(array_merge([$ui->label($key)],is_array($aliases)?$aliases:[]))),0,10);
            $values['button.aliases.'.$key]=json_encode($aliases,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        }
        $this->bot->store->db->exec('BEGIN IMMEDIATE');
        try {
            foreach($values as $name=>$value) $this->bot->store->set($name,$value);
            foreach($remove as $name) $this->bot->store->query('DELETE FROM settings WHERE key=?',[$name]);
            $this->bot->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->bot->store->db->exec('ROLLBACK'); throw $e; }
        return true;
    }
    public function receive(int $chat,int $user,array $message,array $state): void
    {
        $ui=$this->bot->ui; ['kind'=>$kind,'key'=>$key,'message'=>$menu]=$state;
        $values=[];
        try {
            $definition=$ui->definition($kind==='icon'?'button':$kind,$key);
            if(!$definition) throw new \RuntimeException('Unknown customization item');
            if($kind==='text') {
                $value=TextFormat::fromMessage($message);
                TextFormat::validate($value,$definition['limit']??(!empty($definition['caption'])?700:3000));
                if(!empty($definition['inline'])&&preg_match('~</?(?:blockquote|pre)(?:\s|>)~',$value)) throw new \RuntimeException('This is an inline text field');
                $values['text.'.$key]=$value;
            } elseif($kind==='button') {
                $value=TextFormat::buttonText($message);
                if($value===''||TextFormat::length($value)>48||str_contains($value,"\n")||str_contains($value,'<')) throw new \RuntimeException('Invalid button label');
                $values['button.'.$key]=$value;
                if($icon=TextFormat::emoji($message)) $values['button.icon.'.$key]=$icon;
            } elseif($kind==='icon') {
                $value=TextFormat::emoji($message)??trim((string)($message['text']??''));
                if(!preg_match('/^\d{5,24}$/D',$value)) throw new \RuntimeException('Invalid custom emoji ID');
                $values['button.icon.'.$key]=$value;
            } else throw new \RuntimeException('Invalid customization kind');
        } catch(\Throwable) {
            $this->send($chat,$ui->text('custom_invalid'),[[$ui->button('cancel','custom:item:'.($kind==='icon'?'button':$kind).':'.$key)]],(int)$menu);
            return;
        }
        if($this->commit($chat,$kind==='icon'?'button':$kind,$key,(int)$menu,$values)) $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
    }
    public function handle(int $chat,int $user,string $action,int $message): bool
    {
        $ui=$this->bot->ui; $parts=explode(':',$action);
        if(($parts[0]??'')!=='custom') return false;
        $op=$parts[1]??''; $kind=$parts[2]??''; $key=$parts[3]??'';
        if($op==='home') $this->home($chat,$message);
        elseif($op==='groups') $this->groups($chat,$kind,$message);
        elseif($op==='list') $this->listing($chat,$kind,$key,(int)($parts[4]??0),$message);
        elseif($op==='item') $this->item($chat,$kind,$key,$message);
        elseif($op==='edit') $this->begin($chat,$user,$kind,$key,$message);
        elseif($op==='icon') $this->begin($chat,$user,'icon',$kind,$message);
        elseif($op==='access') {
            $this->bot->store->set('admins_only',$this->bot->store->get('admins_only','0')==='1'?'0':'1');
            $this->home($chat,$message);
        } elseif($op==='back') $this->bot->panel($chat,$message);
        elseif($op==='color'&&$ui->definition('button',$kind)) {
            $rows=[];
            foreach(['default','primary','success','danger'] as $color) $rows[]=[$ui->button('color_'.$color,'custom:setcolor:'.$kind.':'.$color)];
            $rows[]=[$ui->button('back','custom:item:button:'.$kind)];
            $this->send($chat,$ui->text('custom_catalog',['title'=>$ui->label('color'),'page'=>1,'pages'=>1]),$rows,$message);
        } elseif($op==='setcolor'&&$ui->definition('button',$kind)&&in_array($key,['default','primary','success','danger'],true)) {
            $this->commit($chat,'button',$kind,$message,['button.style.'.$kind=>$key==='default'?'':$key]);
        } elseif($op==='clearicon'&&$ui->definition('button',$kind)) $this->commit($chat,'button',$kind,$message,['button.icon.'.$kind=>'']);
        elseif($op==='reset'&&($definition=$ui->definition($kind,$key))) {
            $values=[$kind.'.'.$key=>$definition['value']];
            if($kind==='button') $values+=['button.style.'.$key=>$definition['style']??'','button.icon.'.$key=>''];
            $this->commit($chat,$kind,$key,$message,$values,array_keys($values));
        }
        return true;
    }
}
