<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function spoilerCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
final class SpoilerApi implements Zero\Api {
    public array $calls=[]; public bool $deleted=false; public bool $reject=false;
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='editMessageMedia'&&isset($params['has_spoiler'])) throw new RuntimeException('Spoiler belongs inside InputMediaPhoto');
        if($method==='editMessageCaption'&&isset($params['has_spoiler'])) throw new RuntimeException('Caption edits do not support spoiler flags');
        if(str_starts_with($method,'edit')&&$this->deleted) { $this->deleted=false; throw new RuntimeException('Message deleted',404); }
        if($method==='editMessageMedia'&&$this->reject) throw new RuntimeException('Photo rejected',400);
        return ['message_id'=>$params['message_id']??701,'photo'=>[['file_id'=>'FIXTURE_POSTER']]];
    }
    public function last(): array { return end($this->calls); }
}
$root=sys_get_temp_dir().'/ciro-spoilers-'.bin2hex(random_bytes(5));
try {
    $store=new Zero\Store($root); $api=new SpoilerApi(); $config=new Zero\Config('123:fixture',[42],$root); $bot=new Zero\Bot($config,$store,$api);
    $poster=$root.'/poster.jpg'; file_put_contents($poster,"\xff\xd8\xff".'neutral fixture');
    $links=['pornhub'=>'https://www.pornhub.com/view_video.php?viewkey=phfixture',
        'beeg'=>'https://beeg.com/-0123456789012345','xnxx'=>'https://www.xnxx.com/video-55awb78/fixture_video',
        'xvideos'=>'https://www.xvideos.com/video.abc123def4/fixture_video','tikporn'=>'https://tik.porn/video/2344693',
        'youporn'=>'https://www.youporn.com/watch/189780301/','eporner'=>'https://www.eporner.com/video-HmstDuT4BNE/'];
    $click=function(string $data,int $user=42,int $chat=42) use($bot): void {
        $bot->handle(['callback_query'=>['id'=>'spoiler','from'=>['id'=>$user],
            'message'=>['message_id'=>700,'chat'=>['id'=>$chat,'type'=>$chat<0?'supergroup':'private']],'data'=>$data]]);
    };
    $serial=0;
    foreach($links+['youtube'=>'https://youtu.be/ybNMMK5Q318'] as $site=>$url) {
        $adult=$site!=='youtube';
        spoilerCheck(Zero\adultService($url)===($adult?$site:null),'fixture matches the intended service: '.$site);
        $store->query('INSERT INTO jobs(update_id,user_id,chat_id,thread_id,url,created,message_id,phase) VALUES(?,?,?,?,?,?,?,?)',[++$serial,42,-100123,321,$url,time(),77,'inspect']);
        $job=$store->query('SELECT * FROM jobs WHERE id=?',[(int)$store->db->lastInsertId()])->fetch();
        $rows=[[$bot->ui->forService($site)->button('quality_720','quality:'.$job['id'].':720',['quality'=>'720p'])]];
        $bot->jobPoster($job,'FIXTURE_REMOTE_POSTER','<b>Neutral title</b>',$rows); [$method,$params]=$api->last();
        spoilerCheck($method==='editMessageMedia'&&($params['media']['has_spoiler']??false)===$adult&&$params['reply_markup']['inline_keyboard']===$rows,'remote/cached cover edits use a nested native spoiler and retain quality callbacks: '.$site);
        $bot->jobPosterFile($job,$poster,'<b>Neutral title</b>',$rows); [$method,$params]=$api->last();
        spoilerCheck($method==='editMessageMedia'&&($params['media']['has_spoiler']??false)===$adult&&$params['poster'] instanceof CURLFile,'uploaded cover edits carry the same media spoiler: '.$site);
        foreach(['remote','file'] as $source) {
            $store->query('UPDATE jobs SET message_id=0,message_kind=\'text\' WHERE id=?',[$job['id']]);
            if($source==='file') $bot->jobPosterFile($job,$poster,'Title',$rows); else $bot->jobPoster($job,'FIXTURE_REMOTE_POSTER','Title',$rows);
            [$method,$params]=$api->last();
            spoilerCheck($method==='sendPhoto'&&($params['has_spoiler']??false)===$adult&&$params['message_thread_id']===321,'new '.$source.' covers use the spoiler and original group topic: '.$site);
        }
        $api->deleted=true; $bot->jobMessage(array_intersect_key($job,array_flip(['id','chat_id','thread_id'])),'<b>Uploading</b>'); [$method,$params]=$api->last();
        spoilerCheck($method==='sendPhoto'&&$params['photo']==='FIXTURE_POSTER'&&($params['has_spoiler']??false)===$adult,'a deleted status reuses the confirmed photo ID with its spoiler: '.$site);
        $api->deleted=true; $bot->jobPosterFile($job,$poster,'Title',$rows); [$method,$params]=$api->last();
        spoilerCheck($method==='sendPhoto'&&($params['has_spoiler']??false)===$adult,'a deleted uploaded cover is recreated with the same spoiler: '.$site);
        $store->query('UPDATE jobs SET message_id=77,message_kind=\'text\',poster=\'\' WHERE id=?',[$job['id']]);
        $api->reject=true; $bot->jobPosterFile($job,$poster,'Title',$rows); $api->reject=false; [$method,$params]=$api->last();
        spoilerCheck($method==='editMessageText'&&!isset($params['has_spoiler'])&&$params['reply_markup']['inline_keyboard']===$rows,'a refused photo safely falls back to the text quality menu: '.$site);
        if(!$adult) continue;
        spoilerCheck($bot->adultSpoiler($site,'cover')&&$bot->adultSpoiler($site,'video'),'both media spoilers default on: '.$site);
        $click('custom:settings:'.$site); $buttons=array_merge(...$api->last()[1]['reply_markup']['inline_keyboard']);
        $toggles=array_filter($buttons,fn($button)=>str_starts_with($button['callback_data']??'','custom:spoiler:'));
        spoilerCheck(count($toggles)===2&&!array_filter($toggles,fn($button)=>($button['style']??'')!=='primary'||!str_contains($button['text'],'✅')),'each site has two independent blue spoiler switches: '.$site);
        $click('custom:spoiler:'.$site.':cover');
        spoilerCheck(!$bot->adultSpoiler($site,'cover')&&$bot->adultSpoiler($site,'video'),'turning off the cover does not change the video: '.$site);
        $bot->jobPosterFile($job,$poster,'Title',$rows); [$method,$params]=$api->last();
        spoilerCheck(!isset($params['media']['has_spoiler']),'the next cover honours its disabled switch: '.$site);
        $click('custom:spoiler:'.$site.':cover',7,7); $click('custom:spoiler:'.$site.':cover',42,-100123);
        spoilerCheck(!$bot->adultSpoiler($site,'cover'),'ordinary users and group callbacks cannot change private settings: '.$site);
        $other=$site==='pornhub'?'beeg':'pornhub';
        spoilerCheck($bot->adultSpoiler($other,'cover'),'another site keeps its own spoiler setting: '.$site);
        $click('custom:spoiler:'.$site.':cover'); $click('custom:spoiler:'.$site.':video');
        $reopened=new Zero\Bot($config,new Zero\Store($root),$api);
        spoilerCheck(!$reopened->adultSpoiler($site,'video')&&$reopened->adultSpoiler($site,'cover'),'the independent video setting persists through restart: '.$site);
        $click('custom:spoiler:'.$site.':video');
    }
    $click('custom:spoiler:youtube:cover'); $click('custom:spoiler:beeg:audio');
    spoilerCheck($store->get('adult.youtube.spoiler_cover')===''&&$store->get('adult.beeg.spoiler_audio')==='','forged service and unsupported media types cannot create spoiler settings');
    foreach(['adult_spoiler_cover','adult_spoiler_video'] as $key) spoilerCheck(!$bot->ui->customizable('button',$key),'spoiler management controls stay administrator-only: '.$key);
} finally { Zero\Download::clean($root); }
