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
    $store->pushError('eporner','network','eporner=forbidden');
    alertCheck(array_column(array_column($api->calls,1),'chat_id')===[42,55],'errors go privately only to bootstrap-authorized current administrators');
    alertCheck(str_contains($api->calls[0][1]['text'],'eporner=forbidden'),'a structured alert contains the service, reason and clean source cause');
    $store->pushError('eporner','network','eporner=forbidden');
    alertCheck(count($api->calls)===2,'repeated errors are throttled in process memory without a persistent outbox');
    $store->pushError('eporner','storage','https://secret.invalid/token');
    alertCheck(!str_contains(json_encode($api->calls),'secret.invalid'),'raw URLs and arbitrary diagnostics never enter notifications');
    $api->fail=true; $store->pushError('batch','upload');
    alertCheck(count($api->calls)===6,'a rejected alert never recurses or retries delivery');
    $store->set('last_job_error','in-flight only'); $store->set('recent_errors','must not persist');
    $reopen=new Zero\Store($root);
    alertCheck($reopen->get('last_job_error')===''&&$reopen->get('recent_errors')===''&&$reopen->get('preserve')==='settings','request diagnostics disappear with their process and settings survive');
    alertCheck((int)$store->query("SELECT COUNT(*) FROM settings WHERE key IN ('recent_errors','last_job_error','last_job_error_reason','last_job_sources','last_job_service')")->fetchColumn()===0,'no error-history rows are written');
    alertCheck(ini_get('log_errors')==='0'&&ini_get('display_errors')==='0','runtime PHP diagnostics are not written to server logs');
} finally {
    restore_error_handler(); restore_exception_handler();
    require_once dirname(__DIR__).'/src/Download.php'; Zero\Download::clean($root);
}
