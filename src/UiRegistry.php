<?php
declare(strict_types=1);
namespace Zero;

/** Shared controls get a service-bound catalog key; existing settings remain their read-only fallback. */
final class UiRegistry
{
    public const QUALITY_BUTTONS=['quality_240','quality_360','quality_480','quality_720','quality_high','quality_native','extract_audio'];
    private const VIDEO_TEXTS=['quality_prompt','cover_title','cover_instruction','cover_limit','queued','inspecting','quality_expired','quality_busy',
        'downloading','uploading','audio_downloading','audio_uploading','failed','error_size','error_restricted','error_cookies','error_duration',
        'error_network','error_storage','error_blocked','error_format','error_process','error_upload','handoff_ready','handoff_expired','handoff_queued','handoff_denied','handoff_failed','audio_failed','audio_error_size','audio_error_format','audio_error_upload'];
    private const YOUTUBE_TEXTS=['video_caption','video_title','video_quality','video_duration','audio_caption','audio_title','audio_format','audio_duration'];
    public static function services(): array { return array_values(array_unique(['youtube',...galleryServices(),...musicServices(),...adultServices()])); }
    public static function shared(string $service,string $kind): array
    {
        if(in_array($service,['youtube',...adultServices()],true)) return $kind==='button'
            ?array_merge(array_values(array_filter(self::QUALITY_BUTTONS,fn($key)=>($key!=='quality_240'||$service!=='youtube')&&($key!=='quality_native'||in_array($service,['tikporn','eporner'],true)))),$service==='youtube'?['source_video','source_audio']:['handoff_open'])
            :array_merge(array_values(array_diff(self::VIDEO_TEXTS,$service==='youtube'?['handoff_ready','handoff_expired','handoff_queued','handoff_denied','handoff_failed']:['error_size','error_restricted','error_format','error_network','error_blocked','audio_error_size','audio_error_format'])),$service==='youtube'?self::YOUTUBE_TEXTS:[]);
        return $kind==='button'&&in_array($service,musicServices(),true)?['music_share','radio_play','batch_back']:[];
    }
    public static function key(string $service,string $kind,string $key): string
    {
        return in_array($key,self::shared($service,$kind),true)?$service.'__'.$key:$key;
    }
    public static function legacy(string $kind,string $key): string
    {
        // Companion fields have no YouTube copy. Resolve their legacy editor addresses to an active adult scope too.
        $service=str_starts_with($key,'handoff_')||($kind==='button'&&$key==='quality_240')?'pornhub':($kind==='button'&&$key==='quality_native'?'eporner':'youtube');
        return self::key($service,$kind,$key);
    }
    public static function expand(array $catalog): array
    {
        foreach(self::services() as $service) {
            $catalog['groups'][$service]??=$service==='youtube'?'یوتیوب':$service;
            foreach(['text'=>'texts','button'=>'buttons'] as $kind=>$bucket) foreach(self::shared($service,$kind) as $key) {
                $base=$catalog[$bucket][$key]??null;
                if(($base['audience']??'')!=='user'||!empty($base['hidden'])) continue;
                $entry=array_replace($base,['group'=>$service,'inherits'=>$key,'service'=>$service,'base'=>$key]);
                if($kind==='button'&&in_array($key,self::QUALITY_BUTTONS,true)) { $entry['style']=''; $entry['inherit_style']=false; }
                $catalog[$bucket][self::key($service,$kind,$key)]=$entry;
            }
        }
        return $catalog;
    }
    /** Catalog entries belonging to a downloader, including all active, independently editable fields. */
    public static function items(string $service,array $catalog): array
    {
        if(!in_array($service,self::services(),true)) return [];
        $items=[];
        foreach(['texts'=>'text','buttons'=>'button'] as $bucket=>$kind) foreach($catalog[$bucket] as $key=>$item)
            if(($item['group']??'')===$service&&($item['audience']??'')==='user'&&empty($item['hidden'])) $items[]=[$kind,$key];
        return $items;
    }
    public static function category(string $kind,array $item): string
    {
        if($kind==='button') return 'button';
        $key=$item['base']??'';
        return !empty($item['caption'])||in_array($key,['cover_title','cover_instruction','cover_limit',...self::YOUTUBE_TEXTS],true)
            ||preg_match('/_(?:notice|people|views|tags)$/D',$item['key']??'')?'caption':'text';
    }
    /** Stable button slots. Layouts move slots, never callback data, source URLs or dynamic media records. */
    public static function layouts(string $service): array
    {
        if(in_array($service,['youtube',...adultServices()],true)) {
            $result=['quality'=>array_filter(['240'=>'quality_240','360'=>'quality_360','480'=>'quality_480','720'=>'quality_720','1080'=>'quality_high','native'=>'quality_native','audio'=>'extract_audio'],fn($key)=>in_array($key,self::shared($service,'button'),true))];
            if($service==='youtube') $result+=['video'=>['source'=>'source_video'],'audio'=>['source'=>'source_audio'],
                'post_slides'=>['prev'=>'youtube_post_prev','next'=>'youtube_post_next','source'=>'source_youtube_post'],
                'post_delivery'=>['source'=>'source_youtube_post']];
            else $result+=['handoff'=>['open'=>'handoff_open']];
            if($service==='youtube') {
                foreach(self::layouts('instagram') as $id=>$slots) if(str_starts_with($id,'checker_')&&!str_contains($id,'_story_')) $result[$id]=array_map(fn($key)=>str_replace('instagram_checker','youtube_checker',$key),$slots);
                $result['checker_new']=['videos'=>'youtube_checker_mode1','shorts'=>'youtube_checker_mode2','posts'=>'youtube_checker_mode4','videos_shorts'=>'youtube_checker_mode3','videos_posts'=>'youtube_checker_mode5','shorts_posts'=>'youtube_checker_mode6','all'=>'youtube_checker_mode7','close'=>'youtube_checker_close'];
                foreach(['video','short'] as $kind) $result['checker_'.$kind.'_delivery']=['source'=>'youtube_checker_'.$kind.'_source'];
            }
            return $result;
        }
        if(in_array($service,galleryServices(),true)) return ['slides'=>['prev'=>$service.'_prev','next'=>$service.'_next','source'=>'source_'.$service],
            'delivery'=>['source'=>'source_'.$service],'article'=>['article'=>'open_'.$service.'_article']]+($service==='instagram'?[
                'story_slides'=>['prev'=>'instagram_story_prev','next'=>'instagram_story_next','source'=>'source_instagram_story'],
                'story_delivery'=>['source'=>'source_instagram_story'],
                'checker_home'=>['add'=>'instagram_checker_add',...array_fill_keys(array_map(fn($n)=>'entry'.$n,range(1,8)),'instagram_checker_entry'),'prev'=>'instagram_checker_previous','next'=>'instagram_checker_next','hub'=>'checker_menu_back','close'=>'instagram_checker_close'],
                'checker_manage'=>['check'=>'instagram_checker_check','rename'=>'instagram_checker_rename','toggle'=>'instagram_checker_pause','mode'=>'instagram_checker_change_mode','delete'=>'instagram_checker_delete','back'=>'instagram_checker_back','close'=>'instagram_checker_close'],
                'checker_new'=>['posts'=>'instagram_checker_mode1','stories'=>'instagram_checker_mode2','both'=>'instagram_checker_mode3','close'=>'instagram_checker_close'],
                'checker_delete'=>['yes'=>'instagram_checker_delete_yes','back'=>'instagram_checker_back','close'=>'instagram_checker_close'],
                'checker_post_slides'=>['prev'=>'instagram_checker_post_prev','next'=>'instagram_checker_post_next','source'=>'instagram_checker_post_source'],
                'checker_story_slides'=>['prev'=>'instagram_checker_story_prev','next'=>'instagram_checker_story_next','source'=>'instagram_checker_story_source'],
                'checker_post_delivery'=>['source'=>'instagram_checker_post_source'],'checker_story_delivery'=>['source'=>'instagram_checker_story_source'],
            ]:[]);
        if(in_array($service,musicServices(),true)) return ['delivery'=>['source'=>'source_'.$service,'share'=>'music_share','radio'=>'radio_play','back'=>'batch_back']];
        return [];
    }
    public static function rows(string $service,string $layout): array
    {
        return match($layout) {
            'checker_home'=>[['add'],...array_map(fn($n)=>['entry'.$n],range(1,8)),['prev','next'],['hub'],['close']],
            'checker_manage'=>[['check','rename'],['toggle'],['mode','delete'],['back','close']],
            'checker_new'=>$service==='youtube'?[['videos','shorts'],['posts'],['videos_shorts'],['videos_posts','shorts_posts'],['all'],['close']]:[['posts'],['stories'],['both'],['close']],
            'checker_delete'=>[['yes'],['back','close']],
            'quality'=>array_merge(array_chunk(array_values(array_filter(array_map('strval',array_keys(self::layouts($service)[$layout])),fn($id)=>$id!=='audio')),2),[['audio']]),
            'slides'=>[['prev','next'],['source']],
            'story_slides','post_slides','checker_post_slides','checker_story_slides'=>[['prev','next'],['source']],
            default=>array_map(fn($id)=>[(string)$id],array_keys(self::layouts($service)[$layout]??[])),
        };
    }
    /** Validate saved rows and append new slots using their declared default placement. */
    public static function normalize(array $rows,array $known,array $defaults): array
    {
        $result=[]; $used=[];
        foreach(array_slice($rows,0,24) as $row) {
            if(!is_array($row)) continue;
            $clean=[];
            foreach(array_slice($row,0,8) as $id) {
                if(!is_string($id)&&!is_int($id)) continue;
                $id=(string)$id;
                if(!in_array($id,$known,true)||isset($used[$id])) continue;
                $used[$id]=true; $clean[]=$id;
            }
            if($clean) $result[]=$clean;
        }
        foreach($defaults as $row) {
            $missing=[];
            foreach($row as $id) { $id=(string)$id; if(in_array($id,$known,true)&&!isset($used[$id])) { $missing[]=$id; $used[$id]=true; } }
            if($missing) $result[]=$missing;
        }
        return $result;
    }
}
