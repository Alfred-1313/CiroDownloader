<?php
declare(strict_types=1);
$root=(string)getenv('FIXTURE_ROOT'); $path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$mode=json_decode((string)file_get_contents($root.'/mode.json'),true);
if($path==='/post-anonymous') {
    if(($mode['anonymous_response']??'')==='forbidden') http_response_code(403);
    header('Content-Type: text/html');
    echo ($mode['anonymous_response']??'')==='no_data'?'<html><body>Sign in</body></html>':'<script>var ytInitialData = {"contents":[]};</script>';
    return;
}
if($path==='/post') {
    http_response_code((int)($mode['http']??200));
    $id='UgkxcNOh_kIeoLnJzcsUSE6xIg1-fz_PrvBY';
    $post=['postId'=>!empty($mode['wrong_id'])?'UgAAAAAAAAAAAA':$id,'authorText'=>['runs'=>[['text'=>'Fixture channel']]],
        'contentText'=>['runs'=>[['text'=>$mode['text']??'متن پست <b>بدون تفسیر HTML</b> & نمونه']]]];
    $images=[];
    for($i=1;$i<=(int)($mode['images']??1);$i++) $images[]=['backstageImageRenderer'=>['image'=>['thumbnails'=>[
        ['url'=>'https://yt3.ggpht.com/image-'.$i,'width'=>1200,'height'=>1600]]]]];
    if(count($images)===1) $post['backstageAttachment']=$images[0];
    elseif($images) $post['backstageAttachment']=['postMultiImageRenderer'=>['images'=>$images]];
    if(!empty($mode['video'])) $post['backstageAttachment']=['videoRenderer'=>['videoId'=>'ybNMMK5Q318']];
    $other=['backstagePostRenderer'=>['postId'=>'Ug_OTHER_POST','contentText'=>['simpleText'=>'NEIGHBOUR MUST NOT LEAK']]];
    header('Content-Type: text/html');
    echo '<script>var ytInitialData = '.json_encode(['contents'=>[$other,['backstagePostRenderer'=>$post]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).';</script>';
    return;
}
if($path==='/bad-image') { http_response_code((int)($mode['image_http']??503)); echo 'not media'; return; }
require __DIR__.'/instagram_stories_api.php';
