<?php

namespace App\Domains\Financial\Enums;

use App\Domains\Financial\Enums\BonusRestrictionType;

enum BonusRestrictionType: string
{
    case NEGOCIO = 'NEGOCIO';
    case CATEGORIA = 'CATEGORIA';
}