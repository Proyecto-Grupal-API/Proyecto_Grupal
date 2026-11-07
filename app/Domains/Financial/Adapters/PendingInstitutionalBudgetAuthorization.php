<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;

final class PendingInstitutionalBudgetAuthorization implements InstitutionalBudgetAuthorization
{
    public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return false; }
}
