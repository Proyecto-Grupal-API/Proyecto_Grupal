<?php

namespace App\Domains\Financial\Enums;

enum CashShiftStatus: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
}
