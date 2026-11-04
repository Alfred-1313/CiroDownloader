<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
$config=new Zero\Config('123:fake-token',[42],sys_get_temp_dir(),'http://ciro-telegram-api:8081',1990000000);
$api=new Zero\Telegram($config);
if($api->downloadFile('/telegram-api/test/cookies.txt',100)!=='private-test-data') throw new RuntimeException('Local file import failed');
foreach([['/telegram-api/test/outside',1000000],['/telegram-api/../etc/passwd',1000000],['/telegram-api/test/cookies.txt',4]] as [$path,$limit]) {
    $rejected=false;
    try { $api->downloadFile($path,$limit); } catch(RuntimeException) { $rejected=true; }
    if(!$rejected) throw new RuntimeException('Unsafe or oversized local file was accepted');
}
echo "PASS local API files can be imported only within the shared directory and size limit\n";

