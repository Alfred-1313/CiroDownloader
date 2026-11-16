<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function inputCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
final class InputNoticeApi implements Zero\Api
{
    public array $calls=[];
    public bool $rejectPreview=false;
    public bool $rejectReaction=false;
    public function call(string $method,array $params=[]): mixed
    {
        $this->calls[]=[$method,$params];
        if(in_array($method,['sendMessage','editMessageText'],true)) {
            Zero\TextFormat::validate($params['text'],4096);
            foreach($params['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button)
                if(strlen($button['callback_data']??'')>64) throw new RuntimeException('callback too long');
            if($this->rejectPreview) { $this->rejectPreview=false; throw new RuntimeException('rejected preview',400); }
        }
        if($method==='setMessageReaction'&&$this->rejectReaction) throw new RuntimeException('reaction unavailable',400);
        return $method==='sendMessage'?['message_id'=>500]:true;
    }
    public function last(): array
    {
        $messages=array_values(array_filter($this->calls,fn($call)=>in_array($call[0],['sendMessage','editMessageText'],true)));
        return end($messages)[1]??[];
    }
    public function button(string $action): ?array
    {
        foreach($this->last()['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button)
            if(($button['callback_data']??'')===$action) return $button;
        return null;
    }
    public function reactions(): array { return array_values(array_filter($this->calls,fn($call)=>$call[0]==='setMessageReaction')); }
}
$root=sys_get_temp_dir().'/ciro-input-notices-'.bin2hex(random_bytes(5));
try {
    $store=new Zero\Store($root); $api=new InputNoticeApi(); $bot=new Zero\Bot(new Zero\Config('123:fixture',[42],$root),$store,$api); $serial=0;
    (new Zero\GroupSupport($bot))->cacheIdentity(['id'=>123,'username'=>'CiroDownloaderBot']);
    $click=function(string $action,int $user=42,int $chat=42) use($bot,&$serial): void {
        $bot->handle(['update_id'=>++$serial,'callback_query'=>['id'=>'input'.$serial,'from'=>['id'=>$user],
            'message'=>['message_id'=>500,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']],'data'=>$action]]);
    };
    $submit=function(string $text,int $user=7,?int $chat=null,int $thread=0,array $entities=[]) use($bot,&$serial): int {
        $chat??=$user; $id=++$serial;
        $bot->handle(['update_id'=>$id,'message'=>['message_id'=>$id,'from'=>['id'=>$user,'is_bot'=>false],
            'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private'],'message_thread_id'=>$thread,'text'=>$text,'entities'=>$entities]]);
        return $id;
    };
    $state=fn()=>$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn();
    $submit('سلام، این چیه؟');
    inputCheck($api->last()['text']===$bot->ui->text('unknown_message')&&count($api->reactions())===0,'unknown private text has its own friendly reply with reactions off by default');
    foreach(['https://example.com/unavailable','www.example.com/post','example.com/post'] as $text) {
        $submit($text);
        inputCheck($api->last()['text']===$bot->ui->text('invalid'),'unusable link gets the separate editable notice: '.$text);
    }
    $submit('اینجا بزن',7,null,0,[['type'=>'text_link','offset'=>0,'length'=>9,'url'=>'https://example.com']]);
    inputCheck($api->last()['text']===$bot->ui->text('invalid'),'a Telegram text-link entity is recognized as an unusable link');
    inputCheck((int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'unsupported input never creates download jobs');

    $click('custom:category:general');
    inputCheck($api->button('custom:section:inputs')!==null,'General exposes the unknown-message and unusable-link section');
    $click('custom:section:inputs');
    foreach(['unknown_message','invalid'] as $key) inputCheck($api->button('custom:item:text:'.$key)!==null,'both notices open in the existing item editor: '.$key);
    $click('custom:item:text:unknown_message');
    inputCheck($api->button('custom:section:inputs')!==null&&$api->button('custom:messagereaction:unknown_message')!==null,'the message item has its own reaction control and exact Back');
    $click('custom:messagereaction:unknown_message');
    inputCheck($api->button('custom:item:text:unknown_message')!==null,'reaction choices go back to the same message item');
    $click('custom:messagereaction:unknown_message:0');
    inputCheck($bot->ui->messageReaction('unknown_message')==='🤔'&&$bot->ui->messageReaction('invalid')==='','the unknown-message emoji is independent of the invalid-link emoji');
    inputCheck((new Zero\Ui(new Zero\Store($root)))->messageReaction('unknown_message')==='🤔','message reaction choices survive reopening the persistent store');
    inputCheck($bot->ui->customized('text','unknown_message')&&(new Zero\Personalization($bot))->sectionCustomized('inputs'),'reaction-only changes mark the item and parent section');
    $click('custom:category:general');
    inputCheck(str_contains($api->button('custom:section:inputs')['text'],'✏️'),'reaction-only changes appear on the General section marker');
    $click('custom:home');
    inputCheck(str_contains($api->button('custom:category:general')['text'],'✏️'),'reaction-only changes appear on the home category marker');
    $source=$submit('پیام ناشناس جدید'); $reactions=$api->reactions(); $reaction=end($reactions)[1];
    inputCheck((int)$reaction['message_id']===$source&&(int)$reaction['chat_id']===7&&$reaction['reaction']===[['type'=>'emoji','emoji'=>'🤔']],'the reaction targets the original human message, not the reply');
    $api->rejectReaction=true; $submit('یک پیام دیگر'); $api->rejectReaction=false;
    inputCheck($api->last()['text']===$bot->ui->text('unknown_message'),'a rejected optional reaction never suppresses the user reply');

    $click('custom:messagereaction:unknown_message:1',8,8);
    $click('custom:messagereaction:unknown_message:1',42,-100123);
    $click('custom:messagereaction:help:0');
    inputCheck($bot->ui->messageReaction('unknown_message')==='🤔'&&$store->get('reaction.help')==='','ordinary users, groups and unsupported fields cannot change message reactions');
    $click('custom:messagereaction:invalid:input'); $pending=$state();
    $submit('✅',42);
    inputCheck($state()===$pending&&$bot->ui->messageReaction('invalid')==='','unsupported emoji retains the old value and pending editor');
    $submit('👍 🔥',42);
    inputCheck($state()===$pending,'multiple emoji do not leave the editor or alter settings');
    $submit('👍',42,null,0,[['type'=>'custom_emoji','offset'=>0,'length'=>2,'custom_emoji_id'=>'5368324170671202286']]);
    inputCheck($state()===$pending&&$bot->ui->messageReaction('invalid')==='','premium custom-emoji reactions are not accepted as standard emoji');
    $api->rejectPreview=true; $submit('❤️',42);
    inputCheck($state()===$pending&&$bot->ui->messageReaction('invalid')==='','a rejected item preview preserves pending input and the saved emoji');
    $submit('❤️',42);
    inputCheck(!$state()&&$bot->ui->messageReaction('invalid')==='❤','a valid manual emoji is normalized, previewed and saved');
    $source=$submit('https://example.com/post',9); $reactions=$api->reactions();
    inputCheck(end($reactions)[1]['reaction']===[['type'=>'emoji','emoji'=>'❤']]&&(int)end($reactions)[1]['message_id']===$source,'invalid links use only their own selected emoji');

    $click('custom:edit:text:unknown_message');
    $custom='<b>پیام دلخواه 🙂</b> <tg-emoji emoji-id="5368324170671202286">🙂</tg-emoji>';
    $submit($custom,42);
    inputCheck($bot->ui->text('unknown_message')===$custom,'the existing caption editor preserves supported HTML and premium emoji');
    $click('custom:edit:text:unknown_message'); $submit('<b>خراب',42);
    inputCheck($state()&&$bot->ui->text('unknown_message')===$custom,'invalid HTML preserves the working message and editor');
    $click('custom:item:text:unknown_message');
    $source=$submit('چیزی بنویس',10); inputCheck($api->last()['text']===$custom,'runtime sends the saved custom unknown-message text');
    $click('custom:reset:text:unknown_message');
    inputCheck($bot->ui->messageReaction('unknown_message')===''&&$bot->ui->text('unknown_message')===Zero\Ui::definitions()['texts']['unknown_message']['value']&&$bot->ui->messageReaction('invalid')==='❤','item reset restores its text and reaction while preserving the other message');

    $before=count($api->calls); $submit('گفت‌وگوی معمولی',9,-100123,17); $submit('https://example.com',9,-100123,17);
    inputCheck(count($api->calls)===$before,'ordinary group chatter and unsupported bare links stay ignored');
    $source=$submit('/download@CiroDownloaderBot https://example.com',9,-100123,17); $reactions=$api->reactions();
    inputCheck((int)$api->last()['message_thread_id']===17&&(int)end($reactions)[1]['chat_id']===-100123&&(int)end($reactions)[1]['message_id']===$source,'an explicit invalid group download preserves the topic and original reaction target');
    $store->set('admins_only','1'); $before=count($api->reactions()); $submit('پیام ناشناس',11); $store->set('admins_only','0');
    inputCheck(count($api->reactions())===$before,'restricted users do not receive acknowledgement reactions');
    $store->set('admins_only','1'); $submit('/download@CiroDownloaderBot https://example.com',11,-100123,17); $store->set('admins_only','0');
    inputCheck(count($api->reactions())===$before,'an explicit invalid group request cannot bypass the reaction access gate');
    $click('custom:messagereaction:invalid:off');
    inputCheck($bot->ui->messageReaction('invalid')===''&&Zero\Reactions::selected($store)==='👾','message reactions can be disabled independently of accepted-link reactions');
    $click('custom:messagereaction:unknown_message:0'); $click('custom:messagereaction:invalid:1');
    $store->set('instagram.stories','1'); $click('custom:resetall:yes');
    inputCheck($bot->ui->messageReaction('unknown_message')===''&&$bot->ui->messageReaction('invalid')===''&&$store->get('instagram.stories')==='1'&&Zero\Reactions::selected($store)==='👾','reset-all clears both appearance reactions and preserves feature and download-reaction switches');

    $legacy=Zero\Ui::definitions()['texts']['invalid']['legacy_values'];
    foreach([end($legacy),Zero\Ui::escape(end($legacy)),$custom] as $n=>$saved) {
        $db=new Zero\Store($root.'/migration'.$n); $db->set('text.invalid',$saved); $db->query("DELETE FROM settings WHERE key='ui_input_notices_v1'");
        $db=new Zero\Store($root.'/migration'.$n); $ui=new Zero\Ui($db);
        if($saved===$custom) {
            inputCheck($ui->text('invalid')===$custom&&$ui->text('unknown_message')===$custom,'splitting the old shared message seeds both contexts from deliberate custom text');
            $db->set('text.unknown_message','متن مستقل'); new Zero\Store($root.'/migration'.$n);
            inputCheck($ui->text('unknown_message')==='متن مستقل'&&$ui->text('invalid')===$custom,'later context edits survive repeated startup independently');
        } else inputCheck($ui->text('invalid')===Zero\Ui::definitions()['texts']['invalid']['value'],'historical saved defaults migrate to the friendly copy, including escaped copies');
    }
} finally { Zero\Download::clean($root); }
