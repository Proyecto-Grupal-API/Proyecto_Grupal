<?php

namespace App\Domains\Financial\Enums;

enum WalletStatus: string
{
    case ACTIVA = 'ACTIVA';
    case BLOQUEADA = 'BLOQUEADA';
    case SUSPENDIDA = 'SUSPENDIDA';
    case CERRADA = 'CERRADA';
}