<?php

namespace App\Contracts;

use App\Services\RewardsAuthorizationService;
use Illuminate\Container\Attributes\Bind;

#[Bind(RewardsAuthorizationService::class)]
interface RewardsAuthorization
{
    // This decides a right in E7, never eligibility, resource access or dual approval.
    public function allows(?string $subjectId, string $capability, ?string $scopeType = null, ?string $scopeId = null): bool;
}
