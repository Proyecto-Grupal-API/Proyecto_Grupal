<?php

namespace App\Domains\Financial\Enums;

enum WithdrawalStatus: string
{
    case PENDIENTE = 'PENDIENTE';
    case COMPLETADA = 'COMPLETADA';
    case FALLIDA = 'FALLIDA';
    case CANCELADA = 'CANCELADA';
}