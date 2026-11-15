<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/Instagram.php';

/** Cookie-pool and subprocess boundary for Instagram's public account metadata. */
final class InstagramAccounts
{
    public function __construct(private Config $config,private ?\Closure $fixture=null) {}
    /** Only fixed stages and a numeric HTTP code may reach a private administrator alert. */
    public static function diagnostic(array $data): string
    {
        $stage=in_array($data['stage']??'', ['search','profile','avatar','runtime'],true)?$data['stage']:'runtime';
        $http=$data['http']??0; $http=is_int($http)&&$http>=100&&$http<=599?$http:0;
        return 'instagram='.$stage.', http='.$http;
    }
    public function fetch(array $request,string $folder): array
    {
        if($this->fixture!==null) return ($this->fixture)($request,$folder);
        $pool=Instagram::pool($this->config); $jars=$pool->order(3);
        // Account search needs a signed-in session even when post downloads try anonymously first.
        if(!$jars) throw new MediaFailure('cookies','Instagram account session required');
        $python=dirname($this->config->galleryDl).'/python';
        if(!is_executable($python)) throw new MediaFailure('process','Instagram account runtime unavailable');
        $input=$folder.'/instagram-account-request.json';
        $json=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(strlen($json)>65536||file_put_contents($input,$json)!==strlen($json)||!chmod($input,0600)) throw new MediaFailure('storage','Cannot prepare Instagram account request');
        $failed=[]; $failure=new MediaFailure('cookies','Instagram account session unavailable');
        foreach($jars as $jar) {
            $cookies=$pool->content($jar['id']);
            try { Instagram::validateCookies($cookies??''); }
            catch(\RuntimeException) { $pool->markFailed($jar['id']); continue; }
            $private=$folder.'/instagram-account-cookies.txt';
            if(file_put_contents($private,$cookies)!==strlen($cookies)||!chmod($private,0600)) throw new MediaFailure('storage','Cannot prepare Instagram account session');
            $command=[$python,dirname(__DIR__).'/bin/instagram-users.py','--request',$input,'--cookies',$private];
            if($this->config->galleryProxy!=='') array_push($command,'--proxy',$this->config->galleryProxy);
            try {
                $output=(new Download($this->config))->run($command,$folder,180,16*1024*1024,8*1024*1024);
                $result=json_decode($output,true,32,JSON_THROW_ON_ERROR);
            } catch(\Throwable) { throw new MediaFailure('network','Instagram account transport unavailable'); }
            finally { if(is_file($private)) unlink($private); }
            if(!is_array($result)) throw new MediaFailure('format','Instagram account response unavailable');
            if(isset($result['error'])) {
                $reason=in_array($result['error'],['cookies','blocked','network','notfound','unavailable','format'],true)?$result['error']:'format';
                $failure=new MediaFailure($reason,'Instagram account request refused ['.self::diagnostic($result).']');
                if($reason==='cookies') { $failed[]=$jar['id']; continue; }
                throw $failure;
            }
            $pool->markWorking($jar['id']); foreach($failed as $id) $pool->markFailed($id);
            return $result;
        }
        throw $failure;
    }
}
