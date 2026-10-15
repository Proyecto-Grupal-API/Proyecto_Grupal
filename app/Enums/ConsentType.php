<?php

namespace App\Enums;

enum ConsentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Marketing = 'marketing';

    case ProfileTerms = 'profile_terms';

    case CredentialTerms = 'credential_terms';

    public function isFeatureSpecific(): bool
    {
        return in_array($this, [self::ProfileTerms, self::CredentialTerms], true);
    }

    public function requiresVersion(): bool
    {
        return $this !== self::Marketing;
    }
}
