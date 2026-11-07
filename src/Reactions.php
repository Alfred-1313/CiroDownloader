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
    public static function selected(Store $store): string
    {
        return self::normalize($store->get('link_reaction',self::DEFAULT_EMOJI))??'';
    }
    public static function apply(Store $store,Api $api,int $chat,int $message): void
    {
        $emoji=self::selected($store);
        if($message<1||$emoji==='') return;
        try { $api->call('setMessageReaction',['chat_id'=>$chat,'message_id'=>$message,'reaction'=>[['type'=>'emoji','emoji'=>$emoji]]]); }
        catch(\Throwable $error) { error_log('Link reaction unavailable: code='.(int)$error->getCode()); }
    }
}
