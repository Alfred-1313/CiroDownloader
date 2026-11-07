<?php
declare(strict_types=1);
namespace Zero;

final class Personalization
{
    public function __construct(private Bot $bot) {}
    private function send(int $chat,string $text,array $rows,int $message): int
    {
        try { return $this->bot->send($chat,$text,$rows,$message); }
        catch(\RuntimeException $e) {
            if($e->getCode()!==404||$message<1) throw $e;
        }
        $replacement=$this->bot->send($chat,$text,$rows);
        $saved=$this->bot->store->query('SELECT key FROM states WHERE user_id=?',[$chat])->fetchColumn();
        if(is_string($saved)&&str_starts_with($saved,'ui:')) {
            $state=json_decode(substr($saved,3),true);
            if(is_array($state)&&(int)($state['message']??0)===$message) {
                $state['message']=$replacement;
                $this->bot->store->query('UPDATE states SET key=? WHERE user_id=? AND key=?',
                    ['ui:'.json_encode($state,JSON_THROW_ON_ERROR),$chat,$saved]);
            }
        }
        return $replacement;
    }
    public function home(int $chat,int $message=0): void
    {
        $ui=$this->bot->ui;
        $this->bot->store->set('custom.section.'.$chat,'home');
        $state=$ui->plain($this->bot->store->get('admins_only','0')==='1'?'state_on':'state_off');
        $groupState=$ui->plain($this->bot->store->groupsEnabled()?'state_on':'state_off');
        $galleryRows=[];
        foreach(galleryServices() as $service) { $galleryRows[]=[$this->heading($ui->label('section_'.$service))]; $galleryRows[]=[$ui->button('section_'.$service,'custom:section:'.$service)]; }
        $this->send($chat,$ui->text('custom_panel',['state'=>$state]),[
            [$this->heading($ui->label('sections'))],
            [$ui->button('sections','custom:sections')],
            [$this->heading($ui->label('texts'))],
            [$ui->button('texts','custom:groups:text')],
            [$this->heading($ui->label('captions'))],
            [$ui->button('captions','custom:groups:caption')],
            [$this->heading($ui->label('buttons'))],
            [$ui->button('buttons','custom:groups:button')],
            [$this->heading($ui->label('section_group'))],
            [$ui->button('groups_mode','custom:groups_mode',['state'=>$groupState])],
            [$this->heading($ui->label('section_access'))],
            [$ui->button('admins_only','custom:access',['state'=>$state])],
            [$ui->button('access_message','custom:item:text:admin_only_notice')],
            [$this->heading($ui->label('processing_limits'))],
            [$ui->button('processing_limits','custom:limits')],
            [$this->heading($ui->label('reaction_heading'))],
            [$ui->button('reaction_settings','custom:reaction')],
            ...$galleryRows,
            [$ui->button('back_admin','custom:back')],
        ],$message);
    }
    private function heading(string $label): array
    {
        return $this->bot->ui->button('custom_heading','custom:noop',['label'=>$label]);
    }
    private function limits(int $chat,int $message,string $notice=''): void
    {
        $ui=$this->bot->ui; $rows=[];
        foreach(['private','group'] as $scope) {
            $limit=$this->bot->store->processingLimit($scope==='group'?-1:$chat);
            $rows[]=[$this->heading($ui->label('limit_'.$scope))];
            $rows[]=[$ui->button('limit_value','custom:limit:'.$scope,['state'=>$limit>0?(string)$limit:$ui->plain('limit_disabled')])];
        }
        $rows[]=[$ui->button('back_custom','custom:home')];
        $this->send($chat,$ui->text('processing_limits_info').($notice!==''?"\n\n".$notice:''),$rows,$message);
    }
    private function reactions(int $chat,int $message): void
    {
        $ui=$this->bot->ui; $current=Reactions::selected($this->bot->store);
        $rows=[[$this->heading($ui->label('reaction_heading'))]];
        foreach(array_chunk(Reactions::PRESETS,4,true) as $choices) {
            $row=[];
            foreach($choices as $index=>$emoji) $row[]=$ui->button('reaction_choice','custom:reaction:'.$index,['emoji'=>$emoji]);
            $rows[]=$row;
        }
        $rows[]=[$ui->button('reaction_custom','custom:reaction:input')];
        $rows[]=[$ui->button('reaction_off','custom:reaction:off')];
        $rows[]=[$ui->button('back_custom','custom:home')];
        $this->send($chat,$ui->text('reaction_info',['reaction'=>$current!==''?$current:$ui->plain('state_off')]),$rows,$message);
    }
    private function beginReaction(int $chat,int $user,int $message): void
    {
        $ui=$this->bot->ui;
        $message=$this->send($chat,$ui->text('reaction_prompt'),[[$ui->button('back','custom:reaction')]],$message);
        $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'ui:'.json_encode(['kind'=>'reaction','key'=>'link','message'=>$message],JSON_THROW_ON_ERROR),time()+600]);
    }
    private function beginLimit(int $chat,int $user,string $scope,int $message): void
    {
        $ui=$this->bot->ui;
        $message=$this->send($chat,$ui->text('processing_limit_prompt',['title'=>$ui->label('limit_'.$scope)]),[
            [$ui->button('limit_off','custom:limitoff:'.$scope)],
            [$ui->button('back','custom:limits')],
        ],$message);
        $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'ui:'.json_encode(['kind'=>'limit','key'=>$scope,'message'=>$message],JSON_THROW_ON_ERROR),time()+600]);
    }
    private function sectionItems(string $section): array
    {
        $screens=[
            'cover'=>[['text','quality_prompt'],['text','cover_title'],['text','cover_instruction'],['text','cover_limit'],['button','quality_360'],['button','quality_480'],['button','quality_720'],['button','quality_high'],['button','extract_audio']],
            'video'=>[['text','video_caption'],['text','video_title'],['text','video_quality'],['text','video_duration'],['button','source_video']],
            'audio'=>[['text','audio_caption'],['text','audio_title'],['text','audio_format'],['text','audio_duration'],['button','source_audio'],['text','audio_downloading'],['text','audio_uploading'],['text','audio_failed'],['text','audio_error_size'],['text','audio_error_format'],['text','audio_error_upload']],
            'help'=>[['text','welcome'],['text','help'],['text','prompt'],['text','your_id'],['button','help']],
            'group'=>[['text','group_help'],['text','help_groups_hint'],['text','groups_disabled'],['button','add_to_group']],
            'access'=>[['text','offline'],['text','admin_only_notice']],
            'progress'=>[['text','queued'],['text','inspecting'],['text','quality_expired'],['text','quality_busy'],['text','downloading'],['text','uploading'],['text','audio_downloading'],['text','audio_uploading']],
            'errors'=>[],
        ];
        foreach(galleryServices() as $service) $screens[$service]=[['text',$service.'_queued'],['text',$service.'_downloading'],['text',$service.'_uploading'],['text',$service.'_caption'],['text',$service.'_page_caption'],['button',$service.'_prev'],['button',$service.'_next'],['button','source_'.$service],['button','open_'.$service.'_article']];
        if(!array_key_exists($section,$screens)) return [];
        $items=$screens[$section];
        if($section==='errors') foreach($this->entries('text','errors') as $key=>$item) $items[]=['text',$key];
        if(in_array($section,galleryServices(),true)) foreach($this->entries('text',$section) as $key=>$item) if(!in_array(['text',$key],$items,true)) $items[]=['text',$key];
        return array_values(array_filter($items,fn($item)=>$this->bot->ui->customizable($item[0],$item[1])));
    }
    private function sections(int $chat,int $message): void
    {
        $ui=$this->bot->ui; $rows=[[$this->heading($ui->label('sections'))]];
        foreach(array_merge(['cover','video','audio'],galleryServices(),['help','group','access','progress','errors']) as $section) $rows[]=[$ui->button('section_'.$section,'custom:section:'.$section)];
        $rows[]=[$ui->button('back_custom','custom:home')];
        $this->send($chat,$ui->text('section_info',['title'=>$ui->label('sections')]),$rows,$message);
    }
    private function section(int $chat,string $section,int $message): void
    {
        $ui=$this->bot->ui; $items=$this->sectionItems($section);
        if(!$items) { $this->sections($chat,$message); return; }
        $this->bot->store->set('custom.section.'.$chat,$section); $rows=[[$this->heading($ui->label('section_'.$section))]];
        if(in_array($section,galleryServices(),true)) {
            $prefix=galleryPrefix($section);
            $rows[]=[$this->heading($ui->label($section.'_output'))];
            $rows[]=[$ui->button($section.'_mode_pages','custom:'.$prefix.'mode:pages')];
            $rows[]=[$ui->button($section.'_mode_article','custom:'.$prefix.'mode:article')];
            $rows[]=[$this->heading($ui->label($section.'_source_heading'))];
            $rows[]=[$ui->button($section.'_source_toggle','custom:'.$prefix.'source',['state'=>$ui->plain($ui->gallerySourceEnabled($section)?'state_on':'state_off')])];
        }
        foreach($items as [$kind,$key]) $rows[]=[$ui->button('catalog_item','custom:item:'.$kind.':'.$key,['label'=>$ui->definition($kind,$key)['label']])];
        if($section==='cover') {
            $rows[]=[$ui->button('cover_use_parts','custom:covermode:parts')];
            $rows[]=[$ui->button('cover_use_legacy','custom:covermode:legacy')];
            $rows[]=[$ui->button('reorder','custom:reorder')];
        }
        if(in_array($section,['video','audio'],true)) {
            $rows[]=[$ui->button('cover_use_parts','custom:mediamode:'.$section.':parts')];
            $rows[]=[$ui->button('cover_use_legacy','custom:mediamode:'.$section.':legacy')];
        }
        $rows[]=[$ui->button('back','custom:sections')];
        $text=in_array($section,galleryServices(),true)?$ui->text($section.'_mode_info',['mode'=>$ui->label($section.'_mode_'.($this->bot->store->get($section.'.carousel_mode','pages')==='article'?'article':'pages'))]):($section==='cover'?$ui->text('section_cover_info'):$ui->text(in_array($section,['video','audio'],true)?'section_media_info':'section_info',['title'=>$ui->label('section_'.$section)]));
        $this->send($chat,$text,$rows,$message);
    }
    private function reorder(int $chat,int $message,array $order, string $notice='',bool $plain=false): void
    {
        $ui=$this->bot->ui; $rows=[];
        foreach($order as $index=>$quality) {
            $key=$quality==='audio'?'extract_audio':Ui::qualityKey($quality);
            $sample=$plain?$ui->button('catalog_item','custom:noop',['label'=>$ui->definition('button',$key)['label']]):$ui->button($key,'custom:noop',['quality'=>$quality.'p']);
            $row=[$sample];
            if($index>0) $row[]=$ui->button('move_up','custom:move:'.$quality.':up');
            if($index<count($order)-1) $row[]=$ui->button('move_down','custom:move:'.$quality.':down');
            $rows[]=$row;
        }
        $rows[]=[$ui->button('reset_order','custom:orderreset')];
        $rows[]=[$ui->button('back','custom:section:cover')];
        $this->send($chat,$ui->text('reorder_info').($notice!==''?"\n\n".$notice:''),$rows,$message);
    }
    private function coverMode(int $chat,int $message,string $mode): void
    {
        $ui=$this->bot->ui; $ui->preview(['cover.parts'=>$mode]);
        try {
            if($mode==='parts'&&!$ui->coverPartsFit((string)round($this->bot->config->maxBytes/1000000,1))) {
                $this->send($chat,$ui->text('cover_too_long'),[[$ui->button('back','custom:section:cover')]],$message);
                return;
            }
            $caption=$ui->coverCaption($this->samples()['title'],(string)round($this->bot->config->maxBytes/1000000,1));
            $text=$ui->text('custom_item',['title'=>$ui->label('section_cover'),'value'=>$caption],['value'])."\n\n".$ui->text('custom_saved');
            $this->send($chat,$text,[[$ui->button('back','custom:section:cover')]],$message);
        } catch(\RuntimeException $e) {
            if($e->getCode()!==400) throw $e;
            $ui->preview([]);
            $this->send($chat,$ui->text('custom_preview_failed'),[[$ui->button('back','custom:section:cover')]],$message);
            return;
        } finally { $ui->preview([]); }
        $this->bot->store->set('cover.parts',$mode);
    }
    private function mediaMode(int $chat,int $message,string $kind,string $mode): void
    {
        $ui=$this->bot->ui; $ui->preview(['media.'.$kind.'.parts'=>$mode]);
        try {
            if($mode==='parts'&&!$ui->mediaPartsFit($kind)) {
                $this->send($chat,$ui->text('cover_too_long'),[[$ui->button('back','custom:section:'.$kind)]],$message);
                return;
            }
            $caption=$ui->mediaCaption($kind,$this->samples());
            $text=$ui->text('custom_item',['title'=>$ui->label('section_'.$kind),'value'=>$caption],['value'])."\n\n".$ui->text('custom_saved');
            $this->send($chat,$text,[[$ui->button('back','custom:section:'.$kind)]],$message);
        } catch(\RuntimeException $e) {
            if($e->getCode()!==400) throw $e;
            $ui->preview([]);
            $this->send($chat,$ui->text('custom_preview_failed'),[[$ui->button('back','custom:section:'.$kind)]],$message);
            return;
        } finally { $ui->preview([]); }
        $this->bot->store->set('media.'.$kind.'.parts',$mode);
    }
    private function entries(string $kind,string $group=''): array
    {
        $result=[];
        foreach(Ui::definitions()[$kind==='button'?'buttons':'texts'] as $key=>$item) {
            if(!$this->bot->ui->customizable($kind==='button'?'button':'text',$key)||($group!==''&&$item['group']!==$group)) continue;
            if($kind==='caption'&&empty($item['caption'])) continue;
            if($kind==='text'&&!empty($item['caption'])) continue;
            $result[$key]=$item;
        }
        if($group==='download'&&in_array($kind,['caption','button'],true)) {
            $ordered=[];
            foreach(['cover','video','audio'] as $section) foreach($this->sectionItems($section) as [$itemKind,$key]) {
                if(isset($result[$key])&&$itemKind===($kind==='button'?'button':'text')) $ordered[$key]=$result[$key];
            }
            $result=$ordered+$result;
        }
        return $result;
    }
    public function groups(int $chat,string $kind,int $message): void
    {
        if(!in_array($kind,['text','caption','button'],true)) return;
        $this->bot->store->set('custom.section.'.$chat,'');
        $ui=$this->bot->ui; $rows=[[$this->heading($ui->label($kind==='button'?'buttons':($kind==='caption'?'captions':'texts')))]];
        foreach(Ui::definitions()['groups'] as $key=>$label) if($this->entries($kind,$key)) $rows[]=[$ui->button('group_'.$key,'custom:list:'.$kind.':'.$key.':0')];
        $rows[]=[$ui->button('back_custom','custom:home')];
        $this->send($chat,$ui->text('custom_catalog',['title'=>$ui->label($kind==='button'?'buttons':($kind==='caption'?'captions':'texts')),'page'=>1,'pages'=>1]),$rows,$message);
    }
    public function listing(int $chat,string $kind,string $group,int $page,int $message): void
    {
        if(!in_array($kind,['text','caption','button'],true)||!isset(Ui::definitions()['groups'][$group])) return;
        $ui=$this->bot->ui; $items=$this->entries($kind,$group); $size=$this->pageSize($kind,$group); $pages=max(1,(int)ceil(count($items)/$size));
        if(!$items) { $this->groups($chat,$kind,$message); return; }
        $this->bot->store->set('custom.section.'.$chat,'');
        $page=max(0,min($pages-1,$page)); $rows=[[$this->heading($ui->label('group_'.$group))]];
        $lastSection='';
        foreach(array_slice($items,$page*$size,$size,true) as $key=>$item) {
            if($group==='download'&&in_array($kind,['caption','button'],true)) {
                foreach(['cover','video','audio'] as $section) {
                    if(!in_array([$kind==='button'?'button':'text',$key],$this->sectionItems($section),true)) continue;
                    if($lastSection!==$section) $rows[]=[$this->heading($ui->label('catalog_'.$kind.'_'.$section))];
                    $lastSection=$section; break;
                }
            }
            $rows[]=[$ui->button('catalog_item','custom:item:'.($kind==='caption'?'text':$kind).':'.$key,['label'=>$item['label']])];
        }
        $navigation=[];
        if($page>0) $navigation[]=$ui->button('previous',"custom:list:$kind:$group:".($page-1));
        if($page+1<$pages) $navigation[]=$ui->button('next',"custom:list:$kind:$group:".($page+1));
        if($navigation) $rows[]=$navigation;
        $rows[]=[$ui->button('back','custom:groups:'.$kind)];
        $this->send($chat,$ui->text('custom_catalog',['title'=>$ui->label('group_'.$group),'page'=>$page+1,'pages'=>$pages]),$rows,$message);
    }
    private function pageSize(string $kind,string $group): int
    {
        // Keep each caption's components together instead of splitting video
        // title, quality and duration across two catalog pages.
        return $group==='download'&&in_array($kind,['caption','button'],true)?16:8;
    }
    private function samples(): array
    {
        return ['description'=>'متن نمونهٔ پست اینستاگرام','page_url'=>'https://telegra.ph/Example-11-06','index'=>'1','count'=>'12','sent'=>'10','title'=>'عنوان نمونهٔ ویدیو','label'=>'تنظیم نمونه','quality'=>'720p','format'=>'MP3','duration'=>'05:32','url'=>'https://youtu.be/ybNMMK5Q318','max_size'=>(string)round($this->bot->config->maxBytes/1000000,1),'id'=>'123456','username'=>'CiroDownloaderBot',
            'state'=>$this->bot->ui->plain('state_on'),'poller'=>'آنلاین','worker'=>'آنلاین','queued'=>'0','running'=>'0','users'=>'120','completed'=>'34','failed'=>'2','cookies'=>'تنظیم شده','proxy'=>'تنظیم نشده','error'=>'خطای نمونه',
            'page'=>'1','pages'=>'1','variables'=>'{title}, {quality}, {url}','value'=>'متن نمونه','notice'=>'','checked'=>'2026-11-03 12:00','version'=>Updates::version(),'bar'=>'▰▰▰▰▰▱▱▱▱▱ 50%','stage'=>'در حال بررسی','changes'=>'• یک قابلیت تازه','status'=>'کوکی قبلی موجود است.','percent'=>'50'];
    }
    public function item(int $chat,string $kind,string $key,int $message=0,string $notice='',bool $strictPreview=false): void
    {
        $ui=$this->bot->ui; $definition=$ui->definition($kind,$key);
        if(!$ui->customizable($kind,$key)) { $this->home($chat,$message); return; }
        $samples=$this->samples();
        if(preg_match('/^quality_(360|480|720)$/D',$key,$match)) $samples['quality']=$match[1].'p';
        elseif($key==='quality_high') $samples['quality']='1080p';
        $value=$kind==='button'?Ui::escape($ui->label($key,$samples)):$ui->text($key,$samples);
        $text=$ui->text('custom_item',['title'=>$definition['label'],'value'=>$value],['value']).($notice!==''?"\n\n".$notice:'');
        $rows=[];
        if($kind==='button') $rows[]=[$ui->button($key,'custom:noop',$samples)];
        $rows[]=[$ui->button('edit',"custom:edit:$kind:$key")];
        if($kind==='button') {
            $rows[]=[$ui->button('color','custom:color:'.$key),$ui->button('icon','custom:icon:'.$key)];
            $rows[]=[$ui->button('remove_icon','custom:clearicon:'.$key)];
        }
        $rows[]=[$ui->button('reset',"custom:reset:$kind:$key")];
        $listKind=!empty($definition['caption'])?'caption':$kind;
        $position=array_search($key,array_keys($this->entries($listKind,$definition['group'])),true);
        $page=$position===false?0:intdiv($position,$this->pageSize($listKind,$definition['group']));
        $section=$this->bot->store->get('custom.section.'.$chat);
        $back=in_array([$kind,$key],$this->sectionItems($section),true)?'custom:section:'.$section:"custom:list:$listKind:".$definition['group'].':'.$page;
        if($section==='home'&&$kind==='text'&&$key==='admin_only_notice') $back='custom:home';
        $rows[]=[$ui->button('back',$back)];
        try { $this->send($chat,$text,$rows,$message); }
        catch(\RuntimeException $e) {
            if($strictPreview||$e->getCode()!==400) throw $e;
            // Keep repair controls usable when a previously saved emoji or text
            // is no longer accepted by Telegram. Never use this fallback to save.
            if($kind==='button') array_shift($rows);
            $plain=Ui::escape(html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            $text=$ui->text('custom_item',['title'=>$definition['label'],'value'=>$plain],['value']);
            $text.="\n\n".$ui->text('custom_preview_unavailable').($notice!==''?"\n\n".$notice:'');
            $this->send($chat,$text,$rows,$message);
        }
    }
    public function begin(int $chat,int $user,string $kind,string $key,int $message=0): void
    {
        $ui=$this->bot->ui; $definition=$ui->definition($kind==='icon'?'button':$kind,$key);
        if(!$ui->customizable($kind==='icon'?'button':$kind,$key)) { $this->home($chat,$message); return; }
        $variables=empty($definition['variables'])?'':$ui->text('custom_variables',['variables'=>implode(', ',array_map(fn($name)=>'{'.$name.'}',$definition['variables']))]);
        if(!empty($definition['inline'])) $variables.="\n".$ui->text('custom_inline_hint');
        $text=$kind==='icon'?$ui->text('custom_icon_prompt'):$ui->text('custom_prompt',['title'=>$definition['label'],'variables'=>$variables],['variables']);
        $message=$this->send($chat,$text,[[$ui->button('cancel','custom:item:'.($kind==='icon'?'button':$kind).':'.$key)]],$message);
        $this->bot->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,'ui:'.json_encode(['kind'=>$kind,'key'=>$key,'message'=>$message],JSON_THROW_ON_ERROR),time()+600]);
    }
    private function commit(int $chat,string $kind,string $key,int $message,array $values,array $remove=[]): bool
    {
        $ui=$this->bot->ui;
        if(!$ui->customizable($kind,$key)) { $this->home($chat,$message); return false; }
        if($kind==='text'&&in_array($key,['cover_title','cover_instruction','cover_limit'],true)) $values['cover.parts']='parts';
        elseif($kind==='text'&&$key==='quality_prompt') $values['cover.parts']='legacy';
        if($kind==='text') foreach(['video'=>['video_title','video_quality','video_duration'],'audio'=>['audio_title','audio_format','audio_duration']] as $media=>$fields) {
            if(in_array($key,$fields,true)) $values['media.'.$media.'.parts']='parts';
            elseif($key===$media.'_caption') $values['media.'.$media.'.parts']='legacy';
        }
        try {
            $ui->preview($values);
            if($kind==='text'&&in_array($key,['cover_title','cover_instruction','cover_limit'],true)&&!$ui->coverPartsFit((string)round($this->bot->config->maxBytes/1000000,1))) {
                $ui->preview([]);
                $this->item($chat,$kind,$key,$message,$ui->text('cover_too_long'));
                return false;
            }
            if($kind==='text') foreach(['video'=>['video_title','video_quality','video_duration'],'audio'=>['audio_title','audio_format','audio_duration']] as $media=>$fields) {
                if(in_array($key,$fields,true)&&!$ui->mediaPartsFit($media)) {
                    $ui->preview([]); $this->item($chat,$kind,$key,$message,$ui->text('cover_too_long')); return false;
                }
            }
            if((array_key_exists('text.help',$values)||array_key_exists('text.help_groups_hint',$values))&&!(new GroupSupport($this->bot))->helpFits()) {
                $ui->preview([]);
                $this->item($chat,$kind,$key,$message,$ui->text('custom_help_too_long'));
                return false;
            }
            $this->item($chat,$kind,$key,$message,$ui->text('custom_saved'),true);
        } catch(\RuntimeException $e) {
            if($e->getCode()!==400) throw $e;
            $ui->preview([]);
            $this->item($chat,$kind,$key,$message,$ui->text('custom_preview_failed'));
            return false;
        } finally { $ui->preview([]); }
        // Legacy installations shared the source button. Detach each audio
        // attribute before changing its video counterpart so the old audio
        // appearance remains stable without losing existing customization.
        if($kind==='button'&&$key==='source_video') {
            $old=$ui->button('source_video');
            foreach(['button.'=>$ui->label('source_video'),'button.style.'=>$old['style']??'','button.icon.'=>$old['icon_custom_emoji_id']??''] as $prefix=>$value) {
                $videoKey=$prefix.'source_video'; $audioKey=$prefix.'source_audio';
                if(array_key_exists($videoKey,$values)&&!$this->bot->store->query('SELECT 1 FROM settings WHERE key=?',[$audioKey])->fetchColumn()) $values[$audioKey]=$value;
            }
        }
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
        if($kind==='reaction'&&$key==='link') {
            $custom=array_filter($message['entities']??[],fn($entity)=>($entity['type']??'')==='custom_emoji');
            $emoji=$custom?null:Reactions::normalize((string)($message['text']??''));
            if($emoji===null) {
                $this->send($chat,$ui->text('reaction_invalid'),[[$ui->button('back','custom:reaction')]],(int)$menu); return;
            }
            $this->bot->store->set('link_reaction',$emoji);
            $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $this->reactions($chat,(int)$menu); return;
        }
        if($kind==='limit'&&in_array($key,['private','group'],true)) {
            $value=strtr(trim((string)($message['text']??'')),array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
            if(!preg_match('/^(?:[0-9]|1[0-9]|20)$/D',$value)) {
                $this->send($chat,$ui->text('processing_limit_invalid'),[[$ui->button('back','custom:limits')]],(int)$menu);
                return;
            }
            $this->bot->store->set('processing_limit.'.$key,$value);
            $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $this->limits($chat,(int)$menu,$ui->text('custom_saved'));
            return;
        }
        if(!$ui->customizable($kind==='icon'?'button':$kind,$key)) {
            $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $this->home($chat,(int)$menu); return;
        }
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
        $target=match($op) {
            'item','edit','reset'=>[$kind,$key],
            'icon','color','setcolor','clearicon'=>['button',$kind],
            default=>null,
        };
        if($target&&!$ui->customizable($target[0],$target[1])) { $this->home($chat,$message); return true; }
        if($op==='home') $this->home($chat,$message);
        elseif($op==='reaction') {
            if($kind==='input') $this->beginReaction($chat,$user,$message);
            else {
                if($kind==='off') $this->bot->store->set('link_reaction','');
                elseif(ctype_digit($kind)&&isset(Reactions::PRESETS[(int)$kind])) $this->bot->store->set('link_reaction',Reactions::PRESETS[(int)$kind]);
                $this->reactions($chat,$message);
            }
        }
        elseif($op==='limits') $this->limits($chat,$message);
        elseif($op==='limit'&&in_array($kind,['private','group'],true)) $this->beginLimit($chat,$user,$kind,$message);
        elseif($op==='limitoff'&&in_array($kind,['private','group'],true)) {
            $this->bot->store->set('processing_limit.'.$kind,'0');
            $this->limits($chat,$message,$ui->text('custom_saved'));
        }
        elseif($op==='sections') $this->sections($chat,$message);
        elseif($op==='section') $this->section($chat,$kind,$message);
        elseif(in_array($op,['igmode','pinmode','ttmode','xmode'],true)&&in_array($kind,['pages','article'],true)) {
            $service=galleryFromPrefix(substr($op,0,-4));
            $this->bot->store->set($service.'.carousel_mode',$kind);
            $this->section($chat,$service,$message);
        }
        elseif(in_array($op,['igsource','pinsource','ttsource','xsource'],true)) {
            $service=galleryFromPrefix(substr($op,0,-6));
            $this->bot->store->set($service.'.source_button',$ui->gallerySourceEnabled($service)?'0':'1');
            $this->section($chat,$service,$message);
        }
        elseif($op==='covermode'&&in_array($kind,['parts','legacy'],true)) $this->coverMode($chat,$message,$kind);
        elseif($op==='mediamode'&&in_array($kind,['video','audio'],true)&&in_array($key,['parts','legacy'],true)) $this->mediaMode($chat,$message,$kind,$key);
        elseif($op==='reorder') {
            try { $this->reorder($chat,$message,$ui->coverOrder()); }
            catch(\RuntimeException $e) { if($e->getCode()!==400) throw $e; $this->reorder($chat,$message,$ui->coverOrder(),$ui->text('custom_preview_unavailable'),true); }
        }
        elseif($op==='move'&&in_array($kind,['360','480','720','1080','audio'],true)&&in_array($key,['up','down'],true)) {
            $order=$ui->coverOrder(); $index=array_search($kind,$order,true); $other=$index+($key==='up'?-1:1);
            if($other>=0&&$other<count($order)) [$order[$index],$order[$other]]=[$order[$other],$order[$index]];
            try { $this->reorder($chat,$message,$order); $this->bot->store->set('cover.order',json_encode($order,JSON_THROW_ON_ERROR)); }
            catch(\RuntimeException $e) { if($e->getCode()!==400) throw $e; $this->reorder($chat,$message,$ui->coverOrder(),$ui->text('custom_preview_failed'),true); }
        }
        elseif($op==='orderreset') {
            try { $this->reorder($chat,$message,['360','480','720','1080','audio']); $this->bot->store->query('DELETE FROM settings WHERE key=?',['cover.order']); }
            catch(\RuntimeException $e) { if($e->getCode()!==400) throw $e; $this->reorder($chat,$message,$ui->coverOrder(),$ui->text('custom_preview_failed'),true); }
        }
        elseif($op==='groups') { $this->bot->store->set('custom.section.'.$chat,''); $this->groups($chat,$kind,$message); }
        elseif($op==='list') { $this->bot->store->set('custom.section.'.$chat,''); $this->listing($chat,$kind,$key,(int)($parts[4]??0),$message); }
        elseif($op==='item') $this->item($chat,$kind,$key,$message);
        elseif($op==='edit') $this->begin($chat,$user,$kind,$key,$message);
        elseif($op==='icon') $this->begin($chat,$user,'icon',$kind,$message);
        elseif($op==='access') {
            $this->bot->store->set('admins_only',$this->bot->store->get('admins_only','0')==='1'?'0':'1');
            $this->home($chat,$message);
        } elseif($op==='groups_mode') {
            $this->bot->store->set('groups_enabled',$this->bot->store->groupsEnabled()?'0':'1');
            (new GroupSupport($this->bot))->syncHelpButtons();
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
            if($kind==='button') {
                $parent=$definition['inherits']??''; $parentButton=$parent!==''?$ui->button($parent):[];
                if($parent!=='') $values['button.'.$key]=$ui->label($parent);
                $values+=['button.style.'.$key=>$parentButton['style']??($definition['style']??''),'button.icon.'.$key=>$parentButton['icon_custom_emoji_id']??''];
            }
            $this->commit($chat,$kind,$key,$message,$values,array_keys($values));
        }
        return true;
    }
}
