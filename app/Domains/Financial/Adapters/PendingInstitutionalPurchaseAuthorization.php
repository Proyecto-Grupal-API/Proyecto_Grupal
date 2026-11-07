<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization;
final class PendingInstitutionalPurchaseAuthorization implements InstitutionalPurchaseAuthorization
{
    public function allows(string $actorId, string $action, array $context): bool { return false; }
}
