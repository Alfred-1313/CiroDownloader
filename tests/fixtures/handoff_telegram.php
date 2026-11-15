<?php
declare(strict_types=1);
$method=basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
$record=['method'=>$method,'chat'=>$_POST['chat_id']??'', 'message'=>$_POST['message_id']??'', 'spoiler'=>$_POST['has_spoiler']??'', 'caption'=>$_POST['caption']??'', 'bytes'=>isset($_FILES['video'])?(int)$_FILES['video']['size']:0];
if($method!=='getUpdates') file_put_contents(getenv('HANDOFF_TEST_LOG'),json_encode($record)."\n",FILE_APPEND|LOCK_EX);
if($method==='getUpdates') {
    usleep(100000);
    $result=(int)($_POST['offset']??0)<=5?[json_decode((string)file_get_contents(getenv('HANDOFF_TEST_UPDATE')),true)]:[];
} elseif($method==='getMe') $result=['id'=>456,'is_bot'=>true,'username'=>'fixture_bot'];
elseif($method==='getWebhookInfo') $result=['url'=>''];
elseif($method==='sendVideo') $result=['message_id'=>901,'caption'=>$_POST['caption']??'','has_media_spoiler'=>true];
elseif(str_starts_with($method,'send')||str_starts_with($method,'edit')) $result=['message_id'=>(int)($_POST['message_id']??800)];
else $result=true;
header('Content-Type: application/json'); echo json_encode(['ok'=>true,'result'=>$result]);
