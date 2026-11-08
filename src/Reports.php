<?php
declare(strict_types=1);
namespace Zero;

/**
 * The administrator's status reports, in the style of the update screens: a title, state lines between separators,
 * and a row of buttons (the other reports, refresh, close) under one message that is edited in place.
 * Callback data is "rp:SCREEN"; only administrators reach it.
 */
final class Reports
{
    public const SCREENS=['stats','services','cookies','errors','server'];
    private const SERVICES=['youtube','instagram','pinterest','tiktok','twitter','spotify','soundcloud','menu','batch'];
    public function __construct(private Bot $bot) {}

    /** The buttons under the main status message. */
    public static function homeRows(Ui $ui): array
    {
        $rows=[[$ui->button('report_stats','rp:stats'),$ui->button('report_services','rp:services')],[$ui->button('report_cookies','rp:cookies'),$ui->button('report_errors','rp:errors')],[$ui->button('report_server','rp:server')]];
        $rows[]=[$ui->button('report_close','rp:x')];
        return $rows;
    }

    private function ago(int $time): string
    {
        $ui=$this->bot->ui; if($time<1) return $ui->plain('report_never');
        $seconds=max(0,time()-$time);
        return $seconds<3600?$ui->plainWith('report_ago_m',['n'=>(string)max(1,intdiv($seconds,60))]):($seconds<172800?$ui->plainWith('report_ago_h',['n'=>(string)intdiv($seconds,3600)]):$ui->plainWith('report_ago_d',['n'=>(string)intdiv($seconds,86400)]));
    }
    private function seconds(float $ms): string { return $this->bot->ui->plainWith('report_seconds',['n'=>number_format($ms/1000,1)]); }
    private function rate(int $ok,int $failed): string { return $ok+$failed>0?round(100*$ok/($ok+$failed)).'٪':'—'; }
    private function name(string $code): string { $ui=$this->bot->ui; return $ui->definition('text','report_name_'.$code)?$ui->plain('report_name_'.$code):$code; }
    private function sums(int $days): array
    {
        $from=gmdate('Y-m-d',time()-($days-1)*86400);
        return $this->bot->store->query('SELECT service,SUM(ok) ok,SUM(failed) failed,SUM(ms) ms FROM stats WHERE day>=? GROUP BY service',[$from])->fetchAll();
    }
    private function total(array $rows): array
    {
        $ok=array_sum(array_column($rows,'ok')); $failed=array_sum(array_column($rows,'failed')); $ms=array_sum(array_column($rows,'ms'));
        return [(int)$ok,(int)$failed,(int)$ms];
    }

