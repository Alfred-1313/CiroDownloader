<?php
declare(strict_types=1);
namespace Zero;

final class InstagramPages
{
    public const TTL=86400;
    public function __construct(private Config $config,private Store $store,private Api $api) {}
    private function root(): string { return $this->config->storage.'/instagram-pages'; }
    public function prepare(array $job,array $media): array
    {
        $root=$this->root();
        if(!is_dir($root)&&!mkdir($root,0700,true)) throw new MediaFailure('storage','Cannot create Instagram page cache');
        $folder=$root.'/job-'.(int)$job['id'].'-'.bin2hex(random_bytes(8));
        if(!mkdir($folder,0700)) throw new MediaFailure('storage','Cannot create Instagram page session');
        $items=[];
        try {
            foreach($media as $index=>$item) {
                $name=($index+1).($item['type']==='photo'?'.jpg':'.mp4');
                if(!rename($item['path'],$folder.'/'.$name)||!chmod($folder.'/'.$name,0600)) throw new MediaFailure('storage','Cannot retain Instagram slide');
                unset($item['path'],$item['description']); $items[]=$item+['file'=>$name];
            }
            $state=['folder'=>basename($folder),'items'=>$items,'next'=>1,'message'=>0,'expires'=>time()+self::TTL,
                'description'=>(string)($media[0]['description']??'')];
            touch($folder,$state['expires']-self::TTL);
            $job['options']=json_encode(['pages'=>$state],JSON_THROW_ON_ERROR);
            $this->store->query("UPDATE jobs SET phase='instagram_page',options=? WHERE id=?",[$job['options'],$job['id']]);
            return $job;
        } catch(\Throwable $e) { Download::clean($folder); throw $e; }
    }
    private function state(array $job): ?array
    {
        $state=json_decode($job['options'],true)['pages']??null;
        if(!is_array($state)||!is_array($state['items']??null)||count($state['items'])<2||count($state['items'])>Instagram::MAX_ITEMS
            ||!preg_match('/^job-'.(int)$job['id'].'-[a-f0-9]{16}$/D',(string)($state['folder']??''))) return null;
        return $state;
    }
    public function queue(int $id,int $user,int $chat,int $message,int $thread,int $page): string
    {
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $job=$this->store->query("SELECT * FROM jobs WHERE id=? AND user_id=? AND chat_id=? AND thread_id=? AND phase='instagram_page'",[$id,$user,$chat,$thread])->fetch();
            $state=$job?$this->state($job):null;
            if(!$state||(int)$state['expires']<=time()||!is_dir($this->root().'/'.$state['folder'])) $notice='instagram_page_expired';
            elseif((int)$state['message']!==$message||(int)$state['next']!==$page||$page>count($state['items'])) $notice='instagram_page_stale';
            elseif(in_array($job['status'],['queued','running'],true)) $notice='instagram_page_busy';
            elseif($job['status']!=='done') $notice='instagram_page_expired';
            elseif($this->store->get('update_in_progress','0')==='1') $notice='updating';
            elseif($this->store->busy($user,$chat)) $notice='quality_busy';
            elseif($this->store->full()) $notice='queue_full';
            else {
                // NULL lets the worker create the new page's status. The poller
                // never uploads media, and two clicks cannot reserve one slide.
                $this->store->query("UPDATE jobs SET status='queued',message_id=NULL,message_kind='text',expires=0 WHERE id=?",[$id]);
                $notice='';
            }
            $this->store->db->exec('COMMIT'); return $notice;
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
    }
    public function deliver(array $job,Bot $bot): void
    {
        $state=$this->state($job); $index=(int)($state['next']??0);
        if(!$state||(int)$state['expires']<=time()||$index<1||$index>count($state['items'])) throw new MediaFailure('expired','Instagram page session expired');
        $item=$state['items'][$index-1]; $folder=$this->root().'/'.$state['folder'];
        $name=$index.($item['type']==='photo'?'.jpg':'.mp4'); $path=$folder.'/'.$name;
        if(is_link($folder)||is_link($path)||!is_file($path)) throw new MediaFailure('expired','Instagram slide is no longer available');
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) throw new MediaFailure('restricted','Instagram page delivery no longer authorized');
        $count=count($state['items']);
        $bot->jobMessage($job,$bot->text('instagram_uploading',['sent'=>$index-1,'count'=>$count]));
        if($this->config->api==='http://ciro-telegram-api:8081') Download::ensureSpace('/telegram-api',filesize($path)+268435456);
        $description=$index===1?$state['description']:'';
        while(TextFormat::length($description)>700) $description=mb_substr($description,0,max(0,mb_strlen($description)-50));
        $caption=Ui::fitCaption($bot->text('instagram_page_caption',['index'=>$index,'count'=>$count,'url'=>$job['url'],'description'=>$description]));
        $source=$bot->ui->button('source_instagram'); $source['url']=$job['url']; $rows=[];
        if($index<$count) $rows[]=[$bot->ui->button('instagram_next','igpage:'.$job['id'].':'.($index+1),['index'=>$index+1,'count'=>$count])];
        $rows[]=[$source];
        $params=['chat_id'=>(int)$job['chat_id'],'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>$rows]];
        if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
        if($item['type']==='video') $params+=['width'=>$item['width'],'height'=>$item['height'],'duration'=>$item['duration'],'supports_streaming'=>true];
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) throw new MediaFailure('restricted','Instagram page delivery no longer authorized');
        $result=$this->api->call($item['type']==='photo'?'sendPhoto':'sendVideo',$params+[
            $item['type']=>new \CURLFile($path,$item['type']==='photo'?'image/jpeg':'video/mp4',$name)]);
        if(!is_array($result)||empty($result['message_id'])) throw new MediaFailure('upload','Missing Instagram page message ID');
        // Finish this claim's status before releasing the cursor for another click.
        try { $bot->finishJob($job); } catch(\Throwable) { error_log('Instagram page delivered; status cleanup unavailable'); }
        $previous=(int)$state['message']; $state['message']=(int)$result['message_id']; $state['next']=$index+1;
        $this->store->query("UPDATE jobs SET status='done',options=? WHERE id=?",[json_encode(['pages'=>$state],JSON_THROW_ON_ERROR),$job['id']]);
        if($previous>0) {
            try { $this->api->call('editMessageReplyMarkup',['chat_id'=>(int)$job['chat_id'],'message_id'=>$previous,'reply_markup'=>['inline_keyboard'=>[[$source]]]]); }
            catch(\Throwable) { error_log('Previous Instagram page controls could not be removed'); }
        }
        if($index===$count) $this->clean($job);
    }
    public function clean(array $job): void
    {
        foreach(glob($this->root().'/job-'.(int)$job['id'].'-*')?:[] as $folder) if(!is_link($folder)) Download::clean($folder);
    }
    public function prune(): void
    {
        foreach(glob($this->root().'/job-*')?:[] as $folder) {
            if(is_link($folder)||!is_dir($folder)||filemtime($folder)>time()-self::TTL||!preg_match('/^job-(\d+)-[a-f0-9]{16}$/D',basename($folder),$match)) continue;
            if(!$this->store->query("SELECT 1 FROM jobs WHERE id=? AND status IN ('queued','running')",[(int)$match[1]])->fetchColumn()) Download::clean($folder);
        }
    }
}
