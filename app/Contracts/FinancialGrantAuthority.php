<?php

namespace App\Contracts;

use App\Models\User;

/** Intentionally unbound: no institutional authority has been designated. */
interface FinancialGrantAuthority
{
    public function allows(User $actor, User $subject, string $operation, string $role, ?string $scopeType, ?string $scopeId): bool;

    public function reference(): string;
}