    private function stats(): string
    {
        $ui=$this->bot->ui; $store=$this->bot->store; $now=time();
        [$dayOk,$dayFailed]=$this->total($this->sums(1)); [$weekOk,$weekFailed,$weekMs]=$this->total($this->sums(7)); [$monthOk,$monthFailed]=$this->total($this->sums(30));
        $top=$this->sums(7); usort($top,fn($a,$b)=>((int)$b['ok']+(int)$b['failed'])<=>((int)$a['ok']+(int)$a['failed']));
        $active=fn(int $seconds)=>(int)$store->query('SELECT COUNT(DISTINCT user_id) FROM jobs WHERE created>=?',[$now-$seconds])->fetchColumn();
        return $ui->text('report_stats_page',[
            'users'=>(int)$store->query('SELECT COUNT(*) FROM users')->fetchColumn(),'active_day'=>$active(86400),'active_week'=>$active(7*86400),
            'day_ok'=>$dayOk,'day_failed'=>$dayFailed,'week_ok'=>$weekOk,'week_failed'=>$weekFailed,'week_rate'=>$this->rate($weekOk,$weekFailed),
            'month_ok'=>$monthOk,'month_failed'=>$monthFailed,'month_rate'=>$this->rate($monthOk,$monthFailed),
            'avg'=>$weekOk+$weekFailed>0?$this->seconds($weekMs/($weekOk+$weekFailed)):$ui->plain('report_none'),
            'top'=>$top?$this->name((string)$top[0]['service']).' ('.((int)$top[0]['ok']+(int)$top[0]['failed']).')':$ui->plain('report_none'),
        ]);
    }
    private function services(): string
    {
        $ui=$this->bot->ui; $rows=[]; $week=[]; foreach($this->sums(7) as $row) $week[$row['service']]=$row;
        foreach(self::SERVICES as $code) {
            $row=$week[$code]??null; $count=$row?(int)$row['ok']+(int)$row['failed']:0;
            $rows[]=$count>0?$ui->text('report_service_row',['name'=>$this->name($code),'ok'=>(int)$row['ok'],'failed'=>(int)$row['failed'],'rate'=>$this->rate((int)$row['ok'],(int)$row['failed']),'avg'=>$this->seconds((float)$row['ms']/$count)])
                :$ui->text('report_service_idle',['name'=>$this->name($code)]);
        }
        return $ui->text('report_services_page',['rows'=>implode("\n",$rows)],['rows']);
    }
    private function cookies(): string
    {
        $ui=$this->bot->ui; $store=$this->bot->store; $health=new CookieHealth($this->bot->config,$store,$this->bot->api); $rows=[];
        foreach($health->pools() as $code=>$pool) {
            $jars=$pool->describe();
            $expiries=array_values(array_filter(array_column($jars,'expires'),fn($time)=>is_int($time)&&$time>0));
            $worked=array_values(array_filter(array_column($jars,'ok'),fn($time)=>is_int($time)&&$time>0));
            $rows[]=$ui->text('report_cookie_row',[
                'service'=>CookieJars::serviceName($ui,$code),'count'=>count($jars),'failed'=>count(array_filter($jars,fn($jar)=>$jar['failed'])),
                'expiry'=>$expiries?$ui->text('report_cookie_expiry',['days'=>max(0,(int)ceil((min($expiries)-time())/86400))]):$ui->plain('report_cookie_no_expiry'),
                'last'=>$worked?$this->ago(max($worked)):$ui->plain('report_never'),
            ],['expiry']);
        }
        $minutes=CookieHealth::minutes($store);
        return $ui->text('report_cookies_page',[
            'rows'=>$rows?implode("\n\n",$rows):$ui->plain('report_none'),
            'interval'=>$minutes<60?$ui->plainWith('report_minutes',['n'=>$minutes]):$ui->plainWith('report_hours',['n'=>intdiv($minutes,60)]),
            'last'=>$this->ago((int)$store->get('cookie_sweep_last','0')),
            'alerts'=>$ui->plain($health->alertsEnabled()?'switch_on':'switch_off'),'keepalive'=>$ui->plain($health->keepAliveEnabled()?'switch_on':'switch_off'),
        ],['rows']);
    }
    private function errors(): string
    {
        $ui=$this->bot->ui; $list=json_decode($this->bot->store->get('recent_errors','[]'),true); $rows=[];
        foreach(is_array($list)?$list:[] as $error) {
            $service=in_array($error['s'],[...self::SERVICES],true)?$error['s']:'youtube'; $key=($service!=='youtube'&&$service!=='menu'&&$service!=='batch'?$service.'_':'').'error_'.$error['r'];
            $meaning=$ui->definition('text',$key)?$ui->plain($key):$ui->plain('failed');
            $rows[]=$ui->text('report_error_row',['ago'=>$this->ago((int)$error['t']),'name'=>$this->name($service),'reason'=>(string)$error['r'],'meaning'=>mb_substr($meaning,0,110),
                'sources'=>preg_match('/^[a-z]+=[a-z]+(?:, [a-z]+=[a-z]+){0,4}$/D',(string)($error['c']??''))===1?"\n".$error['c']:''],['sources']);
        }
        return $ui->text('report_errors_page',['rows'=>$rows?implode("\n\n",$rows):$ui->plain('report_no_errors')],['rows']);
    }
    private function server(): string
    {
        $ui=$this->bot->ui; $store=$this->bot->store; $config=$this->bot->config; $now=time();
        $free=(float)@disk_free_space($config->storage); $total=(float)@disk_total_space($config->storage); $load=sys_getloadavg()?:[0,0,0];
        $count=fn(string $where)=>(int)$store->query('SELECT COUNT(*) FROM jobs WHERE '.$where)->fetchColumn();
        return $ui->text('report_server_page',[
            'version'=>Updates::version(),'poller'=>$ui->plain($now-(int)$store->get('poller_heartbeat','0')<60?'online':'unresponsive'),
            'worker'=>$ui->plain($now-(int)$store->get('worker_heartbeat','0')<20||$count("status='running'")>0?'online':'unresponsive'),
            'slots'=>$config->workerConcurrency,'queued'=>$count("status IN ('queued','confirming')"),'running'=>$count("status='running'"),'waiting'=>$count("status='waiting_quality'"),
            'free'=>number_format($free/1073741824,1),'total'=>number_format($total/1073741824,1),'free_percent'=>$total>0?round(100*$free/$total).'٪':'—',
            'load'=>number_format($load[0],2).' / '.number_format($load[1],2).' / '.number_format($load[2],2),
            'api'=>$ui->plain($config->api==='https://api.telegram.org'?'report_api_cloud':'report_api_local'),
            'inline'=>$ui->plain((new Shares($this->bot))->enabled()?'report_inline_on':'report_inline_off'),'proxy'=>$ui->plain($config->youtubeProxy!==''?'configured':'not_configured'),'gproxy'=>$ui->plain($config->galleryProxy!==''?'configured':'not_configured'),'pot'=>$ui->plain($config->youtubePotUrl!==''?'configured':'not_configured'),
        ]);
    }

    /** One report screen in the message that asked for it ($message), or the main status message for "home". */
    public function show(int $chat,string $screen,int $message): void
    {
        $ui=$this->bot->ui;
        if($screen==='x') { try { $this->bot->api->call('deleteMessage',['chat_id'=>$chat,'message_id'=>$message]); } catch(\Throwable) {} return; }
        if($screen==='home') { $this->bot->health($chat,$message); return; }
        if(!in_array($screen,self::SCREENS,true)) return;
        $text=match($screen) {'stats'=>$this->stats(),'services'=>$this->services(),'cookies'=>$this->cookies(),'errors'=>$this->errors(),'server'=>$this->server()};
        $rows=[[$ui->button('report_refresh','rp:'.$screen),$ui->button('report_back','rp:home')],[$ui->button('report_close','rp:x')]];
        $this->bot->send($chat,$text,$rows,$message);
    }
}
