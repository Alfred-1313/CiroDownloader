<?php
declare(strict_types=1);
namespace Zero;

/** Service hubs and layout editing use the existing Personalization renderer and item editor. */
final class ServiceCustomization
{
    private const PAGE_SIZE=10;
    public function __construct(private Bot $bot,private Personalization $editor) {}
    private function views(int $chat): array { $v=json_decode($this->bot->store->get('custom.views.'.$chat,'{}'),true); return is_array($v)?$v:[]; }
    private function remember(int $chat,int $message,array $view): void
    {
        $views=$this->views($chat); unset($views[(string)$message]); $views[(string)$message]=$view;
        $this->bot->store->set('custom.views.'.$chat,json_encode(array_slice($views,-20,null,true),JSON_THROW_ON_ERROR));
    }
    public function moveView(int $chat,int $old,int $new): void
    {
        $views=$this->views($chat); if(!isset($views[(string)$old])) return;
        $view=$views[(string)$old]; unset($views[(string)$old]);
        $this->bot->store->set('custom.views.'.$chat,json_encode($views,JSON_THROW_ON_ERROR)); $this->remember($chat,$new,$view);
    }
    public function forgetView(int $chat,int $message): void
    {
        $views=$this->views($chat); unset($views[(string)$message]); $this->bot->store->set('custom.views.'.$chat,json_encode($views,JSON_THROW_ON_ERROR));
    }
    private function show(int $chat,int $message,string $text,array $rows,array $view): void
    {
        $id=$this->editor->send($chat,$text,$rows,$message); $this->remember($chat,$id,$view);
    }
    private function heading(string $key): array { return [$this->editor->heading($this->bot->ui->label($key),$key)]; }
    private function footer(string $back): array { $ui=$this->bot->ui; return [$ui->button('back',$back),$ui->button('close','custom:close')]; }
    private function title(string $service): string { return $this->bot->ui->label('section_'.$service); }
    private function navigation(string $key,string $data,bool $changed): array
    {
        $ui=$this->bot->ui; $button=$ui->button($key,$data);
        if($changed) $button['text']=$ui->plainWith('mark_group',['label'=>$button['text']]);
        return $button;
    }
    private function layoutChanged(string $service,string $layout): bool
    {
        $ui=$this->bot->ui; $defaults=clone $ui;
        $defaults->preview(['layout.'.$service.'.'.$layout=>'null']);
        return $ui->layoutRows($service,$layout)!==$defaults->layoutRows($service,$layout);
    }
    private function changed(string $service,string $filter=''): bool
    {
        $ui=$this->bot->ui;
        foreach($filter===''?UiRegistry::items($service,Ui::definitions()):$this->items($service,$filter) as [$kind,$key])
            if($ui->customized($kind,$key)) return true;
        if($filter!=='') return false;
        foreach(UiRegistry::layouts($service) as $layout=>$slots) if($this->layoutChanged($service,$layout)) return true;
        if(in_array($service,['youtube',...adultServices()],true)) {
            $scoped=$ui->forService($service);
            if($scoped->setting('cover.per_row','2')!=='2'||$scoped->coverOrder()!==['360','480','720','1080','audio']) return true;
            foreach($service==='youtube'?['cover','video','audio']:['cover'] as $type) if($scoped->captionMode($type)!=='parts') return true;
        }
        return false;
    }
    private function groupChanged(array $sections): bool
    {
        foreach($sections as $section) if($this->editor->sectionCustomized($section)) return true;
        return false;
    }
    public function home(int $chat,int $message=0,string $notice=''): void
    {
        $ui=$this->bot->ui; $rows=[$this->heading('hub_downloaders')];
        foreach(array_unique(['youtube',...galleryServices(),...musicServices()]) as $service) $rows[]=[$this->navigation('section_'.$service,'custom:service:'.$service,$this->changed($service))];
        foreach(['adult','music','general'] as $category) {
            $changed=$category==='adult'?(bool)array_filter(adultServices(),fn($service)=>$this->changed($service)):$this->groupChanged($category==='music'?['menu','batch','search','radio','radio_player']:['help','group','access','errors']);
            $rows[]=$this->heading('hub_'.$category); $rows[]=[$this->navigation('hub_open_'.$category,'custom:category:'.$category,$changed)];
        }
        $rows[]=[$ui->button('reset_all','custom:resetall'),$ui->button('close','custom:close')];
        $this->bot->store->set('custom.section.'.$chat,'home');
        $this->show($chat,$message,$ui->text('custom_panel').($notice!==''?"\n\n".$notice:''),$rows,['type'=>'home']);
    }
    public function category(int $chat,int $message,string $category): void
    {
        $ui=$this->bot->ui; $rows=[$this->heading('hub_'.$category)];
        if($category==='adult') {
            $rows[]=[$ui->button('hub_shared_adult','custom:adultpage')];
            foreach(adultServices() as $site) $rows[]=[$this->navigation('section_'.$site,'custom:service:'.$site,$this->changed($site))];
        } else {
            foreach($category==='music'?['menu','batch','search','radio','radio_player']:['help','group','access','errors'] as $section)
                $rows[]=[$this->navigation('section_'.$section,'custom:section:'.$section,$this->groupChanged([$section]))];
            if($category==='general') $rows[]=[$ui->button('features_hub','custom:features')];
        }
        $rows[]=$this->footer('custom:home');
        $this->show($chat,$message,$ui->text('hub_category_info',['title'=>$ui->label('hub_'.$category)]),$rows,['type'=>'category','category'=>$category]);
    }
    public function service(int $chat,int $message,string $service,string $notice=''): void
    {
        if(!in_array($service,UiRegistry::services(),true)) { $this->home($chat,$message); return; }
        $ui=$this->bot->ui; $rows=[];
        if(in_array($service,['youtube',...adultServices()],true)) { $rows[]=$this->heading('hub_cover'); $rows[]=[$this->navigation('hub_edit_cover','custom:coverhub:'.$service,$this->changed($service,'cover')||$this->changed($service,'quality')||$this->layoutChanged($service,'quality'))]; }
        foreach($service==='instagram'?['caption','button','text','stories','checker']:($service==='youtube'?['caption','button','text','posts']:['caption','button','text']) as $kind) {
            if(!$this->items($service,$kind)) continue;
            $rows[]=$this->heading('hub_'.$kind); $rows[]=[$this->navigation('hub_edit_'.$kind,'custom:items:'.$service.':'.$kind.':0',$this->changed($service,$kind))];
            if($service==='instagram'&&$kind==='checker') $rows[]=[$ui->button('instagram_checker_settings','checker:settings:instagram')];
        }
        $rows[]=$this->heading('hub_layout'); $rows[]=[$this->navigation('hub_edit_layout','custom:layouts:'.$service,(bool)array_filter(array_keys(UiRegistry::layouts($service)),fn($id)=>$this->layoutChanged($service,$id)))];
        $rows[]=$this->heading('sec_settings'); $rows[]=[$ui->button('hub_settings','custom:settings:'.$service)];
        $rows[]=[$ui->button('reset_service','custom:resetservice:'.$service)];
        $rows[]=$this->footer(in_array($service,adultServices(),true)?'custom:category:adult':'custom:home');
        $this->show($chat,$message,$ui->text('hub_service_info',['title'=>$this->title($service)]).($notice!==''?"\n\n".$notice:''),$rows,['type'=>'service','service'=>$service]);
    }
    /** Bind a confirmation to this service's appearance, including its read-only legacy fallbacks. */
    private function fingerprint(string $service): string
    {
        $names=[]; $ui=$this->bot->ui;
        foreach(UiRegistry::items($service,Ui::definitions()) as [$kind,$key]) {
            $seen=[];
            do {
                if(isset($seen[$key])) break;
                $seen[$key]=true; $names[]=$kind.'.'.$key;
                if($kind==='button') foreach(['style','icon','emoji','pos','plain','hidden'] as $part) $names[]='button.'.$part.'.'.$key;
                $key=$ui->definition($kind,$key)['inherits']??'';
            } while($key!=='');
        }
        if(in_array($service,['youtube',...adultServices()],true)) foreach(['cover.parts','cover.order','cover.per_row','media.video.parts','media.audio.parts'] as $part)
            array_push($names,$part,'service.'.$service.'.'.$part);
        foreach(UiRegistry::layouts($service) as $layout=>$slots) $names[]='layout.'.$service.'.'.$layout;
        $values=[];
        foreach(array_unique($names) as $name) $values[$name]=$this->bot->store->get($name,"\0");
        ksort($values);
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
    /** A scoped reset writes factory overrides, shielding this service from legacy shared values. */
    private function reset(int $chat,int $message,string $service,bool $confirmed): void
    {
        $ui=$this->bot->ui; $store=$this->bot->store;
        if(!$confirmed) {
            $rows=[[$ui->button('reset_service_yes','custom:resetservice:'.$service.':yes')],[$ui->button('cancel','custom:service:'.$service)]];
            $this->show($chat,$message,$ui->text('custom_reset_service',['title'=>$this->title($service)]),$rows,
                ['type'=>'reset_service','service'=>$service,'fingerprint'=>$this->fingerprint($service)]);
            return;
        }
        $view=$this->views($chat)[(string)$message]??[];
        if(($view['type']??'')!=='reset_service'||($view['service']??'')!==$service) {
            $this->service($chat,$message,$service,$ui->text('custom_reset_stale')); return;
        }
        $values=[];
        foreach(UiRegistry::items($service,Ui::definitions()) as [$kind,$key]) {
            $definition=$ui->definition($kind,$key); $values[$kind.'.'.$key]=$definition['value'];
            if($kind==='button') {
                $values['button.style.'.$key]=$definition['style']??'';
                foreach(['icon','emoji','pos','plain','hidden'] as $part) $values['button.'.$part.'.'.$key]='';
            }
        }
        if(in_array($service,['youtube',...adultServices()],true)) {
            $values['service.'.$service.'.cover.per_row']='2'; $values['service.'.$service.'.cover.order']='["360","480","720","1080","audio"]';
            foreach($service==='youtube'?['cover.parts','media.video.parts','media.audio.parts']:['cover.parts'] as $part) $values['service.'.$service.'.'.$part]='parts';
        }
        $store->db->exec('BEGIN IMMEDIATE'); $transactionOpen=true;
        try {
            if(($view['fingerprint']??'')!==$this->fingerprint($service)) {
                $store->db->exec('ROLLBACK'); $transactionOpen=false;
                $this->service($chat,$message,$service,$ui->text('custom_reset_stale')); return;
            }
            foreach($values as $name=>$value) $store->set($name,$value);
            foreach(UiRegistry::layouts($service) as $layout=>$slots) $store->query('DELETE FROM settings WHERE key=?',['layout.'.$service.'.'.$layout]);
            $this->service($chat,$message,$service,$ui->text('custom_reset_service_done',['title'=>$this->title($service)]));
            $store->db->exec('COMMIT'); $transactionOpen=false;
        } catch(\Throwable $error) { if($transactionOpen) { try { $store->db->exec('ROLLBACK'); } catch(\Throwable) {} } throw $error; }
    }
    private function items(string $service,string $filter): array
    {
        return array_values(array_filter(UiRegistry::items($service,Ui::definitions()),function($item) use($filter) {
            [$kind,$key]=$item; $definition=$this->bot->ui->definition($kind,$key); $definition['key']=$key;
            $instagram=($definition['group']??'')==='instagram';
            $part='';
            if($instagram&&(str_starts_with($key,'instagram_checker_')||in_array($key,['instagram_checker','command_insta_checker'],true))) $part='checker';
            elseif($instagram&&(str_starts_with($key,'instagram_story_')||in_array($key,['source_instagram_story','command_story_instagram'],true))) $part='stories';
            elseif(($definition['group']??'')==='youtube'&&(str_starts_with($key,'youtube_post_')||$key==='source_youtube_post')) $part='posts';
            if(in_array($filter,['stories','posts','checker'],true)) return $part===$filter;
            if($part!=='') return false;
            $cover=$kind==='text'&&in_array($definition['base']??'',['quality_prompt','cover_title','cover_instruction','cover_limit'],true);
            $quality=$kind==='button'&&in_array($definition['base']??'',UiRegistry::QUALITY_BUTTONS,true);
            if($filter==='cover') return $cover;
            if($filter==='quality') return $quality;
            if($cover||($filter==='button'&&$quality)) return false;
            return UiRegistry::category($kind,$definition)===$filter;
        }));
    }
    private function cover(int $chat,int $message,string $service): void
    {
        if(!in_array($service,['youtube',...adultServices()],true)) return;
        $ui=$this->bot->ui; $rows=[$this->heading('hub_cover'),[$this->navigation('hub_edit_cover_caption','custom:items:'.$service.':cover:0',$this->changed($service,'cover'))],
            $this->heading('hub_quality'),[$this->navigation('hub_edit_quality','custom:items:'.$service.':quality:0',$this->changed($service,'quality'))],
            $this->heading('hub_layout'),[$this->navigation('layout_quality','custom:layout:'.$service.':quality',$this->layoutChanged($service,'quality'))],$this->footer('custom:service:'.$service)];
        $this->show($chat,$message,$ui->text('hub_cover_info',['title'=>$this->title($service)]),$rows,['type'=>'cover','service'=>$service]);
    }
    public function listing(int $chat,int $message,string $service,string $kind,int $page=0): void
    {
        if(!in_array($kind,['caption','button','text','cover','quality','stories','posts','checker'],true)||!in_array($service,UiRegistry::services(),true)) { $this->home($chat,$message); return; }
        $ui=$this->bot->ui; $items=$this->items($service,$kind); $pages=max(1,(int)ceil(count($items)/self::PAGE_SIZE)); $page=max(0,min($pages-1,$page));
        $rows=[$this->heading('hub_'.$kind)];
        foreach(array_slice($items,$page*self::PAGE_SIZE,self::PAGE_SIZE) as [$type,$key]) $rows[]=[$this->editor->marked($type,$key)];
        $nav=[];
        if($page>0) $nav[]=$ui->button('previous','custom:items:'.$service.':'.$kind.':'.($page-1));
        if($page+1<$pages) $nav[]=$ui->button('next','custom:items:'.$service.':'.$kind.':'.($page+1));
        if($nav) $rows[]=$nav;
        $rows[]=$this->footer(in_array($kind,['cover','quality'],true)?'custom:coverhub:'.$service:'custom:service:'.$service);
        $this->show($chat,$message,$ui->text('custom_catalog',['title'=>$this->title($service).' · '.$ui->label('hub_'.$kind),'page'=>$page+1,'pages'=>$pages]),$rows,
            ['type'=>'items','service'=>$service,'kind'=>$kind,'page'=>$page]);
    }
    public function back(int $chat,int $message,string $kind,string $key): ?string
    {
        $view=$this->views($chat)[(string)$message]??null;
        if(!$view||($view['type']??'')!=='items') return null;
        if(!in_array([$kind,$key],$this->items($view['service'],$view['kind']),true)) return null;
        return 'custom:items:'.$view['service'].':'.$view['kind'].':'.$view['page'];
    }
    public function settingsReturn(int $chat,int $message,string $service): bool
    {
        $view=$this->views($chat)[(string)$message]??[];
        if(($view['service']??'')!==$service) return false;
        $this->settings($chat,$message,$service); return true;
    }
    public function adultBack(int $chat,int $message): string
    {
        $view=$this->views($chat)[(string)$message]??[];
        return $view['adult_back']??(($view['type']??'')==='settings'&&in_array($view['service']??'',adultServices(),true)?'custom:settings:'.$view['service']:'custom:category:adult');
    }
    public function rememberAdult(int $chat,int $message,string $back): void { $this->remember($chat,$message,['type'=>'adult_shared','adult_back'=>$back]); }
    public function settings(int $chat,int $message,string $service,string $notice=''): void
    {
        if(!in_array($service,UiRegistry::services(),true)) { $this->home($chat,$message); return; }
        $ui=$this->bot->ui; $rows=[$this->heading('sec_settings')];
        if(in_array($service,adultServices(),true)) {
            $op=$service==='pornhub'?'adult':'adult'.$service;
            $rows[]=[$ui->button('adult_'.$service,'custom:'.$op,['state'=>$ui->plain($this->bot->store->get('adult.'.$service,'0')==='1'?'switch_on':'switch_off')])];
            foreach(['cover','video'] as $kind) $rows[]=[$ui->button('adult_spoiler_'.$kind,'custom:spoiler:'.$service.':'.$kind,
                ['state'=>$ui->plain($this->bot->adultSpoiler($service,$kind)?'switch_on':'switch_off')])];
            $rows[]=[$ui->button('hub_shared_adult','custom:adultpage')];
        }
        if(in_array($service,['youtube',...adultServices()],true)) {
            foreach($service==='youtube'?['cover','video','audio']:['cover'] as $type) {
                $scoped=$ui->forService($service); $mode=$scoped->captionMode($type);
                $rows[]=[$ui->button('hub_caption_mode','custom:mode:'.$service.':'.$type.':'.($mode==='legacy'?'parts':'legacy'),
                    ['title'=>$ui->label('section_'.$type),'state'=>$ui->plain($mode==='legacy'?'hub_mode_full':'hub_mode_parts')])];
            }
        }
        $settings=$this->editor->settingsRows($service);
        if(($settings[0][0]['callback_data']??'')==='custom:hint:sec_settings') array_shift($settings);
        array_push($rows,...$settings);
        if(in_array($service,musicServices(),true)) $rows[]=[$ui->button('hub_open_music','custom:category:music')];
        $rows[]=$this->footer('custom:service:'.$service);
        $this->show($chat,$message,$ui->text('hub_settings_info',['title'=>$this->title($service)]).($notice!==''?"\n\n".$notice:''),$rows,['type'=>'settings','service'=>$service]);
    }
    private function mode(int $chat,int $message,string $service,string $type,string $mode): void
    {
        if(!in_array($service,['youtube',...adultServices()],true)||!in_array($type,$service==='youtube'?['cover','video','audio']:['cover'],true)||!in_array($mode,['parts','legacy'],true)) return;
        $name='service.'.$service.'.'.($type==='cover'?'cover.parts':'media.'.$type.'.parts'); $ui=$this->bot->ui;
        $ui->preview([$name=>$mode]); $scoped=$ui->forService($service);
        try {
            $fits=$mode!=='parts'||($type==='cover'?$scoped->coverPartsFit((string)round($this->bot->config->maxBytes/1000000,1)):$scoped->mediaPartsFit($type));
            if(!$fits) throw new \RuntimeException('Caption exceeds allowed size',400);
            $samples=['title'=>'نمونه','quality'=>'720p','duration'=>'03:10','format'=>'MP3'];
            $value=$type==='cover'?$scoped->coverCaption('نمونه',(string)round($this->bot->config->maxBytes/1000000,1)):$scoped->mediaCaption($type,$samples);
            $this->editor->send($chat,$ui->text('custom_preview',['title'=>$this->title($service),'value'=>$value],['value'])."\n\n".$ui->text('custom_saved'),[$this->footer('custom:settings:'.$service)],$message);
        } catch(\RuntimeException $e) { if($e->getCode()!==400) throw $e; $ui->preview([]); $this->settings($chat,$message,$service,$ui->text('custom_preview_failed')); return; }
        finally { $ui->preview([]); }
        $this->bot->store->set($name,$mode);
    }
    private function spoiler(int $chat,int $message,string $service,string $kind): void
    {
        if(!in_array($service,adultServices(),true)||!in_array($kind,['cover','video'],true)) return;
        $this->bot->store->set('adult.'.$service.'.spoiler_'.$kind,$this->bot->adultSpoiler($service,$kind)?'0':'1');
        $this->settings($chat,$message,$service);
    }
    private function layouts(int $chat,int $message,string $service): void
    {
        $ui=$this->bot->ui; $rows=[$this->heading('hub_layout')];
        foreach(UiRegistry::layouts($service) as $id=>$slots) $rows[]=[$this->navigation('layout_'.$id,'custom:layout:'.$service.':'.$id,$this->layoutChanged($service,$id))];
        $rows[]=$this->footer('custom:service:'.$service);
        $this->show($chat,$message,$ui->text('hub_layouts_info',['title'=>$this->title($service)]),$rows,['type'=>'layouts','service'=>$service]);
    }
    private function layout(int $chat,int $message,string $service,string $id,string $op='',string $slot='',string $direction=''): void
    {
        $keys=UiRegistry::layouts($service)[$id]??null; if(!$keys) return;
        $ui=$this->bot->ui; $name='layout.'.$service.'.'.$id; $rows=$ui->layoutRows($service,$id); $next=$rows;
        $view=$this->views($chat)[(string)$message]??[];
        $selected=($view['type']??'')==='layout'&&($view['service']??'')===$service&&($view['layout']??'')===$id?($view['selected']??''):'';
        if($op==='pick'&&isset($keys[$slot])) $selected=$slot;
        if($op==='cols'&&in_array($slot,['1','2','3','4'],true)) $next=array_chunk(array_merge(...$rows),(int)$slot);
        if($op==='move'&&isset($keys[$slot])&&in_array($direction,['up','down','left','right','split'],true)) {
            foreach($next as $r=>$row) if(($c=array_search($slot,$row,true))!==false) {
                if($direction==='split'&&count($row)>1) { array_splice($next[$r],$c,1); array_splice($next,$r+1,0,[[$slot]]); }
                elseif(in_array($direction,['up','down'],true)) { $other=$r+($direction==='up'?-1:1); if(isset($next[$other])) { $column=min($c,count($next[$other])-1); [$next[$r][$c],$next[$other][$column]]=[$next[$other][$column],$next[$r][$c]]; } }
                elseif($direction==='left') { if($c>0) [$next[$r][$c],$next[$r][$c-1]]=[$next[$r][$c-1],$next[$r][$c]]; elseif($r>0&&count($next[$r-1])<8) { array_shift($next[$r]); $next[$r-1][]=$slot; } }
                elseif($direction==='right') { if($c+1<count($row)) [$next[$r][$c],$next[$r][$c+1]]=[$next[$r][$c+1],$next[$r][$c]]; elseif(isset($next[$r+1])&&count($next[$r+1])<8) { array_pop($next[$r]); array_unshift($next[$r+1],$slot); } }
                break;
            }
        }
        $next=UiRegistry::normalize($next,array_map('strval',array_keys($keys)),UiRegistry::rows($service,$id));
        $change=$op==='reset'||$next!==$rows;
        if($op==='reset') $next=UiRegistry::rows($service,$id);
        $scoped=$ui->forService($service); $keyboard=[];
        foreach($next as $row) {
            $line=[];
            foreach($row as $key) { $sample=$scoped->button($keys[$key],'custom:layout:'.$service.':'.$id.':pick:'.$key,['quality'=>(ctype_digit((string)$key)?$key:'1440').'p','count'=>'2','index'=>'1']); unset($sample['_hidden']); $sample['_preview']=true; $line[]=$sample; }
            if($line) $keyboard[]=$line;
        }
        if(isset($keys[$selected])) {
            $controls=[];
            foreach(['up','down','left','right'] as $direction) $controls[]=$ui->button('layout_'.$direction,'custom:layout:'.$service.':'.$id.':move:'.$selected.':'.$direction);
            $keyboard[]=$controls; $keyboard[]=[$ui->button('layout_split','custom:layout:'.$service.':'.$id.':move:'.$selected.':split')];
        }
        $width=[]; foreach(['1','2','3','4'] as $n) $width[]=$ui->button('layout_columns','custom:layout:'.$service.':'.$id.':cols:'.$n,['n'=>$n]); $keyboard[]=$width;
        $keyboard[]=[$ui->button('reset_order','custom:layout:'.$service.':'.$id.':reset')]; $keyboard[]=$this->footer('custom:layouts:'.$service);
        try { $this->show($chat,$message,$ui->text('hub_layout_editor',['title'=>$this->title($service),'selected'=>isset($keys[$selected])?$scoped->label($keys[$selected],['quality'=>(ctype_digit($selected)?$selected:'1440').'p','index'=>'1','count'=>'2']):$ui->plain('state_none')]),$keyboard,['type'=>'layout','service'=>$service,'layout'=>$id,'selected'=>$selected]); }
        catch(\RuntimeException $e) { if($e->getCode()!==400) throw $e; $this->editor->send($chat,$ui->text('custom_preview_failed'),[$this->footer('custom:layout:'.$service.':'.$id)],$message); return; }
        if($change) $this->bot->store->set($name,json_encode($next,JSON_THROW_ON_ERROR));
    }
    public function handle(int $chat,int $user,string $action,int $message): bool
    {
        $p=explode(':',$action); $op=$p[1]??''; $service=$p[2]??'';
        if(!in_array($op,['service','category','items','settings','layouts','layout','mode','coverhub','spoiler','resetservice'],true)) return false;
        if($op==='category') { if(in_array($service,['adult','music','general'],true)) $this->category($chat,$message,$service); else $this->home($chat,$message); return true; }
        if(!in_array($service,UiRegistry::services(),true)) { $this->home($chat,$message); return true; }
        match($op) {
            'service'=>$this->service($chat,$message,$service), 'settings'=>$this->settings($chat,$message,$service), 'layouts'=>$this->layouts($chat,$message,$service),
            'coverhub'=>$this->cover($chat,$message,$service),
            'items'=>$this->listing($chat,$message,$service,$p[3]??'',max(0,(int)($p[4]??0))),
            'layout'=>$this->layout($chat,$message,$service,$p[3]??'',$p[4]??'',$p[5]??'',$p[6]??''),
            'mode'=>$this->mode($chat,$message,$service,$p[3]??'',$p[4]??''),
            'spoiler'=>$this->spoiler($chat,$message,$service,$p[3]??''),
            'resetservice'=>$this->reset($chat,$message,$service,($p[3]??'')==='yes'),
        };
        return true;
    }
}
