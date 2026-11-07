<?php
declare(strict_types=1);
namespace Zero;

/**
 * Administrator-facing helpers shared by every cookie panel: importing one or
 * many exports (TXT files or one ZIP), listing the jars and handling the
 * per-jar inline buttons. Each service keeps its own pool; nothing here lets
 * one export serve two services.
 */
final class CookieJars
{
    public const SERVICES=['yt'=>'youtube','ig'=>'instagram','pin'=>'pinterest','tt'=>'tiktok','x'=>'twitter'];
    public function __construct(private Bot $bot) {}
    public static function pool(Config $config,string $code): ?CookiePool
    {
        if($code==='yt') return youtubeCookiePool($config);
        $service=galleryFromPrefix($code);
        return $service===null?null:GalleryMedia::poolFor($config,$service);
    }
    /**
     * Download one document and add every export it holds to the pool.
     * A single TXT or JSON keeps strict errors (so the caller can explain them);
     * a ZIP reports each file separately and never stops at the first bad one.
     * @return array{added:int,replaced:int,unchanged:int,invalid:int,notes:list<array{0:string,1:string}>,live:bool}
     */
    public static function ingest(Api $api,array $document,CookiePool $pool,string $workDirectory,?callable $verify=null): array
    {
        $extension=strtolower(pathinfo((string)($document['file_name']??''),PATHINFO_EXTENSION));
        $limit=$extension==='zip'?CookiePool::MAX_ZIP_BYTES:CookiePool::MAX_BYTES;
        if(!in_array($extension,['txt','json','zip'],true)||(int)($document['file_size']??0)>$limit||empty($document['file_id'])) throw new \InvalidArgumentException('Invalid cookie document');
        if(!($api instanceof FileApi)) throw new \RuntimeException('Cookie file unavailable');
        $file=$api->call('getFile',['file_id'=>$document['file_id']]);
        if(!is_array($file)||empty($file['file_path'])||(int)($file['file_size']??0)>$limit) throw new \RuntimeException('Cookie file unavailable');
        $bytes=$api->downloadFile((string)$file['file_path'],$limit);
        if($extension!=='zip') return self::ingestContent($bytes,$pool,$verify);
        $entries=CookiePool::zipEntries($bytes,$workDirectory);
        if(!$entries) throw new \InvalidArgumentException('The archive holds no cookie files');
        return self::addEntries($entries,$pool,false,null);
    }
    /** A pasted message or a single file: strict errors, optional live check. */
    public static function ingestContent(string $content,CookiePool $pool,?callable $verify=null): array
    {
        return self::addEntries([['cookies',$content,'']],$pool,true,$verify);
    }
    private static function addEntries(array $entries,CookiePool $pool,bool $single,?callable $verify): array
    {
        $summary=['added'=>0,'replaced'=>0,'unchanged'=>0,'invalid'=>0,'notes'=>[],'live'=>false];
        foreach($entries as [$name,$content,$reason]) {
            if($content===null) { $summary['invalid']++; $summary['notes'][]=[$name,$reason]; continue; }
            try {
                $content=$pool->normalize($content);
                if($single&&$verify!==null) { $content=$verify($content); $summary['live']=true; }
                $summary[$pool->add($content)['status']]++;
            } catch(\Throwable $e) {
                if($single) throw $e;
                $summary['invalid']++; $summary['notes'][]=[$name,$e->getMessage()==='Cookie pool is full'?'full':'invalid'];
            }
        }
        return $summary;
    }
    public function name(string $code): string
    {
        return trim(preg_replace('/^[^\p{L}\p{N}]+/u','',$this->bot->ui->label('cookie_'.self::SERVICES[$code]))??'');
    }
    /** The message shown after a document: counts, per-file reasons and the new total. */
    public function result(string $code,array $summary): string
    {
        $ui=$this->bot->ui; $notes=[];
        foreach(array_slice($summary['notes'],0,8) as [$name,$reason]) {
            $key='cookie_reason_'.$reason;
            $notes[]='• '.Ui::escape($name).' — '.($ui->definition('text',$key)?$ui->plain($key):$ui->plain('cookie_reason_invalid'));
        }
        $pool=self::pool($this->bot->config,$code);
        return $ui->text('cookie_batch_result',[
            'service'=>$this->name($code),'added'=>$summary['added'],'replaced'=>$summary['replaced'],'unchanged'=>$summary['unchanged'],'invalid'=>$summary['invalid'],
            'total'=>$pool?$pool->count():0,'max'=>CookiePool::MAX_JARS,'check'=>$ui->plain($summary['live']?'cookie_check_live':'cookie_check_structure'),
            'notes'=>$notes?"\n".implode("\n",$notes):'',
        ],['notes']);
    }
    private function rows(string $code,array $jars): array
    {
        $rows=[]; $ui=$this->bot->ui;
        foreach($jars as $index=>$jar) {
            $tag=$code.':'.$jar['id'].':'.substr($jar['hash'],0,8);
            $rows[]=[$ui->button('cookie_jar_export','ckex:'.$tag,['n'=>$index+1]),$ui->button('cookie_jar_remove','ckrm:'.$tag,['n'=>$index+1])];
        }
        return $rows;
    }
    public function view(int $chat,string $code,int $message=0,string $notice=''): void
    {
        $ui=$this->bot->ui; $pool=self::pool($this->bot->config,$code); if(!$pool) return;
        $lines=[]; $jars=$pool->describe();
        foreach($jars as $index=>$jar) {
            $lines[]=$ui->text($jar['failed']?'cookie_pool_jar_failed':'cookie_pool_jar',['n'=>$index+1,'count'=>$jar['count'],'expired'=>$jar['expired'],'session'=>$jar['session']]);
        }
        $text=$ui->text('cookie_pool_view',[
            'service'=>$this->name($code),'count'=>count($jars),'max'=>CookiePool::MAX_JARS,
            'jars'=>$lines?implode("\n",$lines):$ui->plain('cookie_pool_empty'),
            'mode'=>$ui->plain($this->bot->store->get('cookies.fallback','0')==='1'?'cookie_mode_fallback':'cookie_mode_always'),
        ],['jars']).($notice!==''?"\n\n".$notice:'');
        $this->bot->send($chat,$text,$this->rows($code,$jars),$message);
    }
    /** Inline buttons of the pool list: export or remove one jar. */
    public function callback(array $query,int $chat,int $user,int $message): void
    {
        $ui=$this->bot->ui; $answer=fn(string $text='',bool $alert=false)=>$this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$query['id'],'text'=>$text,'show_alert'=>$alert,'cache_time'=>0]);
        if($chat!==$user||!$this->bot->admin($user)||!preg_match('/^ck(rm|ex):(yt|ig|pin|tt|x):([a-f0-9]{12}|main):([a-f0-9]{8})$/D',(string)($query['data']??''),$match)) { $answer($ui->plain('denied'),true); return; }
        [, $action,$code,$id,$prefix]=$match;
        $pool=self::pool($this->bot->config,$code); $jar=null;
        foreach($pool?$pool->jars():[] as $candidate) if($candidate['id']===$id&&str_starts_with($candidate['hash'],$prefix)) $jar=$candidate;
        if(!$jar) { $answer($ui->plain('cookie_remove_changed'),true); $this->view($chat,$code,$message); return; }
        if($action==='ex') {
            try {
                $content=$pool->content($id);
                if($content===null||!hash_equals($jar['hash'],hash('sha256',$content))) throw new \RuntimeException('Cookie jar changed');
                $this->bot->api->call('sendDocument',['chat_id'=>$chat,'document'=>new \CURLStringFile($content,self::SERVICES[$code].'-cookies.txt','text/plain'),
                    'caption'=>$ui->text($code==='yt'?'cookie_export_caption':($code.'_cookie_export_caption')),'parse_mode'=>'HTML','protect_content'=>true]);
                $answer();
            } catch(\Throwable) { $answer($ui->plain('cookie_export_failed'),true); }
            return;
        }
        try { $removed=$pool->remove($id,$jar['hash']); }
        catch(\Throwable) { $answer($ui->plain('cookie_remove_failed'),true); return; }
        $answer($ui->plain($removed?'cookie_jar_removed':'cookie_remove_changed'),!$removed);
        $this->view($chat,$code,$message);
    }
}
