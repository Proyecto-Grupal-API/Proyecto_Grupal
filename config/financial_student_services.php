<?php

/*
 * Cobros de Servicios al Estudiante (E5) en Finanzas: lockers e impresiones.
 * La cuenta receptora es la misma de las multas de
 * Biblioteca (una sola cuenta institucional de Servicios al Estudiante),
 * salvo que se configure otra.
 */
return [
    'enabled' => env('FINANCIAL_STUDENT_SERVICES_ENABLED', false),
    'client_id' => env('FINANCIAL_STUDENT_SERVICES_CLIENT_ID'),
    'recipient_organization_id' => env('FINANCIAL_STUDENT_SERVICES_RECIPIENT_ORGANIZATION_ID', env('FINANCIAL_LIBRARY_FINES_RECIPIENT_ORGANIZATION_ID')),
    'recipient_wallet_id' => env('FINANCIAL_STUDENT_SERVICES_RECIPIENT_WALLET_ID', env('FINANCIAL_LIBRARY_FINES_RECIPIENT_WALLET_ID')),
];
