<?php
namespace App\Domains\Financial\Enums;

enum WalletHoldStatus: string
{
    case ACTIVA = 'ACTIVA';
    case LIBERADA = 'LIBERADA';
    case CAPTURADA = 'CAPTURADA';
    case EXPIRADA = 'EXPIRADA';
}
