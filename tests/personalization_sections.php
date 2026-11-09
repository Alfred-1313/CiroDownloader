<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
final class SectionApi implements Zero\Api {
    public array $calls=[]; public bool $rejectIcons=false;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($this->rejectIcons&&(str_contains(json_encode($params),'icon_custom_emoji_id')||str_contains(json_encode($params),'tg-emoji'))) throw new RuntimeException('Telegram rejected emoji',400);
        return $method==='sendMessage'?['message_id'=>900]:true;
    }
}
function sectionCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function sectionClick(string $data,int $user=42): array { return ['callback_query'=>['id'=>'sections','from'=>['id'=>$user],'message'=>['message_id'=>900,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]; }
function sectionInput(string $text,int $user=42): array { return ['update_id'=>random_int(1,1000000),'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]; }
function sectionActions(SectionApi $api): array {
    $result=[]; foreach(end($api->calls)[1]['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button) if(isset($button['callback_data'])) $result[]=$button['callback_data']; return $result;
}
$temp=sys_get_temp_dir().'/ciro-sections-'.bin2hex(random_bytes(6));mkdir($temp,0700);
try {
    $store=new Zero\Store($temp);$api=new SectionApi();$bot=new Zero\Bot(new Zero\Config('123:fake',[42],$temp),$store,$api);
    $bot->handle(sectionClick('custom:sections'));
    foreach(['cover','video','audio','instagram','pinterest','tiktok','twitter','pornhub','beeg','spotify','soundcloud','menu','batch','search','radio','radio_player','help','group','access','progress','errors'] as $section) sectionCheck(in_array('custom:section:'.$section,sectionActions($api),true),'screen is available: '.$section);
    $seen=[];
    foreach(['cover','video','audio','instagram','pinterest','tiktok','twitter','pornhub','beeg','spotify','soundcloud','menu','batch','search','radio','radio_player','help','group','access','progress','errors'] as $section) {
        // Long screens are paged; follow the Next links to see every item.
        $queue=['custom:section:'.$section]; $visited=[];
        while($queue) {
            $address=array_shift($queue); if(isset($visited[$address])) continue; $visited[$address]=true;
            $bot->handle(sectionClick($address));
            foreach(sectionActions($api) as $action) {
                if(preg_match('/^custom:item:(text|button):(.+)$/D',$action,$m)) {
                    sectionCheck($bot->ui->customizable($m[1],$m[2]),'screen contains only user-facing active fields: '.$action);
                    $seen[$m[1]][$m[2]]=true;
                } elseif(preg_match('/^custom:section:'.$section.':\d+$/D',$action)) $queue[]=$action;
            }
        }
        sectionCheck(count($visited)>=1&&count($visited)<=4,'screen is paged into a few short pages: '.$section);
    }
    foreach(['text','button'] as $kind) foreach($bot->catalog($kind) as $key=>$definition) {
        sectionCheck(isset($seen[$kind][$key]),'user-facing field belongs to a screen: '.$kind.'.'.$key);
    }
    $bot->handle(sectionClick('custom:list:caption:download:0'));
    $captionKeys=['quality_prompt','cover_title','cover_instruction','cover_limit','video_caption','video_title','video_quality','video_duration','audio_caption','audio_title','audio_format','audio_duration'];
    $captionActions=array_values(array_filter(sectionActions($api),fn($action)=>str_starts_with($action,'custom:item:')));
    sectionCheck($captionActions===array_map(fn($key)=>'custom:item:text:'.$key,$captionKeys),'download captions are ordered by cover, video and audio, with every component separate on one page');
    $rows=end($api->calls)[1]['reply_markup']['inline_keyboard']; $headings=[];
    foreach($rows as $row) foreach($row as $button) {
        if($button['callback_data']==='custom:noop') { $headings[]=$button['text']; sectionCheck(!isset($button['style']),'caption category header is a white title'); }
        elseif(str_starts_with($button['callback_data'],'custom:item:')) sectionCheck(($button['style']??'')==='primary','individual caption controls are blue like every selectable button');
    }
    foreach(['cover','video','audio'] as $section) sectionCheck(in_array($bot->ui->label('catalog_caption_'.$section),$headings,true),'download caption category is explicit: '.$section);
    $bot->handle(sectionClick('custom:item:text:video_duration'));
    sectionCheck(in_array('custom:list:caption:download:0',sectionActions($api),true),'Back from the final video component returns to the same grouped catalog');
    $bot->handle(sectionClick('custom:list:button:download:0'));
    foreach(['quality_360','quality_480','quality_720','quality_high'] as $key) sectionCheck(in_array('custom:item:button:'.$key,sectionActions($api),true),'each quality has its own catalog control: '.$key);
    sectionCheck(!in_array('custom:item:button:quality',sectionActions($api),true),'the obsolete combined 360-to-720 control is hidden');
    $bot->handle(sectionClick('custom:section:cover'));
    foreach(['custom:item:text:quality_prompt','custom:item:text:cover_title','custom:item:text:cover_instruction','custom:item:text:cover_limit','custom:item:button:quality_360','custom:item:button:quality_480','custom:item:button:quality_720','custom:item:button:quality_high','custom:item:button:extract_audio','custom:covermode:parts','custom:covermode:legacy','custom:reorder'] as $action) sectionCheck(in_array($action,sectionActions($api),true),'cover has its own editable control: '.$action);
    $bot->config->maxBytes=1990000000;
    $bot->handle(sectionClick('custom:item:text:cover_limit'));
    sectionCheck(str_contains(end($api->calls)[1]['text'],'1990 MB'),'cover preview uses the configured upload limit');
    $bot->handle(sectionClick('custom:item:button:quality_360'));
    sectionCheck(in_array('custom:section:cover',sectionActions($api),true),'back from a control returns to its screen');
    $store->set('button.quality','OLD {quality}');$store->set('button.style.quality','success');$store->set('button.icon.quality','5368324170671202286');
    $before=$bot->ui->button('quality_360','custom:noop',['quality'=>'360p']);
    sectionCheck($before['text']==='OLD 360p'&&$before['style']==='success'&&$before['icon_custom_emoji_id']==='5368324170671202286','existing shared quality customization is inherited');
    $bot->handle(sectionClick('custom:edit:button:quality_360'));$bot->handle(sectionInput('360 BEST'));
    $bot->handle(sectionClick('custom:setcolor:quality_360:danger'));
    sectionCheck($bot->ui->button('quality_360',null,['quality'=>'360p'])['text']==='360 BEST'&&$bot->ui->button('quality_360')['style']==='danger','360p has independent name and color');
    sectionCheck($bot->ui->button('quality_480',null,['quality'=>'480p'])['text']==='OLD 480p'&&$bot->ui->button('quality_480')['style']==='success','changing 360p does not change 480p');
    $bot->handle(sectionClick('custom:reset:button:quality_360'));
    sectionCheck($bot->ui->button('quality_360',null,['quality'=>'360p'])===$bot->ui->button('quality',null,['quality'=>'360p']),'reset restores inherited title, color and icon');
    $store->set('text.quality_prompt','OLD CUSTOM {title} / {max_size}');
    sectionCheck($bot->ui->coverCaption('VIDEO','1990')==='OLD CUSTOM VIDEO / 1990','previously customized complete caption remains active');
    $bot->handle(sectionClick('custom:edit:text:cover_instruction'));$bot->handle(sectionInput('کیفیت مورد نظر را انتخاب کن'));
    $caption=$bot->ui->coverCaption('VIDEO','1990');
    sectionCheck(str_contains($caption,'VIDEO')&&str_contains($caption,'کیفیت مورد نظر')&&str_contains($caption,'1990 MB'),'editing an individual caption part activates all separate components');
    sectionCheck($store->get('text.quality_prompt')==='OLD CUSTOM {title} / {max_size}','activating individual parts retains the previous complete caption');
    $bot->handle(sectionClick('custom:edit:text:cover_limit'));$bot->handle(sectionInput('محدودیت {max_size} مگابایت'));
    sectionCheck(str_contains($bot->ui->coverCaption('VIDEO','1990'),'محدودیت 1990 مگابایت'),'upload-limit wording can be edited independently while retaining the real limit');
    $bot->handle(sectionClick('custom:edit:text:cover_title'));$bot->handle(sectionInput('<b>{title}</b>'));
    sectionCheck(str_starts_with($bot->ui->coverCaption('VIDEO','1990'),'<b>VIDEO</b>'),'cover title can be edited independently with supported formatting');
    $bot->handle(sectionClick('custom:edit:text:cover_title'));$bot->handle(sectionInput('{title} {title} {title}'));
    sectionCheck($store->get('text.cover_title')==='<b>{title}</b>'&&str_contains(end($api->calls)[1]['text'],'طولانی'),'combined caption overflow preserves the previous title');
    $bot->handle(sectionClick('custom:edit:text:quality_prompt'));$bot->handle(sectionInput('FULL {title} {max_size}'));
    sectionCheck($bot->ui->coverCaption('VIDEO','1990')==='FULL VIDEO 1990'&&$store->get('text.cover_instruction')==='کیفیت مورد نظر را انتخاب کن','editing the complete caption reactivates it while preserving separate components');
    $bot->handle(sectionClick('custom:covermode:parts'));
    sectionCheck(str_contains($bot->ui->coverCaption('VIDEO','1990'),'کیفیت مورد نظر'),'separate parts can be reactivated without retyping them');
    $bot->handle(sectionClick('custom:covermode:legacy'));
    sectionCheck($bot->ui->coverCaption('VIDEO','1990')==='FULL VIDEO 1990','the saved complete caption can be reactivated without retyping it');
    $store->set('text.cover_title','{title} {title} {title}');
    $bot->handle(sectionClick('custom:covermode:parts'));
    sectionCheck($store->get('cover.parts')==='legacy'&&str_contains(end($api->calls)[1]['text'],'طولانی'),'an oversized stored caption cannot be reactivated');
    $store->set('text.cover_title','<tg-emoji emoji-id="5368324170671202286">🙂</tg-emoji> {title}');$api->rejectIcons=true;
    $bot->handle(sectionClick('custom:covermode:parts'));
    sectionCheck($store->get('cover.parts')==='legacy'&&$bot->ui->coverCaption('VIDEO','1990')==='FULL VIDEO 1990','a rejected mode preview keeps the working caption active');
    $store->set('text.cover_title','<b>{title}</b>');$api->rejectIcons=false;
    $values=['title'=>'VIDEO','quality'=>'720p','format'=>'MP3','duration'=>'05:32','url'=>'https://youtu.be/ybNMMK5Q318'];
    $bot->handle(sectionClick('custom:section:video'));
    foreach(['video_caption','video_title','video_quality','video_duration'] as $key) sectionCheck(in_array('custom:item:text:'.$key,sectionActions($api),true),'video screen contains editable '.$key);
    sectionCheck(in_array('custom:item:button:source_video',sectionActions($api),true),'video caption and its source button are together');
    $store->set('text.video_caption','<b>OLD {title}</b>');
    sectionCheck($bot->ui->mediaCaption('video',$values)==='<b>OLD VIDEO</b>','existing customized video caption stays active');
    $bot->handle(sectionClick('custom:edit:text:video_quality'));$bot->handle(sectionInput('رزولوشن {quality}'));
    sectionCheck(str_contains($bot->ui->mediaCaption('video',$values),'رزولوشن 720p')&&$store->get('text.video_caption')==='<b>OLD {title}</b>','video caption parts can be edited without losing the complete caption');
    $bot->handle(sectionClick('custom:mediamode:video:legacy'));
    sectionCheck($bot->ui->mediaCaption('video',$values)==='<b>OLD VIDEO</b>','old video caption can be reactivated');
    $bot->handle(sectionClick('custom:mediamode:video:parts'));
    sectionCheck(str_contains($bot->ui->mediaCaption('video',$values),'رزولوشن 720p'),'video parts can be reactivated without retyping');
    $bot->handle(sectionClick('custom:edit:text:video_title'));$bot->handle(sectionInput('{title} {title} {title} {title} {title} {title}'));
    sectionCheck($store->get('text.video_title')===''&&str_contains(end($api->calls)[1]['text'],'طولانی'),'overlong assembled video caption is rejected');
    $bot->handle(sectionClick('custom:section:audio'));
    foreach(['audio_caption','audio_title','audio_format','audio_duration'] as $key) sectionCheck(in_array('custom:item:text:'.$key,sectionActions($api),true),'audio screen contains editable '.$key);
    sectionCheck(in_array('custom:item:button:source_audio',sectionActions($api),true),'audio caption and its source button are together');
    $store->set('button.source_video','SOURCE VIDEO'); $store->set('button.style.source_video','success');
    sectionCheck($bot->ui->button('source_audio')['text']==='SOURCE VIDEO'&&$bot->ui->button('source_audio')['style']==='success','audio source button inherits the existing source settings');
    $bot->handle(sectionClick('custom:edit:button:source_video'));$bot->handle(sectionInput('SOURCE VIDEO CHANGED'));
    $bot->handle(sectionClick('custom:setcolor:source_video:danger'));
    sectionCheck($bot->ui->button('source_audio')['text']==='SOURCE VIDEO'&&$bot->ui->button('source_audio')['style']==='success','changing video source attributes keeps the previous audio appearance');
    $bot->handle(sectionClick('custom:setcolor:source_video:success'));
    $bot->handle(sectionClick('custom:edit:button:source_audio'));$bot->handle(sectionInput('SOURCE AUDIO'));
    $bot->handle(sectionClick('custom:setcolor:source_audio:danger'));
    sectionCheck($bot->ui->button('source_audio')['text']==='SOURCE AUDIO'&&$bot->ui->button('source_audio')['style']==='danger'&&$bot->ui->button('source_video')['style']==='success','audio and video source buttons have independent labels and colors');
    $bot->handle(sectionClick('custom:reset:button:source_audio'));
    sectionCheck($bot->ui->button('source_audio')===$bot->ui->button('source_video'),'reset restores inherited source button settings');
    $bot->handle(sectionClick('custom:edit:text:audio_duration'));$bot->handle(sectionInput('زمان {duration}'));
    sectionCheck(str_contains($bot->ui->mediaCaption('audio',$values),'زمان 05:32')&&str_contains($bot->ui->mediaCaption('video',$values),'رزولوشن 720p'),'audio and video caption components are independent');
    $oldOrder=$bot->ui->coverOrder();$api->rejectIcons=true;
    $bot->handle(sectionClick('custom:reorder'));
    sectionCheck(in_array('custom:move:audio:up',sectionActions($api),true),'ordering controls remain available if an old emoji is rejected');
    $bot->handle(sectionClick('custom:move:audio:up'));
    sectionCheck($bot->ui->coverOrder()===$oldOrder&&$store->get('cover.order')==='','a rejected preview cannot save a reordered layout');
    $api->rejectIcons=false;
    $bot->handle(sectionClick('custom:move:audio:up'));$bot->handle(sectionClick('custom:move:audio:up'));
    sectionCheck($bot->ui->coverOrder()===['360','480','audio','720','1080'],'individual buttons can move independently');
    sectionCheck((new Zero\Ui(new Zero\Store($temp)))->coverOrder()===$bot->ui->coverOrder(),'button order persists after restart');
    $bot->handle(sectionInput('https://youtu.be/ybNMMK5Q318',7));
    $job=$store->query('SELECT * FROM jobs WHERE user_id=7')->fetch();
    $bot->qualityMenu($job,['title'=>'VIDEO','options'=>[360=>[['id'=>'18']],720=>[['id'=>'136']],'audio'=>[['id'=>'140']]]]);
    $rows=end($api->calls)[1]['reply_markup']['inline_keyboard'];
    $actions=[];foreach($rows as $row)foreach($row as $button)$actions[]=$button['callback_data'];
    sectionCheck($actions===['quality:'.$job['id'].':360','quality:'.$job['id'].':audio','quality:'.$job['id'].':720'],'real quality menu respects saved order and omits unavailable qualities');
    sectionCheck(count($rows[1])===1&&str_contains($rows[1][0]['callback_data'],':audio'),'audio retains its own row after moving');
    $previous=$store->get('cover.order');$bot->handle(sectionClick('custom:move:720:up',7));
    sectionCheck($store->get('cover.order')===$previous,'ordinary users cannot change the layout');
    $bot->handle(sectionClick('custom:orderreset'));
    sectionCheck($bot->ui->coverOrder()===['360','480','720','1080','audio']&&$store->get('cover.order')==='','layout can be reset without resetting button names or colors');
    $bot->handle(sectionClick('custom:section:admin'));
    sectionCheck(!in_array('custom:item:text:health',sectionActions($api),true),'forged sections cannot expose administrator settings');
} finally { Zero\Download::clean($temp); }
