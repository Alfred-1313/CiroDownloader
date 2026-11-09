<?php
declare(strict_types=1);
namespace Zero;

/** Small presentation-clock snapshots for the panel; no Telegram work runs in the audio loop. */
final class RadioPlayback
{
    private array $tracks=[], $silences=[];
    private float $written=0;
    public function __construct(private Store $store,private int $chat,private string $session='0') {}
    public function start(array $item,float $at): void { $this->tracks[]=['item'=>$item,'start'=>$at,'end'=>null]; }
    public function finish(int $id,float $at): void
    {
        for($i=count($this->tracks)-1;$i>=0;$i--) if((int)$this->tracks[$i]['item']['id']===$id&&$this->tracks[$i]['end']===null) { $this->tracks[$i]['end']=$at; break; }
    }
    public function silence(float $at,float $seconds): void
    {
        $last=array_key_last($this->silences);
        if($last!==null&&abs($this->silences[$last][0]+$this->silences[$last][1]-$at)<0.001) $this->silences[$last][1]+=$seconds;
        else $this->silences[]=[$at,$seconds];
    }
    public function tick(float $clock,bool $paused): void
    {
        if(microtime(true)-$this->written<1) return;
        $this->written=microtime(true); $track=null;
        foreach($this->tracks as $entry) if($entry['start']<=$clock&&($entry['end']===null||$entry['end']>$clock)) $track=$entry;
        $snapshot=['id'=>0,'title'=>'','artists'=>'','elapsed'=>0,'duration'=>0,'cover'=>'','paused'=>$paused,'at'=>microtime(true),'session'=>$this->session];
        if($track!==null) {
            $elapsed=max(0,$clock-$track['start']);
            foreach($this->silences as [$at,$seconds]) $elapsed-=max(0,min($clock,$at+$seconds)-max($track['start'],$at));
            $item=$track['item']; $duration=max(0,(int)$item['duration']);
            $snapshot=array_replace($snapshot,['id'=>(int)$item['id'],'title'=>$item['title'],'artists'=>$item['artists'],'elapsed'=>min($duration,max(0,$elapsed)),'duration'=>$duration,'cover'=>(string)($item['cover']??'')]);
        }
        $this->store->set('radio.progress.'.$this->chat,json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $this->tracks=array_values(array_filter($this->tracks,fn($entry)=>$entry['end']===null||$entry['end']>$clock-2));
        $earliest=$this->tracks[0]['start']??$clock;
        $this->silences=array_values(array_filter($this->silences,fn($entry)=>$entry[0]+$entry[1]>=$earliest));
    }
    public function clear(): void { $this->store->query('DELETE FROM settings WHERE key=?',['radio.progress.'.$this->chat]); }
}
