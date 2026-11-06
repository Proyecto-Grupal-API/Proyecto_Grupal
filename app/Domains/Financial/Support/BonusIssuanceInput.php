<?php

namespace App\Domains\Financial\Support;

use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Enums\BonusRestrictionType;
use Illuminate\Validation\Rule;

/** Shared input rules for the web controller and direct administrative service callers. */
final class BonusIssuanceInput
{
    public static function validate(array $data): array
    {
        return validator($data, [
            'issuer_scope_type' => ['prohibited'], 'issuer_scope_id' => ['prohibited'],
            'beneficiary_id' => ['required', 'string', 'size:24', 'regex:/\A[0-9a-fA-F]{24}\z/'],
            'type' => ['required', Rule::enum(BonusType::class)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'valid_from' => ['required', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/'],
            'expires_at' => ['required', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/'],
            'combinable' => ['required', 'boolean'], 'allows_partial_use' => ['required', 'boolean'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'restrictions' => ['nullable', 'array', 'max:50'],
            'restrictions.*.type' => ['required', Rule::enum(BonusRestrictionType::class)],
            'restrictions.*.target_id' => ['required', 'string', 'max:255', 'regex:/\S/u'],
        ])->validate();
    }
}
