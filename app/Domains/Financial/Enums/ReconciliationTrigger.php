<?php

namespace App\Domains\Financial\Enums;

/**
 * Origen de una ejecución de conciliación.
 */
enum ReconciliationTrigger: string
{
    case API = 'API';
    case INTERFAZ = 'INTERFAZ';
    case COMANDO = 'COMANDO';
    case PROGRAMADA = 'PROGRAMADA';
}
