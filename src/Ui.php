<?php
declare(strict_types=1);
namespace Zero;

final class Ui
{
    public const REPLY_KEYS=['help','admin','health','update','cookies','download','customize','on','off','back_home','add_admin','cookie_youtube','cookie_upload','cookie_view','cookie_remove','cookie_export','cookie_delete_confirm','cookie_back_services','cookie_mode','cookie_guide','cookie_alerts','cookie_keepalive','cookie_interval','cookie_check_now','cookie_back_youtube','cookie_back_admin','cookie_instagram','ig_cookie_upload','ig_cookie_view','ig_cookie_export','ig_cookie_remove','ig_cookie_confirm','ig_cookie_back','cookie_pinterest','pin_cookie_upload','pin_cookie_view','pin_cookie_export','pin_cookie_remove','pin_cookie_confirm','pin_cookie_back','cookie_tiktok','tt_cookie_upload','tt_cookie_view','tt_cookie_export','tt_cookie_remove','tt_cookie_confirm','tt_cookie_back','cookie_twitter','x_cookie_upload','x_cookie_view','x_cookie_export','x_cookie_remove','x_cookie_confirm','x_cookie_back'];
    private static ?array $definitions=null;
    private array $preview=[];
    public function __construct(private Store $store) {}
    public function preview(array $values): void { $this->preview=$values; }
    /** A setting as it would be shown now, including a pending preview. */
    public function setting(string $key,string $default=''): string { return $this->get($key,$default); }
    private function get(string $key,string $default=''): string { return array_key_exists($key,$this->preview)?$this->preview[$key]:$this->store->get($key,$default); }
    public static function definitions(): array
    {
        return self::$definitions??=json_decode((string)file_get_contents(dirname(__DIR__).'/resources/ui.json'),true,512,JSON_THROW_ON_ERROR);
    }
    public function definition(string $kind,string $key): ?array { return self::definitions()[$kind==='button'?'buttons':'texts'][$key]??null; }
    public function customizable(string $kind,string $key): bool
    {
        if(!in_array($kind,['text','button'],true)) return false;
        $item=$this->definition($kind,$key);
        return ($item['audience']??'')==='user'&&empty($item['hidden']);
    }
    public function catalog(string $kind): array
    {
        $result=[];
        foreach(self::definitions()[$kind==='button'?'buttons':'texts'] as $key=>$item) if($this->customizable($kind,$key)) $result[$key]=[$item['label'],$item['value']];
        return $result;
    }
    public function template(string $key): string
    {
        $item=$this->definition('text',$key);
        if(!$item) throw new \RuntimeException('Unregistered UI text: '.$key);
        return $this->get('text.'.$key,$item['value']);
    }
    public function text(string $key,array $values=[],array $raw=[]): string
    {
        $replace=[];
        foreach($values as $name=>$value) $replace['{'.$name.'}']=in_array($name,$raw,true)?(string)$value:self::escape((string)$value);
        $result=strtr($this->template($key),$replace);
        $definition=$this->definition('text',$key);
        $limit=!empty($definition['caption'])?1024:3900;
        if(TextFormat::length(html_entity_decode(strip_tags($result),ENT_QUOTES|ENT_HTML5,'UTF-8'))>$limit) $result=strtr($definition['value'],$replace);
        return $result;
    }
    /** A text with {placeholders} filled for places that take plain text, such as button labels. */
    public function plainWith(string $key,array $values): string
    {
        $replace=[]; foreach($values as $name=>$value) $replace['{'.$name.'}']=(string)$value;
        return strtr($this->plain($key),$replace);
    }
    public function plain(string $key): string { return html_entity_decode(strip_tags($this->template($key)),ENT_QUOTES|ENT_HTML5,'UTF-8'); }
    public static function escape(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    public static function fitCaption(string $html): string
    {
        $plain=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return TextFormat::length($plain)<=1024?$html:self::escape(mb_substr($plain,0,500));
    }
    public static function duration(int $seconds): string
    {
        $seconds=max(0,$seconds);
        return $seconds>=3600?sprintf('%d:%02d:%02d',intdiv($seconds,3600),intdiv($seconds%3600,60),$seconds%60):sprintf('%02d:%02d',intdiv($seconds,60),$seconds%60);
    }
    public function label(string $key,array $values=[]): string
    {
        $item=$this->definition('button',$key);
        if(!$item) throw new \RuntimeException('Unregistered UI button: '.$key);
        $replace=[];
        foreach($values as $name=>$value) $replace['{'.$name.'}']=(string)$value;
        $fallback=isset($item['inherits'])?$this->get('button.'.$item['inherits'],$item['value']):$item['value'];
        return strtr($this->get('button.'.$key,$fallback),$replace);
    }
    public function replyAction(string $text): ?string
    {
        foreach(self::REPLY_KEYS as $key) if($text===$this->label($key)) return $key;
        foreach(self::REPLY_KEYS as $key) {
            $saved=json_decode($this->store->get('button.aliases.'.$key,'[]'),true);
            $aliases=array_merge($this->definition('button',$key)['aliases']??[],is_array($saved)?$saved:[]);
            if(in_array($text,$aliases,true)) return $key;
        }
        $normalized=self::replyKey($text);
        if($normalized!=='') foreach(self::REPLY_KEYS as $key) {
            $saved=json_decode($this->store->get('button.aliases.'.$key,'[]'),true);
            $aliases=array_merge([$this->label($key)],$this->definition('button',$key)['aliases']??[],is_array($saved)?$saved:[]);
            foreach($aliases as $alias) if(is_string($alias)&&$normalized===self::replyKey($alias)) return $key;
        }
        return null;
    }
    private static function replyKey(string $text): string
    {
        $text=mb_strtolower(strtr($text,['ي'=>'ی','ك'=>'ک']));
        return preg_replace('/[^\p{L}\p{N}]+/u','',$text)??'';
    }
    /** One emoji: keycap, flag, or a pictograph with its modifiers and joined parts. */
    private const EMOJI='(?:[0-9#*]\x{FE0F}?\x{20E3}|[\x{1F1E6}-\x{1F1FF}]{2}|\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}]*(?:\x{200D}\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}]*)*)';
    /** Text without the emoji at either end of the label. */
    public static function stripEmoji(string $text): string
    {
        $clean=preg_replace('/^(?:'.self::EMOJI.'\s*)+/u','',$text)??$text;
        $clean=trim(preg_replace('/(?:\s*'.self::EMOJI.')+$/u','',$clean)??$clean);
        return $clean!==''?$clean:$text;
    }
    /** One emoji and nothing else. */
    public static function singleEmoji(string $text): ?string
    {
        $text=trim($text);
        return strlen($text)<=64&&preg_match('/^'.self::EMOJI.'$/Du',$text)===1?$text:null;
    }
    /** Navigation, article and reply-keyboard buttons keep working only if they stay visible and keep their label. */
    public function required(string $key): bool
    {
        return !empty($this->definition('button',$key)['required'])||in_array($key,self::REPLY_KEYS,true);
    }
    public function button(string $key,?string $data=null,array $values=[]): array
    {
        $item=$this->definition('button',$key);
        $button=['text'=>$this->label($key,$values)];
        if($data!==null) $button['callback_data']=$data;
        $parent=$item['inherits']??'';
        $style=$this->get('button.style.'.$key,$parent!==''?$this->get('button.style.'.$parent,$this->definition('button',$parent)['style']??''):($item['style']??''));
        if(in_array($style,['primary','success','danger'],true)) $button['style']=$style;
        $icon=$this->get('button.icon.'.$key,$parent!==''?$this->get('button.icon.'.$parent):'');
        $premium=preg_match('/^\d{5,24}$/D',$icon)===1;
        if($premium) $button['icon_custom_emoji_id']=$icon;
        // Reply-keyboard labels are matched against what Telegram sends back, so only inline buttons are restyled.
        if(!in_array($key,self::REPLY_KEYS,true)) {
            $plain=$this->get('button.plain.'.$key)==='1'; $emoji=self::singleEmoji($this->get('button.emoji.'.$key));
            if($plain) $button['text']=self::stripEmoji($button['text']);
            elseif($premium) $button['text']=self::stripEmoji($button['text']);
            elseif($emoji!==null) $button['text']=$this->get('button.pos.'.$key,'start')==='end'?self::stripEmoji($button['text']).' '.$emoji:$emoji.' '.self::stripEmoji($button['text']);
        }
        // Telegram::call removes hidden buttons just before sending, so every screen honours the switch.
        if(!$this->required($key)&&$this->get('button.hidden.'.$key)==='1') $button['_hidden']=true;
        return $button;
    }
    public function instagramSourceEnabled(): bool { return $this->gallerySourceEnabled('instagram'); }
    public function instagramSourceRows(string $url): array { return $this->gallerySourceRows('instagram',$url); }
    public function gallerySourceEnabled(string $service): bool { return $this->get($service.'.source_button','0')==='1'; }
    public function gallerySourceRows(string $service,string $url): array
    {
        if(!$this->gallerySourceEnabled($service)) return [];
        $button=$this->button('source_'.$service); $button['url']=$url;
        return [[$button]];
    }
    public function coverCaption(string $title,string $maxSize): string
    {
        $mode=$this->get('cover.parts','');
        if($mode==='legacy'||($mode===''&&$this->store->get('text.quality_prompt','')!=='')) return $this->text('quality_prompt',['title'=>$title,'max_size'=>$maxSize]);
        return $this->text('cover_title',['title'=>$title])."\n\n".$this->text('cover_instruction')."\n".$this->text('cover_limit',['max_size'=>$maxSize]);
    }
    public function coverPartsFit(string $maxSize): bool
    {
        // The downloader retains up to 180 Unicode characters; each can occupy
        // two Telegram UTF-16 units. Check the combined photo caption before saving.
        $title=strtr($this->template('cover_title'),['{title}'=>self::escape(str_repeat('🙂',180))]);
        $limit=strtr($this->template('cover_limit'),['{max_size}'=>self::escape($maxSize)]);
        $plain=html_entity_decode(strip_tags($title."\n\n".$this->template('cover_instruction')."\n".$limit),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return TextFormat::length($plain)<=1024;
    }
    public function mediaCaption(string $kind,array $values): string
    {
        $mode=$this->get('media.'.$kind.'.parts','');
        if($mode==='legacy'||($mode===''&&$this->store->get('text.'.$kind.'_caption','')!=='')) return $this->text($kind.'_caption',$values);
        $detail=$kind==='video'?'video_quality':'audio_format';
        return $this->text($kind.'_title',$values)."\n\n<blockquote>".$this->text($detail,$values)."\n".$this->text($kind.'_duration',$values).'</blockquote>';
    }
    public function mediaPartsFit(string $kind): bool
    {
        $values=['{title}'=>self::escape(str_repeat('🙂',100)),'{quality}'=>'1080p','{format}'=>'MP3',
            '{duration}'=>str_repeat('9',24),'{url}'=>'https://www.youtube.com/watch?v=ybNMMK5Q318'];
        $detail=$kind==='video'?'video_quality':'audio_format';
        $html=strtr($this->template($kind.'_title'),$values)."\n\n".strtr($this->template($detail),$values)."\n".strtr($this->template($kind.'_duration'),$values);
        return TextFormat::length(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'))<=1024;
    }
    public function coverOrder(): array
    {
        $default=['360','480','720','1080','audio'];
        $order=json_decode($this->store->get('cover.order',''),true);
        if(!is_array($order)||count($order)!==count($default)||count(array_unique($order,SORT_REGULAR))!==count($default)) return $default;
        foreach($order as $key) if(!is_string($key)||!in_array($key,$default,true)) return $default;
        return $order;
    }
    public static function qualityKey(string $quality): string { return $quality==='1080'?'quality_high':'quality_'.$quality; }
}

final class TextFormat
{
    public static function length(string $text): int { return intdiv(strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8')),2); }
    private static function slice(string $text,int $start,int $length): string
    {
        return mb_convert_encoding(substr(mb_convert_encoding($text,'UTF-16LE','UTF-8'),$start*2,$length*2),'UTF-8','UTF-16LE');
    }
    public static function emoji(array $message): ?string
    {
        $id=$message['sticker']['custom_emoji_id']??null;
        foreach($message['entities']??[] as $entity) if(($entity['type']??'')==='custom_emoji') { $id=$entity['custom_emoji_id']??null; break; }
        return is_string($id)&&preg_match('/^\d{5,24}$/D',$id)?$id:null;
    }
    public static function buttonText(array $message): string
    {
        $text=(string)($message['text']??'');
        foreach($message['entities']??[] as $entity) if(($entity['type']??'')==='custom_emoji') {
            $start=(int)$entity['offset']; $end=$start+(int)$entity['length'];
            return trim(self::slice($text,0,$start).self::slice($text,$end,self::length($text)-$end));
        }
        return trim($text);
    }
    public static function fromMessage(array $message): string
    {
        $text=(string)($message['text']??''); $events=[];
        $tags=['bold'=>'b','italic'=>'i','underline'=>'u','strikethrough'=>'s','spoiler'=>'tg-spoiler','code'=>'code','pre'=>'pre','blockquote'=>'blockquote','expandable_blockquote'=>'blockquote'];
        foreach($message['entities']??[] as $index=>$entity) {
            $type=$entity['type']??''; $tag=$tags[$type]??null;
            $open=$tag?'<'.$tag.($type==='expandable_blockquote'?' expandable':'').'>':null;
            if($type==='custom_emoji'&&preg_match('/^\d{5,24}$/D',(string)($entity['custom_emoji_id']??''))) {
                $tag='tg-emoji'; $open='<tg-emoji emoji-id="'.$entity['custom_emoji_id'].'">';
            } elseif($type==='text_link'&&self::safeLink((string)($entity['url']??''))) {
                $tag='a'; $open='<a href="'.Ui::escape($entity['url']).'">';
            }
            if(!$open) continue;
            $start=(int)$entity['offset']; $length=(int)$entity['length']; $end=$start+$length;
            if($start<0||$length<1||$end>self::length($text)) throw new \RuntimeException('Invalid text entity range');
            $priority=$type==='custom_emoji'?100:0;
            $events[$start]['open'][]=[$length,$priority,$index,$open];
            $events[$end]['close'][]=[$start,$priority,$index,'</'.$tag.'>'];
        }
        if(!$events) return preg_match('~</?(?:b|strong|i|em|u|s|del|ins|strike|blockquote|code|pre|a|tg-emoji|tg-spoiler)(?:\s|>)~i',$text)?$text:Ui::escape($text);
        ksort($events); $result=''; $position=0;
        foreach($events as $offset=>$event) {
            $result.=Ui::escape(self::slice($text,$position,(int)$offset-$position));
            $close=$event['close']??[]; $open=$event['open']??[];
            usort($close,fn($a,$b)=>[$b[0],$b[1],$b[2]]<=>[$a[0],$a[1],$a[2]]);
            usort($open,fn($a,$b)=>[$b[0],$a[1],$a[2]]<=>[$a[0],$b[1],$b[2]]);
            foreach($close as $part) $result.=$part[3];
            foreach($open as $part) $result.=$part[3];
            $position=(int)$offset;
        }
        return $result.Ui::escape(self::slice($text,$position,self::length($text)-$position));
    }
    private static function safeLink(string $url): bool
    {
        return preg_match('~^https?://[^\s<>"\x00-\x1f]+$~D',$url)===1||preg_match('~^tg://user\?id=[1-9]\d*$~D',$url)===1;
    }
    public static function validate(string $html,int $limit): void
    {
        $visible=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(trim($visible)===''||self::length($visible)>$limit||strlen($html)>24000) throw new \RuntimeException('Text exceeds the allowed length');
        $stack=[];
        foreach(preg_split('~(<[^>]*>)~u',$html,-1,PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if($part===''||$part[0]!=='<') {
                if(preg_match('/[<>]|&(?!amp;|lt;|gt;|quot;|#\d+;|#x[0-9a-fA-F]+;)/u',$part)) throw new \RuntimeException('Escape literal HTML characters');
                continue;
            }
            if(preg_match('~^</([a-z-]+)>$~D',$part,$match)) {
                if(array_pop($stack)!==$match[1]) throw new \RuntimeException('Mismatched HTML tag');
                continue;
            }
            $tag=null;
            if(preg_match('~^<(b|strong|i|em|u|ins|s|strike|del|code|pre|tg-spoiler)>$~D',$part,$match)) $tag=$match[1];
            elseif(preg_match('~^<blockquote(?: expandable)?>$~D',$part)) {
                if(in_array('blockquote',$stack,true)) throw new \RuntimeException('Nested blockquotes are not supported');
                $tag='blockquote';
            } elseif(preg_match('~^<tg-emoji emoji-id="\d{5,24}">$~D',$part)) $tag='tg-emoji';
            elseif(preg_match('~^<a href="([^"]+)">$~D',$part,$match)&&self::safeLink(html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8'))) $tag='a';
            if(!$tag||in_array('tg-emoji',$stack,true)) throw new \RuntimeException('Unsupported HTML tag');
            $stack[]=$tag;
        }
        if($stack) throw new \RuntimeException('Unclosed HTML tag');
    }
}
