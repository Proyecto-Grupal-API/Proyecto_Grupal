<?php

namespace App\Services\PointsLedger\DTO;

final readonly class ReversePointsRequest
{
    public function __construct(
        public string $relatedLedgerId,   // id del PointsLedger original a revertir
        public string $reason,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {
    }
}
