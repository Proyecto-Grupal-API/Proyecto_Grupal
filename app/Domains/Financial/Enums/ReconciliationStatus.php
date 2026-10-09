<?php

namespace App\Domains\Financial\Enums;

enum ReconciliationStatus: string
{
    case EN_PROCESO = 'EN_PROCESO';
    case CUADRADA = 'CUADRADA';
    case CON_DIFERENCIAS = 'CON_DIFERENCIAS';
    case FALLIDA = 'FALLIDA';
}
