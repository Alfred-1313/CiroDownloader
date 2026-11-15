<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryMedia.php';

/** Story media shares Instagram's extractor, but never its post-download cookie pool. */
final class InstagramStories extends GalleryMedia
{
    protected static function cookiePath(Config $config): string { return $config->instagramStoriesCookiePath(); }
    public static function enabled(Store $store): bool { return $store->get('instagram.stories','0')==='1'; }
    public static function parse(string $text): ?array
    {
        if(!preg_match('~^/story_instagram(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~sD',trim($text),$m)) return null;
        return ['bot'=>$m[1]??'','argument'=>trim($m[2]??'')];
    }
    public static function handle(Bot $bot,array $update,int $user,int $chat,int $thread,array $command): void
    {
        if(($command['bot']??'')!==''&&strcasecmp($command['bot'],$bot->store->get('bot_username'))!==0) return;
        if(!$bot->allowed($user,$chat)) { $bot->send($chat,$bot->denial($chat),[],0,$thread); return; }
        $name=instagramUsername((string)$command['argument']);
        if($name===null) { $bot->send($chat,$bot->text('instagram_story_usage'),[],0,$thread); return; }
        $bot->requestVideo((int)$update['update_id'],$user,$chat,'https://www.instagram.com/stories/'.$name.'/', $thread,(int)($update['message']['message_id']??0));
    }
}
