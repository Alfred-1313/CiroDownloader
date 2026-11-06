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
            $state=['folder'=>basename($folder),'items'=>$items,'current'=>0,'target'=>1,'revision'=>0,'message'=>0,'expires'=>time()+self::TTL,
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
        // Existing one-way sessions can continue using their first old button.
        if(!isset($state['current'])) {
            $state['current']=max(1,min(count($state['items']),(int)($state['next']??2)-1));
            $state['target']=(int)($state['next']??2); $state['revision']=0;
            unset($state['next']);
        }
        return $state;
    }
    public function queue(int $id,int $user,int $chat,int $message,int $thread,int $page,?int $revision=null): string
    {
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $job=$this->store->query("SELECT * FROM jobs WHERE id=? AND user_id=? AND chat_id=? AND thread_id=? AND phase='instagram_page'",[$id,$user,$chat,$thread])->fetch();
            $state=$job?$this->state($job):null;
            if(!$state||(int)$state['expires']<=time()||!is_dir($this->root().'/'.$state['folder'])) $notice='instagram_page_expired';
            elseif((int)$state['message']!==$message||$page<1||$page>count($state['items'])||abs($page-(int)$state['current'])!==1
                ||($revision===null?(int)$state['revision']!==0:$revision!==(int)$state['revision'])) $notice='instagram_page_stale';
            elseif(in_array($job['status'],['queued','running'],true)) $notice='instagram_page_busy';
            elseif($job['status']!=='done') $notice='instagram_page_expired';
            elseif($this->store->get('update_in_progress','0')==='1') $notice='updating';
            elseif($this->store->busy($user,$chat)) $notice='quality_busy';
            elseif($this->store->full()) $notice='queue_full';
            else {
                // Reserve a status slot for errors while the worker edits media.
                // The poller never uploads, and duplicate clicks cannot reserve a slide.
                $state['target']=$page;
                $this->store->query("UPDATE jobs SET status='queued',message_id=NULL,message_kind='text',expires=0,options=? WHERE id=?",[json_encode(['pages'=>$state],JSON_THROW_ON_ERROR),$id]);
                $notice='';
            }
            $this->store->db->exec('COMMIT'); return $notice;
        } catch(\Throwable $e) { $this->store->db->exec('ROLLBACK'); throw $e; }
    }
    public function deliver(array $job,Bot $bot): void
    {
        $state=$this->state($job); $index=(int)($state['target']??0);
        if(!$state||(int)$state['expires']<=time()||$index<1||$index>count($state['items'])) throw new MediaFailure('expired','Instagram page session expired');
        $item=$state['items'][$index-1]; $folder=$this->root().'/'.$state['folder'];
        $name=$index.($item['type']==='photo'?'.jpg':'.mp4'); $path=$folder.'/'.$name;
        if(is_link($folder)||is_link($path)||!is_file($path)) throw new MediaFailure('expired','Instagram slide is no longer available');
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) throw new MediaFailure('restricted','Instagram page delivery no longer authorized');
        $count=count($state['items']);
        $initial=(int)$state['message']===0;
        if($initial) $bot->jobMessage($job,$bot->text('instagram_uploading',['sent'=>0,'count'=>$count]));
        $cached=is_string($item['file_id']??null)&&preg_match('/^[A-Za-z0-9_-]{1,1024}$/D',$item['file_id']);
        if(!$cached&&$this->config->api==='http://ciro-telegram-api:8081') Download::ensureSpace('/telegram-api',filesize($path)+268435456);
        $description=$state['description'];
        while(TextFormat::length($description)>700) $description=mb_substr($description,0,max(0,mb_strlen($description)-50));
        $caption=Ui::fitCaption($bot->text('instagram_page_caption',['index'=>$index,'count'=>$count,'url'=>$job['url'],'description'=>$description]));
        $source=$bot->ui->button('source_instagram'); $source['url']=$job['url']; $navigation=[]; $revision=(int)$state['revision']+1;
        if($index>1) $navigation[]=$bot->ui->button('instagram_prev','igpage:'.$job['id'].':'.($index-1).':'.$revision,['index'=>$index-1,'count'=>$count]);
        $navigation[]=$bot->ui->button('instagram_page_counter','igpage:noop',['index'=>$index,'count'=>$count]);
        if($index<$count) $navigation[]=$bot->ui->button('instagram_next','igpage:'.$job['id'].':'.($index+1).':'.$revision,['index'=>$index+1,'count'=>$count]);
        $rows=[$navigation];
        $rows[]=[$source];
        $params=['chat_id'=>(int)$job['chat_id'],'reply_markup'=>['inline_keyboard'=>$rows]];
        $media=['type'=>$item['type'],'media'=>$cached?$item['file_id']:'attach://slide','caption'=>$caption,'parse_mode'=>'HTML'];
        if($item['type']==='video') $media+=['width'=>$item['width'],'height'=>$item['height'],'duration'=>$item['duration'],'supports_streaming'=>true];
        $file=new \CURLFile($path,$item['type']==='photo'?'image/jpeg':'video/mp4',$name);
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) throw new MediaFailure('restricted','Instagram page delivery no longer authorized');
        if($initial) {
            if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
            unset($media['type'],$media['media']);
            $result=$this->api->call($item['type']==='photo'?'sendPhoto':'sendVideo',$params+$media+[$item['type']=>$file]);
        } else {
            $result=$this->api->call('editMessageMedia',$params+['message_id'=>(int)$state['message'],'media'=>$media]+($cached?[]:['slide'=>$file]));
        }
        if(!is_array($result)||empty($result['message_id'])||(!$initial&&(int)$result['message_id']!==(int)$state['message'])) throw new MediaFailure('upload','Missing or mismatched Instagram page message ID');
        $photo=$result['photo']??[];
        $fileId=$item['type']==='video'?($result['video']['file_id']??null):($photo?($photo[array_key_last($photo)]['file_id']??null):null);
        if(is_string($fileId)&&preg_match('/^[A-Za-z0-9_-]{1,1024}$/D',$fileId)) $state['items'][$index-1]['file_id']=$fileId;
        // Finish this claim's status before releasing the cursor for another click.
        if($initial) try { $bot->finishJob($job); } catch(\Throwable) { error_log('Instagram page delivered; status cleanup unavailable'); }
        $state['message']=(int)$result['message_id']; $state['current']=$index; $state['target']=$index; $state['revision']=$revision;
        $this->store->query("UPDATE jobs SET status='done',options=? WHERE id=?",[json_encode(['pages'=>$state],JSON_THROW_ON_ERROR),$job['id']]);
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
