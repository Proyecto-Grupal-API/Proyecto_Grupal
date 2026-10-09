<?php

/*
|--------------------------------------------------------------------------
| Módulo 2 Financiero – configuración operativa de 2.10
|--------------------------------------------------------------------------
|
| Aquí NO se definen políticas financieras (montos máximos, número de
| operaciones, etc.). Esas políticas se registran como datos en la tabla
| financial_limits mediante la API o la interfaz. Este archivo solo
| contiene parámetros operativos. Los valores marcados PENDIENTE no tienen
| un valor por defecto que suponga una decisión de negocio.
|
*/

$csv = static fn (?string $value): array => array_values(array_filter(array_map(
    'trim',
    explode(',', (string) $value)
)));

return [

    // Zona horaria con la que se delimita un "día" o "mes" de negocio
    // para los límites por periodo y para la conciliación diaria.
    'business_timezone' => env(
        'FINANCIAL_BUSINESS_TIMEZONE',
        'America/Mexico_City'
    ),

    'holds' => [
        // Technical ceiling only; actual durations are approved as database policies.
        // Default is the SQL Server integer storage limit, not a recommended duration.
        'absolute_max_seconds' => (int) env('FINANCIAL_HOLD_ABSOLUTE_MAX_SECONDS', 2147483647),
        'schedule_expiration' => (bool) env('FINANCIAL_HOLD_SCHEDULE_EXPIRATION', false),
    ],

    'reconciliation' => [
        // Habilita la ejecución programada (scheduler de Laravel).
        'schedule_enabled' => (bool) env(
            'FINANCIAL_RECONCILIATION_SCHEDULE_ENABLED',
            false
        ),

        // PENDIENTE: hora local de corte (HH:MM, business_timezone). Sin
        // valor por defecto: si falta, el scheduler NO se registra.
        'daily_at' => env('FINANCIAL_RECONCILIATION_DAILY_AT'),

        // PENDIENTE: calendario de ejecución. Valores admitidos:
        // DIARIO | LUNES_A_VIERNES. Sin valor por defecto. Días festivos
        // no están contemplados (requieren un calendario acordado).
        'calendar' => env('FINANCIAL_RECONCILIATION_CALENDAR'),

        // Actor registrado en las ejecuciones automáticas.
        'system_actor' => env(
            'FINANCIAL_RECONCILIATION_ACTOR',
            'SYSTEM_SCHEDULER'
        ),

        // Parámetros técnicos del job y del lock por fecha de negocio.
        'job_tries' => (int) env('FINANCIAL_RECONCILIATION_JOB_TRIES', 3),
        'job_backoff_seconds' => env('FINANCIAL_RECONCILIATION_JOB_BACKOFF', '60,300'),
        'lock_ttl_seconds' => (int) env('FINANCIAL_RECONCILIATION_LOCK_TTL', 900),
    ],

    // PENDIENTE: roles responsables de los controles financieros en la
    // interfaz web. Nombres de rol del Módulo 1 separados por coma.
    // Vacío = nadie tiene acceso (se deniega por defecto).
    //  - view: consultar límites, alertas y conciliaciones.
    //  - manage: crear/modificar límites, cambiar alertas y
    //    ejecutar/resolver conciliaciones (incluye view).
    // La API de servicio sigue usando los scopes financial:read y
    // financial:control.
    'access' => [
        'view_roles' => $csv(env('FINANCIAL_CONTROL_VIEW_ROLES')),
        'manage_roles' => $csv(env('FINANCIAL_CONTROL_MANAGE_ROLES')),
    ],
];
