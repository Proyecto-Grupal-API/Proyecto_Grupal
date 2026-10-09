<?php

namespace App\Domains\Financial\Data;

use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\MovementType;

/**
 * Resultado de evaluar una operación contra los límites vigentes.
 * No mueve dinero ni escribe en la base de datos.
 */
final class LimitEvaluation
{
    /**
     * @param array<int, LimitViolation> $violations
     */
    public function __construct(
        public readonly string $walletId,
        public readonly MovementType $operation,
        public readonly int $amountCents,
        public readonly int $limitsEvaluated,
        public readonly array $violations
    ) {
    }

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    /**
     * @return array<int, LimitViolation>
     */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->violations,
            fn (LimitViolation $v) =>
                $v->limit->action === LimitAction::BLOQUEAR
        ));
    }

    public function isBlocked(): bool
    {
        return $this->blocking() !== [];
    }

    /**
     * ACEPTADA | BLOQUEADA | ALERTADA
     */
    public function decision(): string
    {
        if ($this->isBlocked()) {
            return 'BLOQUEADA';
        }

        return $this->hasViolations() ? 'ALERTADA' : 'ACEPTADA';
    }

    public function toArray(): array
    {
        return [
            'wallet_id' => $this->walletId,
            'operation' => $this->operation->value,
            'amount_cents' => $this->amountCents,
            'decision' => $this->decision(),
            'limits_evaluated' => $this->limitsEvaluated,
            'violations' => array_map(
                fn (LimitViolation $v) => $v->toArray(),
                $this->violations
            ),
        ];
    }
}
