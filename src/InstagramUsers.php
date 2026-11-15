<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/InstagramAccounts.php';

/** A bounded account search, kept in its existing status message and worker queue. */
final class InstagramUsers
{
    public const PHASE='instagram_users', PAGE=6, TTL=3600;
    public function __construct(private Bot $bot,private ?InstagramAccounts $accounts=null) {}
    public static function enabled(Store $store): bool { return $store->get('instagram.user_search','0')==='1'; }
    public static function parse(string $text): ?array
    {
        if(!preg_match('~^/user_instagram(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~sD',trim($text),$m)) return null;
        return ['bot'=>$m[1]??'','argument'=>trim($m[2]??'')];
    }
    public static function handle(Bot $bot,array $update,int $user,int $chat,int $thread,array $command): void
    {
        if(($command['bot']??'')!==''&&strcasecmp($command['bot'],$bot->store->get('bot_username'))!==0) return;
        if(!$bot->allowed($user,$chat)) { $bot->send($chat,$bot->denial($chat),[],0,$thread); return; }
        $query=ltrim(trim((string)$command['argument']),'@');
        $query=trim(preg_replace('/[\p{C}]+|\s+/u',' ',$query)??'');
        if(mb_strlen($query)<2||mb_strlen($query)>50||!preg_match('/^[\p{L}\p{N}_. ]+$/uD',$query)) {
            $bot->send($chat,$bot->text('instagram_user_usage'),[],0,$thread); return;
        }
        if(!self::enabled($bot->store)) { $bot->send($chat,$bot->text('instagram_user_disabled'),[],0,$thread); return; }
        $bot->requestVideo((int)$update['update_id'],$user,$chat,'https://www.instagram.com/', $thread,(int)($update['message']['message_id']??0),
            self::PHASE,['query'=>$query,'op'=>'search','revision'=>0]);
    }
    private function values(array $item): array
    {
        $ui=$this->bot->ui; $number=fn($v)=>is_int($v)&&$v>=0?number_format($v):$ui->plain('instagram_user_unknown');
        return ['username'=>(string)$item['username'],'name'=>(string)($item['name']?:$item['username']),
            'posts'=>$number($item['posts']??null),'followers'=>$number($item['followers']??null),'following'=>$number($item['following']??null),
            'privacy'=>$ui->plain(!empty($item['private'])?'instagram_user_private':'instagram_user_public'),
            'bio'=>trim((string)($item['bio']??''))?:$ui->plain('instagram_user_no_bio')];
    }
    private function rows(array $job,array $data,bool $profile=false): array
    {
        $ui=$this->bot->ui; $prefix='iu:'.$job['id'].':'.$data['revision'].':'; $slots=[];
        if($profile) {
            $slots['back']=[$ui->button('instagram_user_back',$prefix.'b')];
            if($ui->instagramSourceEnabled()) {
                $button=$ui->button('instagram_user_open'); $button['url']='https://www.instagram.com/'.$data['items'][$data['selected']]['username'].'/'; $slots['source']=[$button];
            }
        } else {
            foreach(array_slice($data['items'],$data['page']*self::PAGE,self::PAGE,true) as $index=>$item) {
                $button=$ui->button('instagram_user_result',$prefix.'u:'.$index,$this->values($item));
                // Dynamic names/counts can be longer than the administrator's preview sample.
                while(TextFormat::length($button['text'])>64) $button['text']=mb_substr($button['text'],0,-1);
                $slots['result'.($index%self::PAGE)]=[$button];
            }
            if($data['page']>0) $slots['previous']=[$ui->button('instagram_user_previous',$prefix.'p:'.($data['page']-1))];
            if(($data['page']+1)*self::PAGE<count($data['items'])) $slots['next']=[$ui->button('instagram_user_next',$prefix.'p:'.($data['page']+1))];
        }
        $slots['close']=[$ui->button('instagram_user_close',$prefix.'x')];
        $layout=$profile?'user_profile':'user_results';
        return $ui->layout('instagram',$layout,$slots,UiRegistry::rows('instagram',$layout));
    }
    private function list(array $job,array $data,string $notice=''): void
    {
        $this->bot->jobMessage($job,$this->bot->text('instagram_user_results',['query'=>$data['query'],'page'=>$data['page']+1,
            'pages'=>max(1,(int)ceil(count($data['items'])/self::PAGE))]).($notice!==''?"\n\n".$notice:''),$this->rows($job,$data));
    }
    private function permitted(array $job): bool
    {
        $bot=$this->bot;
        if($bot->allowed((int)$job['user_id'],(int)$job['chat_id'])&&self::enabled($bot->store)) return true;
        $bot->store->query("UPDATE jobs SET status='failed',options='{}',expires=0 WHERE id=?",[$job['id']]);
        $bot->jobMessage($job,$bot->allowed((int)$job['user_id'],(int)$job['chat_id'])?$bot->text('instagram_user_disabled'):$bot->denial((int)$job['chat_id']));
        return false;
    }
    /** Cache only the avatar confirmed by Telegram for this account, never a previous account's photo. */
    private function profileMessage(array $job,string $caption,array $rows,?string $photo,bool $file): string
    {
        $bot=$this->bot; $store=$bot->store;
        $saved=$store->query('SELECT message_id,message_kind FROM jobs WHERE id=?',[$job['id']])->fetch();
        $message=(int)$saved['message_id'];
        if($photo===null) {
            if($saved['message_kind']==='text') { $bot->jobMessage($job,$caption,$rows); return ''; }
            // Telegram cannot change a photo into text. Confirm the replacement before removing the old message.
            $id=$bot->send((int)$job['chat_id'],$caption,$rows,0,(int)$job['thread_id']);
            if($id<1) throw new \RuntimeException('Missing Instagram profile message');
            $store->query("UPDATE jobs SET message_id=?,message_kind='text',poster='' WHERE id=?",[$id,$job['id']]);
            try { $bot->api->call('deleteMessage',['chat_id'=>(int)$job['chat_id'],'message_id'=>$message]); } catch(\Throwable) {}
            return '';
        }
        $params=['chat_id'=>(int)$job['chat_id'],'reply_markup'=>['inline_keyboard'=>$rows]];
        $media=$file?'attach://avatar':$photo;
        if($message>0) {
            $params+=['message_id'=>$message,'media'=>['type'=>'photo','media'=>$media,'caption'=>Ui::fitCaption($caption),'parse_mode'=>'HTML']];
            if($file) $params['avatar']=new \CURLFile($photo,(string)mime_content_type($photo),'avatar.jpg');
        } else {
            $params+=['photo'=>$file?new \CURLFile($photo,(string)mime_content_type($photo),'avatar.jpg'):$photo,'caption'=>Ui::fitCaption($caption),'parse_mode'=>'HTML'];
            if((int)$job['chat_id']<0&&(int)$job['thread_id']>0) $params['message_thread_id']=(int)$job['thread_id'];
        }
        try { $result=$bot->api->call($message>0?'editMessageMedia':'sendPhoto',$params); }
        catch(\RuntimeException $error) {
            if($error->getCode()!==404||$message<1) throw $error;
            $store->query('UPDATE jobs SET message_id=0 WHERE id=?',[$job['id']]);
            return $this->profileMessage($job,$caption,$rows,$photo,$file);
        }
        if(!is_array($result)||empty($result['message_id'])) throw new \RuntimeException('Missing Instagram avatar confirmation');
        $photos=$result['photo']??[]; $id=$photos?(string)(end($photos)['file_id']??''):'';
        $store->query("UPDATE jobs SET message_id=?,message_kind='photo',poster=? WHERE id=?",[(int)$result['message_id'],$id,$job['id']]);
        return $id;
    }
    /** Network and media work runs only in a worker child. Returns confirmed avatar bytes for speed reporting. */
    public function process(array $job,string $folder,array &$timings): int
    {
        $bot=$this->bot; $store=$bot->store; $accounts=$this->accounts??new InstagramAccounts($bot->config);
        $data=json_decode((string)$job['options'],true,32,JSON_THROW_ON_ERROR); $op=$data['op']; $bytes=0; $started=hrtime(true);
        if(!$this->permitted($job)) return 0;
        try {
            $bot->jobMessage($job,$bot->text($op==='profile'?'instagram_user_loading_profile':'instagram_user_loading',['query'=>$data['query']]));
            if($op==='search') {
                $data['items']=$accounts->fetch(['op'=>'search','query'=>$data['query']],$folder)['items']; $data['page']=0;
                $job['expires']=time()+self::TTL;
            } elseif($op==='page') {
                $offset=$data['page']*self::PAGE; $items=array_slice($data['items'],$offset,self::PAGE);
                if(array_filter($items,fn($item)=>empty($item['loaded']))) {
                    $items=$accounts->fetch(['op'=>'page','items'=>$items],$folder)['items'];
                    array_splice($data['items'],$offset,count($items),$items);
                }
            } elseif($op==='profile') {
                $index=$data['selected']; $item=$data['items'][$index];
                if(empty($item['file_id'])) {
                    $result=$accounts->fetch(['op'=>'profile','item'=>$item],$folder); $data['items'][$index]=$result['item'];
                    $path=$folder.'/instagram-avatar.jpg';
                    if(!empty($result['photo'])&&is_file($path)) $bytes=(int)filesize($path);
                }
            }
            // Access and the feature switch may have changed while Instagram was answering.
            if(!$this->permitted($job)) return 0;
            $data['op']=$op==='profile'?'profile':'list';
            if(!$data['items']) {
                $store->query("UPDATE jobs SET status='done',options='{}',expires=0 WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->text('instagram_user_empty',['query'=>$data['query']])); return 0;
            }
            if($op==='profile') {
                $item=$data['items'][$data['selected']]; $caption=$bot->text('instagram_user_caption',$this->values($item));
                $rows=$this->rows($job,$data,true);
                $timings['prepare_ms']=(int)round((hrtime(true)-$started)/1e6); $uploadStarted=hrtime(true);
                $photo=!empty($item['file_id'])?$item['file_id']:($bytes>0?$path:null);
                $id=$this->profileMessage($job,$caption.($photo===null?"\n\n".$bot->text('instagram_user_no_photo'):''),$rows,$photo,$bytes>0);
                if($bytes>0&&is_file($path)) unlink($path);
                $timings['upload_ms']=(int)round((hrtime(true)-$uploadStarted)/1e6);
                if($id!=='') $data['items'][$data['selected']]['file_id']=$id;
            } else $this->list($job,$data);
            $store->query("UPDATE jobs SET status='done',options=?,expires=? WHERE id=?",[json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$job['expires'],$job['id']]);
            return $bytes;
        } catch(\Throwable $error) {
            $reason=$error instanceof MediaFailure?$error->reason:'process';
            ErrorAlerts::report('instagram',$reason);
            $key='instagram_user_error_'.(in_array($reason,['cookies','blocked','network','notfound'],true)?$reason:'process');
            // A failed page/profile keeps the previous search usable instead of stranding its buttons.
            if(!empty($data['items'])&&(int)$job['expires']>time()) {
                $data['op']='list';
                $store->query("UPDATE jobs SET status='done',options=? WHERE id=?",[json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$job['id']]);
                $this->list($job,$data,$bot->text($key));
            } else {
                $store->query("UPDATE jobs SET status='failed',options='{}',expires=0 WHERE id=?",[$job['id']]);
                $bot->jobMessage($job,$bot->text($key));
            }
            return 0;
        }
    }
    public function callback(array $update,int $chat,int $user,int $thread,int $message): void
    {
        $cb=$update['callback_query'];
        if(!preg_match('/^iu:(\d{1,12}):(\d{1,9}):(x|b|[up]:\d{1,2})$/D',(string)($cb['data']??''),$m)) return;
        $store=$this->bot->store; $job=$store->query('SELECT * FROM jobs WHERE id=? AND phase=?',[(int)$m[1],self::PHASE])->fetch();
        $data=$job?json_decode((string)$job['options'],true):[]; $key='';
        if(!$job||(int)$job['expires']<time()||empty($data['items'])) $key='instagram_user_expired';
        elseif((int)$job['user_id']!==$user||(int)$job['chat_id']!==$chat||(int)$job['message_id']!==$message||($chat<0&&(int)$job['thread_id']!==$thread)) $key='instagram_user_owner';
        elseif(!self::enabled($store)) $key='instagram_user_disabled';
        elseif($job['status']!=='done'||(int)$data['revision']!==(int)$m[2]) $key='instagram_user_stale';
        elseif($store->get('update_in_progress','0')==='1') $key='maintenance';
        $op=$m[3];
        if($key==='') {
            if($op==='x') {
                $changed=$store->query("UPDATE jobs SET options='{}',expires=0 WHERE id=? AND status='done' AND options=?",[$job['id'],$job['options']])->rowCount();
                if(!$changed) $key='instagram_user_stale';
                else {
                    $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id']]);
                    try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); } catch(\Throwable) {}
                    return;
                }
            } else {
                $kind=$op[0]; $index=(int)substr($op,2);
                if($kind==='u'&&isset($data['items'][$index])&&intdiv($index,self::PAGE)===$data['page']) { $data['op']='profile'; $data['selected']=$index; }
                elseif($op==='b'&&$data['op']==='profile') $data['op']='page';
                elseif($kind==='p'&&$data['op']==='list'&&$index>=0&&$index*self::PAGE<count($data['items'])) { $data['op']='page'; $data['page']=$index; }
                else $key='instagram_user_stale';
                if($key===''&&$store->full()) $key='queue_full';
                if($key==='') {
                    $data['revision']++;
                    if(!$store->query("UPDATE jobs SET status='queued',options=? WHERE id=? AND status='done' AND options=?",[json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$job['id'],$job['options']])->rowCount()) $key='instagram_user_stale';
                }
            }
        }
        $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->bot->ui->plain($key?:'instagram_user_loading_profile'),'show_alert'=>$key!=='','cache_time'=>0]);
    }
}
