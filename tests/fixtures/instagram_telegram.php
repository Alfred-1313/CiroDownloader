<?php
declare(strict_types=1);

// A local Telegram fixture keeps message identity, media type and file IDs.
function instagramTelegramFixture(string $method,array $params,array $uploads,string $root,int $newId): ?array
{
    if(!in_array($method,['sendPhoto','sendVideo','editMessageMedia'],true)) return null;
    $path=$root.'/telegram-media.json';
    $state=is_file($path)?json_decode((string)file_get_contents($path),true):['messages'=>[],'files'=>[]];
    $editing=$method==='editMessageMedia';
    $media=$editing?json_decode($params['media']??'',true):['type'=>$method==='sendPhoto'?'photo':'video','caption'=>$params['caption']??''];
    if(!is_array($media)||!in_array($media['type']??'', ['photo','video'],true)) throw new RuntimeException('Invalid input media');
    $id=$editing?(int)$params['message_id']:$newId; $key=$params['chat_id'].'.'.$id;
    if($editing&&(!isset($state['messages'][$key])||isset($params['message_thread_id']))) throw new RuntimeException('Invalid edit destination');
    $reference=$editing?$media['media']:'attach://'.$media['type'];
    if(str_starts_with($reference,'attach://')) {
        $file=$uploads[substr($reference,9)]??null;
        if(!$file||!is_file($file['tmp_name'])||$file['type']!==($media['type']==='photo'?'image/jpeg':'video/mp4')) throw new RuntimeException('Multipart media attachment is missing or mismatched');
        $fileId='fixture_'.$media['type'].'_'.hash_file('sha256',$file['tmp_name']);
        $state['files'][$fileId]=$media['type'];
        if($media['type']==='video') copy($file['tmp_name'],$root.'/delivered.mp4');
    } else {
        if(($state['files'][$reference]??null)!==$media['type']) throw new RuntimeException('Unknown Telegram file ID');
        $fileId=$reference;
    }
    $thread=$editing?$state['messages'][$key]['thread']:(int)($params['message_thread_id']??0);
    $state['messages'][$key]=['type'=>$media['type'],'file_id'=>$fileId,'thread'=>$thread,'caption'=>$media['caption']];
    file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));
    return ['message_id'=>$id,'message_thread_id'=>$thread]+($media['type']==='photo'?['photo'=>[['file_id'=>$fileId]]]:['video'=>['file_id'=>$fileId]]);
}
