<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\ContextualBonusAuthorizationProvider;
use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Identity\Contracts\ScopedCapabilityAuthorization;
final class RoleBonusAuthorizationProvider implements ContextualBonusAuthorizationProvider
{
    public function __construct(private readonly ScopedCapabilityAuthorization $authorization) {}
    public function canIssueBonus(string $issuerType, string $issuerId, BonusType $bonusType): bool
    { return $this->canIssueInContext($issuerType, $issuerId, $bonusType, new BonusIssuerContext()); }
    public function canIssueInContext(string $issuerType, string $issuerId, BonusType $type, BonusIssuerContext $context): bool
    { return $issuerType === 'USER' && $this->authorization->authorized($issuerId, 'bonus.issue.'.$type->value, $context->scopeType, $context->scopeId); }
}
