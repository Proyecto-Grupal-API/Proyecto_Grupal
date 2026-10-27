<?php

namespace App\Domains\Financial\Exceptions;

use App\Domains\Financial\Data\LimitEvaluation;
use InvalidArgumentException;

/**
 * La operación rebasa al menos un límite con acción BLOQUEAR.
 *
 * Extiende InvalidArgumentException para que los controladores
 * existentes la sigan tratando como conflicto de negocio (409).
 */
class FinancialLimitExceededException extends InvalidArgumentException
{
    public function __construct(
        public readonly LimitEvaluation $evaluation,
        public readonly ?string $alertId = null,
        public readonly ?string $correlationId = null
    ) {
        parent::__construct(
            'La operación excede un límite financiero configurado.'
        );
    }

    public function toArray(): array
    {
        return [
            'code' => 'FINANCIAL_LIMIT_EXCEEDED',
            'alert_id' => $this->alertId,
            'correlation_id' => $this->correlationId,
            'evaluation' => $this->evaluation->toArray(),
        ];
    }
}
