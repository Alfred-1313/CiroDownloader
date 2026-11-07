<?php
declare(strict_types=1);
namespace Zero;

class GalleryPages
{
    public const TTL=86400;
    public function __construct(private Config $config,private Store $store,private Api $api,private string $service='instagram') { if(!in_array($service,['instagram','pinterest'],true)) throw new \InvalidArgumentException('Unsupported gallery service'); }
    private function root(): string { return $this->config->storage.'/'.$this->service.'-pages'; }
    public function prepare(array $job,array $media): array
    {
        $root=$this->root();
        if(!is_dir($root)&&!mkdir($root,0700,true)) throw new MediaFailure('storage','Cannot create Gallery page cache');
        $folder=$root.'/job-'.(int)$job['id'].'-'.bin2hex(random_bytes(8));
        if(!mkdir($folder,0700)) throw new MediaFailure('storage','Cannot create Gallery page session');
        $items=[];
        try {
            foreach($media as $index=>$item) {
                $name=($index+1).($item['type']==='photo'?'.jpg':'.mp4');
                if(!rename($item['path'],$folder.'/'.$name)||!chmod($folder.'/'.$name,0600)) throw new MediaFailure('storage','Cannot retain Gallery slide');
                unset($item['path'],$item['description']); $items[]=$item+['file'=>$name];
            }
            $state=['folder'=>basename($folder),'items'=>$items,'current'=>0,'target'=>1,'revision'=>0,'message'=>0,'expires'=>time()+self::TTL,
                'description'=>(string)($media[0]['description']??'')];
            touch($folder,$state['expires']-self::TTL);
            $job['options']=json_encode(['pages'=>$state],JSON_THROW_ON_ERROR);
            $this->store->query("UPDATE jobs SET phase='{$this->service}_page',options=? WHERE id=?",[$job['options'],$job['id']]);
            return $job;
        } catch(\Throwable $e) { Download::clean($folder); throw $e; }
    }
    private function state(array $job): ?array
    {
        $state=json_decode($job['options'],true)['pages']??null;
        if(!is_array($state)||!is_array($state['items']??null)||count($state['items'])<2||count($state['items'])>GalleryMedia::MAX_ITEMS
            ||!preg_match('/^job-'.(int)$job['id'].'-[a-f0-9]{16}$/D',(string)($state['folder']??''))) return null;
        // Existing one-way sessions can continue using their first old button.
        if(!isset($state['current'])) {
            $state['current']=max(1,min(count($state['items']),(int)($state['next']??2)-1));
            $state['target']=(int)($state['next']??2); $state['revision']=0;
            unset($state['next']);
        }
        return $state;
    }
    public function queue(int $id,int $user,int $chat,int $message,?int $thread,int $page,?int $revision=null): string
    {
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $job=$this->store->query("SELECT * FROM jobs WHERE id=? AND chat_id=? AND phase='{$this->service}_page'",[$id,$chat])->fetch();
            $state=$job?$this->state($job):null;
            if(!$state||(int)$state['expires']<=time()||!is_dir($this->root().'/'.$state['folder'])) $notice=$this->service.'_page_expired';
            elseif((int)$state['message']!==$message) $notice=$this->service.'_page_stale';
            elseif((int)$job['user_id']!==$user) $notice=$this->service.'_page_owner';
            // Optional Telegram topic metadata must not reject the authenticated
            // sender. The worker retains the saved topic; explicit mismatches fail.
            elseif(($thread!==null&&(int)$job['thread_id']!==$thread)||$page<1||$page>count($state['items'])||abs($page-(int)$state['current'])!==1
                ||($revision===null?(int)$state['revision']!==0:$revision!==(int)$state['revision'])) $notice=$this->service.'_page_stale';
            elseif(in_array($job['status'],['queued','running'],true)) $notice=$this->service.'_page_busy';
            elseif($job['status']!=='done') $notice=$this->service.'_page_expired';
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
        if(!$state||(int)$state['expires']<=time()||$index<1||$index>count($state['items'])) throw new MediaFailure('expired','Gallery page session expired');
        $item=$state['items'][$index-1]; $folder=$this->root().'/'.$state['folder'];
        $name=$index.($item['type']==='photo'?'.jpg':'.mp4'); $path=$folder.'/'.$name;
        if(is_link($folder)||is_link($path)||!is_file($path)) throw new MediaFailure('expired','Gallery slide is no longer available');
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) throw new MediaFailure('restricted','Gallery page delivery no longer authorized');
        $count=count($state['items']);
        $initial=(int)$state['message']===0;
        if($initial) $bot->jobMessage($job,$bot->text($this->service.'_uploading',['sent'=>0,'count'=>$count]));
        $cached=is_string($item['file_id']??null)&&preg_match('/^[A-Za-z0-9_-]{1,1024}$/D',$item['file_id']);
        if(!$cached&&$this->config->api==='http://ciro-telegram-api:8081') Download::ensureSpace('/telegram-api',filesize($path)+268435456);
        $description=$state['description'];
        while(TextFormat::length($description)>700) $description=mb_substr($description,0,max(0,mb_strlen($description)-50));
        $caption=Ui::fitCaption($bot->text($this->service.'_page_caption',['index'=>$index,'count'=>$count,'url'=>$job['url'],'description'=>$description]));
        $navigation=[]; $revision=(int)$state['revision']+1;
        if($index>1) $navigation[]=$bot->ui->button($this->service.'_prev',($this->service==='pinterest'?'pinpage:':'igpage:').$job['id'].':'.($index-1).':'.$revision,['index'=>$index-1,'count'=>$count]);
        if($index<$count) $navigation[]=$bot->ui->button($this->service.'_next',($this->service==='pinterest'?'pinpage:':'igpage:').$job['id'].':'.($index+1).':'.$revision,['index'=>$index+1,'count'=>$count]);
        $rows=array_merge([$navigation],$bot->ui->gallerySourceRows($this->service,$job['url']));
        $params=['chat_id'=>(int)$job['chat_id'],'reply_markup'=>['inline_keyboard'=>$rows]];
        $media=['type'=>$item['type'],'media'=>$cached?$item['file_id']:'attach://slide','caption'=>$caption,'parse_mode'=>'HTML'];
        if($item['type']==='video') $media+=['width'=>$item['width'],'height'=>$item['height'],'duration'=>$item['duration'],'supports_streaming'=>true];
        $file=new \CURLFile($path,$item['type']==='photo'?'image/jpeg':'video/mp4',$name);
        if(!$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])) throw new MediaFailure('restricted','Gallery page delivery no longer authorized');
        if($initial) {
            if((int)$job['chat_id']<0&&(int)($job['thread_id']??0)>0) $params['message_thread_id']=(int)$job['thread_id'];
            unset($media['type'],$media['media']);
            $result=$this->api->call($item['type']==='photo'?'sendPhoto':'sendVideo',$params+$media+[$item['type']=>$file]);
        } else {
            $result=$this->api->call('editMessageMedia',$params+['message_id'=>(int)$state['message'],'media'=>$media]+($cached?[]:['slide'=>$file]));
        }
        if(!is_array($result)||empty($result['message_id'])||(!$initial&&(int)$result['message_id']!==(int)$state['message'])) throw new MediaFailure('upload','Missing or mismatched Gallery page message ID');
        $photo=$result['photo']??[];
        $fileId=$item['type']==='video'?($result['video']['file_id']??null):($photo?($photo[array_key_last($photo)]['file_id']??null):null);
        if(is_string($fileId)&&preg_match('/^[A-Za-z0-9_-]{1,1024}$/D',$fileId)) $state['items'][$index-1]['file_id']=$fileId;
        // Finish this claim's status before releasing the cursor for another click.
        if($initial) try { $bot->finishJob($job); } catch(\Throwable) { error_log('Gallery page delivered; status cleanup unavailable'); }
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
