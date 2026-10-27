<?php

namespace App\Domains\Financial\Enums;

enum BonusStatus: string
{
    case PENDIENTE = 'PENDIENTE';
    case ACTIVO = 'ACTIVO';
    case AGOTADO = 'AGOTADO';
    case CANCELADO = 'CANCELADO';
    case EXPIRADO = 'EXPIRADO';
}
