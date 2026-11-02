<?php

namespace App\Contracts;

use App\Services\FinancialAuthorizationService;
use Illuminate\Container\Attributes\Bind;

#[Bind(FinancialAuthorizationService::class)]
interface BonusAuthorization
{
    // subjectId is a canonical User._id, not proof of an authenticated human actor.
    public function allows(?string $subjectId, string $bonusType, string $operation, ?string $scopeType = null, ?string $scopeId = null): bool;
}
