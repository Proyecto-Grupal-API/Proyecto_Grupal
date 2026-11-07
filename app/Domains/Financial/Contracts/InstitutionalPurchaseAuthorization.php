<?php
namespace App\Domains\Financial\Contracts;

/** Must validate current human authority/consent and the trusted commercial order, not only IDs. */
interface InstitutionalPurchaseAuthorization
{
    public function allows(string $actorId, string $action, array $context): bool;
}
