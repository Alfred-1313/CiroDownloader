<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
require dirname(__DIR__).'/src/Download.php';

function formatCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
final class FormatApi implements Zero\FileApi {
    public array $calls=[]; public array $files=[];
    public function call(string $method,array $params=[]): mixed {
        $this->calls[]=[$method,$params];
        if($method==='getFile') return ['file_path'=>$params['file_id'],'file_size'=>strlen($this->files[$params['file_id']]??'')];
        return ['message_id'=>900];
    }
    public function downloadFile(string $filePath,int $maxBytes): string { return $this->files[$filePath]??''; }
    public function count(string $method): int { return count(array_filter($this->calls,fn($c)=>$c[0]===$method)); }
}
function igJson(string $session,string $csrf='C1'): string {
    return json_encode([
        ['domain'=>'.instagram.com','expirationDate'=>2147483647.5,'hostOnly'=>false,'httpOnly'=>true,'name'=>'sessionid','path'=>'/','secure'=>true,'session'=>false,'value'=>$session],
        ['domain'=>'www.instagram.com','hostOnly'=>true,'httpOnly'=>false,'name'=>'csrftoken','path'=>'/','secure'=>true,'session'=>true,'value'=>$csrf],
    ]);
}
$temp=sys_get_temp_dir().'/ciro-formats-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
try {
    $config=new Zero\Config('123:fixture',[42],$temp); $store=new Zero\Store($temp); $api=new FormatApi(); $bot=new Zero\Bot($config,$store,$api);

    // Conversion of what browsers and phone extensions produce.
    $netscape=Zero\CookieFormats::toNetscape(igJson('JSON_SESSION'),'instagram.com');
    $rows=array_values(array_filter(explode("\n",$netscape)));
    formatCheck($rows[0]==='# Netscape HTTP Cookie File'&&$rows[1]==="#HttpOnly_.instagram.com\tTRUE\t/\tTRUE\t2147483647\tsessionid\tJSON_SESSION"&&$rows[2]==="www.instagram.com\tFALSE\t/\tTRUE\t0\tcsrftoken\tC1",'a JSON export becomes Netscape with HttpOnly, host-only and session cookies preserved');
    formatCheck(str_contains(Zero\CookieFormats::toNetscape('{"cookies":'.igJson('WRAPPED').'}'),'WRAPPED'),'a wrapped cookie list is accepted');
    $bare="#HttpOnly_.instagram.com\tTRUE\t/\tTRUE\t2147483647\tsessionid\tBARE\r\n.instagram.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tX\r\n";
    $fixed=Zero\CookieFormats::toNetscape($bare);
    formatCheck(str_starts_with($fixed,"# Netscape HTTP Cookie File\n")&&!str_contains($fixed,"\r"),'a Netscape export without its first line gets it, and Windows line endings are normalised');
    $valid="# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t2147483647\tsessionid\tOK\n";
    formatCheck(Zero\CookieFormats::toNetscape($valid)===$valid,'a valid Netscape file is returned unchanged');
    $header=Zero\CookieFormats::toNetscape('sessionid=HEAD123; csrftoken=abc; ds_user_id=77','instagram.com');
    formatCheck(substr_count($header,"\n.instagram.com\tTRUE\t/\tTRUE\t0\t")===3&&str_contains($header,"\tsessionid\tHEAD123"),'a header string becomes session cookies of the service domain');
    $rejected=0;
    foreach([['not cookies at all','instagram.com'],['[{"name":"a"}]','instagram.com'],['{"a":1}','instagram.com'],["a=b\nc=d",'instagram.com'],['a=b; c=d',''],[json_encode([['domain'=>'.instagram.com','name'=>"bad\tname",'value'=>'v']]),'instagram.com'],[json_encode([['domain'=>'.instagram.com','name'=>'a','value'=>"x\ny"]]),'instagram.com'],['[]','instagram.com']] as [$text,$domain]) {
        try { Zero\CookieFormats::toNetscape($text,$domain); } catch(RuntimeException) { $rejected++; }
    }
    formatCheck($rejected===8,'unrecognised, malformed or tab/newline-injecting exports are rejected');
    formatCheck(Zero\CookieFormats::looksLikeCookies(igJson('S'))&&Zero\CookieFormats::looksLikeCookies('sessionid=ABCDEFGH; csrftoken=zzzzzzzz')&&Zero\CookieFormats::looksLikeCookies($valid),'JSON, header strings and Netscape text are recognised as cookies');
    formatCheck(!Zero\CookieFormats::looksLikeCookies('سلام')&&!Zero\CookieFormats::looksLikeCookies('https://www.instagram.com/p/ABC_def12/')&&!Zero\CookieFormats::looksLikeCookies(str_repeat('a',50)),'ordinary chat messages and links are not mistaken for cookies');

    // The service's own rules still apply to converted exports.
    $instagram=Zero\Instagram::pool($config);
    $first=$instagram->add(igJson('POOL_SESSION'));
    formatCheck($first['status']==='added'&&str_contains((string)file_get_contents($config->instagramCookiePath()),"sessionid\tPOOL_SESSION"),'a JSON export is stored as a normal Netscape jar');
    $again=$instagram->add("# Netscape HTTP Cookie File\n#HttpOnly_.instagram.com\tTRUE\t/\tTRUE\t2147483647\tsessionid\tPOOL_SESSION\n.instagram.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tNEWER\n");
    formatCheck($again['status']==='replaced'&&$instagram->count()===1,'the same account in another format replaces its jar instead of duplicating it');
    $wrong=0;
    foreach([fn()=>$instagram->add(json_encode([['domain'=>'.tiktok.com','name'=>'sessionid','value'=>'T','path'=>'/','secure'=>true]])),fn()=>Zero\TikTok::pool($config)->add(igJson('X'))] as $try) {
        try { $try(); } catch(RuntimeException) { $wrong++; }
    }
    formatCheck($wrong===2&&Zero\TikTok::pool($config)->count()===0,'converted exports for another site are still refused by the service pool');

    // Telegram: pasted text and JSON files while an import waits.
    $send=fn(string $text,int $user=42,int $id=0)=>$bot->handle(['update_id'=>random_int(1,1000000),'message'=>['message_id'=>$id?:random_int(5000,9000),'from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]);
    $document=function(string $name,string $content,int $user=42) use($bot,$api): void { static $n=0; $id='f-'.++$n; $api->files[$id]=$content; $bot->handle(['message'=>['message_id'=>7000+$n,'from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'document'=>['file_id'=>$id,'file_name'=>$name,'file_size'=>strlen($content)]]]); };
    $pool=Zero\Twitter::pool($config);
    $send($bot->label('cookie_twitter')); $send($bot->label('x_cookie_upload'));
    $deletes=$api->count('deleteMessage');
    $send(json_encode([['domain'=>'.x.com','name'=>'auth_token','value'=>'XTOKEN','path'=>'/','secure'=>true,'httpOnly'=>true,'expirationDate'=>2147483647],['domain'=>'.x.com','name'=>'ct0','value'=>'CT0','path'=>'/','secure'=>true,'session'=>true]]));
    formatCheck($pool->count()===1&&$api->count('deleteMessage')===$deletes+1&&$store->query('SELECT key FROM states WHERE user_id=42')->fetchColumn()==='x_cookie.upload','a pasted JSON export is saved, the message with the credentials is deleted and the import stays open');
    $send('auth_token=SECOND; ct0=abcdef'); 
    formatCheck($pool->count()===2,'a pasted header string adds another account');
    $count=$pool->count(); $send('https://x.com/fixture/status/1234567890123456789'); $send('سلام، این یک پیام عادی است');
    formatCheck($pool->count()===$count&&$api->count('deleteMessage')===$deletes+2,'links and ordinary messages are not imported or deleted');
    $before=$pool->count(); $send(igJson('NOT_FOR_X'),7); $send('auth_token=OTHER; ct0=1',7);
    formatCheck($pool->count()===$before,'other users cannot paste cookies');
    // A long export arrives as several Telegram messages: the parts are joined, kept parts are deleted, and the import completes by itself.
    $cookies=[]; for($i=1;$i<=70;$i++) $cookies[]=['domain'=>'.x.com','name'=>$i===1?'auth_token':'extra'.$i,'value'=>($i===1?'SPLITTOKEN':'v').str_repeat('x',90),'path'=>'/','secure'=>true,'httpOnly'=>true,'expirationDate'=>2147483647];
    $long=json_encode($cookies,JSON_PRETTY_PRINT); $cut=[(int)(strlen($long)*0.4),(int)(strlen($long)*0.75)]; $count=$pool->count(); $deletes=$api->count('deleteMessage');
    $parts=[substr($long,0,$cut[0]),substr($long,$cut[0],$cut[1]-$cut[0]),substr($long,$cut[1])];
    $send($parts[0],42,8101);
    formatCheck($pool->count()===$count&&$api->count('deleteMessage')===$deletes+1&&str_contains((string)end($api->calls)[1]['text'],'بخش 1'),'the first part of a long paste is kept, its message deleted and the admin told to send the rest');
    $send($parts[1],42,8102); formatCheck($pool->count()===$count&&$api->count('deleteMessage')===$deletes+2,'the second part is kept too');
    $send($parts[2],42,8103);
    formatCheck($pool->count()===$count+1&&$api->count('deleteMessage')===$deletes+3,'the last part completes it: one account is imported and every part message is gone');
    $send('[{"name":"x"'.str_repeat('y',10).'",',42,8110); $send('{"broken":',42,8111); $send('رفتیم سراغ فایل',42,8112);
    formatCheck($pool->count()===$count+1,'broken parts never import anything');
    $store->query("DELETE FROM settings WHERE key LIKE 'cookie.parts.%'");
    // Only the tail of an export arrives (its first part was never sent): every whole cookie in it is kept, and the admin is told what is missing.
    $tail="\"value\": \"FAKE_TAIL_VALUE_FOR_TEST\",\n        \"domain\": \".x.com\",\n        \"path\": \"/\"\n    },\n    {\n        \"name\": \"auth_token\",\n        \"value\": \"TAILTOKEN\",\n        \"domain\": \".x.com\",\n        \"hostOnly\": false,\n        \"path\": \"/\",\n        \"secure\": true,\n        \"session\": false,\n        \"expirationDate\": 2147483647\n    }\n]";
    formatCheck(count(Zero\CookieFormats::salvage($tail))===1&&count(Zero\CookieFormats::salvage('[{"name":"a","domain":".x.com","value":"v"},{"name":"b","domain":".x.com","value":"}{\\"","x":{"y":1}},{"name":"c"'))===2,'whole cookie objects are found even when the start or end of the text is missing, braces inside values included');
    $count=$pool->count(); $send($tail,42,8120);
    $texts=array_values(array_filter(array_map(fn($call)=>(string)($call[1]['text']??''),$api->calls)));
    formatCheck($pool->count()===$count+1&&str_contains(end($texts),'فقط انتهای متن'),'a pasted tail is imported with its whole cookies and a warning that the beginning is missing');
    $send('"value": "orphan", "domain": ".x.com", "path": "/"',42,8121);
    formatCheck($pool->count()===$count+1,'a stray piece without a closing bracket or a whole cookie imports nothing');
    $document('phone.json',json_encode([['domain'=>'.x.com','name'=>'auth_token','value'=>'FROMJSONFILE','path'=>'/','secure'=>true,'expirationDate'=>2147483647]]));
    formatCheck($pool->count()===5,'a .json document is accepted');
    $zip=sys_get_temp_dir().'/ciro-fmt-'.bin2hex(random_bytes(4)); $z=new ZipArchive(); $z->open($zip,ZipArchive::CREATE); $z->addFromString('a.json',json_encode([['domain'=>'.x.com','name'=>'auth_token','value'=>'ZIPJSON','path'=>'/','secure'=>true,'expirationDate'=>2147483647]])); $z->addFromString('b.txt','auth_token=ZIPHEADER; ct0=1'); $z->close();
    $bytes=(string)file_get_contents($zip); unlink($zip); $document('mix.zip',$bytes);
    formatCheck($pool->count()===7,'a ZIP may mix JSON files and header strings');

    // YouTube: a pasted export is tested like a single file.
    $probe=$temp.'/probe'; $trace=$temp.'/probe-trace';
    file_put_contents($probe,"#!/usr/bin/env php\n<?php\nfile_put_contents(".var_export($trace,true).",'x',FILE_APPEND);echo ".var_export(json_encode(['title'=>'Public','formats'=>[['format_id'=>'18','width'=>640,'height'=>360,'vcodec'=>'avc1','acodec'=>'aac']]]),true).";");
    chmod($probe,0700); $config->ytDlp=$probe;
    $send($bot->label('cookies')); $send($bot->label('cookie_youtube')); $send($bot->label('cookie_upload'));
    $send(json_encode([['domain'=>'.youtube.com','name'=>'SID','value'=>'YT_SID','path'=>'/','secure'=>true,'expirationDate'=>2147483647],['domain'=>'.youtube.com','name'=>'HSID','value'=>'H','path'=>'/','secure'=>false,'session'=>true]]));
    formatCheck(is_file($trace)&&Zero\youtubeCookiePool($config)->count()===1,'a pasted YouTube export is converted, tested with the real downloader and saved');

    // The in-bot guide.
    $send($bot->label('cookies')); $send($bot->label('cookie_guide'));
    $text=end($api->calls)[1]['text']??'';
    formatCheck(str_contains($text,'docs/cookie-guide.md')&&str_contains($text,'Cookie-Editor')&&str_contains($text,'robots.txt'),'the cookie services menu offers a short guide for computer, Android and iPhone');
    $before=count($api->calls); $send($bot->label('cookie_guide'),7);
    formatCheck(!array_filter(array_slice($api->calls,$before),fn($c)=>str_contains((string)($c[1]['text']??''),'docs/cookie-guide.md')),'ordinary users cannot open the administrator cookie guide');
    // A YouTube export taken while logged out (only VISITOR_INFO1_LIVE, YSC, PREF) is converted but marked as a guest cookie.
    $guestJson=json_encode([['name'=>'VISITOR_INFO1_LIVE','value'=>'GUEST1','domain'=>'.youtube.com','hostOnly'=>false,'path'=>'/','secure'=>true,'httpOnly'=>true,'session'=>false,'expirationDate'=>2147483000],
        ['name'=>'YSC','value'=>'GUEST2','domain'=>'.youtube.com','hostOnly'=>false,'path'=>'/','secure'=>true,'httpOnly'=>true,'session'=>true]]);
    $youtubePool=Zero\youtubeCookiePool($config); $guest=$youtubePool->normalize($guestJson);
    $account=$guest."\n.youtube.com\tTRUE\t/\tTRUE\t2147483000\t__Secure-3PSID\tSIGNED_IN";
    formatCheck($youtubePool->isGuest($guest)&&!$youtubePool->isGuest($account),'a logged-out YouTube export is recognised as a guest cookie');
    $summary=Zero\CookieJars::ingestContent($guestJson,$youtubePool);
    formatCheck(in_array(['cookies','guest'],$summary['notes'],true)&&$summary['added']===1,'importing it still works, with a note that it has no account');
    $summary=Zero\CookieJars::ingestContent($account,$youtubePool);
    formatCheck(!in_array(['cookies','guest'],$summary['notes'],true),'an export with a signed-in account has no such note');
} finally { Zero\Download::clean($temp); }
