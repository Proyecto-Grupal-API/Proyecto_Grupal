<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\ContextualBonusAuthorizationProvider;
use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Integrations\Identity\IdentityFinancialAuthorizationClient;

final class IdentityBonusAuthorizationProvider implements ContextualBonusAuthorizationProvider
{
    public function __construct(private readonly IdentityFinancialAuthorizationClient $client) {}

    public function canIssueBonus(string $issuerType, string $issuerId, BonusType $bonusType): bool
    {
        if (! $this->humanSubject($issuerType, $issuerId)) {
            return false;
        }

        return $this->client->check($issuerId, $bonusType);
    }

    public function canIssueInContext(string $issuerType, string $issuerId, BonusType $type, BonusIssuerContext $context): bool
    {
        if (! $this->humanSubject($issuerType, $issuerId)) {
            return false;
        }

        return $this->client->check($issuerId, $type, $context);
    }

    private function humanSubject(string $issuerType, string $issuerId): bool
    {
        // USER identifies the kind of subject, not proof of consent or session.
        return $issuerType === 'USER' && preg_match('/^[a-f0-9]{24}$/Di', $issuerId) === 1;
    }
}
