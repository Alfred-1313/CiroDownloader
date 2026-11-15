<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/InstagramAccounts.php';

/** One exact Instagram profile; no user-list/search API is called. */
final class InstagramUsers
{
    public const PHASE='instagram_users', TTL=3600;
    public function __construct(private Bot $bot,private ?InstagramAccounts $accounts=null) {}
    // Keep the saved switch from the previous search feature.
    public static function enabled(Store $store): bool { return $store->get('instagram.user_search','0')==='1'; }
    public static function stories(Store $store): bool { return $store->get('instagram.stories','0')==='1'; }
    public static function parse(string $text): ?array
    {
        if(!preg_match('~^/(user_instagram|story_instagram)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~sD',trim($text),$m)) return null;
        return ['kind'=>$m[1]==='story_instagram'?'stories':'profile','bot'=>$m[2]??'','argument'=>trim($m[3]??'')];
    }
    public static function name(string $input): ?string { return instagramProfileUsername($input)??instagramUsername($input); }
    public static function handle(Bot $bot,array $update,int $user,int $chat,int $thread,array $command): void
    {
        if(($command['bot']??'')!==''&&strcasecmp($command['bot'],$bot->store->get('bot_username'))!==0) return;
        if(!$bot->allowed($user,$chat)) { $bot->send($chat,$bot->denial($chat),[],0,$thread); return; }
        $story=($command['kind']??'profile')==='stories'; $name=self::name((string)$command['argument']);
        if($name===null) { $bot->send($chat,$bot->text($story?'instagram_story_usage':'instagram_user_usage'),[],0,$thread); return; }
        if($story) {
            $bot->requestVideo((int)$update['update_id'],$user,$chat,'https://www.instagram.com/stories/'.$name.'/', $thread,(int)($update['message']['message_id']??0));
            return;
        }
        if(!self::enabled($bot->store)) { $bot->send($chat,$bot->text('instagram_user_disabled'),[],0,$thread); return; }
        $bot->requestVideo((int)$update['update_id'],$user,$chat,'https://www.instagram.com/'.$name.'/', $thread,(int)($update['message']['message_id']??0),
            self::PHASE,['query'=>$name,'op'=>'direct','revision'=>0]);
    }
    private function values(array $item): array
    {
        $ui=$this->bot->ui; $number=fn($v)=>is_int($v)&&$v>=0?number_format($v):$ui->plain('instagram_user_unknown');
        return ['username'=>(string)$item['username'],'name'=>(string)($item['name']?:$item['username']),
            'posts'=>$number($item['posts']??null),'followers'=>$number($item['followers']??null),'following'=>$number($item['following']??null),
            'privacy'=>$ui->plain(!empty($item['private'])?'instagram_user_private':'instagram_user_public'),
            'bio'=>trim((string)($item['bio']??''))?:$ui->plain('instagram_user_no_bio')];
    }
    private function rows(array $job,array $data): array
    {
        $ui=$this->bot->ui; $prefix='iu:'.$job['id'].':'.$data['revision'].':'; $slots=[]; $name=$data['items'][0]['username'];
        if(self::stories($this->bot->store)) $slots['stories']=[$ui->button('instagram_user_stories',$prefix.'s')];
        if($ui->instagramSourceEnabled()) { $button=$ui->button('instagram_user_open'); $button['url']='https://www.instagram.com/'.$name.'/'; $slots['source']=[$button]; }
        $slots['close']=[$ui->button('instagram_user_close',$prefix.'x')];
        return $ui->layout('instagram','user_profile',$slots,UiRegistry::rows('instagram','user_profile'));
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
    public function process(array $job,string $folder,array &$timings): int
    {
        $bot=$this->bot; $store=$bot->store; $data=json_decode((string)$job['options'],true,32,JSON_THROW_ON_ERROR);
        if(!$this->permitted($job)) return 0;
        if(($name=self::name((string)($data['query']??'')))===null) {
            $store->query("UPDATE jobs SET status='failed',options='{}',expires=0 WHERE id=?",[$job['id']]);
            $bot->jobMessage($job,$bot->text('instagram_user_usage')); return 0;
        }
        try {
            $started=hrtime(true); $bot->jobMessage($job,$bot->text('instagram_user_loading_profile'));
            $result=($this->accounts??new InstagramAccounts($bot->config))->fetch(['op'=>'direct','username'=>$name],$folder);
            $item=$result['item'];
            if(strcasecmp((string)$item['username'],$name)!==0) throw new MediaFailure('format','Mismatched Instagram profile');
            if(!$this->permitted($job)) return 0;
            if(is_array($result['warning']??null)) {
                $warning=$result['warning']; $reason=in_array($warning['reason']??'',['cookies','blocked','network','format'],true)?$warning['reason']:'format';
                ErrorAlerts::report('instagram',$reason,InstagramAccounts::diagnostic($warning));
            }
            $data=['query'=>$name,'op'=>'profile','direct'=>true,'items'=>[$item],'revision'=>0];
            $caption=$bot->text('instagram_user_caption',$this->values($item)); $path=$folder.'/instagram-avatar.jpg';
            $bytes=!empty($result['photo'])&&is_file($path)?(int)filesize($path):0;
            $timings['prepare_ms']=(int)round((hrtime(true)-$started)/1e6); $upload=hrtime(true);
            $id=$this->profileMessage($job,$caption.($bytes>0?'':"\n\n".$bot->text('instagram_user_no_photo')),$this->rows($job,$data),$bytes>0?$path:null,$bytes>0);
            if($bytes>0&&is_file($path)) unlink($path);
            $timings['upload_ms']=(int)round((hrtime(true)-$upload)/1e6);
            if($id!=='') $data['items'][0]['file_id']=$id;
            $store->query("UPDATE jobs SET status='done',options=?,expires=? WHERE id=?",[json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),time()+self::TTL,$job['id']]);
            return $bytes;
        } catch(\Throwable $error) {
            $reason=$error instanceof MediaFailure?$error->reason:'process';
            $sources=preg_match('/\[(instagram=(?:search|profile|avatar|runtime), http=(?:0|[1-5][0-9]{2}))\]$/D',$error->getMessage(),$detail)?$detail[1]:'';
            ErrorAlerts::report('instagram',match($reason) {'notfound'=>'restricted','unavailable'=>'format',default=>$reason},$sources);
            $key='instagram_user_error_'.(in_array($reason,['cookies','blocked','network','notfound','unavailable'],true)?$reason:'process');
            $store->query("UPDATE jobs SET status='failed',options='{}',expires=0 WHERE id=?",[$job['id']]); $bot->jobMessage($job,$bot->text($key)); return 0;
        }
    }
    public function callback(array $update,int $chat,int $user,int $thread,int $message): void
    {
        $cb=$update['callback_query'];
        if(!preg_match('/^iu:(\d{1,12}):(\d{1,9}):(x|s|b|[up]:\d{1,2})$/D',(string)($cb['data']??''),$m)) return;
        $store=$this->bot->store; $job=$store->query('SELECT * FROM jobs WHERE id=? AND phase=?',[(int)$m[1],self::PHASE])->fetch();
        $data=$job?json_decode((string)$job['options'],true):[]; $key=''; $op=$m[3];
        if(!$job||(int)$job['expires']<time()||empty($data['direct'])||empty($data['items'])) $key='instagram_user_expired';
        elseif((int)$job['user_id']!==$user||(int)$job['chat_id']!==$chat||(int)$job['message_id']!==$message||($chat<0&&isset($cb['message']['message_thread_id'])&&(int)$job['thread_id']!==$thread)) $key='instagram_user_owner';
        elseif(!self::enabled($store)) $key='instagram_user_disabled';
        elseif($job['status']!=='done'||(int)$data['revision']!==(int)$m[2]||!in_array($op,['s','x'],true)) $key='instagram_user_stale';
        elseif($op==='s'&&!self::stories($store)) $key='instagram_story_disabled';
        elseif($store->get('update_in_progress','0')==='1') $key='maintenance';
        if($key==='') {
            $thread=(int)$job['thread_id'];
            if($op==='x') {
                $store->query("UPDATE jobs SET options='{}',expires=0 WHERE id=?",[$job['id']]);
                $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id']]);
                try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); } catch(\Throwable) {} return;
            }
            $active=isset($data['story_job'])?$store->query('SELECT status FROM jobs WHERE id=?',[(int)$data['story_job']])->fetchColumn():false;
            if(in_array($active,['queued','running','confirming'],true)||(int)($data['story_pending']??0)>time()-30) $key='instagram_story_busy';
            else {
                $data['story_pending']=time(); $claimed=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                if(!$store->query("UPDATE jobs SET options=? WHERE id=? AND status='done' AND options=?",[$claimed,$job['id'],$job['options']])->rowCount()) $key='instagram_story_busy';
                else {
                    $name=instagramUsername((string)$data['items'][0]['username']);
                    $result=$name===null?'failed':$this->bot->requestVideo((int)$update['update_id'],$user,$chat,'https://www.instagram.com/stories/'.$name.'/', $thread,0,null,[],true);
                    unset($data['story_pending']);
                    if($result==='queued') $data['story_job']=(int)$store->query('SELECT id FROM jobs WHERE update_id=?',[(int)$update['update_id']])->fetchColumn();
                    $store->query('UPDATE jobs SET options=? WHERE id=? AND options=?',[json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$job['id'],$claimed]);
                    $key=match($result) {'queued'=>'instagram_story_accepted','busy'=>'busy','full'=>'queue_full','updating'=>'maintenance',default=>'instagram_story_failed'};
                }
            }
        }
        $this->bot->api->call('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$this->bot->ui->plain($key),'show_alert'=>$key!=='instagram_story_accepted','cache_time'=>0]);
    }
}
