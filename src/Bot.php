<?php
declare(strict_types=1);
namespace Zero;

final class Bot
{
    public const TEXTS = [
        'welcome' => ['Welcome message', "<b>Welcome to Ciro Downloader</b>\n\nSend a YouTube video or Shorts link and I will download an MP4 up to 480p.\n\nUse the buttons below to get started."],
        'help' => ['Help message', "<b>How it works</b>\n\n1. Send one YouTube video or Shorts link.\n2. Wait while the video is downloaded and prepared.\n3. Receive the MP4 file here.\n\nLive streams, playlists and videos longer than 20 minutes are not supported. One active download is allowed per user."],
        'offline' => ['Maintenance message', 'The downloader is temporarily paused. Please try again later.'],
        'prompt' => ['Link prompt', '<b>Ready when you are</b>\n\nSend a YouTube video or Shorts link.'],
        'queued' => ['Queue message', 'Your request is queued. I will send an update when processing starts.'],
        'downloading' => ['Download status', 'Downloading and preparing your video…'],
        'uploading' => ['Upload status', 'Download complete. Uploading the video now…'],
        'video_caption' => ['File caption', "<b>Your video is ready</b>\n\n<b>{title}</b>\nQuality: <code>{quality}</code>\n{url}"],
        'failed' => ['Download error', 'The video could not be downloaded or uploaded. It may be restricted, live, too long or larger than the configured file limit.'],
        'invalid' => ['Invalid link message', 'That does not look like a valid YouTube video link. Please send a youtube.com or youtu.be URL.'],
        'restarted' => ['Restart message', 'The worker restarted while processing your request. If you did not receive a file, please send the link again.'],
    ];
    public const BUTTONS = [
        'download' => ['Download button', 'Download video'], 'help' => ['Help button', 'Help'],
        'admin' => ['Admin button', 'Admin panel'], 'on' => ['Enable button', 'Enable downloader'],
        'off' => ['Disable button', 'Pause downloader'],
        'texts' => ['Message settings button', 'Edit messages'],
        'buttons' => ['Button settings button', 'Edit buttons'],
        'back' => ['Back button', 'Back'],
    ];
    public function __construct(public Config $config, public Store $store, public Api $api) {}
    public function text(string $key): string { return $this->store->get('text.'.$key, str_replace('\\n', "\n", self::TEXTS[$key][1])); }
    public function label(string $key): string { return $this->store->get('button.'.$key, self::BUTTONS[$key][1]); }
    public function admin(int $id): bool { return in_array($id, $this->config->admins, true); }
    public function button(string $key, string $data): array { return ['text'=>$this->label($key), 'callback_data'=>$data]; }
    public function replyButton(string $key): array { return ['text'=>$this->label($key)]; }
    public function send(int $chat, string $text, array $rows = []): void
    {
        $params = ['chat_id'=>$chat, 'text'=>$text, 'parse_mode'=>'HTML', 'disable_web_page_preview'=>true];
        if ($rows) $params['reply_markup'] = ['inline_keyboard'=>$rows];
        $this->api->call('sendMessage', $params);
    }
    public function sendKeyboard(int $chat, string $text, array $rows): void
    {
        $this->api->call('sendMessage', [
            'chat_id'=>$chat, 'text'=>$text, 'parse_mode'=>'HTML', 'disable_web_page_preview'=>true,
            'reply_markup'=>['keyboard'=>$rows, 'resize_keyboard'=>true, 'is_persistent'=>true],
        ]);
    }
    public function home(int $chat, int $user): void
    {
        if ($this->admin($user)) {
            $this->sendKeyboard($chat, $this->text('welcome'), [
                [$this->replyButton('download'), $this->replyButton('help')],
                [$this->replyButton('admin')],
            ]);
            return;
        }
        $rows = [[$this->button('download','download'),$this->button('help','help')]];
        $this->send($chat, $this->text('welcome'), $rows);
    }
    public function panel(int $chat): void
    {
        $on = $this->store->enabled();
        $this->send($chat, '<b>Admin panel</b>\nStatus: <b>'.($on ? 'Enabled' : 'Paused').'</b>', [
            [$this->button($on?'off':'on', $on?'set:off':'set:on')],
            [$this->button('texts','list:text')],[$this->button('buttons','list:button')],
            [$this->button('back','home')],
        ]);
    }
    public function catalog(string $kind): array { return $kind === 'text' ? self::TEXTS : ($kind === 'button' ? self::BUTTONS : []); }
    public function listItems(int $chat, string $kind): void
    {
        $rows=[];
        foreach ($this->catalog($kind) as $key=>$item) $rows[]=[['text'=>$item[0], 'callback_data'=>"item:$kind:$key"]];
        $rows[]=[$this->button('back','admin')];
        $this->send($chat, '<b>Choose a section</b>\nSelect the messages or buttons you want to manage.', $rows);
    }
    public function item(int $chat, string $kind, string $key): void
    {
        if (!isset($this->catalog($kind)[$key])) return;
        $current=$kind==='text'?$this->text($key):$this->label($key);
        $default=str_replace('\\n',"\n",$this->catalog($kind)[$key][1]);
        $current=htmlspecialchars($current, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $default=htmlspecialchars($default, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->send($chat,"<b>Current value</b>\n$current\n\n<b>Default value</b>\n$default",[
            [['text'=>'Edit value','callback_data'=>"edit:$kind:$key"]],
            [['text'=>'Restore default','callback_data'=>"reset:$kind:$key"]],
            [$this->button('back',"list:$kind")],
        ]);
    }
    public function handle(array $update): void
    {
        $cb=$update['callback_query']??null;
        $message=$cb['message']??$update['message']??null;
        $user=(int)($cb['from']['id']??$message['from']['id']??0);
        if (!$message || ($message['chat']['type']??'')!=='private' || !$user) return;
        $chat=(int)$message['chat']['id'];
        $this->store->query('INSERT OR IGNORE INTO users(id) VALUES(?)',[$user]);
        if ($cb) {
            $action=(string)($cb['data']??'');
            $privileged = !in_array($action,['home','download','help'],true);
            $isHelp = $action === 'help';
            // Check every callback, including forged/stale ones, before state changes.
            $this->api->call('answerCallbackQuery',[
                'callback_query_id'=>$cb['id'],
                'text'=>($privileged&&!$this->admin($user))?'Admin access required.':($isHelp?'Help is shown below.':''),
                'show_alert'=>$isHelp,
                'cache_time'=>1,
            ]);
            if ($privileged&&!$this->admin($user)) return;
            if (!$this->store->enabled()&&!$this->admin($user)) { $this->send($chat,$this->text('offline')); return; }
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            if ($action==='home') $this->home($chat,$user);
            elseif ($action==='help') $this->send($chat,$this->text('help'));
            elseif ($action==='download') $this->send($chat,$this->text('prompt'));
            elseif ($action==='admin') $this->panel($chat);
            elseif (in_array($action,['set:on','set:off'],true)) {
                $this->store->set('enabled',$action==='set:on'?'1':'0'); $this->panel($chat);
            } else {
                $parts=explode(':',$action); [$op,$kind,$key]=array_pad($parts,3,'');
                if ($op==='list' && $this->catalog($kind)) $this->listItems($chat,$kind);
                elseif (isset($this->catalog($kind)[$key])) {
                    if ($op==='item') $this->item($chat,$kind,$key);
                    elseif ($op==='reset') {
                        $this->store->query('DELETE FROM settings WHERE key=?',["$kind.$key"]); $this->item($chat,$kind,$key);
                    } elseif ($op==='edit') {
                        $this->store->query('INSERT OR REPLACE INTO states(user_id,key,expires) VALUES(?,?,?)',[$user,"$kind.$key",time()+600]);
                        $this->send($chat,'<b>Send the new value</b>\nUse /cancel to stop editing.'.($key==='video_caption'?"\nAvailable variables: <code>{title}</code>, <code>{quality}</code>, <code>{url}</code>":''));
                    }
                }
            }
            return;
        }
        $text=trim((string)($message['text']??''));
        if ($text==='') return;
        if ($text==='/id') { $this->send($chat,"Your Telegram ID: <code>$user</code>"); return; }
        if (in_array($text,['/cancel','/start','/admin'],true)) $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
        if (($text==='/admin'||$text===$this->label('admin'))&&$this->admin($user)) { $this->panel($chat); return; }
        if (!$this->store->enabled()&&!$this->admin($user)) { $this->send($chat,$this->text('offline')); return; }
        if (in_array($text,['/start','/cancel'],true)) { $this->home($chat,$user); return; }
        if ($text===$this->label('help')) { $this->send($chat,$this->text('help')); return; }
        if ($text===$this->label('download')) { $this->send($chat,$this->text('prompt')); return; }
        $state=$this->store->query('SELECT key FROM states WHERE user_id=? AND expires>?',[$user,time()])->fetchColumn();
        if ($state && $this->admin($user)) {
            [$kind,$key]=explode('.',$state,2);
            if (!isset($this->catalog($kind)[$key])) return;
            $limit=$kind==='button'?48:($key==='video_caption'?700:1200);
            if (mb_strlen($text)>$limit || ($kind==='button'&&str_contains($text,"\n"))) {
                $this->send($chat,"The value must be at most $limit characters; button labels must stay on one line."); return;
            }
            $this->store->set($state,$text);
            $this->store->query('DELETE FROM states WHERE user_id=?',[$user]);
            $this->item($chat,$kind,$key); return;
        }
        $url=youtubeUrl($text);
        if (!$url) { $this->send($chat,$this->text('invalid')); return; }
        $result=$this->store->enqueue((int)$update['update_id'],$user,$chat,$url);
        $response=match($result) {
            'busy'=>'Your previous request is still being processed.', 'full'=>'The queue is full. Please try again shortly.',
            'cooldown'=>'Please wait 15 seconds between requests.', 'duplicate'=>null,
            default=>$this->text('queued'),
        };
        if ($response!==null) $this->send($chat,$response);
    }
}
