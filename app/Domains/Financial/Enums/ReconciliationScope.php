<?php

namespace App\Domains\Financial\Enums;

enum ReconciliationScope: string
{
    case GLOBAL = 'GLOBAL';
    case WALLETS = 'WALLETS';
}
