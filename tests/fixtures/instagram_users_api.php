<?php
declare(strict_types=1);
$root=(string)getenv('FIXTURE_ROOT'); $method=basename((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
$params=$_POST?:json_decode(file_get_contents('php://input'),true)?:[];
$mode=json_decode((string)file_get_contents($root.'/mode.json'),true);
$record=['method'=>$method,'params'=>$params,'files'=>array_map(fn($f)=>['bytes'=>$f['size'],'data'=>base64_encode((string)file_get_contents($f['tmp_name']))],$_FILES)];
file_put_contents($root.'/calls.jsonl',json_encode($record,JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND);
header('Content-Type: application/json');
if($method==='editMessageMedia'&&!empty($mode['reject_photo'])) { http_response_code(400); echo json_encode(['ok'=>false,'error_code'=>400,'description'=>'Fixture rejected picture']); return; }
$id=isset($params['message_id'])?(int)$params['message_id']:500+count(file($root.'/calls.jsonl'));
$result=true;
if($method==='getMe') $result=['id'=>123,'username'=>'FixtureBot'];
elseif(in_array($method,['sendMessage','editMessageText','editMessageCaption','editMessageMedia','sendPhoto'],true)) {
    $result=['message_id'=>$id];
    if($method==='editMessageMedia'||$method==='sendPhoto') {
        $media=isset($params['media'])?json_decode($params['media'],true):[];
        $result['photo']=[['file_id'=>!empty($_FILES)?'confirmed-avatar-'.$id:(string)($media['media']??$params['photo']??'')]];
        $result['caption']=$media['caption']??$params['caption']??'';
    }
}
echo json_encode(['ok'=>true,'result'=>$result]);
