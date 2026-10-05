<?php

namespace App\Domains\Financial\Contracts;

use App\Domains\Financial\Enums\BonusType;

interface BonusAuthorizationProvider
{
    public function canIssueBonus(
        string $issuerType,
        string $issuerId,
        BonusType $bonusType
    ): bool;
}