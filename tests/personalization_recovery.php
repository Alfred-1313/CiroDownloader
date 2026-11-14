<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class RecoveryApi implements Zero\Api {
    public array $calls=[];
    public array $missing=[];
    public bool $rejectEmoji=false;
    public bool $networkFailure=false;
    private int $next=1000;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if(in_array($method,['sendMessage','editMessageText'],true)) {
            if($this->networkFailure) throw new RuntimeException('Simulated transport failure');
            if($method==='editMessageText'&&in_array($params['message_id'],$this->missing,true)) throw new RuntimeException('Telegram message is unavailable',404);
            $encoded=json_encode($params,JSON_THROW_ON_ERROR);
            if($this->rejectEmoji&&(str_contains($encoded,'icon_custom_emoji_id')||str_contains($encoded,'tg-emoji'))) throw new RuntimeException('Telegram API error 400',400);
            try { Zero\TextFormat::validate($params['text'],4096); }
            catch(RuntimeException) { throw new RuntimeException('Telegram API error 400',400); }
            foreach($params['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button) {
                if(Zero\TextFormat::length($button['text'])<1||Zero\TextFormat::length($button['text'])>64||strlen($button['callback_data']??'')>64) throw new RuntimeException('Telegram API error 400',400);
            }
        }
        return $method==='sendMessage'?['message_id'=>++$this->next]:true;
    }
}
function recoveryCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function recoveryClick(string $action,int $message=900): array { return ['callback_query'=>['id'=>'recovery','from'=>['id'=>42],'message'=>['message_id'=>$message,'chat'=>['id'=>42,'type'=>'private']],'data'=>$action]]; }
function recoveryInput(string $text): array { return ['update_id'=>random_int(1,1000000),'message'=>['from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'text'=>$text]]; }
function recoveryActions(RecoveryApi $api): array {
    $actions=[];
    foreach(end($api->calls)[1]['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button) if(isset($button['callback_data'])) $actions[]=$button['callback_data'];
    return $actions;
}
$cases=[
    'all user fields save and reset'=>function($bot,$store,$api) {
        foreach(['text','button'] as $kind) foreach($bot->catalog($kind) as $key=>$definition) {
            $bot->handle(recoveryClick('custom:item:'.$kind.':'.$key));
            recoveryCheck(in_array('custom:edit:'.$kind.':'.$key,recoveryActions($api),true),'editable item opens: '.$kind.'.'.$key);
            $bot->handle(recoveryClick('custom:edit:'.$kind.':'.$key));
            $value=$kind==='text'?'<b>متن آزمایشی</b>':'آزمایشی '.$bot->label($key);
            $bot->handle(recoveryInput($value));
            recoveryCheck($store->get($kind.'.'.$key)===$value,'editing saves the selected field: '.$kind.'.'.$key);
            $reloaded=new Zero\Ui(new Zero\Store($bot->config->storage));
            recoveryCheck(($kind==='text'?$reloaded->template($key):$reloaded->label($key))===$value,'saved field survives restart: '.$kind.'.'.$key);
            if($kind==='button') {
                foreach(['default','primary','success','danger'] as $style) {
                    $bot->handle(recoveryClick('custom:setcolor:'.$key.':'.$style));
                    recoveryCheck($store->get('button.style.'.$key)===($style==='default'?'':$style),'button color saves: '.$key.'.'.$style);
                }
                $bot->handle(recoveryClick('custom:icon:'.$key));
                $bot->handle(recoveryInput('5368324170671202286'));
                recoveryCheck($store->get('button.icon.'.$key)==='5368324170671202286','button emoji saves: '.$key);
            }
            $bot->handle(recoveryClick('custom:reset:'.$kind.':'.$key));
            recoveryCheck(isset(Zero\Ui::definitions()[$kind==='button'?'buttons':'texts'][$key]['service'])?($kind==='text'?$bot->ui->template($key):$bot->ui->label($key))===Zero\Ui::definitions()[$kind==='button'?'buttons':'texts'][$key]['value']:$store->get($kind.'.'.$key,'absent')==='absent','reset restores the default: '.$kind.'.'.$key);
            if($kind==='button') recoveryCheck(isset(Zero\Ui::definitions()['buttons'][$key]['service'])?empty($bot->ui->button($key)['icon_custom_emoji_id']):($store->get('button.style.'.$key,'absent')==='absent'&&$store->get('button.icon.'.$key,'absent')==='absent'),'reset also restores button color and emoji: '.$key);
        }
    },
    'return to the selected catalog page'=>function($bot,$store,$api) {
        $bot->handle(recoveryClick('custom:list:text:youtube:1'));
        $item=current(array_filter(recoveryActions($api),fn($action)=>str_starts_with($action,'custom:item:')));
        recoveryCheck(is_string($item)&&str_contains(end($api->calls)[1]['text'],'<b>2 /'),'a second catalog page is exercised');
        $bot->handle(recoveryClick($item));
        recoveryCheck(in_array('custom:list:text:youtube:1',recoveryActions($api),true),'Back preserves the page containing the selected item');
    },
    'repair a rejected saved button'=>function($bot,$store,$api) {
        $store->set('button.icon.help','5368324170671202286');
        $api->rejectEmoji=true;
        $bot->handle(recoveryClick('custom:item:button:help'));
        recoveryCheck(in_array('custom:clearicon:help',recoveryActions($api),true)&&in_array('custom:reset:button:help',recoveryActions($api),true),'rejected saved emoji still leaves repair controls accessible');
        recoveryCheck($store->get('button.icon.help')==='5368324170671202286','opening a rejected preview does not change stored settings');
        $bot->handle(recoveryClick('custom:clearicon:help'));
        recoveryCheck($store->get('button.icon.help')==='','the rejected emoji can be removed through Personalization');
    },
    'repair rejected saved text'=>function($bot,$store,$api) {
        $original='<tg-emoji emoji-id="5368324170671202286">🙂</tg-emoji> Welcome';
        $store->set('text.welcome',$original); $api->rejectEmoji=true;
        $bot->handle(recoveryClick('custom:item:text:welcome'));
        recoveryCheck($store->get('text.welcome')===$original&&!str_contains(end($api->calls)[1]['text'],'<tg-emoji'),'rejected text gets a safe editor preview without changing its saved value');
        recoveryCheck(in_array('custom:edit:text:welcome',recoveryActions($api),true),'rejected text remains editable');
        $bot->handle(recoveryClick('custom:reset:text:welcome'));
        recoveryCheck($store->get('text.welcome')==='','reset repairs a rejected saved text');
    },
    'reject a candidate and allow retry'=>function($bot,$store,$api) {
        $store->set('button.icon.help','5368324170671202286'); $api->rejectEmoji=true;
        $bot->handle(recoveryClick('custom:icon:help'));
        $bot->handle(recoveryInput('5368324170671202287'));
        recoveryCheck($store->get('button.icon.help')==='5368324170671202286','a rejected candidate never replaces an already rejected saved emoji');
        recoveryCheck((bool)$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'rejected preview retains the input session for retry');
        $api->rejectEmoji=false;
        $bot->handle(recoveryInput('5368324170671202287'));
        recoveryCheck($store->get('button.icon.help')==='5368324170671202287'&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'a valid retry saves and closes the edit session');
    },
    'recover a deleted edit message'=>function($bot,$store,$api) {
        $bot->handle(recoveryClick('custom:edit:text:welcome'));
        $api->missing=[900];
        $bot->handle(recoveryInput('<b>bad</i>'));
        $state=json_decode(substr($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn(),3),true);
        recoveryCheck($state['message']>900,'a deleted edit prompt is replaced and its new message ID is retained');
        $replacement=$state['message'];
        $bot->handle(recoveryInput('<b>RECOVERED</b>'));
        recoveryCheck($store->get('text.welcome')==='<b>RECOVERED</b>'&&end($api->calls)[0]==='editMessageText'&&end($api->calls)[1]['message_id']===$replacement,'retry updates the replacement message and saves the value');
    },
    'record a newly sent prompt'=>function($bot,$store,$api) {
        (new Zero\Personalization($bot))->begin(42,42,'text','welcome');
        $state=json_decode(substr($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn(),3),true);
        recoveryCheck($state['message']>0,'new edit sessions record the actual sent message ID');
        $bot->handle(recoveryInput('New welcome'));
        recoveryCheck(end($api->calls)[0]==='editMessageText'&&end($api->calls)[1]['message_id']===$state['message'],'saving a new prompt edits it instead of sending another message');
    },
    'failed prompt does not open a phantom session'=>function($bot,$store,$api) {
        $api->networkFailure=true;
        try { $bot->handle(recoveryClick('custom:edit:text:welcome')); throw new LogicException('Expected a transport failure'); }
        catch(RuntimeException $e) { recoveryCheck($e->getCode()===0,'prompt transport failure is propagated'); }
        recoveryCheck(!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'an unsent prompt does not leave a hidden edit session');
    },
    'preview click is a no-op'=>function($bot,$store,$api) {
        $bot->handle(recoveryClick('custom:edit:text:welcome'));
        $before=$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn();
        $bot->handle(recoveryClick('custom:noop',901));
        recoveryCheck($store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()===$before,'pressing an old preview button does not cancel another edit');
    },
    'transport failure keeps the active value'=>function($bot,$store,$api) {
        $bot->handle(recoveryClick('custom:edit:text:welcome')); $before=$bot->text('welcome');
        $api->networkFailure=true;
        try { $bot->handle(recoveryInput('<b>Candidate</b>')); throw new LogicException('Expected a transport failure'); }
        catch(RuntimeException $e) { recoveryCheck($e->getCode()===0,'save transport failure is propagated'); }
        recoveryCheck($bot->text('welcome')===$before,'transport failure neither saves the candidate nor leaks preview overrides');
    },
];
$temp=sys_get_temp_dir().'/ciro-custom-recovery-'.bin2hex(random_bytes(6)); mkdir($temp,0700); $failures=[];
try {
    foreach($cases as $name=>$case) {
        $folder=$temp.'/'.count(glob($temp.'/*')); mkdir($folder,0700);
        $store=new Zero\Store($folder); $api=new RecoveryApi(); $bot=new Zero\Bot(new Zero\Config('123:fake',[42],$folder),$store,$api);
        try { $case($bot,$store,$api); }
        catch(Throwable $e) { $failures[]=$name; echo 'FAIL '.$name.': '.$e->getMessage()."\n"; }
    }
    if($failures) throw new RuntimeException(count($failures).' personalization recovery scenarios failed');
} finally { Zero\Download::clean($temp); }
