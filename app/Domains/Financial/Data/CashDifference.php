<?php

namespace App\Domains\Financial\Data;

/**
 * Diferencia reportada por la fuente de caja (2.8). Los campos de
 * referencia los define 2.8; aquí solo se transportan.
 */
final class CashDifference
{
    /**
     * @param array<string, mixed> $references  Referencias autorizadas por 2.8 (p. ej. cash_shift_id).
     */
    public function __construct(
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly ?string $walletId,
        public readonly ?int $expectedCents,
        public readonly ?int $actualCents,
        public readonly array $references = []
    ) {
    }
}
