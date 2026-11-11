<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';
require dirname(__DIR__).'/src/Worker.php';
function captionCheck(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$root=sys_get_temp_dir().'/ciro-adult-captions-'.bin2hex(random_bytes(6));
try {
    $config=new Zero\Config('123:fixture',[42],$root); $store=new Zero\Store($root);
    $api=new class implements Zero\Api {
        public array $calls=[];
        public function call(string $method,array $params=[]):mixed { $this->calls[]=[$method,$params]; throw new RuntimeException('Fixture caption edit rejected',400); }
    };
    $bot=new Zero\Bot($config,$store,$api); $worker=new Zero\Worker($config,$store,$api);
    $render=new ReflectionMethod($worker,'adultCaption');
    $media=['title'=>'Neutral <Title>','duration'=>125,'quality'=>'480p'];
    foreach(Zero\adultServices() as $site) {
        foreach(['',"<b>&nbsp;\u{200B}</b>",'{people}{tags}{notice}'] as $template) {
            $store->set('text.'.$site.'_caption',$template); $store->set('text.'.$site.'_notice',"\n ");
            foreach([0,180] as $delay) {
                $caption=$render->invoke($worker,$bot,$media,[],false,$delay,$site);
                captionCheck(str_contains($caption,'Neutral &lt;Title&gt;')&&str_contains($caption,'480p')&&str_contains($caption,'02:05'),$site.': blank or metadata-only captions retain the title, quality and duration');
                captionCheck($delay>0?(str_starts_with($caption,'<blockquote>🗑')&&str_contains($caption,'180 ثانیه')):!str_contains($caption,'🗑'),$site.': a blank saved notice falls back only when deletion is enabled');
                Zero\TextFormat::validate($caption,1024);
            }
        }
        $store->set('text.'.$site.'_caption','<b>CUSTOM {title}</b>{notice}{notice}');
        $store->set('text.'.$site.'_notice','<blockquote>DELETE {seconds}</blockquote>');
        $caption=$render->invoke($worker,$bot,$media,[],false,30,$site);
        captionCheck(str_starts_with($caption,"<blockquote>DELETE 30</blockquote>\n\n<b>CUSTOM ")&&substr_count($caption,'DELETE 30')===1,$site.': meaningful rich custom text survives and the notice appears once at the top');
    }
    // Optional UI text retains its existing behavior; only delivery-critical text requests a fallback.
    $store->set('text.youporn_caption','');
    captionCheck($bot->ui->text('youporn_caption')==='','ordinary optional rendering is unchanged');
    $repair=new ReflectionMethod($worker,'confirmAdultCaption');
    $repair->invoke($worker,['message_id'=>123],['chat_id'=>42,'caption'=>'<b>Caption</b>'],'youporn',180);
    captionCheck(count($api->calls)===1&&$api->calls[0][0]==='editMessageCaption'&&$api->calls[0][1]['message_id']===123,'a rejected caption repair never retries or resends a confirmed video');
} finally { Zero\Download::clean($root); }
