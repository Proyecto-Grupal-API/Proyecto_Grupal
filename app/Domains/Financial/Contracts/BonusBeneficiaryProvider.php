<?php
namespace App\Domains\Financial\Contracts;
interface BonusBeneficiaryProvider
{
    /** Active verified account, or null. Identity transport failures must fail closed. */
    public function findActive(string $userId): ?array;
}
