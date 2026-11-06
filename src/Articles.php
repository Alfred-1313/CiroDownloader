<?php
declare(strict_types=1);
namespace Zero;

final class Articles
{
    private int $lastHttpStatus=0;
    public function __construct(private Config $config,private string $telegraph='https://api.telegra.ph',private string $mediaHost='https://catbox.moe/user/api.php',private ?string $fallbackMediaHost='https://qu.ax/upload.php') {}
    private function request(string $provider,string $operation,string $url,array $params,bool $json=true): mixed
    {
        $context='Article provider='.$provider.' operation='.$operation;
        $this->lastHttpStatus=0;
        $handle=curl_init($url);
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$params,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>600,CURLOPT_FOLLOWLOCATION=>false,
            // Catbox can negotiate HTTP/2 and then reset its stream (curl 92).
            // Select HTTP/1.1 before sending a file; never retry an uncertain POST.
            CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_USERAGENT=>'curl/'.curl_version()['version'],
            CURLOPT_HTTPHEADER=>['Expect:']]);
        try { $body=curl_exec($handle); $status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE); $error=curl_errno($handle); }
        finally { curl_close($handle); }
        $this->lastHttpStatus=(int)$status;
        if($body===false||$error!==0) throw new MediaFailure('article',$context.' transport failed: HTTP='.$status.' curl='.$error.' '.curl_strerror($error));
        $data=$json?json_decode($body,true):null;
        $apiError=is_array($data)&&is_string($data['error']??null)&&preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D',$data['error'])?' api='.$data['error']:'';
        if($status<200||$status>=300) throw new MediaFailure('article',$context.' rejected request: HTTP='.$status.$apiError);
        if(!$json) return trim($body);
        if(!is_array($data)||empty($data['ok'])||!is_array($data['result']??null)) throw new MediaFailure('article',$context.' did not confirm operation: HTTP='.$status.$apiError);
        return $data['result'];
    }
    private function upload(array $item,bool &$fallback,callable $authorized): string
    {
        $video=$item['type']==='video';
        $file=new \CURLFile($item['path'],$video?'video/mp4':'image/jpeg',$video?'video.mp4':'photo.jpg');
        if(!$fallback) {
            try {
                $url=$this->request('catbox','upload',$this->mediaHost,['reqtype'=>'fileupload','fileToUpload'=>$file],false);
                if(!preg_match('~^https://files\.catbox\.moe/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|mp4)$~D',$url)) throw new MediaFailure('article','Article provider=catbox operation=upload returned an unsupported media URL');
                return $url;
            } catch(MediaFailure $failure) {
                // An explicit rejection confirms this upload was not accepted.
                // Transport failures and 5xx responses must never trigger a retry.
                if($this->lastHttpStatus!==412||$this->fallbackMediaHost===null) throw $failure;
                $fallback=true;
                error_log('Article provider=catbox operation=upload HTTP=412 fallback=quax');
            }
        }
        if(!$authorized()) throw new MediaFailure('restricted','Article publication no longer authorized');
        $body=$this->request('quax','upload',(string)$this->fallbackMediaHost,['files[]'=>$file],false);
        $data=json_decode($body,true);
        $url=$data['files'][0]['url']??null;
        if(($data['success']??null)!==true||!is_array($data['files']??null)||count($data['files'])!==1
            ||!is_string($url)||!preg_match('~^https://qu\.ax/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|mp4)$~D',$url)) throw new MediaFailure('article','Article provider=quax operation=upload did not return a confirmed media URL');
        return $url;
    }
    private function token(): string
    {
        $path=$this->config->storage.'/telegraph-account.json';
        $lock=fopen($path.'.lock','c');
        if(!$lock) throw new MediaFailure('storage','Cannot lock article account');
        chmod($path.'.lock',0600); $temporary=$path.'.'.bin2hex(random_bytes(6));
        try {
            if(!flock($lock,LOCK_EX)) throw new MediaFailure('storage','Cannot lock article account');
            clearstatcache(true,$path);
            if(is_file($path)) $account=json_decode((string)file_get_contents($path),true);
            else {
                $account=$this->request('telegraph','createAccount',$this->telegraph.'/createAccount',['short_name'=>'CiroDownloader']);
                if(!is_string($account['access_token']??null)||!preg_match('/^[A-Za-z0-9_-]{20,512}$/D',$account['access_token'])) throw new MediaFailure('article','Article account token is missing');
                if(file_put_contents($temporary,json_encode(['access_token'=>$account['access_token']],JSON_THROW_ON_ERROR))===false||!chmod($temporary,0600)||!rename($temporary,$path)) throw new MediaFailure('storage','Cannot save article account');
            }
            if(!is_string($account['access_token']??null)||!preg_match('/^[A-Za-z0-9_-]{20,512}$/D',$account['access_token'])) throw new MediaFailure('article','Saved article account is invalid');
            return $account['access_token'];
        } finally { if(is_file($temporary)) unlink($temporary); flock($lock,LOCK_UN); fclose($lock); }
    }
    public function publish(array $items,string $source,Ui $ui,callable $authorized): string
    {
        $source=instagramUrl($source);
        if(!$source||count($items)<2||count($items)>Instagram::MAX_ITEMS) throw new MediaFailure('format','Invalid article source or slide count');
        if(!$authorized()) throw new MediaFailure('restricted','Article publication no longer authorized');
        $token=$this->token(); $nodes=[]; $fallback=false;
        $description=mb_substr((string)($items[0]['description']??''),0,10000);
        foreach(preg_split('/\r?\n/',$description) as $paragraph) if(trim($paragraph)!=='') $nodes[]=['tag'=>'p','children'=>[$paragraph]];
        foreach($items as $item) {
            if(!$authorized()) throw new MediaFailure('restricted','Article publication no longer authorized');
            if(!is_file($item['path'])||filesize($item['path'])<1||filesize($item['path'])>min($this->config->maxBytes,200000000)) throw new MediaFailure('size','Article media exceeds hosting size limit');
            $video=$item['type']==='video';
            $url=$this->upload($item,$fallback,$authorized);
            $label=html_entity_decode(strip_tags($ui->text('instagram_article_slide',['index'=>$item['index'],'count'=>count($items)])),ENT_QUOTES|ENT_HTML5,'UTF-8');
            $nodes[]=['tag'=>'figure','children'=>[
                ['tag'=>$video?'video':'img','attrs'=>['src'=>$url]],['tag'=>'figcaption','children'=>[$label]],
            ]];
        }
        $nodes[]=['tag'=>'p','children'=>[['tag'=>'a','attrs'=>['href'=>$source],'children'=>[$ui->label('source_instagram')]]]];
        $content=json_encode($nodes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(strlen($content)>64000) throw new MediaFailure('format','Article content is too long');
        if(!$authorized()) throw new MediaFailure('restricted','Article publication no longer authorized');
        $page=$this->request('telegraph','createPage',$this->telegraph.'/createPage',['access_token'=>$token,'title'=>mb_substr($ui->plain('instagram_article_title'),0,256),
            'author_name'=>mb_substr($ui->plain('instagram_article_author'),0,128),'content'=>$content,'return_content'=>'false']);
        $url=$page['url']??'';
        if(!is_string($url)||!preg_match('~^https://telegra\.ph/[^\s<>?#]+$~uD',$url)) throw new MediaFailure('article','Article service did not return a confirmed page URL');
        return $url;
    }
}
