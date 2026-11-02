<?php

namespace App\Contracts;

use App\Services\FinancialAuthorizationPolicy;
use Illuminate\Container\Attributes\Bind;

#[Bind(FinancialAuthorizationPolicy::class)]
interface FinancialAuthorizationDecision
{
    /** The caller authenticates the human; this query never executes a financial operation. */
    public function allows(?string $subjectId, string $bonusType, string $operation, ?string $scopeType = null, ?string $scopeId = null): bool;
}
