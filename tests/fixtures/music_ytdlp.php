<?php
declare(strict_types=1);
// Stand-in for yt-dlp in the music tests, included by a small generated script that defines FIXTURE_ROOT
// (the downloader runs with a bare environment). It records every call in yt.jsonl.
$args=array_slice($argv,1); $target=end($args);
file_put_contents(FIXTURE_ROOT.'/yt.jsonl',json_encode($args).PHP_EOL,FILE_APPEND);
$mode=json_decode((string)@file_get_contents(FIXTURE_ROOT.'/mode.json'),true)?:[];
if(str_starts_with($target,'ytsearch')) {
    $text=substr($target,(int)strpos($target,':')+1);
    // Pool test: a "Slow" track holds its slot for a while; start and end times show how tracks overlap.
    // A named slow track keeps its search busy, so the progress can be seen while it waits.
    if(!empty($mode['slow'])&&str_contains($text,(string)$mode['slow'])) usleep(4500000);
    if(!empty($mode['pool'])) {
        $started=microtime(true); if(str_contains($text,'Slow')) usleep(2500000);
        file_put_contents(FIXTURE_ROOT.'/pool.jsonl',json_encode(['name'=>$text,'start'=>$started,'end'=>microtime(true)]).PHP_EOL,FILE_APPEND);
    } $name=preg_replace('/ audio$/','',$text);
    echo json_encode(['entries'=>str_contains($text,'Missing')||($mode['youtube']??'ok')==='empty'?[]:[['id'=>'goodMatch01','title'=>$name.' (Official Audio)','duration'=>12.0,'channel'=>'Fixture Artist']]]);
    exit(0);
}
if(str_starts_with($target,'scsearch')) { echo json_encode(['entries'=>[]]); exit(0); }
if(in_array('--flat-playlist',$args,true)) {
    $path=(string)parse_url($target,PHP_URL_PATH);
    if(str_contains($path,'/sets/')) {
        echo json_encode(['title'=>'Mixtape','entries'=>[['id'=>'1','url'=>'https://soundcloud.com/fixture-artist/first-cut','title'=>'First Cut'],['id'=>'2','url'=>'https://soundcloud.com/fixture-artist/second-cut-1'],
            ['id'=>'3','url'=>'https://api-v2.soundcloud.com/tracks/333']]]);
        exit(0);
    }
    if(str_ends_with($path,'/albums')||str_ends_with($path,'/sets')) { echo json_encode(['title'=>'fixture-artist (Albums)','entries'=>[['id'=>'9','url'=>'https://soundcloud.com/fixture-artist/sets/mixtape','title'=>'Mixtape']]]); exit(0); }
    echo json_encode(['title'=>'Fixture Artist (Tracks)','entries'=>[['id'=>'5','url'=>'https://soundcloud.com/fixture-artist/fixture-song','title'=>'Fixture Song'],['id'=>'6','url'=>'https://soundcloud.com/fixture-artist/other-song','title'=>'Other Song']]]);
    exit(0);
}
$template=$args[array_search('-o',$args,true)+1];
copy(FIXTURE_ROOT.'/source.m4a',str_replace('%(ext)s','m4a',$template));
if(in_array('--write-info-json',$args,true)) file_put_contents(str_replace('%(ext)s','info.json',$template),json_encode(['title'=>'Fixture Song','artists'=>['Fixture Artist'],'duration'=>12.4,'timestamp'=>1556841600,'webpage_url'=>'https://soundcloud.com/fixture-artist/fixture-song']));
echo json_encode('Fixture Artist - Fixture Song').PHP_EOL;
