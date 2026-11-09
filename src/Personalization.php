<?php
declare(strict_types=1);
namespace Zero;

final class Personalization
{
    private const PAGE_SIZE=12;
    public function __construct(private Bot $bot) {}
    /**
     * One colour rule for every Personalization screen: titles (they only explain themselves) are always white, every
     * other button you can tap is blue. A state shows in the button's text (✅ / ❌), not in its colour. Only the colour
     * picker keeps its own colours, because those buttons demonstrate them.
     */
    private function recolor(array $rows): array
    {
        foreach($rows as $r=>$row) foreach($row as $c=>$button) {
            $data=(string)($button['callback_data']??'');
            if($data==='') continue;
            if($data==='custom:noop'||str_starts_with($data,'custom:hint:')) unset($rows[$r][$c]['style']);
            elseif(!str_starts_with($data,'custom:setcolor:')) $rows[$r][$c]['style']='primary';
        }
        return $rows;
    }
    private function send(int $chat,string $text,array $rows,int $message): int
    {
        $rows=$this->recolor($rows);
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
    public function home(int $chat,int $message=0,string $notice=''): void
    {
        $ui=$this->bot->ui; $saved=$this->saved();
        $this->bot->store->set('custom.section.'.$chat,'home');
        $rows=[];
        foreach(['youtube'=>['cover','video','audio'],'social'=>[...galleryServices(),'pornhub'],'music'=>[...musicServices(),'menu','batch','search'],'general'=>['help','group','access'],'status'=>['progress','errors']] as $id=>$sections) {
            $rows[]=[$this->heading($ui->label('home_'.$id),'home_'.$id)];
            foreach($sections as $section) {
                $button=$ui->button('section_'.$section,'custom:section:'.$section);
                if($this->sectionCustomized($section,$saved)) $button['style']='success';
                $rows[]=[$button];
            }
        }
        $rows[]=[$this->heading($ui->label('home_features'),'home_features')];
        $rows[]=[$ui->button('features_hub','custom:features')];
        $rows[]=[$ui->button('reset_all','custom:resetall'),$ui->button('close','custom:close')];
        $this->send($chat,$ui->text('custom_panel').($notice!==''?"\n\n".$notice:''),$rows,$message);
    }
    /** Switches that flip at once; their state is the ✅ / ❌ in the label. */
    private function features(int $chat,int $message=0,string $notice=''): void
    {
        $ui=$this->bot->ui; $store=$this->bot->store;
        $store->set('custom.section.'.$chat,'features');
        $rows=[
            [$this->heading($ui->label('feat_group'),'feat_group')],
            [$this->switchButton('groups_mode','custom:groups_mode',$store->groupsEnabled())],
            [$this->heading($ui->label('feat_access'),'feat_access')],
            [$this->switchButton('admins_only','custom:access',$store->get('admins_only','0')==='1')],
            [$ui->button('access_message','custom:item:text:admin_only_notice')],
            [$this->heading($ui->label('feat_adult'),'feat_adult')],
            [$ui->button('adult_page','custom:adultpage')],
            [$this->heading($ui->label('feat_reaction'),'feat_reaction')],
            [$ui->button('reaction_settings','custom:reaction')],
            [$this->heading($ui->label('feat_limits'),'feat_limits')],
            [$ui->button('processing_limits','custom:limits')],
            [$ui->button('back_custom','custom:home'),$ui->button('close','custom:close')],
        ];
        $this->send($chat,$ui->text('custom_features').($notice!==''?"\n\n".$notice:''),$rows,$message);
    }
    /** The 18+ site's own page: the master switch, who may use it, the two deletion filters and the delay. */
    private function adultPage(int $chat,int $message=0): void
    {
        $ui=$this->bot->ui; $store=$this->bot->store;
        $store->set('custom.section.'.$chat,'features');
        $rows=[
            [$this->heading($ui->label('adult_who'),'adult_who')],
            [$this->switchButton('adult_pornhub','custom:adult',$store->get('adult.pornhub','0')==='1')],
            [$this->switchButton('adult_admins','custom:adultadmins',$store->get('adult.admins_only','1')==='1')],
            [$this->heading($ui->label('adult_filters'),'adult_filters')],
            [$this->switchButton('adult_delete_private','custom:adultdelp',$store->get('adult.delete_private','1')==='1')],
            [$this->switchButton('adult_delete_group','custom:adultdelg',$store->get('adult.delete_group','1')==='1')],
            [$ui->button('adult_delete_seconds','custom:adultsec',['n'=>(string)Bot::adultDelays($store)])],
            [$ui->button('back_features','custom:features'),$ui->button('close','custom:close')],
        ];
        $this->send($chat,$ui->text('custom_adult'),$rows,$message);
    }
    private function resetAll(int $chat,int $message): void
    {
        $ui=$this->bot->ui; $store=$this->bot->store; $names=['cover.parts','media.video.parts','media.audio.parts','cover.order'];
        foreach(['text','button'] as $kind) foreach(Ui::definitions()[$kind==='button'?'buttons':'texts'] as $key=>$item) {
            if(!$ui->customizable($kind,$key)) continue;
            if($kind==='text') $names[]='text.'.$key;
            else array_push($names,'button.'.$key,'button.style.'.$key,'button.icon.'.$key,'button.emoji.'.$key,'button.pos.'.$key,'button.plain.'.$key,'button.hidden.'.$key);
        }
        $names[]='cover.per_row';
        $store->db->exec('BEGIN IMMEDIATE');
        try {
            foreach($names as $name) $store->query('DELETE FROM settings WHERE key=?',[$name]);
            $store->db->exec('COMMIT');
        } catch(\Throwable $e) { $store->db->exec('ROLLBACK'); throw $e; }
        $this->home($chat,$message,$ui->text('custom_reset_done'));
    }
    private function confirmResetAll(int $chat,int $message): void
    {
        $ui=$this->bot->ui;
        $this->send($chat,$ui->text('custom_reset_all'),[[$ui->button('reset_all_yes','custom:resetall:yes')],[$ui->button('cancel','custom:home')]],$message);
    }
    private function heading(string $label,string $id=''): array
    {
        return $this->bot->ui->button('custom_heading',$id!==''?'custom:hint:'.$id:'custom:noop',['label'=>$label]);
    }
    /** Short alert explaining a white title button; empty for unknown titles. */
    public function hint(string $id): string
    {
        $ui=$this->bot->ui;
        return preg_match('/^[a-z_]{1,24}$/D',$id)&&$ui->definition('text','hint_'.$id)?mb_substr($ui->plain('hint_'.$id),0,200):'';
    }
    /** @return array<string,string> saved text.* and button.* settings */
    private function saved(): array
    {
        $result=[];
        foreach($this->bot->store->query("SELECT key,value FROM settings WHERE key LIKE 'text.%' OR key LIKE 'button.%'")->fetchAll() as $row) $result[$row['key']]=$row['value'];
        return $result;
    }
    private function customized(string $kind,string $key,array $saved): bool
    {
        $definition=$this->bot->ui->definition($kind,$key);
        if(!$definition) return false;
        if($kind==='text') return isset($saved['text.'.$key])&&$saved['text.'.$key]!==$definition['value'];
        if(isset($saved['button.'.$key])&&$saved['button.'.$key]!==$definition['value']) return true;
        if(isset($saved['button.style.'.$key])&&$saved['button.style.'.$key]!==($definition['style']??'')) return true;
        return !empty($saved['button.icon.'.$key])||!empty($saved['button.emoji.'.$key])||($saved['button.plain.'.$key]??'')==='1'||($saved['button.hidden.'.$key]??'')==='1';
    }
    private function sectionCustomized(string $section,array $saved): bool
    {
        foreach($this->sectionItems($section) as [$kind,$key]) if($this->customized($kind,$key,$saved)) return true;
        return false;
    }
    /** A list row: green with a marker once the administrator has changed it. */
    private function marked(string $kind,string $key,array $saved): array
    {
        $ui=$this->bot->ui; $changed=$this->customized($kind,$key,$saved);
        $button=$ui->button('catalog_item','custom:item:'.$kind.':'.$key,['label'=>$changed?$ui->plainWith($kind==='button'?'mark_button':'mark_text',['label'=>$ui->definition($kind,$key)['label']]):$ui->definition($kind,$key)['label']]);
        if($changed) $button['style']='success';
        return $button;
    }
    private function switchButton(string $key,string $data,bool $on): array
    {
        $ui=$this->bot->ui;
        $button=$ui->button($key,$data,['state'=>$ui->plain($on?'switch_on':'switch_off')]);
        $button['style']=$on?'success':'danger';
        return $button;
    }
    /** A toggle whose label carries its state; "on" is not a success colour here. */
    private function switchLabel(string $key,string $data,bool $on,bool $colour=true): array
    {
        $ui=$this->bot->ui;
        $button=$ui->button($key,$data,['state'=>$ui->plain($on?'switch_on':'switch_off')]);
        if($colour) $button['style']=$on?'success':'danger';
        return $button;
    }
    private function choice(string $key,string $data,bool $selected): array
    {
        $button=$this->bot->ui->button($key,$data);
        if($selected) { $button['text']=$this->bot->ui->plainWith('mark_chosen',['label'=>$button['text']]); $button['style']='success'; }
        return $button;
    }
    private function limits(int $chat,int $message,string $notice=''): void
    {
        $ui=$this->bot->ui; $rows=[];
        foreach(['private','group'] as $scope) {
            $limit=$this->bot->store->processingLimit($scope==='group'?-1:$chat);
            $rows[]=[$this->heading($ui->label('limit_'.$scope))];
            $rows[]=[$ui->button('limit_value','custom:limit:'.$scope,['state'=>$limit>0?(string)$limit:$ui->plain('limit_disabled')])];
        }
        $rows[]=[$ui->button('back_features','custom:features')];
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
        $rows[]=[$this->heading($ui->label('reaction_music_heading'))];
        foreach(array_chunk(Reactions::PRESETS,4,true) as $choices) {
            $row=[];
            foreach($choices as $index=>$emoji) $row[]=$ui->button('reaction_choice','custom:reaction:music:'.$index,['emoji'=>$emoji]);
            $rows[]=$row;
        }
        $rows[]=[$ui->button('reaction_music_same','custom:reaction:music:same')];
        $rows[]=[$ui->button('back_features','custom:features')];
        $music=Reactions::selected($this->bot->store,true);
        $this->send($chat,$ui->text('reaction_info',['reaction'=>$current!==''?$current:$ui->plain('state_off'),'music'=>$music!==''?$music:$ui->plain('state_off')]),$rows,$message);
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
            'cover'=>[['text','quality_prompt'],['text','cover_title'],['text','cover_instruction'],['text','cover_limit'],['button','quality_240'],['button','quality_360'],['button','quality_480'],['button','quality_720'],['button','quality_high'],['button','extract_audio']],
            'video'=>[['text','video_caption'],['text','video_title'],['text','video_quality'],['text','video_duration'],['button','source_video']],
            'audio'=>[['text','audio_caption'],['text','audio_title'],['text','audio_format'],['text','audio_duration'],['button','source_audio'],['text','audio_downloading'],['text','audio_uploading'],['text','audio_failed'],['text','audio_error_size'],['text','audio_error_format'],['text','audio_error_upload']],
            'help'=>[['text','welcome'],['text','help'],['text','prompt'],['text','your_id'],['button','help']],
            'group'=>[['text','group_help'],['text','help_groups_hint'],['text','groups_disabled'],['button','add_to_group']],
            'access'=>[['text','offline'],['text','admin_only_notice']],
            'progress'=>[['text','queued'],['text','inspecting'],['text','quality_expired'],['text','quality_busy'],['text','downloading'],['text','uploading'],['text','audio_downloading'],['text','audio_uploading']],
            'errors'=>[],
        ];
        foreach(galleryServices() as $service) $screens[$service]=[['text',$service.'_queued'],['text',$service.'_downloading'],['text',$service.'_uploading'],['text',$service.'_caption'],['text',$service.'_page_caption'],['button',$service.'_prev'],['button',$service.'_next'],['button','source_'.$service],['button','open_'.$service.'_article']];
        $screens['menu']=array_merge(array_map(fn($key)=>['text','menu_'.$key],['queued','loading','head','info','limited','empty','failed','expired','owner','sent','again','untitled']),
            array_map(fn($key)=>['button','menu_tab_'.$key],['popular','albums','singles','eps','compilations','playlists','related','tracks','sets','reposts','likes']),
            array_map(fn($key)=>['button','menu_'.$key],['track','album','playlist','artist','all','zip','prev','next','back','close','page']));
        // "Download all" has its own screen: progress, why a track failed, the captions and its buttons.
        $screens['batch']=array_merge(array_map(fn($key)=>['text','batch_'.$key],['queued','progress','working','sending','caption','zip_caption','more','failed','stopping',
                'source_youtube','source_soundcloud','source_original','reason_blocked','reason_format','reason_network','reason_cookies','reason_size','reason_storage','reason_restricted','reason_duration','reason_process']),
            [['text','menu_pick_info'],['text','menu_picked'],['text','menu_pick_empty'],['text','menu_pick_full'],['button','menu_pick'],['button','menu_pick_send'],['button','menu_pick_cancel'],['button','batch_stop'],['button','batch_resume'],['button','batch_back'],['button','music_share']]);
        // The /music and /spotify commands: their help, progress and empty-result texts, and the descriptions in Telegram's "/" menu.
        $screens['search']=array_map(fn($key)=>['text',$key],['search_queued','search_loading','search_empty','music_usage','spotify_usage','command_music','command_spotify','command_start','command_help']);
        $screens['pornhub']=[['text','pornhub_caption'],['text','pornhub_notice'],['text','pornhub_people'],['text','pornhub_views'],['text','pornhub_tags'],['text','pornhub_error_size'],['text','pornhub_error_restricted'],['text','pornhub_error_format'],['text','pornhub_error_network'],['text','pornhub_error_blocked']];
        foreach(musicServices() as $service) $screens[$service]=[['text',$service.'_queued'],['text',$service.'_downloading'],['text',$service.'_uploading'],['text',$service.'_caption'],['button','source_'.$service]];
        if(!array_key_exists($section,$screens)) return [];
        $items=$screens[$section];
        if($section==='errors') foreach($this->entries('text','errors') as $key=>$item) $items[]=['text',$key];
        if(in_array($section,[...galleryServices(),...musicServices()],true)) foreach($this->entries('text',$section) as $key=>$item) if(!in_array(['text',$key],$items,true)) $items[]=['text',$key];
        return array_values(array_filter($items,fn($item)=>$this->bot->ui->customizable($item[0],$item[1])));
    }
    /** Texts first, then buttons, so a screen reads as two clear lists. */
    private function orderedItems(string $section): array
    {
        $items=$this->sectionItems($section);
        return array_merge(array_values(array_filter($items,fn($item)=>$item[0]==='text')),array_values(array_filter($items,fn($item)=>$item[0]==='button')));
    }
    private function sectionPage(string $section,string $kind,string $key): int
    {
        $position=array_search([$kind,$key],$this->orderedItems($section),true);
        return $position===false?0:intdiv($position,self::PAGE_SIZE);
    }
    private function sectionAddress(string $section,int $page): string { return 'custom:section:'.$section.($page>0?':'.$page:''); }
    /** Switches and mode choices that belong to one screen. */
    private function settingsRows(string $section): array
    {
        $ui=$this->bot->ui; $rows=[];
        if(in_array($section,galleryServices(),true)) {
            $prefix=galleryPrefix($section); $article=$this->bot->store->get($section.'.carousel_mode','pages')==='article';
            $rows[]=[$this->heading($ui->label('sec_settings'),'sec_settings')];
            $rows[]=[$this->choice($section.'_mode_pages','custom:'.$prefix.'mode:pages',!$article)];
            $rows[]=[$this->choice($section.'_mode_article','custom:'.$prefix.'mode:article',$article)];
            $rows[]=[$this->switchButton($section.'_source_toggle','custom:'.$prefix.'source',$ui->gallerySourceEnabled($section))];
        } elseif($section==='menu') {
            $rows[]=[$this->heading($ui->label('sec_settings'),'sec_settings')];
            $rows[]=[$this->switchButton('menu_close_toggle','custom:menuclose',MusicMenu::closesOnChoice($this->bot->store))];
            $copy=$this->bot->store->get('music.mp3','0')!=='1';
            $formatButton=$ui->button('menu_format_toggle','custom:menuformat',['state'=>$ui->plain($copy?'format_fast':'format_mp3')]); $rows[]=[$formatButton];
        } elseif(in_array($section,musicServices(),true)) {
            $rows[]=[$this->heading($ui->label('sec_settings'),'sec_settings')];
            $rows[]=[$this->switchButton($section.'_source_toggle','custom:'.musicPrefix($section).'source',$ui->gallerySourceEnabled($section))];
        } elseif($section==='cover') {
            $rows[]=[$this->heading($ui->label('sec_settings'),'sec_settings')];
            $rows[]=[$ui->button('cover_use_parts','custom:covermode:parts')];
            $rows[]=[$ui->button('cover_use_legacy','custom:covermode:legacy')];
            $rows[]=[$ui->button('reorder','custom:reorder')];
        } elseif(in_array($section,['video','audio'],true)) {
            $rows[]=[$this->heading($ui->label('sec_settings'),'sec_settings')];
            $rows[]=[$ui->button('cover_use_parts','custom:mediamode:'.$section.':parts')];
            $rows[]=[$ui->button('cover_use_legacy','custom:mediamode:'.$section.':legacy')];
        }
        return $rows;
    }
    private function section(int $chat,string $section,int $message,int $page=0): void
    {
        $ui=$this->bot->ui; $items=$this->orderedItems($section);
        if(!$items) { $this->home($chat,$message); return; }
        $this->bot->store->set('custom.section.'.$chat,$section); $saved=$this->saved();
        $pages=max(1,(int)ceil(count($items)/self::PAGE_SIZE)); $page=max(0,min($pages-1,$page));
        $rows=$page===0?$this->settingsRows($section):[]; $last='';
        foreach(array_slice($items,$page*self::PAGE_SIZE,self::PAGE_SIZE) as [$kind,$key]) {
            if($kind!==$last) { $title=$kind==='text'?'sec_texts':'sec_buttons'; $rows[]=[$this->heading($ui->label($title),$title)]; $last=$kind; }
            $rows[]=[$this->marked($kind,$key,$saved)];
        }
        $navigation=[];
        if($page>0) $navigation[]=$ui->button('previous',$this->sectionAddress($section,$page-1));
        if($page+1<$pages) $navigation[]=$ui->button('next',$this->sectionAddress($section,$page+1));
        if($navigation) $rows[]=$navigation;
        $rows[]=[$ui->button('back_custom','custom:home'),$ui->button('close','custom:close')];
        $text=$section==='menu'?$ui->text('menu_section_info'):($section==='batch'?$ui->text('batch_section_info'):(in_array($section,musicServices(),true)?$ui->text($section.'_section_info'):(in_array($section,galleryServices(),true)?$ui->text($section.'_mode_info',['mode'=>$ui->label($section.'_mode_'.($this->bot->store->get($section.'.carousel_mode','pages')==='article'?'article':'pages'))]):($section==='cover'?$ui->text('section_cover_info'):$ui->text(in_array($section,['video','audio'],true)?'section_media_info':'section_info',['title'=>$ui->label('section_'.$section)])))));
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
        $rows[]=[$ui->button('cover_per_row','custom:perrow',['n'=>max(1,min(3,(int)$this->bot->store->get('cover.per_row','2')))])];
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
            $text=$ui->text('custom_preview',['title'=>$ui->label('section_cover'),'value'=>$caption],['value'])."\n\n".$ui->text('custom_saved');
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
            $text=$ui->text('custom_preview',['title'=>$ui->label('section_'.$kind),'value'=>$caption],['value'])."\n\n".$ui->text('custom_saved');
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
        return ['description'=>'متن نمونهٔ پست اینستاگرام','page_url'=>'https://telegra.ph/Example-11-06','index'=>'1','count'=>'12','sent'=>'10','title'=>'عنوان نمونهٔ ویدیو','label'=>'تنظیم نمونه','quality'=>'720p','format'=>'MP3','duration'=>'05:32','artist'=>'نام خواننده','year'=>'2024','url'=>'https://youtu.be/ybNMMK5Q318','max_size'=>(string)round($this->bot->config->maxBytes/1000000,1),'id'=>'123456','username'=>'CiroDownloaderBot',
            'state'=>$this->bot->ui->plain('state_on'),'poller'=>'آنلاین','worker'=>'آنلاین','queued'=>'0','running'=>'0','users'=>'120','completed'=>'34','failed'=>'2','cookies'=>'تنظیم شده','proxy'=>'تنظیم نشده','error'=>'خطای نمونه',
            'page'=>'1','pages'=>'1','variables'=>'{title}, {quality}, {url}','value'=>'متن نمونه','notice'=>'','checked'=>'2026-11-03 12:00','version'=>Updates::version(),'bar'=>'▰▰▰▰▰▱▱▱▱▱ 50%','stage'=>'در حال بررسی','changes'=>'• یک قابلیت تازه','status'=>'کوکی قبلی موجود است.','percent'=>'50'];
    }
    /** Whether the value on screen differs from the default; uses a pending preview when there is one. */
    private function itemChanged(string $kind,string $key): bool
    {
        $ui=$this->bot->ui; $definition=$ui->definition($kind,$key);
        if(!$definition) return false;
        if($kind==='text') { $value=$ui->setting('text.'.$key); return $value!==''&&$value!==$definition['value']; }
        $label=$ui->setting('button.'.$key); $style=$ui->setting('button.style.'.$key,'-');
        return ($label!==''&&$label!==$definition['value'])||($style!=='-'&&$style!==($definition['style']??''))||$ui->setting('button.icon.'.$key)!==''||$ui->setting('button.emoji.'.$key)!==''||$ui->setting('button.plain.'.$key)==='1'||$ui->setting('button.hidden.'.$key)==='1';
    }
    public function item(int $chat,string $kind,string $key,int $message=0,string $notice='',bool $strictPreview=false): void
    {
        $ui=$this->bot->ui; $definition=$ui->definition($kind,$key);
        if(!$ui->customizable($kind,$key)) { $this->home($chat,$message); return; }
        $samples=$this->samples();
        if(preg_match('/^quality_(360|480|720)$/D',$key,$match)) $samples['quality']=$match[1].'p';
        elseif($key==='quality_high') $samples['quality']='1080p';
        $value=$kind==='button'?Ui::escape($ui->label($key,$samples)):$ui->text($key,$samples);
        $changed=$ui->plain($this->itemChanged($kind,$key)?'state_custom':'state_default'); $color='';
        $reply=$kind==='button'&&in_array($key,Ui::REPLY_KEYS,true); $required=$kind==='button'&&$ui->required($key);
        if($kind==='button') {
            $current=$ui->button($key);
            $color=$ui->label(match($current['style']??'') {'primary'=>'color_primary','success'=>'color_success','danger'=>'color_danger',default=>'color_default'});
            $emoji=Ui::singleEmoji($ui->setting('button.emoji.'.$key));
            $hidden=!$required&&$ui->setting('button.hidden.'.$key)==='1';
            $state=$ui->text('custom_state_button',['state'=>$changed,'emoji'=>$reply?$ui->plain('state_none'):($ui->setting('button.plain.'.$key)==='1'?$ui->plain('state_none'):($emoji??$ui->plain('state_none'))),
                'pos'=>$ui->plain($ui->setting('button.pos.'.$key,'start')==='end'?'pos_end':'pos_start'),'icon'=>$ui->plain(isset($current['icon_custom_emoji_id'])?'state_has':'state_none'),
                'color'=>$color,'visible'=>$ui->plain($required?'state_required':($hidden?'state_hidden':'state_visible'))]);
        } else $state=$ui->text('custom_state_text',['state'=>$changed]);
        $text=$ui->text('custom_item',['title'=>$definition['label'],'state'=>$state,'value'=>$value],['state','value']).($notice!==''?"\n\n".$notice:'');
        $rows=[];
        if($kind==='button') { $sample=$ui->button($key,'custom:noop',$samples); unset($sample['_hidden']); $rows[]=[$sample]; }
        if($kind==='text') $rows[]=[$ui->button('default_text',"custom:default:text:$key"),$ui->button('edit',"custom:edit:$kind:$key")];
        else {
            $rows[]=$reply?[$ui->button('btn_name',"custom:edit:$kind:$key")]:[$ui->button('btn_name',"custom:edit:$kind:$key"),$ui->button('btn_emoji','custom:emoji:'.$key)];
            if(!$reply) $rows[]=[$ui->button('btn_pos','custom:pos:'.$key,['pos'=>$ui->plain($ui->setting('button.pos.'.$key,'start')==='end'?'pos_end':'pos_start')]),
                $this->switchLabel('btn_plain','custom:plain:'.$key,$ui->setting('button.plain.'.$key)==='1',false)];
            $rows[]=[$ui->button('color','custom:cyclecolor:'.$key,['color'=>$color]),$ui->button('icon','custom:icon:'.$key)];
            $rows[]=[$ui->button('remove_icon','custom:clearicon:'.$key)];
            if(!$required) $rows[]=[$ui->button($hidden?'btn_show':'btn_hide','custom:hide:'.$key)];
        }
        $rows[]=[$ui->button('reset',"custom:reset:$kind:$key")];
        $listKind=!empty($definition['caption'])?'caption':$kind;
        $position=array_search($key,array_keys($this->entries($listKind,$definition['group'])),true);
        $page=$position===false?0:intdiv($position,$this->pageSize($listKind,$definition['group']));
        $section=$this->bot->store->get('custom.section.'.$chat);
        if($section==='features'&&$kind==='text'&&$key==='admin_only_notice') $back='custom:features';
        elseif(in_array([$kind,$key],$this->sectionItems($section),true)) $back=$this->sectionAddress($section,$this->sectionPage($section,$kind,$key));
        else $back="custom:list:$listKind:".$definition['group'].':'.$page;
        $rows[]=[$ui->button('back',$back),$ui->button('close','custom:close')];
        try { $this->send($chat,$text,$rows,$message); }
        catch(\RuntimeException $e) {
            if($strictPreview||$e->getCode()!==400) throw $e;
            // Keep repair controls usable when a previously saved emoji or text
            // is no longer accepted by Telegram. Never use this fallback to save.
            if($kind==='button') array_shift($rows);
            $plain=Ui::escape(html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            $text=$ui->text('custom_item',['title'=>$definition['label'],'state'=>$state,'value'=>$plain],['state','value']);
            $text.="\n\n".$ui->text('custom_preview_unavailable').($notice!==''?"\n\n".$notice:'');
            $this->send($chat,$text,$rows,$message);
        }
    }
    private function defaultView(int $chat,string $key,int $message): void
    {
        $ui=$this->bot->ui; $definition=$ui->definition('text',$key);
        if(!$definition||!$ui->customizable('text',$key)) { $this->home($chat,$message); return; }
        // The default is rendered through the preview layer, never saved.
        $ui->preview(['text.'.$key=>$definition['value']]);
        try { $value=$ui->text($key,$this->samples()); }
        finally { $ui->preview([]); }
        $this->send($chat,$ui->text('custom_default',['title'=>$definition['label'],'value'=>$value],['value']),[[$ui->button('back','custom:item:text:'.$key)]],$message);
    }
    /** A saved text that dropped a placeholder is kept, but the administrator is told. */
    private function missingVariables(string $kind,string $key,array $values): string
    {
        if($kind!=='text'||!isset($values['text.'.$key])) return '';
        $definition=$this->bot->ui->definition('text',$key); $missing=[];
        foreach($definition['variables']??[] as $name) if(str_contains($definition['value'],'{'.$name.'}')&&!str_contains($values['text.'.$key],'{'.$name.'}')) $missing[]='{'.$name.'}';
        return $missing?"\n\n".$this->bot->ui->text('custom_missing_variables',['variables'=>implode(', ',$missing)]):'';
    }
    public function begin(int $chat,int $user,string $kind,string $key,int $message=0): void
    {
        $ui=$this->bot->ui; $base=in_array($kind,['icon','emoji'],true)?'button':$kind; $definition=$ui->definition($base,$key);
        if(!$ui->customizable($base,$key)) { $this->home($chat,$message); return; }
        $variables=empty($definition['variables'])?'':$ui->text('custom_variables',['variables'=>implode(', ',array_map(fn($name)=>'{'.$name.'}',$definition['variables']))]);
        if(!empty($definition['inline'])) $variables.="\n".$ui->text('custom_inline_hint');
        $text=$kind==='icon'?$ui->text('custom_icon_prompt'):($kind==='emoji'?$ui->text('custom_emoji_prompt'):$ui->text('custom_prompt',['title'=>$definition['label'],'variables'=>$variables],['variables']));
        $message=$this->send($chat,$text,[[$ui->button('cancel','custom:item:'.$base.':'.$key)]],$message);
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
            $this->item($chat,$kind,$key,$message,$ui->text('custom_saved').$this->missingVariables($kind,$key,$values),true);
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
        if(!$ui->customizable(in_array($kind,['icon','emoji'],true)?'button':$kind,$key)) {
            $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $this->home($chat,(int)$menu); return;
        }
        $values=[];
        try {
            $definition=$ui->definition(in_array($kind,['icon','emoji'],true)?'button':$kind,$key);
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
            } elseif($kind==='emoji') {
                $emoji=Ui::singleEmoji((string)($message['text']??''));
                if($emoji===null||TextFormat::emoji($message)!==null) throw new \RuntimeException('Invalid emoji');
                // A plain emoji replaces a no-emoji choice and sits at the chosen end of the label.
                $values['button.emoji.'.$key]=$emoji; $values['button.plain.'.$key]='0';
            } else throw new \RuntimeException('Invalid customization kind');
        } catch(\Throwable) {
            $this->send($chat,$ui->text($kind==='emoji'?'custom_emoji_invalid':'custom_invalid'),[[$ui->button('cancel','custom:item:'.(in_array($kind,['icon','emoji'],true)?'button':$kind).':'.$key)]],(int)$menu);
            return;
        }
        if($this->commit($chat,in_array($kind,['icon','emoji'],true)?'button':$kind,$key,(int)$menu,$values)) $this->bot->store->query('DELETE FROM states WHERE user_id=?',[$user]);
    }
    public function handle(int $chat,int $user,string $action,int $message): bool
    {
        $ui=$this->bot->ui; $parts=explode(':',$action);
        if(($parts[0]??'')!=='custom') return false;
        $op=$parts[1]??''; $kind=$parts[2]??''; $key=$parts[3]??'';
        $target=match($op) {
            'item','edit','reset','default'=>[$kind,$key],
            'icon','emoji','pos','plain','hide','cyclecolor','color','setcolor','clearicon'=>['button',$kind],
            default=>null,
        };
        if($target&&!$ui->customizable($target[0],$target[1])) { $this->home($chat,$message); return true; }
        if($op==='home') $this->home($chat,$message);
        elseif($op==='reaction') {
            if($kind==='input') $this->beginReaction($chat,$user,$message);
            elseif($kind==='music') {
                if($key==='same') $this->bot->store->set('link_reaction.music','same');
                elseif(ctype_digit($key)&&isset(Reactions::PRESETS[(int)$key])) $this->bot->store->set('link_reaction.music',Reactions::PRESETS[(int)$key]);
                $this->reactions($chat,$message);
            } else {
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
        elseif($op==='sections') $this->home($chat,$message);
        elseif($op==='section') $this->section($chat,$kind,$message,(int)$key);
        elseif($op==='features') $this->features($chat,$message);
        elseif($op==='default'&&$kind==='text') $this->defaultView($chat,$key,$message);
        elseif($op==='resetall') { if($kind==='yes') $this->resetAll($chat,$message); else $this->confirmResetAll($chat,$message); }
        elseif($op==='close') {
            try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); }
            catch(\Throwable) { $this->home($chat,$message); }
        }
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
        elseif($op==='menuformat') {
            $this->bot->store->set('music.mp3',$this->bot->store->get('music.mp3','0')==='1'?'0':'1');
            $this->section($chat,'menu',$message);
        }
        elseif($op==='menuclose') {
            $this->bot->store->set('music.menu_close',MusicMenu::closesOnChoice($this->bot->store)?'0':'1');
            $this->section($chat,'menu',$message);
        }
        elseif(in_array($op,['spsource','scsource'],true)) {
            $service=musicFromPrefix(substr($op,0,-6));
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
        elseif($op==='perrow') {
            $next=max(1,min(3,(int)$this->bot->store->get('cover.per_row','2')))%3+1;
            try { $this->reorder($chat,$message,$ui->coverOrder()); $this->bot->store->set('cover.per_row',(string)$next); $this->reorder($chat,$message,$ui->coverOrder()); }
            catch(\RuntimeException $e) { if($e->getCode()!==400) throw $e; $this->reorder($chat,$message,$ui->coverOrder(),$ui->text('custom_preview_failed'),true); }
        }
        elseif($op==='orderreset') {
            try { $this->reorder($chat,$message,['360','480','720','1080','audio']); $this->bot->store->query('DELETE FROM settings WHERE key IN (?,?)',['cover.order','cover.per_row']); }
            catch(\RuntimeException $e) { if($e->getCode()!==400) throw $e; $this->reorder($chat,$message,$ui->coverOrder(),$ui->text('custom_preview_failed'),true); }
        }
        elseif($op==='groups') { $this->bot->store->set('custom.section.'.$chat,''); $this->groups($chat,$kind,$message); }
        elseif($op==='list') { $this->bot->store->set('custom.section.'.$chat,''); $this->listing($chat,$kind,$key,(int)($parts[4]??0),$message); }
        elseif($op==='item') $this->item($chat,$kind,$key,$message);
        elseif($op==='edit') $this->begin($chat,$user,$kind,$key,$message);
        elseif($op==='icon') $this->begin($chat,$user,'icon',$kind,$message);
        elseif($op==='emoji') $this->begin($chat,$user,'emoji',$kind,$message);
        elseif($op==='pos') $this->commit($chat,'button',$kind,$message,['button.pos.'.$kind=>$ui->setting('button.pos.'.$kind,'start')==='end'?'start':'end','button.plain.'.$kind=>'0']);
        elseif($op==='plain') $this->commit($chat,'button',$kind,$message,['button.plain.'.$kind=>$ui->setting('button.plain.'.$kind)==='1'?'0':'1']);
        elseif($op==='cyclecolor') {
            $order=['','primary','success','danger']; $current=$ui->button($kind)['style']??'';
            $this->commit($chat,'button',$kind,$message,['button.style.'.$kind=>$order[(array_search($current,$order,true)+1)%4]]);
        } elseif($op==='hide') {
            if($ui->required($kind)) { $this->item($chat,'button',$kind,$message,$ui->text('custom_hide_required')); return true; }
            $hide=$ui->setting('button.hidden.'.$kind)!=='1';
            // The quality menu needs at least one choice, or nobody could pick a download.
            $qualities=['quality_360','quality_480','quality_720','quality_high'];
            if($hide&&in_array($kind,$qualities,true)&&!array_filter($qualities,fn($other)=>$other!==$kind&&$ui->setting('button.hidden.'.$other)!=='1')) {
                $this->item($chat,'button',$kind,$message,$ui->text('custom_hide_last')); return true;
            }
            $this->commit($chat,'button',$kind,$message,['button.hidden.'.$kind=>$hide?'1':'0']);
        }
        elseif($op==='access') {
            $this->bot->store->set('admins_only',$this->bot->store->get('admins_only','0')==='1'?'0':'1');
            $this->features($chat,$message);
        } elseif($op==='adultpage') {
            $this->adultPage($chat,$message);
        } elseif($op==='adult') {
            $this->bot->store->set('adult.pornhub',$this->bot->store->get('adult.pornhub','0')==='1'?'0':'1');
            $this->adultPage($chat,$message);
        } elseif($op==='adultdelp') {
            $this->bot->store->set('adult.delete_private',$this->bot->store->get('adult.delete_private','1')==='1'?'0':'1');
            $this->adultPage($chat,$message);
        } elseif($op==='adultdelg') {
            $this->bot->store->set('adult.delete_group',$this->bot->store->get('adult.delete_group','1')==='1'?'0':'1');
            $this->adultPage($chat,$message);
        } elseif($op==='adultsec') {
            $all=Bot::ADULT_DELAYS; $now=Bot::adultDelays($this->bot->store);
            $this->bot->store->set('adult.delete_seconds',(string)$all[(array_search($now,$all,true)+1)%count($all)]);
            $this->adultPage($chat,$message);
        } elseif($op==='adultadmins') {
            $this->bot->store->set('adult.admins_only',$this->bot->store->get('adult.admins_only','1')==='1'?'0':'1');
            $this->adultPage($chat,$message);
        } elseif($op==='groups_mode') {
            $this->bot->store->set('groups_enabled',$this->bot->store->groupsEnabled()?'0':'1');
            (new GroupSupport($this->bot))->syncHelpButtons();
            $this->features($chat,$message);
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
                $values+=['button.style.'.$key=>$parentButton['style']??($definition['style']??''),'button.icon.'.$key=>$parentButton['icon_custom_emoji_id']??'']; foreach(['emoji','pos','plain','hidden'] as $extra) $values['button.'.$extra.'.'.$key]='';
            }
            $this->commit($chat,$kind,$key,$message,$values,array_keys($values));
        }
        return true;
    }
}
