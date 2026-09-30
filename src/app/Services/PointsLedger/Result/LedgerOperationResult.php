<?php

namespace App\Services\PointsLedger\Result;

use App\Models\PointsAccount;
use App\Models\PointsLedger;

final readonly class LedgerOperationResult
{
    public function __construct(
        public PointsLedger $ledgerEntry,
        public PointsAccount $account,
        public bool $wasIdempotentReplay = false,
        /**
         * Solo relevante en reverse(): cuántos puntos del movimiento original no pudieron
         * recuperarse del balance disponible (porque ya se habían canjeado) y por lo tanto
         * quedaron registrados en pending_balance a la espera de una decisión de Gobierno
         * de Puntos (módulo 6).
         */
        public int $shortfallAmount = 0,
    ) {
    }

    public function hasShortfall(): bool
    {
        return $this->shortfallAmount > 0;
    }
}
