<?php

namespace App\Domains\Financial\Enums;

enum TransactionStatus: string
{
    case PENDIENTE = 'PENDIENTE';
    case COMPLETADA = 'COMPLETADA';
    case FALLIDA = 'FALLIDA';
    case REVERTIDA = 'REVERTIDA';
}