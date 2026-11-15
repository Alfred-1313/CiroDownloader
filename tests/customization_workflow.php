<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function workflowCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class WorkflowApi implements Zero\Api
{
    public array $calls=[];
    public bool $reject=false;
    public function call(string $method,array $params=[]): mixed
    {
        $this->calls[]=[$method,$params];
        if(in_array($method,['sendMessage','editMessageText'],true)) {
            if($this->reject) { $this->reject=false; throw new RuntimeException('fixture rejected screen',400); }
            Zero\TextFormat::validate($params['text'],4096);
            foreach($params['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button)
                if(strlen($button['callback_data']??'')>64) throw new RuntimeException('callback exceeds Telegram limit');
        }
        return $method==='sendMessage'?['message_id'=>500]:true;
    }
    public function last(): array
    {
        $calls=array_values(array_filter($this->calls,fn($c)=>in_array($c[0],['sendMessage','editMessageText'],true)));
        return end($calls)[1]??[];
    }
    public function find(string $data): ?array
    {
        foreach($this->last()['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button)
            if(($button['callback_data']??'')===$data) return $button;
        return null;
    }
}
$root=sys_get_temp_dir().'/ciro-custom-workflow-'.bin2hex(random_bytes(5));
$store=new Zero\Store($root); $api=new WorkflowApi(); $bot=new Zero\Bot(new Zero\Config('123:fixture',[42],$root),$store,$api); $serial=0;
$click=function(string $action,int $message=500,int $user=42,int $chat=42) use($bot,&$serial): void {
    $bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'wf'.$serial,'from'=>['id'=>$user],
        'message'=>['message_id'=>$message,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']],'data'=>$action]]);
};
$input=function(string $text,array $entities=[]) use($bot,&$serial): void {
    $bot->handle(['update_id'=>++$serial,'message'=>['message_id'=>++$serial,'from'=>['id'=>42],
        'chat'=>['id'=>42,'type'=>'private'],'text'=>$text,'entities'=>$entities]]);
};
$state=fn()=>$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn();
try {
    $click('custom:home');
    workflowCheck(!str_starts_with($api->find('custom:service:instagram')['text'],'✏️'),'fresh downloader has no changed marker');
    $original='<b>قالب فعلی 🙂</b> {description} <blockquote expandable>نکته</blockquote>';
    $store->set('text.instagram_caption',$original);
    $click('custom:items:instagram:caption:0'); $click('custom:edit:text:instagram_caption');
    workflowCheck(str_contains($api->last()['text'],'<pre>'.Zero\Ui::escape($original).'</pre>'),'editing starts with exact copyable current HTML, not rendered substitutions');
    workflowCheck($api->find('custom:source:instagram_caption:0')!==null,'full source is independently reachable');
    $pending=$state(); $click('custom:source:instagram_caption:0');
    workflowCheck($state()===$pending&&str_contains($api->last()['text'],Zero\Ui::escape($original)),'read-only source preserves pending input and working caption');
    $input('<b>خراب');
    workflowCheck($store->get('text.instagram_caption')===$original&&(bool)$state()&&str_contains($api->last()['text'],'تگ‌ها بسته نشده'),'unclosed HTML explains the error and preserves both old text and pending input');
    $input('<b><i>خراب</b></i>');
    workflowCheck(str_contains($api->last()['text'],'مطابقت ندارد')&&$store->get('text.instagram_caption')===$original,'mismatched nesting has its own safe explanation');
    $input('<b data-no="x">bad</b>');
    workflowCheck(str_contains($api->last()['text'],'پشتیبانی نمی‌شود'),'unsupported HTML reports a supported-format hint');
    $input('<b>نادرست & غلط</b>');
    workflowCheck(str_contains($api->last()['text'],'&amp;lt;')&&$store->get('text.instagram_caption')===$original,'literal HTML operators get safe copyable escape examples');
    $input('<blockquote><blockquote>نادرست</blockquote></blockquote>');
    workflowCheck(str_contains($api->last()['text'],'نقل‌قول توی نقل‌قول')&&(bool)$state(),'nested quotes retain pending input with a specific explanation');
    $input('اول 🙂 وسط 🙂 آخر 🙂 {description}',[
        ['type'=>'custom_emoji','offset'=>4,'length'=>2,'custom_emoji_id'=>'5368324170671202286'],
        ['type'=>'custom_emoji','offset'=>11,'length'=>2,'custom_emoji_id'=>'5368324170671202287'],
        ['type'=>'custom_emoji','offset'=>18,'length'=>2,'custom_emoji_id'=>'5368324170671202288'],
    ]);
    workflowCheck(substr_count($store->get('text.instagram_caption'),'<tg-emoji')===3&&!$state(),'UTF-16 custom emoji at several positions survive the real edit flow');
    // Literal source can be much longer than its rendered caption. Reassemble every page exactly.
    $long=str_repeat('<tg-emoji emoji-id="5368324170671202286">🙂</tg-emoji>',140).'{description}';
    $store->set('text.instagram_caption',$long); $click('custom:edit:text:instagram_caption');
    workflowCheck($api->find('custom:source:instagram_caption:0')!==null,'a long source keeps a usable bounded edit prompt');
    $all=''; $page=0;
    do {
        $click('custom:source:instagram_caption:'.$page);
        preg_match('~<pre>(.*?)</pre>~su',$api->last()['text'],$match);
        $all.=html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        $next=$api->find('custom:source:instagram_caption:'.(++$page));
    } while($next!==null&&$page<10);
    workflowCheck($all===$long&&$page>1&&(bool)$state(),'paged literal source preserves every tag and Unicode character');
    $click('custom:items:instagram:caption:0');
    workflowCheck(str_starts_with($api->find('custom:item:text:instagram_caption')['text'],'✏️'),'list reflects the effective custom caption');
    $click('custom:home');
    workflowCheck(str_starts_with($api->find('custom:service:instagram')['text'],'✏️'),'changed child is visible on the downloader entry');
    $store->set('button.pos.twitter_next','end'); $click('custom:home');
    workflowCheck(str_starts_with($api->find('custom:service:twitter')['text'],'✏️'),'emoji-side-only edits are included in parent markers');
    $store->set('layout.pinterest.slides','[["next","prev"],["source"]]'); $click('custom:home');
    workflowCheck(str_starts_with($api->find('custom:service:pinterest')['text'],'✏️'),'layout-only changes reach the downloader marker');
    $click('custom:service:pinterest');
    workflowCheck(str_starts_with($api->find('custom:layouts:pinterest')['text'],'✏️'),'the service layout category aggregates its children');
    $click('custom:layouts:pinterest');
    workflowCheck(str_starts_with($api->find('custom:layout:pinterest:slides')['text'],'✏️'),'the individual layout entry reflects its current value');
    foreach($api->last()['reply_markup']['inline_keyboard'] as $row) foreach($row as $button)
        workflowCheck(str_starts_with($button['callback_data'],'custom:hint:')?empty($button['style']):($button['style']??'')==='primary','markers preserve white headers and blue management controls');
    // Reset is explicit, private, service-bound, replay-safe and isolated from product settings.
    $store->set('text.quality_prompt','LEGACY {title} {max_size}'); $store->set('button.icon.quality_360','5368324170671202286');
    $store->set('text.youtube__video_caption','CUSTOM {title}'); $store->set('layout.youtube.quality','[["audio"],["360","480","720","1080"]]');
    $preserved=['groups_enabled'=>'0','admins_only'=>'1','adult.beeg'=>'1','adult.delete_private'=>'0',
        'instagram.source_button'=>'1','instagram.carousel_mode'=>'article','text.twitter_caption'=>'KEEP {description}',
        'text.admin_panel'=>'ADMIN','cookies.mode'=>'off'];
    foreach($preserved as $key=>$value) $store->set($key,$value);
    $click('custom:resetservice:youtube');
    workflowCheck($api->find('custom:resetservice:youtube:yes')!==null&&$store->get('text.youtube__video_caption')==='CUSTOM {title}','scoped reset requires a visible confirmation before changing data');
    $click('custom:service:youtube'); $click('custom:resetservice:youtube:yes');
    workflowCheck($store->get('text.youtube__video_caption')==='CUSTOM {title}'&&str_contains($api->last()['text'],'صفحهٔ فعلی نیست'),'a cancelled or stale confirmation does not reset anything');
    $click('custom:resetservice:youtube'); $click('custom:resetservice:instagram:yes');
    workflowCheck($store->get('text.instagram_caption')===$long,'a confirmation for another service cannot authorize a reset');
    $click('custom:resetservice:youtube',501);
    $store->set('text.youtube__video_caption','EDITED IN ANOTHER MENU {title}');
    $click('custom:resetservice:youtube:yes',501);
    workflowCheck($store->get('text.youtube__video_caption')==='EDITED IN ANOTHER MENU {title}'&&str_contains($api->last()['text'],'صفحهٔ فعلی نیست'),'a pending reset cannot erase edits made in another menu after confirmation opened');
    $store->set('text.youtube__video_caption','CUSTOM {title}');
    $click('custom:resetservice:youtube'); $api->reject=true;
    try { $click('custom:resetservice:youtube:yes'); } catch(RuntimeException) {}
    workflowCheck($store->get('text.youtube__video_caption')==='CUSTOM {title}','failed reset-screen delivery rolls back the database transaction');
    $click('custom:resetservice:youtube'); $store->set('text.pinterest_caption','UNRELATED {description}'); $click('custom:resetservice:youtube:yes');
    $definitions=Zero\Ui::definitions();
    workflowCheck($bot->ui->forService('youtube')->template('quality_prompt')===$definitions['texts']['youtube__quality_prompt']['value']&&$store->get('text.quality_prompt')==='LEGACY {title} {max_size}','reset restores real factory values without changing shared legacy data');
    workflowCheck($bot->ui->forService('youtube')->mediaCaption('video',['title'=>'TITLE','quality'=>'720p','duration'=>'03:10'])!== 'CUSTOM TITLE'&&$bot->ui->forService('youtube')->captionMode('video')==='parts','reset restores caption presentation for only the selected service');
    workflowCheck(!isset($bot->ui->forService('youtube')->button('quality_360')['icon_custom_emoji_id'])&&isset($bot->ui->forService('beeg')->button('quality_360')['icon_custom_emoji_id']),'reset shields the selected service from inherited icons without changing others');
    workflowCheck($store->get('layout.youtube.quality')==='','reset removes only this downloader custom layouts');
    workflowCheck($store->get('text.pinterest_caption')==='UNRELATED {description}','an unrelated edit neither blocks the reset nor gets removed');
    foreach($preserved as $key=>$value) workflowCheck($store->get($key)===$value,'scoped reset preserves '.$key);
    $store->set('text.youtube__video_caption','AFTER RESET {title}'); $click('custom:resetservice:youtube:yes');
    workflowCheck($store->get('text.youtube__video_caption')==='AFTER RESET {title}','replayed confirmation cannot remove a later edit');
    foreach(['custom:source:instagram_caption:0','custom:resetservice:instagram','custom:resetservice:instagram:yes'] as $action) {
        $before=$store->get('text.instagram_caption'); $click($action,700,7,7);
        workflowCheck($store->get('text.instagram_caption')===$before&&(end($api->calls)[1]['show_alert']??false),'ordinary users cannot access '.$action);
        $click($action,700,42,-100);
        workflowCheck($store->get('text.instagram_caption')===$before&&(end($api->calls)[1]['show_alert']??false),'new controls remain private-only: '.$action);
    }
    foreach(['current_text','reset_service','reset_service_yes','source_edit'] as $key) workflowCheck(!$bot->ui->customizable('button',$key),'new editor control remains admin-only: '.$key);
    $click('custom:source:admin_panel:0');
    workflowCheck(!str_contains($api->last()['text'],'<pre>ADMIN</pre>')&&!$state(),'forged source routes cannot expose administrator entries or leave a hidden pending editor');
} finally { Zero\Download::clean($root); }
