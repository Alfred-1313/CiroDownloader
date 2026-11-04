<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';

$temp=sys_get_temp_dir().'/ciro-large-'.bin2hex(random_bytes(6)); mkdir($temp,0700);
$server=null;
try {
    $source=$temp.'/large.mp4';
    $handle=fopen($source,'wb');
    if(!$handle||!ftruncate($handle,51000000)) throw new RuntimeException('Cannot create large upload fixture');
    fclose($handle);
    $router=$temp.'/router.php';
    file_put_contents($router,'<?php $file=$_FILES["video"]??null; $ok=$file&&$file["error"]===UPLOAD_ERR_OK&&$file["size"]===51000000; header("Content-Type: application/json"); echo json_encode(["ok"=>$ok,"result"=>["message_id"=>42],"error_code"=>400]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if(!$socket) throw new RuntimeException('Cannot allocate a test port');
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $server=proc_open([PHP_BINARY,'-d','upload_max_filesize=60M','-d','post_max_size=60M','-S',$address,$router],
        [0=>['file','/dev/null','r'],1=>['file',$temp.'/server.log','w'],2=>['file',$temp.'/error.log','w']],$pipes);
    for($i=0;$i<100;$i++) {
        $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);
        if($connection) { fclose($connection); break; }
        usleep(50000);
    }
    $api=new Zero\Telegram(new Zero\Config('123:fake-token',[42],$temp,'http://'.$address,100000000));
    $result=$api->call('sendVideo',['chat_id'=>42,'video'=>new CURLFile($source,'video/mp4','video.mp4')]);
    if(($result['message_id']??0)!==42) throw new RuntimeException('The complete large upload was not received');
    echo "PASS 51 MB multipart upload reaches the configured local API endpoint\n";
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach(glob($temp.'/*')?:[] as $file) unlink($file);
    rmdir($temp);
}

