<?php

namespace App\Domains\Financial\Enums;

enum LimitPeriod: string
{
    case OPERACION = 'OPERACION';
    case DIARIO = 'DIARIO';
    case MENSUAL = 'MENSUAL';
}
