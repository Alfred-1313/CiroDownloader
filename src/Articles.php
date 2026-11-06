<?php
declare(strict_types=1);
namespace Zero;

final class Articles
{
    public function __construct(private Config $config,private string $telegraph='https://api.telegra.ph',private string $mediaHost='https://catbox.moe/user/api.php') {}
    private function request(string $url,array $params,bool $json=true): mixed
    {
        $handle=curl_init($url);
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$params,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>600,CURLOPT_FOLLOWLOCATION=>false]);
        try { $body=curl_exec($handle); $status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE); }
        finally { curl_close($handle); }
        if($body===false||$status<200||$status>=300) throw new MediaFailure('article','Article service transport failed with HTTP '.$status);
        if(!$json) return trim($body);
        $data=json_decode($body,true);
        if(!is_array($data)||empty($data['ok'])||!is_array($data['result']??null)) throw new MediaFailure('article','Article service did not confirm the operation');
        return $data['result'];
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
                $account=$this->request($this->telegraph.'/createAccount',['short_name'=>'CiroDownloader']);
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
        $token=$this->token(); $nodes=[];
        $description=mb_substr((string)($items[0]['description']??''),0,10000);
        foreach(preg_split('/\r?\n/',$description) as $paragraph) if(trim($paragraph)!=='') $nodes[]=['tag'=>'p','children'=>[$paragraph]];
        foreach($items as $item) {
            if(!$authorized()) throw new MediaFailure('restricted','Article publication no longer authorized');
            if(!is_file($item['path'])||filesize($item['path'])<1||filesize($item['path'])>min($this->config->maxBytes,200000000)) throw new MediaFailure('size','Article media exceeds hosting size limit');
            $video=$item['type']==='video';
            $url=$this->request($this->mediaHost,['reqtype'=>'fileupload','fileToUpload'=>new \CURLFile($item['path'],$video?'video/mp4':'image/jpeg',$video?'video.mp4':'photo.jpg')],false);
            // Never place an arbitrary service response or a credential URL in a page.
            if(!is_string($url)||!preg_match('~^https://files\.catbox\.moe/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|mp4)$~D',$url)) throw new MediaFailure('article','Media host did not return a supported public file URL');
            $label=html_entity_decode(strip_tags($ui->text('instagram_article_slide',['index'=>$item['index'],'count'=>count($items)])),ENT_QUOTES|ENT_HTML5,'UTF-8');
            $nodes[]=['tag'=>'figure','children'=>[
                ['tag'=>$video?'video':'img','attrs'=>['src'=>$url]],['tag'=>'figcaption','children'=>[$label]],
            ]];
        }
        $nodes[]=['tag'=>'p','children'=>[['tag'=>'a','attrs'=>['href'=>$source],'children'=>[$ui->label('source_instagram')]]]];
        $content=json_encode($nodes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(strlen($content)>64000) throw new MediaFailure('format','Article content is too long');
        if(!$authorized()) throw new MediaFailure('restricted','Article publication no longer authorized');
        $page=$this->request($this->telegraph.'/createPage',['access_token'=>$token,'title'=>mb_substr($ui->plain('instagram_article_title'),0,256),
            'author_name'=>mb_substr($ui->plain('instagram_article_author'),0,128),'content'=>$content,'return_content'=>'false']);
        $url=$page['url']??'';
        if(!is_string($url)||!preg_match('~^https://telegra\.ph/[^\s<>?#]+$~uD',$url)) throw new MediaFailure('article','Article service did not return a confirmed page URL');
        return $url;
    }
}
