<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Data\LimitEvaluation;
use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\Wallet;

/**
 * Punto de control previo a una operación. Solo considera límites con
 * acción BLOQUEAR; los límites ALERTAR se revisan cuando la operación
 * ya quedó registrada (ver UnusualActivityDetector), para que la alerta
 * quede ligada a su FinancialTransaction.
 *
 * Debe invocarse FUERA de la transacción de base de datos de la
 * operación: así la alerta del intento bloqueado persiste aunque la
 * operación se rechace.
 */
class FinancialLimitGuard
{
    public function __construct(
        private readonly FinancialLimitService $limits,
        private readonly TransactionAlertService $alerts
    ) {
    }

    /**
     * @throws FinancialLimitExceededException
     */
    public function assertAllowed(
        Wallet $wallet,
        MovementType $operation,
        int $amountCents,
        string $referenceType,
        ?string $referenceId = null,
        ?string $actorId = null
    ): LimitEvaluation {
        $evaluation = $this->limits->evaluate(
            wallet: $wallet,
            operation: $operation,
            amountCents: $amountCents,
            actions: [LimitAction::BLOQUEAR]
        );

        if (!$evaluation->isBlocked()) {
            return $evaluation;
        }

        $alert = $this->alerts->recordBlocked(
            $wallet,
            $evaluation,
            $referenceType,
            $referenceId,
            $actorId
        );

        throw new FinancialLimitExceededException(
            $evaluation,
            $alert->public_id,
            $alert->correlation_id
        );
    }
}
