<?php

namespace App\Domains\Financial\Enums;

enum CashRegisterStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
