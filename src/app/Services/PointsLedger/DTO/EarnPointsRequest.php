<?php

namespace App\Services\PointsLedger\DTO;

/**
 * Evento de venta confirmada que llega vía AnalyticsEvent desde Equipo 3 (Marketplace)
 * u otro dominio que participe en el programa de recompensas.
 */
final readonly class EarnPointsRequest
{
    public function __construct(
        public string $studentId,
        public string $businessId,
        public string $sourceDomain,      // 'marketplace', 'services', etc.
        public string $sourceReference,   // id de la venta/pedido en el dominio de origen
        public float $saleAmount,
        public string $idempotencyKey,
        public ?string $campaignId = null,
        public array $metadata = [],
    ) {
    }
}
