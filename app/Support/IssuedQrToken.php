<?php

namespace App\Support;

use App\Models\QrToken;

final readonly class IssuedQrToken
{
    public function __construct(
        public QrToken $token,
        public string $presentedCode,
        public ?string $shortCode = null,
    ) {
    }
}
