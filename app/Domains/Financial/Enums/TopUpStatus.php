<?php

namespace App\Domains\Financial\Enums;

enum TopUpStatus: string
{
    case PENDIENTE = 'PENDIENTE';
    case COMPLETADA = 'COMPLETADA';
    case FALLIDA = 'FALLIDA';
    case CANCELADA = 'CANCELADA';
}