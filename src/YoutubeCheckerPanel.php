<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/InstagramCheckerPanel.php';
require_once __DIR__.'/YoutubeChecker.php';
final class YoutubeCheckerPanel extends InstagramCheckerPanel
{
    protected const SERVICE='youtube', CALLBACK='yc', CHECKER=YoutubeChecker::class;
    public static function command(string $text): ?array
    {
        if(!preg_match('~^/(?:youtube_checker|yt_checker)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~sD',trim($text),$m)) return null;
        return ['bot'=>$m[1]??'','argument'=>trim($m[2]??'')];
    }
    protected function openCookies(int $chat,int $owner): void { (new YoutubeCheckerCookies($this->bot))->handle($chat,$owner,'cookie_youtube_checker'); }
    protected function renderPanel(array $p,string $view,array &$data,string $text,array $rows,?array $sub): int
    {
        $profile=$sub?$this->checker->profile($sub):null;
        if($view==='new'&&isset($data['username'])) {
            $profile=$this->checker->previewProfile((string)$data['username'],(int)$p['owner'],(int)$p['chat_id']);
            $sub=$profile?['target'=>$profile['id'],'generation'=>0,'paused'=>0]:null;
            if(!$profile) foreach(['profile_version','profile_generation','profile_target','profile_wait'] as $key) unset($data[$key]);
        }
        if($profile) {
            $data['profile_version']=(int)$profile['avatar_version'];
            $data['profile_generation']=(int)$sub['generation'];
            $data['profile_target']=(int)$sub['target'];
            $data['profile_wait']=$profile['avatar']===''&&(int)$profile['avatar_checked']<time()-300&&!(int)$sub['paused'];
        }
        $avatar=$profile['avatar']??''; $photo=(string)($p['photo']??'');
        $chat=(int)$p['chat_id']; $message=(int)$p['message_id']; $api=$this->bot->api;
        $previous=json_decode($p['data']??'{}',true);
        // An optional missing avatar must not create a new automatic message for the same channel.
        if($sub&&$photo!==''&&!YoutubeChecker::avatarUrl($avatar)&&(int)($previous['profile_target']??0)===(int)$sub['target']) {
            $api->call('editMessageCaption',['chat_id'=>$chat,'message_id'=>$message,'caption'=>Ui::fitCaption($text),'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>$rows]]);
            return $message;
        }
        if(YoutubeChecker::avatarUrl($avatar)) {
            $caption=Ui::fitCaption($text); $params=['chat_id'=>$chat,'reply_markup'=>['inline_keyboard'=>$rows]];
            if($message>0&&$photo!==''&&($p['photo_source']??'')===$avatar) {
                $api->call('editMessageCaption',$params+['message_id'=>$message,'caption'=>$caption,'parse_mode'=>'HTML']);
                return $message;
            }
            try {
                $image=$profile['avatar_file']?:$avatar;
                if($message>0) $result=$api->call('editMessageMedia',$params+['message_id'=>$message,'media'=>['type'=>'photo','media'=>$image,'caption'=>$caption,'parse_mode'=>'HTML']]);
                else {
                    if($chat<0&&(int)$p['thread_id']>0) $params['message_thread_id']=(int)$p['thread_id'];
                    $result=$api->call('sendPhoto',$params+['photo'=>$image,'caption'=>$caption,'parse_mode'=>'HTML']);
                }
                if(!is_array($result)||empty($result['message_id'])) throw new \RuntimeException('Missing checker photo confirmation');
                $message=(int)$result['message_id']; $photos=$result['photo']??[];
                $file=$photos?(string)(end($photos)['file_id']??''):'';
                $this->query('UPDATE {checker}panels SET photo=?,photo_source=? WHERE token=?',[$file?:$image,$avatar,$p['token']]);
                if($file!=='') $this->query('UPDATE {checker}targets SET avatar_file=? WHERE id=? AND avatar=?',[$file,$sub['target'],$avatar]);
                return $message;
            } catch(\RuntimeException $e) {
                if($e->getCode()!==400) throw $e;
                // Artwork is optional. A rejected new picture keeps the current caption and controls usable.
                if($photo!==''&&(int)($previous['profile_target']??0)===(int)$sub['target']) {
                    $api->call('editMessageCaption',$params+['message_id'=>$message,'caption'=>$caption,'parse_mode'=>'HTML']);
                    return $message;
                }
            }
        }
        $sent=CheckerMessages::text($this->bot,$p,$text,$rows);
        $this->query("UPDATE {checker}panels SET photo='',photo_source='' WHERE token=?",[$p['token']]);
        return $sent;
    }

    /** Enrich only the still-open selection/details message; never register a preview as a subscription. */
    public function refreshProfiles(): void
    {
        foreach($this->checker->profilePanels() as $p) {
            $data=json_decode($p['data'],true); $sub=$p['view']==='item'?$this->item($p,(int)$data['id']):null;
            $destination=$sub?(int)$sub['chat_id']:(int)$p['chat_id'];
            if(($p['view']==='item'&&!$sub)||!$this->checker->allowed((int)$p['owner'],$destination)) {
                $profile=$this->checker->profile(['target'=>$data['profile_target']]);
                $data['profile_version']=(int)($profile['avatar_version']??0);
                $this->query('UPDATE {checker}panels SET data=? WHERE token=? AND data=?',[json_encode($data,JSON_THROW_ON_ERROR),$p['token'],$p['data']]); continue;
            }
            if(!$this->query('SELECT 1 FROM {checker}panels WHERE token=? AND view=? AND data=? AND message_id=?',[$p['token'],$p['view'],$p['data'],$p['message_id']])->fetchColumn()) continue;
            try { $this->show($p,$p['view'],$data,'',true); }
            catch(\Throwable) {
                // No retry sends or diagnostic DMs for missing/rejected/uncertain artwork.
                $profile=$this->checker->profile(['target'=>$data['profile_target']]); $data['profile_version']=(int)$profile['avatar_version'];
                $this->query('UPDATE {checker}panels SET data=? WHERE token=? AND data=?',[json_encode($data,JSON_THROW_ON_ERROR),$p['token'],$p['data']]);
            }
        }
    }
}
