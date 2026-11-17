<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/InstagramChecker.php';
require_once __DIR__.'/YoutubeCheckerMedia.php';

/** YouTube adapter for the shared subscription, quota, baseline and delivery machinery. */
final class YoutubeChecker extends InstagramChecker
{
    protected const SERVICE='youtube', TABLE='yt_check_', DEFAULT_ENABLED='0', ALL=7, UPDATE_BASE=5000000000000000;
    protected const BITS=['video'=>1,'short'=>2,'post'=>4];
    protected const READY=['video'=>'posts_ready','short'=>'stories_ready','post'=>'extra_ready'];
    protected const SENDABLE=['queued','offered'];
    public static function avatarUrl(mixed $url): bool
    {
        if(!is_string($url)||strlen($url)>2048||preg_match('/[\x00-\x20\x7f]/',$url)) return false;
        $p=parse_url($url);
        return $p&&($p['scheme']??'')==='https'&&in_array($p['host']??'',['yt3.ggpht.com','yt3.googleusercontent.com'],true)
            &&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['port']);
    }
    protected function readProfile(array $target): array { return $this->readChannel($target,null); }
    protected function validateProfileIdentity(array $target,mixed $account): void { $this->validateIdentity($target,$account); }
    public function normalizeName(string $name): ?string
    {
        $name=trim($name);
        if(preg_match('~^(?:https?://)?(?:www\.|m\.)?youtube\.com/~i',$name)) {
            if(!str_starts_with($name,'http')) $name='https://'.$name;
            $p=parse_url($name);
            if(!$p||!in_array($p['host']??'',['youtube.com','www.youtube.com','m.youtube.com'],true)||isset($p['user'])||isset($p['pass'])||isset($p['port'])) return null;
            $name=rawurldecode(trim($p['path']??'','/'));
            if(str_starts_with($name,'channel/')) $name=substr($name,8);
            elseif(!str_starts_with($name,'@')) return null;
        }
        if(preg_match('/^UC[A-Za-z0-9_-]{22}$/D',$name)) return $name;
        if(str_starts_with($name,'@')) $name=substr($name,1);
        return preg_match('/^[\p{L}\p{N}_.-]{3,30}$/uD',$name)?'@'.mb_strtolower($name):null;
    }
    public function modeChoices(): array { return ['videos'=>1,'shorts'=>2,'posts'=>4,'videos_shorts'=>3,'videos_posts'=>5,'shorts_posts'=>6,'all'=>7]; }
    public static function prefix(array $job): string
    {
        $o=json_decode((string)($job['options']??'{}'),true);
        $kind=$o['checker_kind']??$o['pages']['checker_kind']??(youtubePostUrl($job['url'])!==null?'post':'video');
        return 'youtube_checker_'.(in_array($kind,['video','short','post'],true)?$kind:'video');
    }
    protected function deliveryPhase(array $row): string { return match($row['kind']) {'post'=>'youtube','video'=>'inspect',default=>'youtube_checker'}; }
    public function offered(array $job,int $message): void
    {
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $this->query("UPDATE {checker}deliveries SET status='offered',message_id=? WHERE id=? AND job_id=? AND status='sending'",[$message,self::deliveryId($job),$job['id']]);
            $this->store->query("UPDATE jobs SET status='waiting_quality' WHERE id=?",[$job['id']]);
            $this->store->db->exec('COMMIT');
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
    }
    public function quality(): int { $q=(int)$this->store->get('youtube.checker.quality','720'); return in_array($q,[360,480,720,1080],true)?$q:720; }
    protected function snapshotItems(array $target,array $snapshot): array
    {
        $account=$snapshot['account_id']??''; $mode=$snapshot['mode']??0;
        $this->validateIdentity($target,$account);
        if(!is_int($mode)||$mode<1||$mode>7) throw new MediaFailure('format','Invalid YouTube checker mode');
        $items=[];
        foreach(['videos'=>'video','shorts'=>'short','posts'=>'post']as$bucket=>$kind) {
            if(!isset($snapshot[$bucket])||!is_array($snapshot[$bucket])||count($snapshot[$bucket])>24) throw new MediaFailure('format','Incomplete YouTube checker snapshot');
            foreach($snapshot[$bucket]as$item) {
                $id=$item['id']??''; $url=$item['url']??'';
                $valid=$kind==='post'?is_string($id)&&preg_match('/^Ug[A-Za-z0-9_-]{8,126}$/D',$id)&&$url==='https://www.youtube.com/post/'.$id
                    :is_string($id)&&preg_match('/^[A-Za-z0-9_-]{11}$/D',$id)&&$url===($kind==='short'?'https://www.youtube.com/shorts/':'https://www.youtube.com/watch?v=').$id;
                if(!$valid||isset($items[$kind.':'.$id])) throw new MediaFailure('format','Invalid YouTube checker item');
                $items[$kind.':'.$id]=['id'=>$id,'url'=>$url,'kind'=>$kind,'time'=>max(0,(int)($item['time']??0))];
            }
        }
        return $items;
    }
    protected function scan(array $target,int $mode): array
    {
        return $this->readChannel($target,$mode);
    }
    private function validateIdentity(array $target,mixed $account): void
    {
        if(!is_string($account)||!preg_match('/^UC[A-Za-z0-9_-]{22}$/D',$account)||($target['account_id']!==''&&$target['account_id']!==$account)
            ||(!str_starts_with($target['username'],'@')&&$target['username']!==$account)) throw new MediaFailure('format','Invalid YouTube checker identity');
    }
    private function readChannel(array $target,?int $mode): array
    {
        $folder=$this->config->storage.'/downloads/checker-'.bin2hex(random_bytes(6));
        if(!is_dir(dirname($folder))) mkdir(dirname($folder),0700,true); mkdir($folder,0700);
        try {
            $command=[dirname($this->config->ytDlp).'/python',dirname(__DIR__).'/bin/youtube-check.py','--channel',$target['account_id']?:$target['username'],...($mode===null?['--profile-only']:['--mode',(string)$mode])];
            if($this->config->youtubeProxy!=='') array_push($command,'--proxy',$this->config->youtubeProxy);
            $jars=YoutubeCheckerMedia::pool($this->config)->order(1);
            if($jars) {
                $content=(string)file_get_contents($jars[0]['path']); validateYouTubeCookies($content);$path=$folder.'/session.txt';
                if(file_put_contents($path,$content)!==strlen($content)||!chmod($path,0600)) throw new MediaFailure('cookies','Cannot prepare checker session');
                array_push($command,'--cookies',$path);
            }
            try{$out=(new Download($this->config))->run($command,$folder,90);}
            catch(MediaFailure $e){$reason=preg_match('/YOUTUBE_CHECK_ERROR (blocked|cookies|restricted|network|format)\b/',$e->getMessage(),$m)?$m[1]:$e->reason;throw new MediaFailure($reason,'YouTube checker scan failed');}
            $data=json_decode($out,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($data)) throw new MediaFailure('format','Invalid YouTube checker snapshot');return $data;
        } finally {Download::clean($folder);}
    }
}
