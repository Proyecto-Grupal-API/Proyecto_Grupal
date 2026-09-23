<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\IdentityProvider;
use App\Models\User;
use App\Services\IdentityService;

class Module1IdentityAdapter implements IdentityProvider
{
    public function __construct(
        private readonly IdentityService $identityService
    ) {
    }

    public function validateQr(
        string $code,
        ?string $validatedByUserId = null,
        ?string $context = null,
        ?string $ipAddress = null
    ): array {
        $validatedBy = $validatedByUserId
            ? User::find($validatedByUserId)
            : null;

        return $this->identityService->validateQrCode(
            $code,
            $validatedBy,
            $context,
            $ipAddress
        );
    }
}