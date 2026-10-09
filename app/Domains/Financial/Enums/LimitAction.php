<?php

namespace App\Domains\Financial\Enums;

enum LimitAction: string
{
    case BLOQUEAR = 'BLOQUEAR';
    case ALERTAR = 'ALERTAR';
}
