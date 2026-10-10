<?php
namespace App\Domains\Financial\Contracts;
use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Enums\BonusType;
interface ContextualBonusAuthorizationProvider extends BonusAuthorizationProvider
{
    public function canIssueInContext(string $issuerType, string $issuerId, BonusType $type, BonusIssuerContext $context): bool;
}
