<?php
namespace App\Domains\Financial\Contracts;

/** Pre-budget authorization; context must be accredited, never a future budget UUID. */
interface InstitutionalBudgetApprovalAuthorization
{
    public function allowsApproval(string $actorId,string $councilId,string $institutionReference): bool;
}
