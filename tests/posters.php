<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
final class PosterApi implements Zero\Api {
    public array $calls=[]; public bool $reject=false; public bool $deleted=false;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='editMessageMedia'&&$this->reject) throw new RuntimeException('Invalid photo',400);
        if(str_starts_with($method,'editMessage')&&$this->deleted) { $this->deleted=false; throw new RuntimeException('Missing message',404); }
        return ['message_id'=>501];
    }
}
function posterCheck(bool $ok,string $text): void { if(!$ok) throw new RuntimeException($text); echo "PASS $text\n"; }
$temp=sys_get_temp_dir().'/ciro-poster-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $url='https://youtu.be/ybNMMK5Q318'; $fallback='https://i.ytimg.com/vi/ybNMMK5Q318/hqdefault.jpg';
    foreach(['http://127.0.0.1/admin','https://i.ytimg.com.evil.test/vi/ybNMMK5Q318/a.jpg','https://i.ytimg.com/vi/differentID/a.jpg','https://user@i.ytimg.com/vi/ybNMMK5Q318/a.jpg'] as $unsafe) {
        posterCheck(Zero\Download::posterUrl(['thumbnail'=>$unsafe],$url)===$fallback,'untrusted thumbnail URLs fall back to the canonical YouTube image');
    }
    $store=new Zero\Store($temp); $api=new PosterApi(); $bot=new Zero\Bot(new Zero\Config('123:fake',[42],$temp),$store,$api);
    $store->enqueue(1,7,7,$url); $job=$store->query('SELECT * FROM jobs')->fetch();
    $bot->jobMessage($job,$bot->text('queued'));
    $media=['title'=>'Example','poster'=>$fallback,'options'=>[360=>[['id'=>'18','audio'=>true]]]];
    $bot->qualityMenu($job,$media);
    $reopened=new Zero\Bot($bot->config,new Zero\Store($temp),$api);
    $reopened->jobMessage($job,'<blockquote><b>Error</b></blockquote>');
    posterCheck(end($api->calls)[0]==='editMessageCaption'&&str_contains(end($api->calls)[1]['caption'],'Error'),'poster state survives restarts and errors edit its caption');
    posterCheck(end($api->calls)[1]['reply_markup']['inline_keyboard']===[],'error captions cannot leave stale quality buttons');
    $api->deleted=true; $reopened->jobMessage($job,'Retry status');
    posterCheck(end($api->calls)[0]==='sendPhoto','a user-deleted poster is replaced with one photo status');
    $reopened->finishJob($job);
    posterCheck(end($api->calls)[0]==='deleteMessage'&&end($api->calls)[1]===['chat_id'=>7,'message_id'=>501],'successful delivery deletes the entire poster message from its original chat');
    $store->query("UPDATE jobs SET message_kind='text',poster='' WHERE id=?",[$job['id']]); $api->reject=true;
    $bot->qualityMenu($job,$media);
    posterCheck(end($api->calls)[0]==='editMessageText'&&!empty(end($api->calls)[1]['reply_markup']['inline_keyboard']),'a rejected poster still offers working quality buttons in the existing text message');
    $bot->finishJob($job);
    posterCheck(end($api->calls)[0]==='deleteMessage','a text fallback status is removed after delivery');
    $long=Zero\Ui::fitCaption('<b>'.str_repeat('😎',2000).'</b>');
    posterCheck(Zero\TextFormat::length(html_entity_decode(strip_tags($long),ENT_QUOTES|ENT_HTML5,'UTF-8'))<=1024,'long customized status messages fit Telegram photo captions');
    posterCheck(Zero\Ui::duration(65)==='01:05'&&Zero\Ui::duration(3661)==='1:01:01','durations use English minutes and seconds, including hours for long videos');
    $caption=$bot->text('video_caption',['title'=>'Example','quality'=>'1080p','duration'=>'05:32']);
    posterCheck(str_contains($caption,'<b>')&&str_contains($caption,'<blockquote>')&&str_contains($caption,'1080p')&&str_contains($caption,'05:32'),'default final video captions show the title, quality and duration with bold and quote formatting');
} finally { Zero\Download::clean($temp); }
