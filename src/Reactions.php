<?php
declare(strict_types=1);
namespace Zero;

final class Reactions
{
    public const DEFAULT_EMOJI='👾';
    public const PRESETS=['👾','👍','❤','🔥','👀','🎉','👏','⚡'];
    // Standard Telegram ReactionTypeEmoji identifiers; arbitrary emoji are not reactions.
    public const EMOJI=['👍','👎','❤','🔥','🥰','👏','😁','🤔','🤯','😱','🤬','😢','🎉','🤩','🤮','💩','🙏','👌','🕊','🤡','🥱','🥴','😍','🐳','❤️‍🔥','🌚','🌭','💯','🤣','⚡','🍌','🏆','💔','🤨','😐','🍓','🍾','💋','🖕','😈','😴','😭','🤓','👻','👨‍💻','👀','🎃','🙈','😇','😨','🤝','✍','🤗','🫡','🎅','🎄','☃','💅','🤪','🗿','🆒','💘','🙉','🦄','😘','💊','🙊','😎','👾','🤷‍♂️','🤷','🤷‍♀️','😡'];
    public static function normalize(string $input): ?string
    {
        $value=str_replace("\u{FE0F}",'',trim($input));
        foreach(self::EMOJI as $emoji) if(str_replace("\u{FE0F}",'',$emoji)===$value) return $emoji;
        return null;
    }
    /**
     * The reaction for a link. Telegram has no music emoji among the reactions a bot may use, so links to music
     * services get their own choice, by default 🔥. When reactions are off, nothing is sent for any link.
     */
    public const DEFAULT_MUSIC='🔥';
    public static function selected(Store $store,bool $music=false): string
    {
        $general=self::normalize($store->get('link_reaction',self::DEFAULT_EMOJI))??'';
        if(!$music||$general==='') return $general;
        $choice=$store->get('link_reaction.music','');
        return $choice==='same'?$general:(self::normalize($choice)??self::DEFAULT_MUSIC);
    }
    public static function apply(Store $store,Api $api,int $chat,int $message,bool $music=false): void
    {
        $emoji=self::selected($store,$music);
        if($message<1||$emoji==='') return;
        try { $api->call('setMessageReaction',['chat_id'=>$chat,'message_id'=>$message,'reaction'=>[['type'=>'emoji','emoji'=>$emoji]]]); }
        catch(\Throwable $error) { error_log('Link reaction unavailable: code='.(int)$error->getCode()); }
    }
}
