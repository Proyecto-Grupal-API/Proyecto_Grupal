<?php
namespace App\Domains\Financial\Contracts;

/** Positive only after validating current actor authority AND a confirmed external payment with canonical references. */
interface InstitutionalSettlementAuthorization
{
    public function allows(string $actorId, string $action, array $context): bool;
}
