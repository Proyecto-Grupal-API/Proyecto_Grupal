<?php
$capabilities=['campus.roles.manage'=>['module'=>1,'submodule'=>null,'action'=>'manage']];
// Closed catalogue: additions do not expand existing grants.
$actions=['read','create','approve','execute','cancel','configure'];
foreach (range(1,7) as $module) {
    $parts=$module===2 ? range(1,10) : [0];
    foreach ($parts as $part) foreach ($actions as $action) {
        $capabilities["module.$module.$part.$action"]=['module'=>$module,'submodule'=>$part ?: null,'action'=>$action];
    }
}
return ['enabled'=>env('CAMPUS_AUTHORIZATION_ENABLED',false),'catalog'=>$capabilities];
