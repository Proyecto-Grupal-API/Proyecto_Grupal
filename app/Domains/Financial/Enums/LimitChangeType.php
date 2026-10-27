<?php

namespace App\Domains\Financial\Enums;

enum LimitChangeType: string
{
    case CREACION = 'CREACION';
    case MODIFICACION = 'MODIFICACION';
}
