<?php
declare(strict_types=1);
require __DIR__.'/instagram_telegram.php';
$root=(string)getenv('FIXTURE_ROOT'); $path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(in_array($path,['/photo.jpg','/clip.mp4'],true)) { header('Content-Type: '.($path==='/photo.jpg'?'image/jpeg':'video/mp4')); readfile($root.$path); return; }
$method=basename($path); $params=$_POST?:json_decode(file_get_contents('php://input'),true)?:[]; $uploads=$_FILES;
$record=['method'=>$method,'params'=>$params,'uploads'=>array_map(fn($file)=>['name'=>$file['name'],'bytes'=>$file['size']],$uploads)];
file_put_contents($root.'/http.jsonl',json_encode($record,JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND);
$id=500+count(file($root.'/http.jsonl')); $result=true;
if($method==='getMe') $result=['id'=>123,'username'=>'FixtureBot'];
elseif(($media=instagramTelegramFixture($method,$params,$uploads,$root,$id))!==null) $result=$media;
elseif($method==='sendMessage') $result=['message_id'=>$id];
header('Content-Type: application/json'); echo json_encode(['ok'=>true,'result'=>$result]);
