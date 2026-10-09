<?php

namespace App\Domains\Financial\Enums;

enum CashMovementType: string
{
    case CASH_IN = 'CASH_IN';
    case CASH_OUT = 'CASH_OUT';
    case TOPUP = 'TOPUP';
    case WITHDRAWAL = 'WITHDRAWAL';
    case ADJUSTMENT = 'ADJUSTMENT';
}
