<?php

namespace App\Domains\Financial\Contracts;

interface LegacyCashReceiptAuthorization
{
    /** Require an active global administrator, never merely an unscoped read grant. */
    public function assertGlobal(string $userId): void;
}
