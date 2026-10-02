<?php

namespace App\Domains\Financial\Enums;

enum BonusMovementType: string
{
    case EMISION = 'EMISION';
    case CONSUMO = 'CONSUMO';
    case CANCELACION = 'CANCELACION';
    case EXPIRACION = 'EXPIRACION';
}