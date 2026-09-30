<?php

namespace App\Services\PointsLedger\DTO;

final readonly class RedeemPointsRequest
{
    public function __construct(
        public string $studentId,
        public string $redemptionId,      // id de la Redemption confirmada (Equipo 7, módulo 5)
        public int $pointsRequired,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {
    }
}
