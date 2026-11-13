<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;
use App\Services\InstitutionalBudgetAuthorizationService;

/** Trusted in-process E2 call; no HTTP actor or budget administration authority. */
final class IdentityInstitutionalBudgetAuthorization implements InstitutionalBudgetAuthorization, \App\Domains\Financial\Contracts\InstitutionalBudgetApprovalAuthorization
{
    public function __construct(private readonly InstitutionalBudgetAuthorizationService $identity) {}

    public function allowsApproval(string $actorId,string $councilId,string $institutionReference): bool
    {
        return app(\App\Services\BudgetAdministrationAuthorization::class)->allows($actorId,'budget.approve',$councilId,$institutionReference);
    }

    public function allows(string $actorId, string $action, ?string $budgetId = null): bool
    {
        return $this->identity->allows($actorId, $action, $budgetId);
    }
}
