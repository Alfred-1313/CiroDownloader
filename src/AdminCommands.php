<?php
declare(strict_types=1);
namespace Zero;

/**
 * Every command an administrator can type, in the order the management panel lists them.
 * A new command is one name here and one `admin_cmd_<name>` text in resources/ui.json; the panel text and
 * Telegram's "/" menu for administrators are built from this list, so they cannot drift apart.
 */
final class AdminCommands
{
    public const LIST=['admin','health','cookies','update','customize','id','help','cancel','start'];

    /** One tappable line per command for the panel message. */
    public static function lines(Ui $ui): string
    {
        $lines=[];
        foreach(self::LIST as $name) $lines[]='/'.$name.' — '.Ui::escape($ui->plain('admin_cmd_'.$name));
        return implode("\n",$lines);
    }
    /** @return list<array{command:string,description:string}> the menu Telegram shows after "/" */
    public static function menu(Ui $ui): array
    {
        $menu=[];
        foreach(self::LIST as $name) $menu[]=['command'=>$name,'description'=>mb_substr($ui->plain('admin_cmd_'.$name),0,256)];
        return $menu;
    }
    /**
     * Show the commands in this administrator's "/" menu (only theirs, never the ordinary users').
     * Sent again only when the list or its wording changed; a refusal never stops the panel from opening.
     */
    public static function register(Api $api,Store $store,Ui $ui,int $chat): void
    {
        $menu=self::menu($ui); $hash=hash('sha256',json_encode($menu,JSON_UNESCAPED_UNICODE));
        if($store->get('commands.'.$chat)===$hash) return;
        try {
            $api->call('setMyCommands',['commands'=>$menu,'scope'=>['type'=>'chat','chat_id'=>$chat]]);
            $store->set('commands.'.$chat,$hash);
        } catch(\Throwable) { /* Telegram may refuse; try again the next time the panel opens. */ }
    }
}
