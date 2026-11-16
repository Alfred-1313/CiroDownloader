<?php
declare(strict_types=1);
namespace Zero;

/** Experimental Play downloads. Profiles are apkeep 1.1.0 / gpapi 6.1.0 device IDs. */
final class GooglePlay
{
    public const DEVICES=['px_9a','sm_s20_plus','sm_a13_5g','google_kiwi_x86_64'];
    public static function enabled(Store $store): bool { return $store->get('play.enabled','0')==='1'; }
    public static function sessionPath(Config $config): string { return $config->storage.'/google-play/apkeep.ini'; }
    /** Validate and normalize the private session without ever returning it in diagnostics. */
    public static function session(string $source): string
    {
        $ini=strlen($source)<=16384?@parse_ini_string($source,true,INI_SCANNER_RAW):false;
        $google=is_array($ini)?($ini['google']??[]):[];
        $email=$google['email']??''; $aas=$google['aas_token']??''; $auth=$google['auth_token']??'';
        if(!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/["\r\n\x00]/',$email)||!is_string($aas)||!is_string($auth)
            ||($aas==='')===($auth==='')||!preg_match('~^[A-Za-z0-9._/+\x7e=:-]{12,8192}$~D',$aas?:$auth))
            throw new MediaFailure('cookies','Google Play session unavailable');
        return "[google]\nemail = \"".$email."\"\n".($aas!==''?'aas_token':'auth_token').' = "'.($aas?:$auth)."\"\n";
    }
    public static function credentials(Config $config): string
    {
        $path=self::sessionPath($config);
        if(is_link($path)||!is_file($path)||!is_readable($path)||filesize($path)>16384||(fileperms($path)&0077)!==0)
            throw new MediaFailure('cookies','Google Play session unavailable');
        return self::session((string)file_get_contents($path));
    }
    public static function configured(Config $config): bool
    {
        try { self::credentials($config); return true; } catch(\Throwable) { return false; }
    }
    public static function open(Bot $bot,array $job): void
    {
        $ui=$bot->ui; $saved=$bot->store->get('play.device.'.$job['user_id']); $slots=[];
        foreach(self::DEVICES as $device) $slots[$device]=[$ui->button('play_device_'.$device,'gp:'.$job['id'].':'.$device,['selected'=>$device===$saved?'✅ ':''])];
        $cancel=$ui->button('play_cancel','gp:'.$job['id'].':cancel'); $slots['cancel']=[$cancel];
        $rows=$ui->layout('play','device',$slots,UiRegistry::rows('play','device'));
        $bot->jobMessage($job,$ui->text('play_choose',['package'=>self::package($job['url'])]),$rows);
        $bot->store->query("UPDATE jobs SET status='waiting_quality',expires=? WHERE id=? AND status='running'",[time()+900,$job['id']]);
    }
    public static function callback(Bot $bot,array $callback,int $user,int $chat,int $thread): void
    {
        preg_match('/^gp:(\d{1,12}):([a-z0-9_]{1,32})$/D',(string)$callback['data'],$match);
        $id=(int)($match[1]??0); $device=$match[2]??''; $store=$bot->store; $notice=''; $job=null;
        $store->db->exec('BEGIN IMMEDIATE');
        try {
            $job=$store->query("SELECT * FROM jobs WHERE id=? AND phase='play'",[$id])->fetch();
            if($job&&(int)$job['user_id']!==$user) $notice='callback_owner';
            elseif(!$job||(int)$job['chat_id']!==$chat||(int)$job['thread_id']!==$thread
                ||(int)$job['message_id']!==(int)($callback['message']['message_id']??0)
                ||((int)$store->get('bot_id','0')>0&&(int)($callback['message']['from']['id']??0)!==(int)$store->get('bot_id'))
                ||$job['status']!=='waiting_quality'||(int)$job['expires']<time()
                ||!in_array($device,[...self::DEVICES,'cancel'],true)) $notice='callback_stale';
            elseif(!self::enabled($store)||!$bot->allowed($user,$chat)) $notice='callback_access';
            elseif($device!=='cancel'&&($store->busy($user,$chat)||$store->full())) $notice='play_busy';
            else {
                $store->query('UPDATE jobs SET status=?,options=?,expires=? WHERE id=?',[$device==='cancel'?'cancelled':'confirming',json_encode(['device'=>$device],JSON_THROW_ON_ERROR),time()+120,$id]);
            }
            $store->db->exec('COMMIT');
        } catch(\Throwable $error) { $store->db->exec('ROLLBACK'); throw $error; }
        $bot->api->call('answerCallbackQuery',['callback_query_id'=>$callback['id'],'text'=>$notice!==''?$bot->ui->plain($notice):'','show_alert'=>$notice!=='','cache_time'=>0]);
        if($notice!=='') return;
        $bot->ui=$bot->ui->forService('play');
        try { $bot->jobMessage($job,$bot->text($device==='cancel'?'play_cancelled':'play_queued')); }
        catch(\Throwable $error) {
            $store->query("UPDATE jobs SET status='failed' WHERE id=? AND status='confirming'",[$id]); throw $error;
        }
        if($device!=='cancel') {
            $store->db->exec('BEGIN IMMEDIATE');
            try {
                if($store->query("UPDATE jobs SET status='queued',expires=0 WHERE id=? AND status='confirming'",[$id])->rowCount()) $store->set('play.device.'.$user,$device);
                $store->db->exec('COMMIT');
            } catch(\Throwable $error) { $store->db->exec('ROLLBACK'); throw $error; }
        }
    }
    public static function package(string $url): string
    {
        $canonical=playUrl($url);
        if($canonical===null) throw new MediaFailure('format','Invalid Google Play app URL');
        return substr($canonical,strlen('https://play.google.com/store/apps/details?id='));
    }
    /** No credentials on argv; downloader output is never exposed or persisted. */
    public static function download(Config $config,string $url,string $device,string $folder): array
    {
        if(!in_array($device,self::DEVICES,true)) throw new MediaFailure('format','Unknown Google Play device');
        $package=self::package($url); $session=self::credentials($config);
        Download::ensureSpace($folder,$config->maxBytes*2+268435456);
        $ini=$folder.'/play.ini'; $output=$folder.'/apks'; mkdir($output,0700);
        if(file_put_contents($ini,$session)===false||!chmod($ini,0600)) throw new MediaFailure('storage','Cannot prepare Google Play session');
        try {
            $result=(new Download($config))->run(['apkeep','-a',$package,'-d','google-play','-i',$ini,'-r','1','-o','device='.$device.',split_apk=true,include_additional_files=false,include_dex_metadata=false',$output],$folder,180,$config->maxBytes+16384,$config->maxBytes,true);
        } catch(MediaFailure $error) {
            $reason=in_array($error->reason,['size','storage','network'],true)?$error->reason:(preg_match('/log in|credentials|token|Terms of Service/i',$error->getMessage())?'cookies':'process');
            throw new MediaFailure($reason,'Google Play download failed');
        } finally { @unlink($ini); }
        // apkeep exits zero for skipped and partially failed apps as well. Require its success marker AND complete APKs.
        if(!str_contains($result,$package.' downloaded successfully!')) throw new MediaFailure('restricted','Google Play app unavailable for this account or device');
        return self::artifact($package,$output,$folder,$config->maxBytes);
    }
    /** Package split APKs unchanged. An APKS archive is not a universal or re-signed APK. */
    public static function artifact(string $package,string $output,string $folder,int $limit): array
    {
        $root=is_dir($output.'/'.$package)?$output.'/'.$package:$output;
        $files=glob($root.'/*')?:[]; $bytes=0; $base=false;
        if(!$files||count($files)>120) throw new MediaFailure('format','Incomplete Google Play files');
        foreach($files as $path) {
            $name=basename($path); $size=(int)filesize($path);
            if(is_link($path)||!is_file($path)||!preg_match('/^'.preg_quote($package,'/').'(?:\.[A-Za-z0-9_.-]+)?\.apk$/D',$name)||$size<32)
                throw new MediaFailure('format','Unexpected Google Play file');
            $bytes+=$size; if($bytes>$limit) throw new MediaFailure('size','Google Play files exceed upload limit');
            $zip=new \ZipArchive();
            if($zip->open($path)!==true) throw new MediaFailure('format','Invalid APK archive');
            $manifest=$zip->statName('AndroidManifest.xml'); $zip->close();
            if(!$manifest||$manifest['size']<1||$manifest['size']>10485760) throw new MediaFailure('format','Missing APK manifest');
            $base=$base||$name===$package.'.apk';
        }
        if(!$base) throw new MediaFailure('format','Missing base APK');
        $split=count($files)>1; $path=$files[0];
        if($split) {
            $path=$folder.'/'.$package.'.apks'; $zip=new \ZipArchive();
            if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true) throw new MediaFailure('storage','Cannot create APKS archive');
            foreach($files as $file) {
                $name=basename($file)===$package.'.apk'?'base.apk':basename($file);
                if(!$zip->addFile($file,$name)||!$zip->setCompressionName($name,\ZipArchive::CM_STORE)) { $zip->close(); throw new MediaFailure('storage','Cannot write APKS archive'); }
            }
            if(!$zip->close()) throw new MediaFailure('storage','Cannot finish APKS archive');
        }
        clearstatcache(true,$path);
        if(filesize($path)>$limit) throw new MediaFailure('size','Google Play archive exceeds upload limit');
        return ['path'=>$path,'name'=>$package.($split?'.apks':'.apk'),'mime'=>$split?'application/zip':'application/vnd.android.package-archive','split'=>$split,'bytes'=>$bytes];
    }
}
