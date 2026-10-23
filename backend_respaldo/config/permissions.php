<?php

/*
|--------------------------------------------------------------------------
| Matriz de roles y permisos — Programa de Recompensas (Equipo 7)
|--------------------------------------------------------------------------
| La API valida SIEMPRE estos permisos en el servidor (Gate::before en
| AppServiceProvider + middleware `can:`). El frontend solo los usa para UI.
|
| SUPUESTOS DOCUMENTADOS (pendientes de aprobación formal; ver docs/ROLES_Y_PERMISOS.md):
|  - `admin` = administrador del programa de recompensas. NO puede cambiar
|    configuraciones sensibles ni gestionar usuarios: eso es `platform_admin`.
|  - `professor` valida/entrega canjes y propone campañas académicas (quedan en
|    borrador hasta que un admin las activa). No acumula ni ajusta puntos.
|  - `auditor` es de solo lectura (auditoría, ledger, analítica, fraude).
|  - `system` es la cuenta de servicio para integraciones (eventos de ventas
|    de otros equipos). No tiene acceso a la interfaz.
*/

$student = [
    'points.view_own',
    'rewards.view',
    'campaigns.view',
    'redemptions.view_own',
    'redemptions.create',
    'redemptions.cancel_own',
];

$professor = [
    'rewards.view',
    'campaigns.view',
    'campaigns.propose_academic',
    'redemptions.view_all',
    'redemptions.validate',
    'redemptions.deliver',
    'analytics.view_academic',
    'audit.view_own',
];

$auditor = [
    'points.view_any',
    'rewards.view',
    'campaigns.view',
    'businesses.view',
    'rules.view',
    'redemptions.view_all',
    'governance.view',
    'fraud.view',
    'audit.view',
    'audit.view_own',
    'analytics.view',
    'analytics.view_academic',
    'integrations.view',
    'catalogs.view',
    'settings.view',
];

$admin = array_values(array_unique(array_merge($auditor, [
    'points.earn',
    'points.reverse',
    'points.reconcile',
    'points.adjust',
    'businesses.manage',
    'rules.manage',
    'campaigns.manage',
    'campaigns.propose_academic',
    'rewards.manage',
    'redemptions.validate',
    'redemptions.deliver',
    'redemptions.manage',
    'governance.manage',
    'fraud.review',
    'catalogs.manage',
    'settings.update',
    'integrations.retry',
])));

$platformAdmin = array_values(array_unique(array_merge($admin, [
    'settings.manage_sensitive',
    'settings.approve',
    'users.view',
    'users.manage',
])));

$system = [
    'points.earn',
    'points.reverse',
    'integrations.ingest',
    'analytics.events.write',
    'audit.write'
];

return [
    'roles' => [
        'student' => $student,
        'professor' => $professor,
        'admin' => $admin,
        'platform_admin' => $platformAdmin,
        'auditor' => $auditor,
        'system' => $system,
    ],

    // Área de la interfaz a la que se redirige cada rol tras iniciar sesión.
    'home_area' => [
        'student' => 'student',
        'professor' => 'professor',
        'admin' => 'admin',
        'platform_admin' => 'admin',
        'auditor' => 'admin',
        'system' => null,
    ],
];
