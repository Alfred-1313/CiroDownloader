<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require __DIR__.'/fixtures/editor_actions.php';

final class BtnApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed { $this->calls[]=[$method,$params]; return $method==='sendMessage'?['message_id'=>900]:true; }
}
function btnCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function btnClick(string $data,int $user=42): array { return ['callback_query'=>['id'=>'b','from'=>['id'=>$user],'message'=>['message_id'=>900,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]; }
function btnInput(string $text,array $entities=[],int $user=42): array { return ['update_id'=>random_int(1,1000000),'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]+($entities?['entities'=>$entities]:[])]; }
function btnButtons(BtnApi $api): array { return array_merge(...(end($api->calls)[1]['reply_markup']['inline_keyboard']??[[]])); }
function btnFind(BtnApi $api,string $action): ?array { $action=currentEditorAction($action); foreach(btnButtons($api) as $b) if(($b['callback_data']??'')===$action) return $b; return null; }
function btnText(BtnApi $api): string { return (string)(end($api->calls)[1]['text']??''); }
$temp=sys_get_temp_dir().'/ciro-buttons-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $store=new Zero\Store($temp); $api=new BtnApi(); $bot=new Zero\Bot(new Zero\Config('123:fake',[42],$temp),$store,$api); $ui=$bot->ui->forService('youtube');

    // Rendering.
    $default=$ui->button('extract_audio','x')['text'];
    btnCheck($default==='🎶 Audio','the default label keeps its emoji');
    $store->set('button.emoji.extract_audio','🔥');
    btnCheck($ui->button('extract_audio','x')['text']==='🔥 Audio','an emoji replaces the label\'s own emoji at the start');
    $store->set('button.pos.extract_audio','end');
    btnCheck($ui->button('extract_audio','x')['text']==='Audio 🔥','the emoji can sit at the end');
    $store->set('button.plain.extract_audio','1');
    btnCheck($ui->button('extract_audio','x')['text']==='Audio','plain mode removes every emoji');
    $store->set('button.extract_audio','🎵 Audio (🔥) end ⭐');
    btnCheck($ui->button('extract_audio','x')['text']==='Audio end','plain mode also removes emoji inside the name and empty punctuation');
    $store->query('DELETE FROM settings WHERE key=?',['button.extract_audio']);
    $store->set('button.plain.extract_audio','0'); $store->set('button.icon.extract_audio','5368324170671202286');
    $premium=$ui->button('extract_audio','x');
    btnCheck($premium['text']==='Audio'&&$premium['icon_custom_emoji_id']==='5368324170671202286','a premium emoji replaces the text emoji instead of doubling it');
    $store->set('button.icon.extract_audio','');
    btnCheck(!isset($ui->button('extract_audio','x')['_hidden'])&&($store->set('button.hidden.extract_audio','1')||true)&&!empty($ui->button('extract_audio','x')['_hidden']),'a hidden button is marked for removal');
    $store->set('button.hidden.help','1'); $store->set('button.hidden.tiktok_next','1'); $store->set('button.emoji.help','🔥');
    btnCheck(empty($ui->button('help')['_hidden'])&&empty($ui->button('tiktok_next','x')['_hidden'])&&$ui->button('help')['text']===$ui->label('help'),'required and reply-keyboard buttons ignore hiding and emoji placement');
    $store->set('button.hidden.help',''); $store->set('button.hidden.tiktok_next',''); $store->set('button.emoji.help','');
    $store->set('button.hidden.extract_audio','');
    foreach(['extract_audio','quality_360'] as $k) foreach(['emoji','pos','plain','icon'] as $f) $store->set('button.'.$f.'.'.$k,'');

    // Transport removes hidden buttons and empty rows.
    $markup=['inline_keyboard'=>[[['text'=>'a','_hidden'=>true],['text'=>'b']],[['text'=>'c','_hidden'=>true]],[['text'=>'d']]]];
    $clean=Zero\Telegram::withoutHidden($markup);
    btnCheck($clean['inline_keyboard']===[[['text'=>'b']],[['text'=>'d']]],'hidden buttons and rows left empty are removed before sending');
    btnCheck(Zero\Telegram::withoutHidden(['keyboard'=>[[['text'=>'x']]]])===['keyboard'=>[[['text'=>'x']]]],'a reply keyboard passes through untouched');

    // Editor screen.
    $bot->handle(btnClick('custom:section:cover')); $bot->handle(btnClick('custom:item:button:extract_audio'));
    $needed=['custom:edit:button:extract_audio','custom:emoji:extract_audio','custom:pos:extract_audio','custom:plain:extract_audio','custom:cyclecolor:extract_audio','custom:icon:extract_audio','custom:clearicon:extract_audio','custom:hide:extract_audio','custom:reset:button:extract_audio','custom:close'];
    btnCheck(!array_filter($needed,fn($a)=>btnFind($api,$a)===null),'the button editor offers name, emoji, side, no-emoji, colour, premium emoji, visibility, reset and close');
    foreach(['✏️ نام','😀 ایموجی','💎 ایموجی پریمیوم','🎨 رنگ','👁 نمایش'] as $line) btnCheck(str_contains(btnText($api),$line),'the state lines show: '.$line);

    // Emoji input.
    $bot->handle(btnClick('custom:emoji:extract_audio'));
    $bot->handle(btnInput('سلام'));
    btnCheck($ui->setting('button.emoji.extract_audio')===''&&$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn()!==false&&str_contains(btnText($api),'فقط یک ایموجی'),'text is refused and the input stays open');
    $bot->handle(btnInput('🔥🔥'));
    btnCheck($ui->setting('button.emoji.extract_audio')==='','two emoji are refused');
    $bot->handle(btnInput('🔥',[['type'=>'custom_emoji','offset'=>0,'length'=>2,'custom_emoji_id'=>'5368324170671202286']]));
    btnCheck($ui->setting('button.emoji.extract_audio')==='','a premium emoji is refused here and kept for its own control');
    $bot->handle(btnInput('🔥'));
    $audio=array_values(array_filter(array_merge(...$ui->button('extract_audio','x')?[[$ui->button('extract_audio','x')]]:[[]]),fn($b)=>true))[0];
    btnCheck($ui->setting('button.emoji.extract_audio')==='🔥'&&$audio['text']==='🔥 Audio'&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'a plain emoji is saved and applied');
    btnCheck(btnFind($api,'custom:pos:extract_audio')!==null&&str_contains(btnText($api),'🔥'),'the screen redraws with the new emoji');

    // Toggles.
    $bot->handle(btnClick('custom:pos:extract_audio'));
    btnCheck($ui->button('extract_audio','x')['text']==='Audio 🔥'&&str_contains(btnText($api),'انتها'),'the side toggle moves the emoji to the end');
    $bot->handle(btnClick('custom:plain:extract_audio'));
    btnCheck($ui->button('extract_audio','x')['text']==='Audio'&&str_contains(btnFind($api,'custom:plain:extract_audio')['text'],'روشن'),'the no-emoji toggle strips every emoji and shows its state');
    $bot->handle(btnClick('custom:plain:extract_audio'));
    btnCheck($ui->button('extract_audio','x')['text']==='Audio 🔥','turning it off brings the emoji back');
    $styles=[]; for($i=0;$i<4;$i++) { $bot->handle(btnClick('custom:cyclecolor:extract_audio')); $styles[]=$ui->button('extract_audio','x')['style']??'default'; }
    btnCheck($styles===['primary','success','danger','default'],'one tap cycles blue, green, red and back to the default');
    $bot->handle(btnClick('custom:cyclecolor:extract_audio'));
    btnCheck(str_contains(btnFind($api,'custom:cyclecolor:extract_audio')['text'],$ui->label('color_primary'))&&str_contains(btnText($api),$ui->label('color_primary')),'the colour button and the state line name the current colour after each tap');
    $bot->handle(btnClick('custom:cyclecolor:extract_audio')); $bot->handle(btnClick('custom:cyclecolor:extract_audio')); $bot->handle(btnClick('custom:cyclecolor:extract_audio'));
    $bot->handle(btnClick('custom:hide:extract_audio'));
    btnCheck(!empty($ui->button('extract_audio','x')['_hidden'])&&str_contains(btnFind($api,'custom:hide:extract_audio')['text'],'نمایان')&&str_contains(btnText($api),'پنهان'),'hiding is one tap and the screen says so');
    $sample=btnButtons($api)[0];
    btnCheck(!isset($sample['_hidden'])&&$sample['callback_data']==='custom:noop','the editor still shows a hidden button so it can be shown again');
    $bot->handle(btnClick('custom:hide:extract_audio'));
    btnCheck(empty($ui->button('extract_audio','x')['_hidden']),'showing it again is one tap');

    // Required buttons and the last quality.
    $bot->handle(btnClick('custom:item:button:tiktok_next'));
    btnCheck(btnFind($api,'custom:hide:tiktok_next')===null&&str_contains(btnText($api),'ضروری'),'a navigation button has no hide control and says it is required');
    $bot->handle(btnClick('custom:hide:tiktok_next'));
    btnCheck(str_contains(btnText($api),'پنهان نمی‌شود')&&$ui->setting('button.hidden.tiktok_next')==='','a forged hide request for a required button is refused');
    $bot->handle(btnClick('custom:item:button:help'));
    btnCheck(btnFind($api,'custom:emoji:help')===null&&btnFind($api,'custom:hide:help')===null&&btnFind($api,'custom:edit:button:help')!==null&&btnFind($api,'custom:cyclecolor:help')!==null,'a reply-keyboard button keeps only name, colour and premium emoji');
    foreach(['quality_360','quality_480','quality_720'] as $k) $bot->handle(btnClick('custom:hide:'.$k));
    $bot->handle(btnClick('custom:hide:quality_high'));
    btnCheck($ui->setting('button.hidden.quality_high')!=='1'&&str_contains(btnText($api),'دست‌کم یک دکمهٔ کیفیت'),'the last visible quality cannot be hidden');
    foreach(['quality_360','quality_480','quality_720'] as $k) $bot->handle(btnClick('custom:hide:'.$k));

    // Marks and reset.
    $bot->handle(btnClick('custom:section:cover'));
    $bot->handle(btnClick('custom:item:button:extract_audio'));
    $store->set('button.emoji.youtube__extract_audio','🔥'); $store->set('button.pos.youtube__extract_audio','end'); $store->set('button.hidden.youtube__extract_audio','1');
    $bot->handle(btnClick('custom:section:cover'));
    btnCheck(str_starts_with(btnFind($api,'custom:item:button:extract_audio')['text'],'🔘')&&(btnFind($api,'custom:item:button:extract_audio')['style']??'')==='primary','a button changed only by emoji or visibility is marked as customised with 🔘 (and is blue like every selectable button)');
    $bot->handle(btnClick('custom:reset:button:extract_audio'));
    $left=$store->query("SELECT key FROM settings WHERE key LIKE 'button.%.extract_audio' OR key='button.extract_audio'")->fetchAll(PDO::FETCH_COLUMN);
    btnCheck($ui->button('extract_audio','x')['text']==='🎶 Audio'&&empty($ui->button('extract_audio','x')['_hidden'])&&!isset($ui->button('extract_audio','x')['icon_custom_emoji_id']),'reset removes name, emoji, side, mode, colour, icon and visibility');
    $store->set('button.hidden.extract_audio','1'); $store->set('button.emoji.help','');
    $bot->handle(btnClick('custom:resetall')); $bot->handle(btnClick('custom:resetall:yes'));
    btnCheck($ui->setting('button.hidden.extract_audio')==='','reset-all also brings back hidden buttons');
    btnCheck($bot->handle(btnClick('custom:hide:extract_audio',7))===null,'an ordinary user cannot change buttons');
    btnCheck($ui->setting('button.hidden.extract_audio')==='','…and nothing changed');

    // Row width of the quality menu.
    $bot->handle(btnClick('custom:reorder'));
    btnCheck(str_contains(btnFind($api,'custom:perrow')['text']??'','2'),'the order screen shows two buttons per row by default');
    $widths=[]; for($i=0;$i<3;$i++) { $bot->handle(btnClick('custom:perrow')); $widths[]=(int)$ui->setting('cover.per_row'); }
    btnCheck($widths===[3,1,2],'the row width cycles 3, 1 and 2');
    $store->query('INSERT OR IGNORE INTO users(id) VALUES(7)');
    $menu=function(int $perRow) use($store,$bot,$api): array {
        $store->set('service.youtube.cover.per_row',(string)$perRow);
        $update=random_int(1000,900000); $store->query("INSERT INTO jobs(update_id,user_id,chat_id,url,created,message_id,phase,thread_id) VALUES(?,?,?,?,?,?,?,0)",[$update,7,7,'https://youtu.be/ybNMMK5Q318',time(),55,'inspect']);
        $job=$store->query('SELECT * FROM jobs WHERE update_id=?',[$update])->fetch();
        $bot->qualityMenu($job,['title'=>'T','options'=>[360=>[['id'=>'18','audio'=>true]],480=>[['id'=>'19','audio'=>true]],720=>[['id'=>'20','audio'=>true]],1080=>[['id'=>'21','audio'=>true]]],'poster'=>'']);
        $rows=end($api->calls)[1]['reply_markup']['inline_keyboard'];
        return array_map('count',$rows);
    };
    btnCheck($menu(1)===[1,1,1,1]&&$menu(2)===[2,2]&&$menu(3)===[3,1],'the quality menu follows the chosen row width');
    $bot->handle(btnClick('custom:orderreset'));
    btnCheck($ui->setting('cover.per_row')==='','resetting the order also resets the row width');
} finally { Zero\Download::clean($temp); }
