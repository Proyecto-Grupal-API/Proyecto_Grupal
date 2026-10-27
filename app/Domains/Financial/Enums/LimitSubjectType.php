<?php

namespace App\Domains\Financial\Enums;

enum LimitSubjectType: string
{
    case GLOBAL = 'GLOBAL';
    case WALLET_TYPE = 'WALLET_TYPE';
    case OWNER = 'OWNER';
    case ROLE = 'ROLE';
}
