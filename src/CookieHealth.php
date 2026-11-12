<?php
declare(strict_types=1);
namespace Zero;

/**
 * Keeps an eye on every cookie pool without touching the network except to
 * message the administrators: drops jars whose account cookie has expired,
 * warns before one does and warns when a service starts refusing one.
 * Each situation is announced once, so the alerts stay useful.
 */
final class CookieHealth
{
    public const EXPIRING_SECONDS=259200, INTERVALS=[30,60,180,360,720,1440], DEFAULT_MINUTES=360;
    private Ui $ui;
    public function __construct(private Config $config,private Store $store,private Api $api) { $this->ui=new Ui($store); }
    public function alertsEnabled(): bool { return $this->store->get('cookies.alerts','1')==='1'; }
    /** Minutes between two checks of every cookie; one of INTERVALS. */
    public static function minutes(Store $store): int
    {
        $value=(int)$store->get('cookies.check_minutes',(string)self::DEFAULT_MINUTES);
        return in_array($value,self::INTERVALS,true)?$value:self::DEFAULT_MINUTES;
    }
    public function keepAliveEnabled(): bool { return $this->store->get('cookies.keepalive','1')==='1'; }
    /** @return array<string,CookiePool> */
    public function pools(): array
    {
        $result=[];
        foreach(array_keys(CookieJars::SERVICES) as $code) { $pool=CookieJars::pool($this->config,$code); if($pool) $result[$code]=$pool; }
        return $result;
    }
    /** One line for /health: how many cookies each service has and how many were refused lately. */
    public function summary(): string
    {
        $items=[];
        foreach($this->pools() as $code=>$pool) {
            $jars=$pool->describe(); $failed=count(array_filter($jars,fn($jar)=>$jar['failed']));
            $items[]=$this->ui->text('cookie_sum_item',['service'=>CookieJars::serviceName($this->ui,$code),'count'=>count($jars),
                'failed'=>$failed>0?' '.$this->ui->text('cookie_sum_failed',['failed'=>$failed]):''],['failed']);
        }
        return implode(' · ',$items)?:$this->ui->plain('cookie_sum_none');
    }
    /** @return list<int> private chats of every administrator */
    private function administrators(): array
    {
        $ids=$this->config->admins;
        foreach($this->store->query('SELECT user_id FROM bot_admins')->fetchAll(\PDO::FETCH_COLUMN) as $id) $ids[]=(int)$id;
        return array_values(array_unique(array_filter($ids,fn($id)=>$id>0)));
    }
    private function notify(string $text): void
    {
        foreach($this->administrators() as $admin) {
            try { $this->api->call('sendMessage',['chat_id'=>$admin,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true]); }
            catch(\Throwable) { /* An administrator who never started the bot cannot be reached. */ }
        }
    }
    private function jarName(array $jar,int $position): string
    {
        return $jar['label']!==''?$this->ui->text('cookie_jar_named',['label'=>$jar['label']]):(string)$position;
    }
    /**
     * Run one pass. Returns the number of alerts sent. Pruning always happens;
     * messages respect the administrator's alert switch.
     */
    public function sweep(?int $now=null): int
    {
        $now??=time(); $sent=0; $alerts=$this->alertsEnabled();
        foreach($this->pools() as $code=>$pool) {
            $service=Ui::escape(CookieJars::serviceName($this->ui,$code));
            $before=$pool->describe(); $names=[];
            foreach($before as $index=>$jar) $names[$jar['id']]=$this->jarName($jar,$index+1);
            foreach($pool->pruneExpired() as $gone) {
                if(!$alerts) continue;
                $left=$pool->count();
                $this->notify($this->ui->text('cookie_alert_expired',['service'=>$service,'jar'=>$names[$gone['id']]??$gone['id'],'left'=>$this->ui->text('cookie_alert_left',['count'=>$left])],['service','jar'])); $sent++;
            }
            foreach($pool->describe() as $index=>$jar) {
                $name=$this->jarName($jar,$index+1);
                if($alerts&&$jar['expires']!==null&&$jar['expires']>$now&&$jar['expires']-$now<=self::EXPIRING_SECONDS&&$now-$pool->warned('exp:'.$jar['id'])>=self::EXPIRING_SECONDS) {
                    $pool->markWarned('exp:'.$jar['id'],$now);
                    $this->notify($this->ui->text('cookie_alert_expiring',['service'=>$service,'jar'=>$name,'days'=>max(1,(int)ceil(($jar['expires']-$now)/86400))],['service','jar'])); $sent++;
                }
                // One alert per failure episode: the marker holds the failure time that was announced.
                if($alerts&&$jar['failed']&&$jar['fail']!==null&&$pool->warned('fail:'.$jar['id'])!==$jar['fail']) {
                    $pool->markWarned('fail:'.$jar['id'],$jar['fail']);
                    $this->notify($this->ui->text('cookie_alert_failing',['service'=>$service,'jar'=>$name],['service','jar'])); $sent++;
                }
            }
        }
        return $sent;
    }
    /**
     * YouTube rotates its session cookies every few hours and the downloader saves the new ones after each use, so a jar
     * nobody used for a while is the one that dies. Every twelve hours each YouTube jar that has not worked since then
     * makes one light request; a refusal because of the cookie itself is reported like any other failure.
     * @return int jars that were kept alive
     */
    public function keepAlive(?int $now=null): int
    {
        if(!$this->keepAliveEnabled()) return 0;
        $now??=time(); $done=0; $blocked=0; $checked=0; $pool=youtubeCookiePool($this->config);
        $url=$this->store->get('youtube_probe_url','https://www.youtube.com/watch?v=ybNMMK5Q318');
        foreach($pool->describe() as $jar) {
            // A jar that worked, or was just checked, within one interval needs no request.
            $interval=self::minutes($this->store)*60-60;
            if($jar['ok']!==null&&$now-(int)$jar['ok']<$interval) continue;
            if($now-$pool->warned('ka:'.$jar['id'])<$interval) continue;
            $pool->markWarned('ka:'.$jar['id'],$now); $checked++;
            $folder=$this->config->storage.'/.keepalive-'.bin2hex(random_bytes(6));
            if(!mkdir($folder,0700)) continue;
            try {
                (new Download($this->config))->keepAlive($folder,['id'=>$jar['id'],'path'=>$jar['path']],$url);
                $pool->markWorking($jar['id']); $done++; $pool->markWarned('blocked',0);
            } catch(MediaFailure $e) {
                // A refusal of the cookie is the cookie's fault; "blocked" may be the server's address, so the jar is not blamed.
                if($e->reason==='cookies') $pool->markFailed($jar['id']);
                elseif($e->reason==='blocked') $blocked++;
                ErrorAlerts::event('YouTube cookie keep-alive: jar='.$jar['id'].' reason='.$e->reason);
            } catch(\Throwable $e) { ErrorAlerts::event('YouTube cookie keep-alive failed: '.get_class($e)); }
            finally { Download::clean($folder); }
        }
        // Every jar that was tried got YouTube's bot check: the cookies are not the problem, the server's address is.
        // Tell the administrators once a day, because music and every YouTube link depend on it.
        if($checked>0&&$blocked===$checked&&$this->alertsEnabled()&&$now-$pool->warned('blocked')>=86400) {
            $pool->markWarned('blocked',$now);
            $this->notify($this->ui->text('cookie_alert_blocked'));
        }
        return $done;
    }
    /** For the worker loop: true when the next check of all cookies is due (the interval is the administrator's choice). */
    public static function dueAt(Store $store,?int $now=null): bool
    {
        $now??=time();
        if($now-(int)$store->get('cookie_sweep_last','0')<self::minutes($store)*60) return false;
        $store->set('cookie_sweep_last',(string)$now);
        return true;
    }
    public function due(?int $now=null): bool { return self::dueAt($this->store,$now); }
}
