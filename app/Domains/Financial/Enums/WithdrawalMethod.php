<?php

namespace App\Domains\Financial\Enums;

enum WithdrawalMethod: string
{
    case EFECTIVO = 'EFECTIVO';
    case TRANSFERENCIA = 'TRANSFERENCIA';
}