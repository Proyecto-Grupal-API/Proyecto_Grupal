<?php

namespace App\Domains\Financial\Enums;

enum RefundRequestStatus: string
{
    case PENDIENTE = 'PENDIENTE';
    case APROBADA = 'APROBADA';
    case RECHAZADA = 'RECHAZADA';
    case COMPLETADA = 'COMPLETADA';
}