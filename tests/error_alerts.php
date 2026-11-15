<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
function alertCheck(bool $ok,string $message): void { if(!$ok) { fwrite(STDERR,"FAIL $message\n"); exit(1); } echo "PASS $message\n"; }
$root=sys_get_temp_dir().'/ciro-alerts-'.bin2hex(random_bytes(5));
try {
    $config=new Zero\Config('123:fixture',[42],$root); $store=new Zero\Store($root);
    $store->query("DELETE FROM settings WHERE key='error_history_removed'");
    foreach(['recent_errors','last_job_error','last_job_sources','last_job_service','last_job_error_reason'] as $key) $store->query('INSERT INTO settings(key,value) VALUES(?,?)',[$key,'old-private-diagnostic']);
    $store=new Zero\Store($root);
    alertCheck((int)$store->query("SELECT COUNT(*) FROM settings WHERE value='old-private-diagnostic'")->fetchColumn()===0,'upgrade removes historical errors without touching application data');
    $store->set('preserve','settings'); $store->addAdministrator(55,42,[42]);
    $store->query('INSERT INTO bot_admins(user_id,added_by,created) VALUES(77,88,?)',[time()]);
    $api=new class implements Zero\Api {
        public array $calls=[]; public bool $fail=false;
        public function call(string $method,array $params=[]): mixed {
            $this->calls[]=[$method,$params];
            Zero\ErrorAlerts::event('A failed notification must not recurse');
            if($this->fail) throw new RuntimeException('secret https://example.invalid/private');
            return ['message_id'=>123];
        }
    };
    Zero\ErrorAlerts::boot($config,$store,$api);
    for($i=0;$i<1000;$i++) {
        $store->pushError('eporner','network','eporner=forbidden');
        Zero\ErrorAlerts::report('runtime','process');
        Zero\ErrorAlerts::event('Worker failure with private diagnostic');
    }
    alertCheck($api->calls===[],'repeated worker, runtime and legacy diagnostics never contact Telegram or administrators');
    $store->pushError('eporner','storage','https://secret.invalid/token');
    $api->fail=true; $store->pushError('batch','upload');
    alertCheck($api->calls===[],'even a failing Telegram transport is never invoked or retried for technical alerts');
    $store->set('last_job_error','in-flight only'); $store->set('recent_errors','must not persist');
    $reopen=new Zero\Store($root);
    alertCheck($reopen->get('last_job_error')===''&&$reopen->get('recent_errors')===''&&$reopen->get('preserve')==='settings','request diagnostics disappear with their process and settings survive');
    alertCheck((int)$store->query("SELECT COUNT(*) FROM settings WHERE key IN ('recent_errors','last_job_error','last_job_error_reason','last_job_sources','last_job_service')")->fetchColumn()===0,'no error-history rows are written');
    alertCheck(ini_get('log_errors')==='0'&&ini_get('display_errors')==='0','runtime PHP diagnostics are not written to server logs');
    $api->fail=false;
    Zero\ErrorAlerts::report('instagram','blocked','instagram=profile, http=429');
    Zero\ErrorAlerts::report('instagram','format','instagram=search, http=404, username=private');
    trigger_error('Private runtime diagnostic',E_USER_WARNING);
    alertCheck($api->calls===[],'HTTP failures and native PHP warnings also remain silent');
} finally {
    restore_error_handler(); restore_exception_handler();
    require_once dirname(__DIR__).'/src/Download.php'; Zero\Download::clean($root);
}
