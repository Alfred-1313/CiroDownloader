<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function captionCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
final class CaptionEditorApi implements Zero\Api {
    public array $calls=[];
    public function call(string $method,array $params=[]): mixed {
        if(in_array($method,['sendMessage','editMessageText'],true)) Zero\TextFormat::validate($params['text'],4096);
        $this->calls[]=[$method,$params];
        return $method==='sendMessage'?['message_id'=>900]:true;
    }
    public function last(): array { return end($this->calls)[1]; }
    public function actions(): array { return array_column(array_merge(...($this->last()['reply_markup']['inline_keyboard']??[[]])),'callback_data'); }
}
$root=sys_get_temp_dir().'/ciro-caption-editor-'.bin2hex(random_bytes(5));
try {
    $store=new Zero\Store($root); $api=new CaptionEditorApi(); $bot=new Zero\Bot(new Zero\Config('123:fixture',[42],$root),$store,$api);
    $click=function(string $action,int $user=42) use($bot): void {
        $bot->handle(['callback_query'=>['id'=>'caption','from'=>['id'=>$user],'message'=>['message_id'=>900,'chat'=>['id'=>$user,'type'=>'private']],'data'=>$action]]);
    };
    $keys=[];
    foreach($bot->catalog('text') as $key=>$entry) if(!empty($bot->ui->definition('text',$key)['caption'])) $keys[]=$key;
    $keys=array_unique([...$keys,'pornhub_notice','pornhub_people','pornhub_views','pornhub_tags']);
    foreach($keys as $key) {
        $click('custom:edit:text:'.$key);
        captionCheck(str_contains($api->last()['text'],'کپشن جدید رو بفرست')&&str_contains($api->last()['text'],'راهنمای فرمت‌بندی')&&in_array('custom:reset:text:'.$key,$api->actions(),true),'caption guide and working reset are available: '.$key);
    }
    $click('custom:items:instagram:caption:0'); $click('custom:edit:text:instagram_caption');
    $prompt=$api->last()['text'];
    preg_match_all('~<code>(.*?)</code>~su',$prompt,$matches);
    $examples=array_map(fn($value)=>html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'),$matches[1]);
    foreach(['<b>X</b>','<i>X</i>','<u>X</u>','<s>X</s>','<code>X</code>','<pre>X</pre>','<blockquote>X</blockquote>','<a href="آدرس">X</a>'] as $example)
        captionCheck(in_array($example,$examples,true),'formatting example is literal and copyable: '.$example);
    captionCheck(str_contains($prompt,'{description}')&&str_contains($prompt,'فقط ابتدای متن (سمت راست برای فارسی)'),'service variables and the requested premium-emoji guidance remain visible');
    $store->set('text.instagram_caption','<b>کپشن قدیمی</b>');
    $click('custom:reset:text:instagram_caption');
    captionCheck($bot->ui->template('instagram_caption')===Zero\Ui::definitions()['texts']['instagram_caption']['value']&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'the prompt reset restores the default and closes pending caption input');
    captionCheck(in_array('custom:items:instagram:caption:0',$api->actions(),true),'reset retains Back to the same service caption list');
    $click('custom:edit:text:instagram_caption');
    $rich='<b>{description}</b> <i>X</i> <u>X</u> <s>X</s> <code>X</code><pre>X</pre><blockquote>X</blockquote><a href="https://example.com/">X</a>';
    $bot->handle(['update_id'=>99,'message'=>['from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'text'=>$rich]]);
    captionCheck($store->get('text.instagram_caption')===$rich&&!$store->query('SELECT 1 FROM states WHERE user_id=42')->fetchColumn(),'all advertised HTML styles save through the guided caption editor');
    $click('custom:edit:text:instagram_caption');
    $bot->handle(['update_id'=>100,'message'=>['from'=>['id'=>42],'chat'=>['id'=>42,'type'=>'private'],'text'=>'😎 {description}','entities'=>[['type'=>'custom_emoji','offset'=>0,'length'=>2,'custom_emoji_id'=>'5368324170671202286']]]]);
    captionCheck($store->get('text.instagram_caption')==='<tg-emoji emoji-id="5368324170671202286">😎</tg-emoji> {description}','the guided editor preserves native premium emoji and caption placeholders');
    $click('custom:edit:button:instagram_next');
    captionCheck(!str_contains($api->last()['text'],'راهنمای فرمت‌بندی')&&!in_array('custom:reset:text:instagram_next',$api->actions(),true),'button names keep their own editor instead of the caption guide');
    $click('custom:edit:text:instagram_caption',7);
    captionCheck(($api->last()['show_alert']??false)&&!$store->query('SELECT 1 FROM states WHERE user_id=7')->fetchColumn(),'ordinary users cannot open caption editing');
    foreach(['custom_caption_prompt','custom_formatting_guide'] as $key) captionCheck(!$bot->ui->customizable('text',$key),'editor guidance stays administrator-only: '.$key);
} finally { Zero\Download::clean($root); }
