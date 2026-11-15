<?php
declare(strict_types=1);
// Runs through the real process boundary; never sends an external request.
$args=[];
for($i=2;$i<count($argv);$i+=2) $args[$argv[$i]]=$argv[$i+1];
$request=json_decode((string)file_get_contents($args['--request']),true);
$cookies=(string)file_get_contents($args['--cookies']);
$record=['request_private'=>(fileperms($args['--request'])&0777)===0600,'cookies_private'=>(fileperms($args['--cookies'])&0777)===0600,
    'proxy'=>($args['--proxy']??'')==='http://proxy.fixture:8080','input'=>$request['op']??''];
file_put_contents(dirname($args['--request']).'/runtime-calls.jsonl',json_encode($record)."\n",FILE_APPEND);
if(str_contains($cookies,'refused_fixture_session')) echo '{"error":"cookies"}';
elseif(($request['username']??$request['query']??'')==='blocked') echo '{"error":"blocked","stage":"profile","http":429}';
elseif(($request['username']??$request['query']??'')==='unavailable') echo '{"error":"unavailable","stage":"search","http":404}';
else echo '{"items":[]}';
