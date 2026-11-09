<?php
declare(strict_types=1);
namespace Zero;

/** Prepare pictures outside the audio loop. Only small, completed JPEGs are read by the player. */
final class RadioArtwork
{
    private array $pending=[];
    public static function read(string $path): ?string
    {
        if($path===''||!is_file($path)||filesize($path)>524288) return null;
        $size=@getimagesize($path);
        return $size&&$size[0]===640&&$size[1]===360&&$size[2]===IMAGETYPE_JPEG?(string)file_get_contents($path):null;
    }
    public static function render(string $cover,string $audio,string $path): bool
    {
        $inputs=[];
        foreach(array_unique([$cover,$audio]) as $file) if($file!==''&&is_file($file)) $inputs[]=['-threads','1','-i',$file];
        $inputs[]=['-f','lavfi','-i','color=c=0x202020:s=640x360'];
        foreach($inputs as $input) {
            @unlink($path);
            $command=array_merge(['timeout','--foreground','--kill-after=1','5','ffmpeg','-hide_banner','-loglevel','error','-y','-filter_threads','1'],$input,
                ['-map','0:v:0','-an','-vf','scale=640:360:force_original_aspect_ratio=decrease,pad=640:360:(ow-iw)/2:(oh-ih)/2:color=0x202020','-frames:v','1','-threads','1','-pix_fmt','yuvj420p','-q:v','4','-f','image2',$path]);
            exec(implode(' ',array_map('escapeshellarg',$command)).' 2>/dev/null',$ignored,$code);
            if($code===0&&self::read($path)!==null) return true;
        }
        @unlink($path); return false;
    }
    public function prepare(array $item): void
    {
        $id=(int)$item['id']; if(isset($this->pending[$id])) return;
        $frame=self::read((string)($item['cover']??''));
        $row=['frame'=>$frame,'at'=>null,'process'=>null,'path'=>'','pid'=>0];
        if($frame===null&&count(array_filter($this->pending,fn($entry)=>is_resource($entry['process'])))<2) {
            $path=tempnam(sys_get_temp_dir(),'radio-frame-');
            if($path!==false) {
                // setsid owns one group for PHP, timeout and FFmpeg so shutdown can reap all of it.
                $process=proc_open(['setsid',PHP_BINARY,dirname(__DIR__).'/bin/radio-frame.php',(string)($item['cover']??''),(string)$item['file'],$path],
                    [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,['PATH'=>getenv('PATH')?:'/usr/bin:/bin']);
                if(is_resource($process)) { $row['process']=$process; $row['pid']=proc_get_status($process)['pid']; $row['path']=$path; }
                else @unlink($path);
            }
        }
        $this->pending[$id]=$row;
    }
    public function start(int $id,float $at): void { if(isset($this->pending[$id])) $this->pending[$id]['at']=$at; }
    /** Completed frames with their audio presentation timestamp, including images that finish after decoding did. */
    public function poll(): array
    {
        $events=[];
        foreach($this->pending as $id=>&$row) {
            if(is_resource($row['process'])) {
                if(proc_get_status($row['process'])['running']) continue;
                proc_close($row['process']); $row['process']=null;
                $row['frame']=self::read($row['path']); @unlink($row['path']); $row['path']='';
            }
            if($row['at']!==null) {
                if($row['frame']!==null) $events[]=[$row['at'],$row['frame']];
                unset($this->pending[$id]);
            }
        }
        unset($row); return $events;
    }
    public function close(): void
    {
        foreach($this->pending as $row) {
            if(is_resource($row['process'])) { @posix_kill(-$row['pid'],SIGTERM); proc_close($row['process']); }
            if($row['path']!=='') @unlink($row['path']);
        }
        $this->pending=[];
    }
    public function __destruct() { $this->close(); }
}
