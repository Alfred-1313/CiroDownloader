<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function serviceCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class ServiceEditorApi implements Zero\Api {
    public array $calls=[]; public bool $reject=false; private int $next=900;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($this->reject&&isset($params['reply_markup'])) { $this->reject=false; throw new RuntimeException('Telegram rejected preview',400); }
        return str_starts_with($method,'send')?['message_id'=>++$this->next]:true;
    }
    public function last(): array {
        $calls=array_values(array_filter($this->calls,fn($call)=>in_array($call[0],['sendMessage','editMessageText'],true)));
        return end($calls)[1]??[];
    }
    public function buttons(): array { return array_merge(...($this->last()['reply_markup']['inline_keyboard']??[[]])); }
    public function find(string $data): ?array { foreach($this->buttons() as $button) if(($button['callback_data']??'')===$data) return $button; return null; }
}
$root=sys_get_temp_dir().'/ciro-service-custom-'.bin2hex(random_bytes(5));
$config=new Zero\Config('123:fixture',[42],$root); $store=new Zero\Store($root); $api=new ServiceEditorApi(); $bot=new Zero\Bot($config,$store,$api); $serial=0;
$click=function(string $data,int $message=500,int $user=42) use($bot,&$serial): void {
    $bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'s'.$serial,'from'=>['id'=>$user,'is_bot'=>false],
        'message'=>['message_id'=>$message,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]);
};
$input=function(string $text,array $entities=[]) use($bot,&$serial): void {
    $bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>++$serial,'from'=>['id'=>42,'is_bot'=>false],
        'chat'=>['id'=>42,'type'=>'private'],'text'=>$text,'entities'=>$entities]]);
};
try {
    $click('custom:home');
    foreach(['youtube',...Zero\galleryServices(),...Zero\musicServices()] as $service) serviceCheck($api->find('custom:service:'.$service)!==null,'one downloader entry opens '.$service);
    foreach(['adult','music','general'] as $category) serviceCheck($api->find('custom:category:'.$category)!==null,'the main screen has the separate '.$category.' category');
    serviceCheck(count($api->last()['reply_markup']['inline_keyboard'])<=16,'the main Personalization screen remains short');
    foreach($api->buttons() as $button) serviceCheck(str_starts_with($button['callback_data'],'custom:hint:')?empty($button['style']):($button['style']??'')==='primary','white explaining titles and blue controls retain the existing UI rule');
    $click('custom:category:adult');
    foreach(Zero\adultServices() as $service) serviceCheck($api->find('custom:service:'.$service)!==null,'adult services have an independent entry: '.$service);
    foreach(Zero\UiRegistry::services() as $service) {
        $click('custom:service:'.$service);
        serviceCheck($api->find('custom:items:'.$service.':caption:0')!==null&&$api->find('custom:settings:'.$service)!==null&&$api->find('custom:layouts:'.$service)!==null,'caption, settings and layout belong to '.$service);
        if(in_array($service,['youtube',...Zero\adultServices()],true)) {
            serviceCheck($api->find('custom:coverhub:'.$service)!==null,'the quality cover has its own entry: '.$service);
            $click('custom:coverhub:'.$service);
            serviceCheck($api->find('custom:items:'.$service.':cover:0')!==null&&$api->find('custom:items:'.$service.':quality:0')!==null,'cover caption and quality/audio buttons are explicitly editable: '.$service);
            $click('custom:items:'.$service.':quality:0');
            serviceCheck($api->find('custom:item:button:'.$service.'__extract_audio')!==null,'audio extraction has its own button editor: '.$service);
        }
    }
    foreach(['youtube',...Zero\adultServices()] as $service) foreach(Zero\UiRegistry::shared($service,'button') as $key) if(in_array($key,Zero\UiRegistry::QUALITY_BUTTONS,true)) serviceCheck(!isset($bot->ui->forService($service)->button($key)['style']),'every quality/audio control defaults white: '.$service.'.'.$key);
    // Existing appearance is retained as a fallback. Quality colours now default white even after legacy green.
    $store->set('button.quality_360','LEGACY {quality}'); $store->set('button.style.quality_360','success');
    $store->set('button.icon.quality_360','5368324170671202286');
    foreach(['youtube',...Zero\adultServices()] as $service) {
        $sample=$bot->ui->forService($service)->button('quality_360','test',['quality'=>'360p']);
        serviceCheck($sample['text']==='LEGACY 360p'&&!isset($sample['style'])&&isset($sample['icon_custom_emoji_id']),'quality controls default white while retaining the old label/icon: '.$service);
    }
    $key='youtube__quality_360'; $click('custom:items:youtube:quality:0'); $click('custom:item:button:'.$key);
    foreach(['edit:button:','emoji:','icon:','plain:','pos:','color:','hide:','reset:button:'] as $op) serviceCheck($api->find('custom:'.$op.$key)!==null,'button control is reachable: '.$op);
    $click('custom:color:'.$key); $click('custom:setcolor:'.$key.':success');
    serviceCheck(($bot->ui->forService('youtube')->button('quality_360','x')['style']??'')==='success'&&!isset($bot->ui->forService('beeg')->button('quality_360','x')['style']),'changing YouTube colour never changes Beeg');
    $preview=$api->buttons()[0]; serviceCheck(($preview['style']??'')==='success'&&!isset($preview['_preview']),'the customer preview actually displays the selected colour');
    $click('custom:edit:button:'.$key); $input('انتخاب {quality}');
    serviceCheck($bot->ui->forService('youtube')->button('quality_360','x',['quality'=>'360p'])['text']==='انتخاب 360p'&&$bot->ui->forService('beeg')->button('quality_360','x',['quality'=>'360p'])['text']==='LEGACY 360p','a button name is editable for just one downloader');
    $click('custom:plain:'.$key); $plain=$bot->ui->forService('youtube')->button('quality_360','x',['quality'=>'360p']);
    serviceCheck(!isset($plain['icon_custom_emoji_id'])&&$plain['text']==='انتخاب 360p','no-emoji mode suppresses the premium icon too');
    $click('custom:icon:'.$key); $input('543210987654321');
    serviceCheck(($bot->ui->forService('youtube')->button('quality_360','x')['icon_custom_emoji_id']??'')==='543210987654321','selecting a premium emoji turns off no-emoji mode');
    $click('custom:emoji:'.$key); $input('🔥');
    $button=$bot->ui->forService('youtube')->button('quality_360','x',['quality'=>'360p']);
    serviceCheck(str_starts_with($button['text'],'🔥')&&!isset($button['icon_custom_emoji_id']),'an ordinary emoji replaces a premium icon');
    $click('custom:pos:'.$key); serviceCheck(str_ends_with($bot->ui->forService('youtube')->button('quality_360','x',['quality'=>'360p'])['text'],'🔥'),'the emoji can move to the other end');
    $old=$store->get('button.icon.'.$key); $click('custom:icon:'.$key); $api->reject=true; $input('543210987654322');
    serviceCheck($store->get('button.icon.'.$key)===$old&&(bool)$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'a rejected Telegram preview preserves both saved appearance and pending input');
    $click('custom:item:button:'.$key); $click('custom:reset:button:'.$key);
    serviceCheck(!isset($bot->ui->forService('youtube')->button('quality_360','x')['style'])&&($bot->ui->forService('beeg')->button('quality_360','x',['quality'=>'360p'])['text']??'')==='LEGACY 360p','resetting one scoped button leaves other services alone');
    // Rich caption edits, plus the existing video caption's audio fallback, become independent on write.
    $beforeAudio=$bot->ui->template('beeg_audio_caption'); $click('custom:items:beeg:caption:0'); $click('custom:edit:text:beeg_caption');
    $input('CUSTOM {title}',['0'=>['type'=>'bold','offset'=>0,'length'=>6]]);
    serviceCheck($store->get('text.beeg_caption')==='<b>CUSTOM</b> {title}'&&$bot->ui->template('beeg_audio_caption')===$beforeAudio,'editing an adult video caption preserves native formatting and the previous audio caption');
    $click('custom:edit:text:beeg_audio_caption'); $input('AUDIO {title}');
    $worker=new Zero\Worker($config,$store,$api); $caption=new ReflectionMethod($worker,'adultCaption');
    $media=['title'=>'Neutral','duration'=>10,'quality'=>'480p'];
    serviceCheck(str_contains($caption->invoke($worker,$bot,$media,[],false,10,'beeg'),'CUSTOM')&&str_contains($caption->invoke($worker,$bot,$media,[],true,10,'beeg'),'AUDIO'),'the real worker renders separate adult video/audio captions');
    $click('custom:edit:text:youtube__video_caption'); $input('YOUTUBE {title}');
    serviceCheck(str_starts_with($bot->ui->forService('youtube')->mediaCaption('video',['title'=>'Neutral']),'YOUTUBE')&&$store->get('text.beeg_caption')==='<b>CUSTOM</b> {title}','YouTube caption mode and content are independent of adult captions');
    // Per-message navigation keeps a long service catalog on its own page even after another menu opens.
    $click('custom:items:beeg:text:1',500); $item=array_values(array_filter($api->buttons(),fn($b)=>str_starts_with($b['callback_data']??'','custom:item:')))[0];
    $click('custom:service:twitter',600); $click($item['callback_data'],500);
    serviceCheck($api->find('custom:items:beeg:text:1')!==null,'Back retains the original service/page across independent menu messages');
    $click('custom:settings:beeg',500); $click('custom:adultbeeg',500);
    serviceCheck($api->find('custom:service:beeg')!==null,'a service toggle redraws its own settings screen');
    $click('custom:adultpage',500); $click('custom:adultdelg',500);
    serviceCheck($api->find('custom:settings:beeg')!==null,'shared adult filters return to the service that opened them');
    // Layout changes preserve callback data/URLs and required navigation, and apply to just this service.
    $click('custom:layout:instagram:slides',500); $click('custom:layout:instagram:slides:pick:next',500); $click('custom:layout:instagram:slides:move:next:left',500);
    $slots=['prev'=>[$bot->ui->button('instagram_prev','igpage:5:1:7')],'next'=>[$bot->ui->button('instagram_next','igpage:5:3:7')],
        'source'=>[$bot->ui->button('source_instagram')+['url'=>'https://www.instagram.com/p/NEUTRAL/']]];
    $rows=$bot->ui->layout('instagram','slides',$slots,[['prev','next'],['source']]);
    serviceCheck($rows[0][0]['callback_data']==='igpage:5:3:7'&&$rows[0][1]['callback_data']==='igpage:5:1:7'&&$rows[1][0]['url']===$slots['source'][0]['url'],'the actual gallery layout preserves owner/revision callbacks and source URL while swapping buttons');
    serviceCheck($bot->ui->layoutRows('twitter','slides')===[['prev','next'],['source']],'Instagram layout edits do not affect X');
    serviceCheck($bot->ui->gallerySourceRows('instagram','https://www.instagram.com/p/NEUTRAL/')===[],'layout editing cannot enable a disabled source button');
    $oldLayout=$store->get('layout.instagram.slides'); $api->reject=true; $click('custom:layout:instagram:slides:cols:1');
    serviceCheck($store->get('layout.instagram.slides')===$oldLayout,'a rejected layout preview never replaces the saved layout');
    $store->set('layout.instagram.slides','[["alien","next","next"],["prev"]]');
    serviceCheck($bot->ui->layoutRows('instagram','slides')===[['next'],['prev'],['source']],'invalid/duplicate layout slots are discarded and missing controls are appended');
    // Render the production quality menus, including the adult native-resolution slot.
    $store->set('text.beeg__cover_instruction','BEEG COVER'); $store->set('service.beeg.cover.parts','parts');
    $store->set('button.beeg__quality_480','BEEG {quality}'); $store->set('button.style.beeg__quality_480','primary');
    $store->set('layout.beeg.quality','[["audio"],["480","240","360","720","1080"]]');
    foreach(['beeg'=>'https://beeg.com/-0123456789012345','eporner'=>'https://www.eporner.com/video-HmstDuT4BNE/'] as $service=>$url) {
        $store->query('INSERT OR IGNORE INTO users(id) VALUES(42)');
        $store->query('INSERT INTO jobs(update_id,user_id,chat_id,url,created,message_id,phase) VALUES(?,?,?,?,?,?,?)',[++$serial,42,42,$url,time(),700,'inspect']);
        $job=$store->query('SELECT * FROM jobs WHERE id=?',[(int)$store->db->lastInsertId()])->fetch();
        $options=$service==='beeg'?[240=>[['id'=>'240']],480=>[['id'=>'480']],'audio'=>[['id'=>'sound']]]:[1440=>[['id'=>'1440']],2160=>[['id'=>'2160']],'audio'=>[['id'=>'sound']]];
        if($service==='eporner') $store->set('layout.eporner.quality','[["native"],["audio"],["240","360","480","720","1080"]]');
        $bot->qualityMenu($job,['title'=>'Neutral','options'=>$options]);
        $keyboard=$api->last()['reply_markup']['inline_keyboard'];
        if($service==='beeg') serviceCheck(str_contains($api->last()['text'],'BEEG COVER')&&$keyboard[0][0]['callback_data']==='quality:'.$job['id'].':audio'&&$keyboard[1][0]['text']==='BEEG 480p'&&$keyboard[1][0]['style']==='primary','the real adult quality menu uses its own cover, label, colour and saved rows');
        else serviceCheck(array_column($keyboard[0],'callback_data')===['quality:'.$job['id'].':1440','quality:'.$job['id'].':2160']&&$keyboard[1][0]['callback_data']==='quality:'.$job['id'].':audio','native adult resolutions retain their exact choice callbacks in a custom layout');
    }
    $store->query("UPDATE jobs SET status='done' WHERE id=?",[$job['id']]);
    $store->set('text.eporner__quality_expired','EPORNER EXPIRED'); $click('quality:'.$job['id'].':audio',700);
    serviceCheck(end($api->calls)[1]['text']==='EPORNER EXPIRED','quality callback notices resolve the owning service without changing ownership checks');
    serviceCheck(!$bot->ui->definition('text','beeg__error_format')&&$bot->ui->customizable('text','beeg_error_format'),'adult errors have one active service-specific editor rather than an unused duplicate');
    // Old saved edit sessions still work, but now target the independently scoped YouTube entry.
    $store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[42,'ui:'.json_encode(['kind'=>'text','key'=>'video_caption','message'=>500]),time()+600]);
    $input('LEGACY EDIT {title}');
    serviceCheck($store->get('text.youtube__video_caption')==='LEGACY EDIT {title}'&&$store->get('text.video_caption')==='','legacy input sessions migrate without overwriting shared historical defaults');
    $before=$store->get('button.style.'.$key); $click('custom:setcolor:'.$key.':danger',500,7);
    serviceCheck($store->get('button.style.'.$key)===$before,'ordinary users cannot mutate scoped button styles');
    foreach($api->calls as [$method,$params]) foreach($params['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button)
        serviceCheck(!isset($button['_preview'])&&strlen($button['callback_data']??'')<=64,'all emitted editor buttons fit Telegram limits and contain no internal preview flags');
} finally { Zero\Download::clean($root); }
