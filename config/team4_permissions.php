<?php

$catalog = [
    /*
    |--------------------------------------------------------------------------
    | Tabla de permisos por rol (v2)
    |--------------------------------------------------------------------------
    | Reglas del flujo real:
    |  - Reservas vienen de Eq. 3 (API), no se crean manualmente.
    |  - OCs: solo admin autoriza/cancela. Buyer y manager solo emiten.
    |  - Recepciones: buyer (es quien gestiona proveedores).
    |  - Devoluciones a proveedor: buyer.
    |  - Devoluciones de cliente: inventory_manager.
    */

    'roles' => [

        'admin' => [
            'label' => 'Administrador',
            'permissions' => ['*'],
        ],

        'inventory_manager' => [
            'label' => 'Responsable de inventario',
            'permissions' => [
                'dashboard.view',

                // Catálogo: puede crear/editar productos, NO eliminar
                'productos.view', 'productos.create', 'productos.update',
                'souvenirs.view', 'souvenirs.create', 'souvenirs.update',
                'costos.view',

                // Inventario: núcleo de su rol
                'inventario.view', 'inventario.adjust',
                'almacenes.view', 'almacenes.create', 'almacenes.update', 'almacenes.delete',
                'kardex.view',

                // Reservas: solo ver (vienen de Eq. 3). Puede liberar si es necesario.
                'reservas.view', 'reservas.release',

                // Compras: puede EMITIR OCs pero NO autorizarlas
                'proveedores.view',
                'compras.view', 'compras.create', 'compras.update',

                // Recepciones: solo ver
                'recepciones.view',

                // Devoluciones: solo de cliente (reingreso a stock)
                'devoluciones.view', 'devoluciones.storeCustomer',

                // Alertas y conteos: núcleo de su rol
                'alertas.view', 'alertas.discard', 'alertas.sync',
                'conteos.view', 'conteos.create', 'conteos.capture', 'conteos.close', 'conteos.delete',
            ],
        ],

        'buyer' => [
            'label' => 'Comprador',
            'permissions' => [
                'dashboard.view',

                // Catálogo: solo lectura
                'productos.view',
                'souvenirs.view',
                'costos.view', 'costos.register',

                // Inventario: solo lectura
                'inventario.view',
                'almacenes.view',
                'kardex.view',
                'reservas.view',

                // Compras: emite OCs, gestiona proveedores, recepciona
                'proveedores.view', 'proveedores.create', 'proveedores.update', 'proveedores.delete',
                'compras.view', 'compras.create', 'compras.update',
                'recepciones.view', 'recepciones.create',

                // Devoluciones a proveedor
                'devoluciones.view', 'devoluciones.storeSupplier',
            ],
        ],

        'auditor' => [
            'label' => 'Auditor',
            'permissions' => [
                'dashboard.view',

                'productos.view', 'souvenirs.view', 'costos.view',
                'inventario.view', 'almacenes.view', 'kardex.view', 'reservas.view',
                'proveedores.view', 'compras.view', 'recepciones.view',
                'devoluciones.view', 'alertas.view', 'conteos.view',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | KPIs visibles por rol (Dashboard)
    |--------------------------------------------------------------------------
    */

    'dashboard_kpis' => [

        'admin' => ['*'],

        'inventory_manager' => [
            'products_active',
            'inventory_units',
            'alerts_active',
            'alerts_critical',
            'movements_week',
            'warehouses',
            'locations',
        ],

        'buyer' => [
            'purchase_orders_pending',
            'suppliers_active',
            'inventory_units',
            'returns_month',
        ],

        'auditor' => [
            'products_active',
            'inventory_units',
            'reservations_active',
            'alerts_active',
            'purchase_orders_pending',
            'suppliers_active',
            'movements_week',
            'returns_month',
            'warehouses',
            'locations',
        ],

    ],
];

$catalog["roles"]["business_manager"] = ["label" => "Gerente del negocio", "permissions" => array_values(array_unique(array_merge($catalog["roles"]["inventory_manager"]["permissions"], $catalog["roles"]["buyer"]["permissions"])))];
$catalog["dashboard_kpis"]["business_manager"] = ["*"];
$catalog['all'] = ['alertas.discard', 'alertas.sync', 'alertas.view', 'almacenes.create', 'almacenes.delete', 'almacenes.update', 'almacenes.view', 'compras.authorize', 'compras.cancel', 'compras.create', 'compras.update', 'compras.view', 'conteos.capture', 'conteos.close', 'conteos.create', 'conteos.delete', 'conteos.view', 'costos.register', 'costos.view', 'dashboard.view', 'devoluciones.storeCustomer', 'devoluciones.storeSupplier', 'devoluciones.view', 'inventario.adjust', 'inventario.view', 'kardex.view', 'productos.create', 'productos.delete', 'productos.update', 'productos.view', 'proveedores.create', 'proveedores.delete', 'proveedores.update', 'proveedores.view', 'recepciones.create', 'recepciones.view', 'reservas.create', 'reservas.release', 'reservas.view', 'souvenirs.create', 'souvenirs.delete', 'souvenirs.update', 'souvenirs.view'];
return $catalog;
