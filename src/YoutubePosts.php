<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryMedia.php';

/** Community images/text share gallery delivery; videos retain quality selection. */
final class YoutubePosts extends GalleryMedia
{
    protected const SERVICE='youtube', NAME='YouTube', PREFIX='ytp';
    public static function enabled(Store $store): bool { return $store->get('youtube.posts','0')==='1'; }
    public static function pool(Config $config): CookiePool { return youtubeCookiePool($config); }
    public static function validateCookies(string $content): void { validateYouTubeCookies($content); }
    public static function cookieIdentity(string $content): string { return youtubeCookieIdentity($content); }

    public function fetch(string $url,string $folder): array
    {
        $url=youtubePostUrl($url);
        if($url===null) throw new MediaFailure('format','Invalid YouTube post');
        $runner=new Download($this->config); $pool=self::pool($this->config); $jars=$pool->order(1);
        $attempts=$jars?($this->config->cookieFallback?[null,$jars[0]]:[$jars[0]]):[null];
        $post=null;
        foreach($attempts as $index=>$jar) {
            $command=[dirname($this->config->ytDlp).'/python',dirname(__DIR__).'/bin/youtube-post.py',
                '--directory',$folder,'--max-bytes',(string)$this->config->maxBytes];
            if($this->config->youtubeProxy!=='') array_push($command,'--proxy',$this->config->youtubeProxy);
            $private=$folder.'/youtube-post-cookies.txt';
            if($jar!==null) {
                $content=(string)file_get_contents($jar['path']); self::validateCookies($content);
                if(file_put_contents($private,$content)!==strlen($content)||!chmod($private,0600)) throw new MediaFailure('cookies','Cannot prepare private YouTube post session');
                array_push($command,'--cookies',$private);
            }
            foreach(glob($folder.'/ytp-*.image')?:[] as $stale) unlink($stale);
            try { $output=$runner->run([...$command,'--',$url],$folder,$this->config->timeout,$this->config->maxBytes*21,$this->config->maxBytes); }
            catch(MediaFailure $error) {
                $reason=preg_match('/YOUTUBE_POST_ERROR (blocked|cookies|restricted|format|size|network)\b/',$error->getMessage(),$match)?$match[1]:$error->reason;
                // A post refusal must not invalidate the cookies used by video downloads.
                // In fallback mode only one anonymous auth refusal may try one jar; no rate-limit fanout.
                if($reason==='cookies'&&$jar===null&&isset($attempts[$index+1])) continue;
                throw new MediaFailure($reason,'YouTube post request failed');
            } finally { if(is_file($private)) unlink($private); }
            $post=json_decode($output,true,512,JSON_THROW_ON_ERROR);
            if($jar!==null) $pool->markWorking($jar['id']);
            break;
        }
        if(!is_array($post)||!is_string($post['description']??null)||!is_string($post['author']??null)||!is_array($post['files']??null)||count($post['files'])>self::MAX_ITEMS) throw new MediaFailure('format','Invalid YouTube post manifest');
        $count=count($post['files']); $result=[];
        foreach($post['files'] as $index=>$name) {
            if($name!=='ytp-'.($index+1).'.image'||!is_file($folder.'/'.$name)||is_link($folder.'/'.$name)) throw new MediaFailure('format','Incomplete YouTube post images');
            $source=$folder.'/'.$name;
            if(@getimagesize($source)===false) throw new MediaFailure('format','Invalid YouTube post image');
            $work=$folder.'/item-'.($index+1); mkdir($work,0700);
            $result[]=$this->prepare($source,$work,$runner)+['index'=>$index+1,'count'=>$count,'description'=>$post['description'],'author'=>$post['author']];
        }
        if(!$count) return [['type'=>'text','description'=>$post['description'],'author'=>$post['author']]];
        return $result;
    }
}
