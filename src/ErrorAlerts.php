<?php
declare(strict_types=1);
namespace Zero;

/** Technical alerts are paused. No Telegram fanout, outbox or persistent logs. */
final class ErrorAlerts
{
    public static function boot(Config $config,Store $store,?Api $api=null): void
    {
        ini_set('log_errors','0'); ini_set('display_errors','0');
        set_exception_handler(static function(\Throwable $e): void { exit(1); });
        set_error_handler(static function(int $severity): bool { return true; });
    }
    /** Keep existing call sites compatible without retaining administrator/API state. */
    public static function bind(Config $config,Store $store,Api $api): void {}
    public static function report(string $service,string $reason,string $sources=''): void {}
    public static function event(string $detail): void {}
}
