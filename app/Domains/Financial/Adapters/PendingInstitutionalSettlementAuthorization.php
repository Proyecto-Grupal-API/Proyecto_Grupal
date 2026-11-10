<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization;
final class PendingInstitutionalSettlementAuthorization implements InstitutionalSettlementAuthorization
{
    public function allows(string $actorId, string $action, array $context): bool { return false; }
}
