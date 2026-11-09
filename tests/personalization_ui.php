<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class UiApi implements Zero\Api {
    public array $calls=[]; public bool $failDelete=false;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='deleteMessage'&&$this->failDelete) throw new RuntimeException('Cannot delete',400);
        return $method==='sendMessage'?['message_id'=>900]:true;
    }
}
function uiCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function uiClick(string $data,int $user=42): array { return ['callback_query'=>['id'=>'ui','from'=>['id'=>$user],'message'=>['message_id'=>900,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]; }
function uiInput(string $text,int $user=42): array { return ['update_id'=>random_int(1,1000000),'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]; }
function uiRows(UiApi $api): array { return end($api->calls)[1]['reply_markup']['inline_keyboard']??[]; }
function uiButtons(UiApi $api): array { return array_merge(...uiRows($api)); }
function uiFind(UiApi $api,string $action): ?array { foreach(uiButtons($api) as $button) if(($button['callback_data']??'')===$action) return $button; return null; }
function uiText(UiApi $api): string { return (string)(end($api->calls)[1]['text']??''); }
$temp=sys_get_temp_dir().'/ciro-ui-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $store=new Zero\Store($temp); $api=new UiApi(); $bot=new Zero\Bot(new Zero\Config('123:fake',[42],$temp),$store,$api);

    // Main screen: white titles, one entry per screen, legend, reset and close.
    $bot->handle(uiInput('/customize'));
    $titles=array_values(array_filter(uiButtons($api),fn($b)=>str_starts_with($b['callback_data']??'','custom:hint:')));
    uiCheck(count($titles)===6&&!array_filter($titles,fn($b)=>isset($b['style'])),'the main screen has six white title buttons');
    foreach(['cover','video','audio','instagram','pinterest','tiktok','twitter','pornhub','spotify','soundcloud','menu','batch','help','group','access','progress','errors'] as $section) uiCheck(uiFind($api,'custom:section:'.$section)!==null,'one entry opens each screen: '.$section);
    uiCheck(uiFind($api,'custom:features')!==null&&uiFind($api,'custom:resetall')!==null&&uiFind($api,'custom:close')!==null,'the main screen offers features, reset-all and close');
    uiCheck(str_contains(uiText($api),'🔵')&&str_contains(uiText($api),'⬜️')&&str_contains(uiText($api),'✏️')&&str_contains(uiText($api),'🔘')&&!str_contains(uiText($api),'🟢'),'a legend explains white titles, blue buttons and the markers');
    uiCheck(!uiFind($api,'custom:groups:text')&&!uiFind($api,'custom:groups:button')&&!uiFind($api,'custom:sections'),'the old parallel text, caption and button trees are gone from the main screen');
    uiCheck(!array_filter(uiButtons($api),fn($b)=>in_array($b['style']??'',['success','danger'],true))&&count(array_filter(uiButtons($api),fn($b)=>($b['style']??'')==='primary'&&!str_starts_with($b['callback_data']??'','custom:hint:')))===count(array_filter(uiButtons($api),fn($b)=>!str_starts_with($b['callback_data']??'','custom:hint:'))),'one colour rule: titles are white and every other button is blue; no green or red'); 

    // Titles explain themselves and never cancel pending input.
    foreach(['home_youtube','home_social','home_music','home_general','home_status','home_features','sec_texts','sec_buttons','sec_settings','feat_group','feat_access','feat_reaction','feat_limits'] as $id) {
        $bot->handle(uiClick('custom:hint:'.$id)); $answer=end($api->calls);
        uiCheck($answer[0]==='answerCallbackQuery'&&$answer[1]['show_alert']===true&&$answer[1]['text']!==''&&mb_strlen($answer[1]['text'])<=200,'a title shows a short alert: '.$id);
    }
    $bot->handle(uiClick('custom:hint:unknown')); uiCheck(end($api->calls)[1]['text']===''&&end($api->calls)[1]['show_alert']===false,'an unknown title shows nothing');
    $bot->handle(uiClick('custom:edit:text:welcome')); $pending=$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn();
    $bot->handle(uiClick('custom:hint:home_youtube'));
    uiCheck($pending!==false&&$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()===$pending,'tapping a title keeps a pending text edit');
    $bot->handle(uiClick('custom:hint:home_youtube',7));
    uiCheck(end($api->calls)[1]['text']===$bot->ui->plain('denied')&&end($api->calls)[1]['show_alert']===true,'an ordinary user is refused by the title buttons');

    // Item screen: state, default text, markers.
    $bot->handle(uiClick('custom:item:text:welcome'));
    uiCheck(str_contains(uiText($api),'پیش‌فرض')&&uiFind($api,'custom:default:text:welcome')!==null&&uiFind($api,'custom:edit:text:welcome')!==null&&uiFind($api,'custom:reset:text:welcome')!==null&&uiFind($api,'custom:close')!==null,'a text shows its state and offers default, edit, reset and close');
    $bot->handle(uiClick('custom:edit:text:welcome')); $bot->handle(uiInput('<b>سلام تازه</b>'));
    uiCheck($store->get('text.welcome')==='<b>سلام تازه</b>'&&str_contains(uiText($api),'سفارشی'),'saving a text redraws the same screen as customized');
    $bot->handle(uiClick('custom:default:text:welcome'));
    uiCheck(str_contains(uiText($api),strip_tags(Zero\Ui::definitions()['texts']['welcome']['value'])===''?'':mb_substr(trim(strip_tags(Zero\Ui::definitions()['texts']['welcome']['value'])),0,12))&&$store->get('text.welcome')==='<b>سلام تازه</b>','the default view shows the original text without changing the saved one');
    $bot->handle(uiClick('custom:section:help'));
    $mark=uiFind($api,'custom:item:text:welcome');
    uiCheck(($mark['style']??'')==='primary'&&str_starts_with($mark['text'],'✏️'),'a changed text is marked with ✏️ in its list and is blue like every selectable button');
    $bot->handle(uiClick('custom:home'));
    uiCheck((uiFind($api,'custom:section:help')['style']??'')==='primary'&&(uiFind($api,'custom:section:cover')['style']??'')==='primary','every screen entry is blue, changed or not');

    // A saved text that loses a placeholder is kept, with a warning.
    $bot->handle(uiClick('custom:edit:text:video_title')); $bot->handle(uiInput('عنوان بدون متغیر'));
    uiCheck($store->get('text.video_title')==='عنوان بدون متغیر'&&str_contains(uiText($api),'{title}'),'a missing placeholder is saved but reported');
    $bot->handle(uiClick('custom:edit:text:video_title')); $bot->handle(uiInput('عنوان {title}'));
    uiCheck(!str_contains(uiText($api),'دیگر در متن نیستند'),'no warning appears when every placeholder is present');

    // Button editor shows its state and the colour on the colour button.
    $bot->handle(uiClick('custom:item:button:extract_audio'));
    uiCheck(uiFind($api,'custom:cyclecolor:extract_audio')!==null&&uiFind($api,'custom:icon:extract_audio')!==null&&uiFind($api,'custom:clearicon:extract_audio')!==null&&str_contains(uiText($api),'🎨 رنگ'),'a button editor shows colour and premium emoji state');
    $bot->handle(uiClick('custom:setcolor:extract_audio:danger'));
    uiCheck(str_contains(uiText($api),$bot->ui->label('color_danger'))&&str_contains(uiFind($api,'custom:cyclecolor:extract_audio')['text'],$bot->ui->label('color_danger')),'the colour button names the current colour after a change');

    // Features page: one-tap switches with green and red.
    $bot->handle(uiClick('custom:features'));
    uiCheck($store->groupsEnabled()&&str_contains(uiFind($api,'custom:groups_mode')['text'],'✅')&&str_contains(uiFind($api,'custom:access')['text'],'❌')&&(uiFind($api,'custom:groups_mode')['style']??'')==='primary'&&(uiFind($api,'custom:access')['style']??'')==='primary','the features page reflects the stored switches in their text (✅ / ❌), all blue');
    $bot->handle(uiClick('custom:groups_mode'));
    uiCheck(!$store->groupsEnabled()&&str_contains(uiFind($api,'custom:groups_mode')['text'],'❌'),'a switch flips at once and redraws the same page');
    $bot->handle(uiClick('custom:access'));
    uiCheck($store->get('admins_only')==='1'&&str_contains(uiFind($api,'custom:access')['text'],'✅')&&str_contains(uiText($api),'قابلیت'),'the access switch flips at once and redraws the same page');
    $bot->handle(uiClick('custom:access',7)); $bot->handle(uiClick('custom:groups_mode',7));
    uiCheck($store->get('admins_only')==='1'&&!$store->groupsEnabled(),'ordinary users cannot flip switches');
    $bot->handle(uiClick('custom:groups_mode')); $bot->handle(uiClick('custom:access'));

    // Paging keeps long screens short and Back returns to the right page.
    $bot->handle(uiClick('custom:section:instagram'));
    uiCheck(uiFind($api,'custom:section:instagram:1')!==null&&uiFind($api,'custom:igmode:pages')!==null&&uiFind($api,'custom:hint:sec_settings')!==null,'a long screen starts with its switches and a Next link');
    $bot->handle(uiClick('custom:section:instagram:1'));
    $second=array_values(array_filter(array_map(fn($b)=>$b['callback_data']??'',uiButtons($api)),fn($a)=>str_starts_with($a,'custom:item:')));
    uiCheck(uiFind($api,'custom:igmode:pages')===null&&uiFind($api,'custom:section:instagram')!==null&&$second!==[],'later pages hold only items and a Previous link');
    $bot->handle(uiClick($second[0])); $back=array_values(array_filter(array_column(uiButtons($api),'callback_data'),fn($a)=>str_starts_with((string)$a,'custom:section:instagram')));
    uiCheck($back==['custom:section:instagram:1'],'Back from an item returns to the page it was opened from');
    $bot->handle(uiClick('custom:igmode:article'));
    uiCheck(str_starts_with(uiFind($api,'custom:igmode:article')['text'],'✅')&&!str_starts_with(uiFind($api,'custom:igmode:pages')['text'],'✅'),'the chosen output mode is ticked');

    // Reset all: confirmation first, then only user customisations return to default.
    $store->set('button.icon.help','5368324170671202286'); $store->set('igsource_probe','keep');
    $store->set('instagram.source_button','1'); $store->set('cover.parts','legacy');
    $bot->handle(uiClick('custom:resetall'));
    uiCheck(uiFind($api,'custom:resetall:yes')!==null&&$store->get('text.welcome')!=='','reset-all asks for confirmation and changes nothing yet');
    $bot->handle(uiClick('custom:home'));
    uiCheck($store->get('text.welcome')==='<b>سلام تازه</b>','cancelling the confirmation keeps every customisation');
    $bot->handle(uiClick('custom:resetall:yes',7));
    uiCheck($store->get('text.welcome')==='<b>سلام تازه</b>','an ordinary user cannot reset everything');
    $bot->handle(uiClick('custom:resetall')); $bot->handle(uiClick('custom:resetall:yes'));
    $left=$store->query("SELECT key FROM settings WHERE key LIKE 'text.%' OR key LIKE 'button.%' OR key IN ('cover.parts','cover.order')")->fetchAll(PDO::FETCH_COLUMN);
    $custom=array_filter($left,fn($key)=>!str_starts_with($key,'button.aliases.'));
    uiCheck($custom===[]&&str_contains(uiText($api),'به پیش‌فرض برگشت')&&str_contains(uiText($api),'🎨'),'reset-all removes texts, button labels, colours and icons and returns to the main screen');
    uiCheck($store->get('instagram.source_button')==='1'&&$store->get('admins_only')==='0'&&$store->get('igsource_probe')==='keep','switches and unrelated settings survive reset-all');

    // Close removes the message; a failed deletion falls back to the main screen.
    $bot->handle(uiClick('custom:close')); $last=end($api->calls);
    uiCheck($last[0]==='deleteMessage'&&$last[1]['message_id']===900,'close deletes the personalization message');
    $api->failDelete=true; $bot->handle(uiClick('custom:close')); $api->failDelete=false;
    uiCheck(end($api->calls)[0]==='sendMessage'||end($api->calls)[0]==='editMessageText','a message that cannot be deleted is replaced by the main screen');
    $before=count($api->calls); $bot->handle(uiClick('custom:close',7));
    uiCheck(!array_filter(array_slice($api->calls,$before),fn($c)=>$c[0]==='deleteMessage'),'an ordinary user cannot close the administrator screen');
} finally { Zero\Download::clean($temp); }
