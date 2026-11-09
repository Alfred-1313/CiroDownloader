<?php
declare(strict_types=1);
namespace Zero;

/**
 * The two search commands, in private chats and groups:
 *   /music pishro-separ   songs for "artist-title" (or any words), as a list to tap
 *   /spotify shayea       the menu of the artist with that name
 * A search travels as a Spotify search address (`searchAddress`), so it is queued, limited and answered like a pasted
 * music link: one status message that becomes the result menu. `Bot::requestVideo` does the queueing.
 */
final class MusicSearch
{
    /** Command name => kind of search. */
    public const COMMANDS=['music'=>'tracks','spotify'=>'artists'];
    /** What Telegram's "/" menu shows to everybody; administrators' own menu (AdminCommands) ends with the same two. */
    public const PUBLIC_MENU=['start','help','music','spotify'];

    /** @return ?array{command:string,argument:string,bot:string} a search command with its text, or null for any other message */
    public static function parse(string $text): ?array
    {
        if(preg_match('~^/(music|spotify)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~sD',trim($text),$parts)!==1) return null;
        return ['command'=>$parts[1],'argument'=>trim($parts[3]??''),'bot'=>$parts[2]??''];
    }

    /** Queue the search, or explain how to write it when the text is missing. */
    public static function handle(Bot $bot,array $update,int $user,int $chat,int $thread,array $command): void
    {
        $query=searchQuery($command['argument']);
        if($query===null) { $bot->send($chat,$bot->text($command['command'].'_usage'),[],0,$thread); return; }
        $bot->requestVideo((int)$update['update_id'],$user,$chat,searchAddress(self::COMMANDS[$command['command']],$query),$thread,(int)($update['message']['message_id']??0));
    }

    /** @return list<array{command:string,description:string}> */
    public static function commands(Ui $ui,array $names): array
    {
        $menu=[];
        foreach($names as $name) $menu[]=['command'=>$name,'description'=>mb_substr($ui->plain('command_'.$name),0,256)];
        return $menu;
    }
    /** The two search commands as an administrator's menu entries (added to AdminCommands' list). */
    public static function adminMenu(Ui $ui): array { return self::commands($ui,array_keys(self::COMMANDS)); }

    /**
     * Show the commands to everyone who has no menu of their own. Sent again only when the list or its wording changed
     * and never more than once an hour on failure; a refusal never stops a message from being answered.
     */
    public static function register(Api $api,Store $store,Ui $ui): void
    {
        $menu=self::commands($ui,self::PUBLIC_MENU); $hash=hash('sha256',json_encode($menu,JSON_UNESCAPED_UNICODE));
        if($store->get('commands.public')===$hash||time()-(int)$store->get('commands.public_tried','0')<3600) return;
        $store->set('commands.public_tried',(string)time());
        try {
            $api->call('setMyCommands',['commands'=>$menu,'scope'=>['type'=>'default']]);
            $store->set('commands.public',$hash);
        } catch(\Throwable) { /* Telegram may refuse; the next hour tries again. */ }
    }
}
