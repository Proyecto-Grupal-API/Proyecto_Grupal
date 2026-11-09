<?php

namespace App\Contracts;

use App\Enums\FinancialContextValidation;

/** Implemented only by a trusted context owner. Intentionally has no production binding. */
interface FinancialContextValidator
{
    public function validate(string $scopeType, string $scopeId): FinancialContextValidation;
}
