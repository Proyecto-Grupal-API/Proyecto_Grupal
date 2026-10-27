<?php

namespace App\Services\PlatformModule\PointsLedger\DTO;

final readonly class ReversePointsRequest
{
    public function __construct(
        public string $relatedLedgerId,   // id del PointsLedger original a revertir
        public string $reason,
        public string $idempotencyKey,
        public array $metadata = [],
        public ?string $actorId = null,
        public ?string $actorRole = null,
    ) {}
}
