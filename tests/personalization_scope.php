<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

final class ScopeApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        return $method==='sendMessage'?['message_id'=>900]:true;
    }
}
function scopeCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function scopeCallback(string $data,int $user=42): array { return ['callback_query'=>['id'=>'scope','from'=>['id'=>$user],'message'=>['message_id'=>900,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$data]]; }
function scopeMessage(string $text,int $user=42): array { return ['update_id'=>random_int(1,1000000),'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]; }
function scopeActions(ScopeApi $api): array {
    $screen=end($api->calls)[1]; $actions=[];
    foreach($screen['reply_markup']['inline_keyboard']??[] as $row) foreach($row as $button) if(isset($button['callback_data'])) $actions[]=$button['callback_data'];
    return $actions;
}
$temp=sys_get_temp_dir().'/ciro-scope-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $store=new Zero\Store($temp); $api=new ScopeApi(); $bot=new Zero\Bot(new Zero\Config('123:fake',[42],$temp),$store,$api);
    foreach(['texts','buttons'] as $section) foreach(Zero\Ui::definitions()[$section] as $definition) scopeCheck(in_array($definition['audience']??'', ['user','admin'],true),'explicit audience: '.$definition['label']);
    scopeCheck(!$bot->ui->customizable('button','unknown')&&!$bot->ui->customizable('icon','help'),'unregistered or invalid editor fields are not editable');
    $found=[];
    foreach(['text','caption','button'] as $kind) {
        $bot->handle(scopeCallback('custom:groups:'.$kind)); $pending=[];
        foreach(scopeActions($api) as $action) if(str_starts_with($action,'custom:list:')) {
            $group=explode(':',$action)[3];
            scopeCheck(in_array($group,['public','download','errors'],true),'only user groups appear in '.$kind.': '.$group);
            $pending[]=$action;
        }
        $visited=[];
        while($pending) {
            $action=array_shift($pending); if(isset($visited[$action])) continue; $visited[$action]=true;
            $bot->handle(scopeCallback($action));
            scopeCheck(end($api->calls)[0]==='editMessageText'&&end($api->calls)[1]['message_id']===900,'catalog navigation edits the existing menu');
            foreach(scopeActions($api) as $next) {
                if(preg_match('/^custom:item:(text|button):(.+)$/D',$next,$item)) {
                    $definition=$bot->ui->definition($item[1],$item[2]);
                    scopeCheck(($definition['audience']??'')==='user'&&empty($definition['hidden']),'listed item belongs to the ordinary user: '.$item[2]);
                    $found[$item[1]][$item[2]]=true;
                } elseif(str_starts_with($next,'custom:list:')) $pending[]=$next;
            }
        }
    }
    foreach(['welcome','help','offline','admin_only_notice','quality_prompt','video_caption','audio_caption','error_cookies'] as $key) scopeCheck(isset($found['text'][$key]),'user-visible text remains editable: '.$key);
    foreach(['help','quality_360','quality_480','quality_720','quality_high','extract_audio','source_video'] as $key) scopeCheck(isset($found['button'][$key]),'user-visible button remains editable: '.$key);
    foreach(['admin','health','cookies','update','customize','back_custom','download','quality'] as $key) scopeCheck(!isset($found['button'][$key])&&!isset($bot->catalog('button')[$key]),'administration or retired button is absent from all catalogs: '.$key);
    foreach(['health','admin_panel','cookie_import','custom_panel','update_menu','delivered','audio_delivered','cancelled'] as $key) scopeCheck(!isset($found['text'][$key])&&!isset($bot->catalog('text')[$key]),'administration or retired text is absent from all catalogs: '.$key);

    $saved=['button.health'=>'Saved administrator label','button.style.health'=>'success','button.icon.health'=>'5368324170671202286','text.health'=>'Saved administrator status',
        'text.welcome'=>'<b>Saved welcome</b>','text.video_caption'=>'<b>{title}</b> <blockquote>{duration}</blockquote>','button.source_video'=>'Saved source link'];
    foreach($saved as $key=>$value) $store->set($key,$value);
    foreach(['custom:item:text:health','custom:edit:text:health','custom:reset:text:health','custom:edit:button:health','custom:reset:button:health',
        'custom:color:health','custom:setcolor:health:danger','custom:icon:health','custom:clearicon:health',
        'custom:edit:button:quality','custom:reset:button:quality','custom:color:quality','edit:button:quality','reset:button:quality',
        'custom:edit:button:admin','custom:edit:button:cookies','custom:edit:button:update','custom:edit:button:customize','custom:edit:button:back_custom',
        'custom:edit:text:cookie_import','custom:edit:text:update_menu','custom:edit:text:custom_panel','custom:edit:button:download',
        'edit:button:health','reset:button:health','edit:text:health','reset:text:health'] as $action) {
        $bot->handle(scopeCallback($action));
        scopeCheck(!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'blocked route cannot open an edit session: '.$action);
        foreach($saved as $key=>$value) if($store->get($key)!==$value) throw new RuntimeException('Blocked route changed '.$key.': '.$action);
    }
    foreach(['text','button','icon'] as $kind) {
        $store->query('INSERT OR REPLACE INTO states VALUES(?,?,?)',[42,'ui:'.json_encode(['kind'=>$kind,'key'=>'health','message'=>900]),time()+600]);
        $bot->handle(scopeMessage('5368324170671202287'));
        scopeCheck(!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn()&&end($api->calls)[0]==='editMessageText'&&end($api->calls)[1]['message_id']===900,'an old administrator edit session closes safely: '.$kind);
        foreach($saved as $key=>$value) if($store->get($key)!==$value) throw new RuntimeException('Old session changed '.$key);
    }
    $bot->handle(scopeCallback('custom:list:button:admin:0'));
    scopeCheck(!in_array('custom:item:button:health',scopeActions($api),true)&&in_array('custom:list:button:public:0',scopeActions($api),true),'an old administration group returns to the user-only group selector');
    $bot->handle(scopeCallback('custom:setcolor:source_video:danger'));
    scopeCheck($store->get('button.style.source_video')==='danger','user button color edits still work');
    $bot->handle(scopeCallback('custom:edit:text:admin_only_notice'));
    $bot->handle(scopeMessage('<blockquote><b>Custom private notice</b></blockquote>'));
    $bot->handle(scopeCallback('custom:access'));
    $bot->handle(scopeMessage('https://youtu.be/ybNMMK5Q318',7));
    scopeCheck(str_contains(end($api->calls)[1]['text'],'Custom private notice')&&(int)$store->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'the user-facing access feature and its message remain configurable');
    $bot->handle(scopeCallback('custom:access'));
    $bot->handle(scopeCallback('custom:setcolor:help:danger',7));
    scopeCheck($store->get('button.style.help')==='','ordinary users cannot edit personalization');
} finally { Zero\Download::clean($temp); }

