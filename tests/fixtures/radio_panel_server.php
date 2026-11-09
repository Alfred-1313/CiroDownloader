<?php
declare(strict_types=1);
$root=(string)getenv('RADIO_PANEL_FIXTURE');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_contains($path,'/file/bot')&&str_ends_with($path,'/photos/preview.jpg')) { header('Content-Type: image/jpeg'); readfile($root.'/preview.jpg'); return; }
$method=basename($path); $params=$_POST;
$entry=['method'=>$method,'params'=>$params,'at'=>microtime(true)];
foreach($_FILES as $key=>$file) $entry['files'][$key]=hash_file('sha256',$file['tmp_name']);
file_put_contents($root.'/calls.jsonl',json_encode($entry,JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);
$mode=json_decode((string)file_get_contents($root.'/mode.json'),true)??[];
if($method==='editMessageCaption'&&!empty($mode['delay_edit'])) usleep((int)$mode['delay_edit']*1000000);
header('Content-Type: application/json');
if(str_starts_with($method,'editMessage')&&in_array((int)($params['message_id']??0),$mode['missing_messages']??[],true)) { echo json_encode(['ok'=>false,'error_code'=>400,'description'=>'Bad Request: message to edit not found']); return; }
if(!empty($mode['reject_photo'])&&in_array($method,['sendPhoto','editMessageMedia'],true)) { echo json_encode(['ok'=>false,'error_code'=>(int)($mode['photo_error_code']??400),'description'=>'Rejected fixture photo']); return; }
if($method==='getFile') $result=['file_path'=>'photos/preview.jpg','file_size'=>filesize($root.'/preview.jpg')];
elseif($method==='getChatMember') $result=['status'=>(int)$params['user_id']===55?'administrator':'member'];
elseif(str_starts_with($method,'send')||str_starts_with($method,'edit')) {
    $counter=$root.'/next'; $next=is_file($counter)?(int)file_get_contents($counter):900;
    $id=(int)($params['message_id']??++$next); file_put_contents($counter,(string)$next);
    $result=['message_id'=>$id,'photo'=>[['file_id'=>'PHOTO_'.$id]]];
} elseif($method==='getMe') $result=['id'=>123,'username'=>'RadioFixtureBot','is_bot'=>true,'supports_inline_queries'=>false];
else $result=true;
echo json_encode(['ok'=>true,'result'=>$result]);
