<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Enums\BonusType;

/** Replace with the trusted issuer contract; never trust self-assigned roles. */
class PendingBonusAuthorizationProvider implements BonusAuthorizationProvider
{
    public function canIssueBonus(string $issuerType, string $issuerId, BonusType $bonusType): bool
    {
        return false;
    }
}
