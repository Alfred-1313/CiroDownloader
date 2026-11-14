<?php
/** Old saved callback addresses remain supported and now open the active service-scoped editor. */
function currentEditorAction(string $action): string {
    $p=explode(':',$action); $op=$p[1]??'';
    if(($p[0]??'')!=='custom') return $action;
    if(in_array($op,['item','edit','reset','default'],true)&&isset($p[3])) $p[3]=Zero\UiRegistry::legacy($p[2],$p[3]);
    elseif(in_array($op,['icon','emoji','pos','plain','hide','cyclecolor','color','setcolor','clearicon'],true)&&isset($p[2])) $p[2]=Zero\UiRegistry::legacy('button',$p[2]);
    if($op==='cyclecolor') $p[1]='color';
    return implode(':',$p);
}
/** Semantic identities of controls when exercising an older section's saved callback protocol. */
function legacyEditorAction(string $action): string {
    $p=explode(':',$action); $op=$p[1]??'';
    if(($p[0]??'')!=='custom') return $action;
    $position=in_array($op,['item','edit','reset','default'],true)?3:(in_array($op,['icon','emoji','pos','plain','hide','color','setcolor','clearicon'],true)?2:null);
    if($position!==null&&isset($p[$position])) {
        $kind=$position===3?$p[2]:'button'; $item=Zero\Ui::definitions()[$kind==='button'?'buttons':'texts'][$p[$position]]??[];
        $p[$position]=$item['base']??$p[$position];
    }
    return implode(':',$p);
}
