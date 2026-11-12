<?php
declare(strict_types=1);
namespace Zero;

/** Best-effort private alerts. No outbox, disk log or error-history table. */
final class ErrorAlerts
{
    private static ?Config $config=null;
    private static ?Store $store=null;
    private static ?Api $api=null;
    private static bool $sending=false;
    private static array $sent=[];
    public static function boot(Config $config,Store $store,?Api $api=null): void
    {
        self::$config=$config; self::$store=$store; self::$api=$api??new Telegram($config);
        ini_set('log_errors','0'); ini_set('display_errors','0');
        set_exception_handler(static function(\Throwable $e): void { self::report('runtime','process'); exit(1); });
        set_error_handler(static function(int $severity): bool {
            if(error_reporting()&$severity) self::report('runtime','process');
            return true;
        });
        register_shutdown_function(static function(): void {
            $error=error_get_last();
            if($error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) self::report('runtime','process');
        });
    }
    public static function bind(Config $config,Store $store,Api $api): void
    {
        if(self::$config!==null) { self::$config=$config; self::$store=$store; self::$api=$api; }
    }
    public static function report(string $service,string $reason,string $sources=''): void
    {
        if(self::$sending||self::$config===null||self::$store===null) return;
        $service=in_array($service,['runtime','worker','poller','radio','batch','menu','article','youtube',...adultServices(),...galleryServices(),...musicServices()],true)?$service:'runtime';
        $reason=in_array($reason,['storage','network','blocked','cookies','restricted','format','size','duration','upload','process'],true)?$reason:'process';
        $sources=$reason!=='storage'&&preg_match('/^[a-z]{1,16}=[a-z]{1,20}(?:, [a-z]{1,16}=[a-z]{1,20}){0,4}$/D',$sources)?$sources:'';
        $key=$service.':'.$reason.':'.$sources;
        // Process-local rate limit only; failed notifications never recurse or queue on disk.
        if(time()-(self::$sent[$key]??0)<60) return;
        if(count(self::$sent)>128) self::$sent=[];
        self::$sent[$key]=time(); self::$sending=true;
        try {
            $ui=new Ui(self::$store);
            $text=$ui->text('admin_error_alert',['service'=>$service,'reason'=>$reason,'sources'=>$sources]);
            foreach(self::$store->administratorIds(self::$config->admins) as $admin) {
                if(!self::$store->isAdmin($admin,self::$config->admins)) continue;
                try {
                    $params=['chat_id'=>$admin,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true];
                    if(self::$api instanceof Telegram) self::$api->alert($params);
                    else self::$api->call('sendMessage',$params);
                } catch(\Throwable) { /* No logging and no alert about a failed alert. */ }
            }
        } catch(\Throwable) {} finally { self::$sending=false; }
    }
    /** Legacy diagnostic call sites supply no raw text to Telegram. */
    public static function event(string $detail): void
    {
        $service='runtime';
        foreach(['radio','worker','poller','cookie','batch','gallery'] as $name) if(str_contains(strtolower($detail),$name)) { $service=in_array($name,['radio','worker','poller','batch'],true)?$name:'runtime'; break; }
        self::report($service,'process');
    }
}
