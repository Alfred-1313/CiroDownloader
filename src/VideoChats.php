<?php
declare(strict_types=1);
namespace Zero;

/**
 * Telegram tells a bot when a video chat or live stream starts or ends in a group or channel (a service message), but a
 * bot cannot join, start or hear one. This only passes that news on: the administrators get one private message.
 * Groups count only when a bot administrator started the chat (the bot may sit in many groups); a channel can only be
 * reached by the bot after one of its administrators added it. Nothing is stored and no message text is read or kept.
 */
final class VideoChats
{
    private const EVENTS=['video_chat_started'=>'started','video_chat_ended'=>'ended'];

    /** @return bool true when the update was a channel post or a video chat service message, so nothing else should handle it */
    public static function handle(Bot $bot,array $update): bool
    {
        $channel=is_array($update['channel_post']??null);
        $post=$channel?$update['channel_post']:($update['message']??null);
        if(!is_array($post)) return false;
        $event=null;
        foreach(self::EVENTS as $field=>$name) if(isset($post[$field])) $event=[$field,$name];
        if($channel&&$bot->store->get('channel_post_logged','0')<(string)(time()-600)) {
            // Which kinds of update reach the bot from channels: field names only, never content, at most every ten minutes.
            $bot->store->set('channel_post_logged',(string)time());
            error_log('Channel update: '.($event!==null?$event[0]:implode(',',array_slice(array_keys($post),0,8))));
        }
        if($event===null) return $channel;
        $chat=is_array($post['chat']??null)?$post['chat']:[];
        $title=trim((string)($chat['title']??''));
        $starter=(int)($post['from']['id']??0); $chatId=(int)($chat['id']??0);
        // The radio of this chat: its stream is live or not, and a chat where an administrator started one can be set up.
        try {
            $radio=new Radio($bot->config,$bot->store);
            if($event[1]==='ended') $radio->setLive($chatId,false);
            else {
                $radio->setLive($chatId,true);
                if($chatId!==0&&($channel||($starter>0&&Radio::chatAdmin($bot->api,$bot->store,$chatId,$starter)))) $radio->seen($chatId,$title,$channel?'channel':'group');
            }
        } catch(\Throwable) { /* The news to the administrators does not depend on the radio. */ }
        if(!$channel&&!($starter>0&&$bot->admin($starter))) return true;
        $text=$event[1]==='started'?$bot->text('video_chat_started',['chat'=>$title!==''?$title:'—']):$bot->text('video_chat_ended',['chat'=>$title!==''?$title:'—',
            'duration'=>Ui::duration(max(0,(int)($post[$event[0]]['duration']??0)))]);
        foreach($bot->store->administratorIds($bot->config->admins) as $admin) {
            try { $bot->api->call('sendMessage',['chat_id'=>$admin,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true]); }
            catch(\Throwable) { /* An administrator who never started the bot cannot be reached. */ }
        }
        return true;
    }
}
