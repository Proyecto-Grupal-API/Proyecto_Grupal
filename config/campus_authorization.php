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
// Dedicated cash capabilities: generic 2.8 grants do not silently expand.
$cash=[
 'read'=>['read','Consultar caja y comprobantes'],
 'operate'=>['execute','Abrir turno y operar efectivo'],
 'adjust'=>['create','Solicitar y ejecutar ajustes autorizados'],
 'close'=>['execute','Cerrar y arquear turno'],
 'manage'=>['configure','Administrar cajas'],
 'recover'=>['execute','Recuperar retiros autorizados'],
 'approval_read'=>['read','Consultar aprobaciones'],
 'approval_manage'=>['configure','Configurar políticas de segunda autorización'],
 'approval_review'=>['approve','Revisar solicitudes como supervisor'],
];
foreach($cash as $name=>[$action,$label])$capabilities['financial.cash.'.$name]=['module'=>2,'submodule'=>8,'action'=>$action,'label'=>$label];
return ['enabled' =>env('CAMPUS_AUTHORIZATION_ENABLED',false),'catalog'=>$capabilities];
