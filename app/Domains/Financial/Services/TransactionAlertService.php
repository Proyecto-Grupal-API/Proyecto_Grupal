<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Data\LimitEvaluation;
use App\Domains\Financial\Data\LimitViolation;
use App\Domains\Financial\Enums\AlertOutcome;
use App\Domains\Financial\Enums\AlertStatus;
use App\Domains\Financial\Enums\AlertType;
use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Models\TransactionAlertStatusChange;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Support\FinancialCorrelation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 2.10 Alertas: registro, consulta y seguimiento.
 */
class TransactionAlertService
{
    public const SYSTEM_ACTOR = 'SYSTEM';

    /**
     * Registra un intento rechazado por uno o más límites BLOQUEAR.
     * No existe FinancialTransaction porque la operación no se ejecutó.
     */
    public function recordBlocked(
        Wallet $wallet,
        LimitEvaluation $evaluation,
        string $referenceType,
        ?string $referenceId = null,
        ?string $actorId = null
    ): TransactionAlert {
        $blocking = $evaluation->blocking();

        if ($blocking === []) {
            throw new InvalidArgumentException(
                'La evaluación no contiene límites que bloqueen la operación.'
            );
        }

        $primary = $blocking[0];

        return DB::connection('sqlsrv')->transaction(
            function () use (
                $wallet,
                $evaluation,
                $referenceType,
                $referenceId,
                $actorId,
                $primary
            ): TransactionAlert {
                $alert = TransactionAlert::create([
                    'public_id' => (string) Str::uuid(),
                    'dedupe_key' => 'BLOCKED:' . Str::uuid(),
                    'alert_type' => AlertType::OPERACION_BLOQUEADA,
                    'status' => AlertStatus::ABIERTA,
                    'limit_id' => $primary->limit->public_id,
                    'wallet_id' => $wallet->public_id,
                    'transaction_id' => null,
                    'ledger_entry_id' => null,
                    'operation' => $evaluation->operation,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'amount_cents' => $evaluation->amountCents,
                    'limit_period' => $primary->limit->period,
                    'limit_metric' => $primary->limit->metric,
                    'limit_action' => $primary->limit->action,
                    'observed_value' => $primary->observedValue,
                    'threshold_value' => $primary->thresholdValue,
                    'details' => [
                        'requested_by' => $actorId,
                        'evaluation' => $evaluation->toArray(),
                    ],
                    'detected_at' => now(),
                    'correlation_id' => $this->correlationId(),
                    'outcome' => AlertOutcome::BLOQUEADA,
                    'period_start' => $primary->periodStart,
                    'period_end' => $primary->periodEnd,
                ]);

                $this->logStatus($alert, null, AlertStatus::ABIERTA, self::SYSTEM_ACTOR, 'Alerta generada por operación bloqueada.');

                return $alert->fresh();
            }
        );
    }

    /**
     * Registra que un movimiento ya contabilizado rebasó un límite.
     * Es idempotente: el mismo movimiento y límite generan una sola alerta.
     */
    public function recordLimitExceeded(
        FinancialTransaction $transaction,
        LedgerEntry $entry,
        LimitViolation $violation
    ): TransactionAlert {
        $dedupeKey = strtolower(
            'LIMIT:' . $violation->limit->public_id
            . ':ENTRY:' . $entry->public_id
        );

        $existing = TransactionAlert::where('dedupe_key', $dedupeKey)->first();

        if ($existing) {
            return $existing;
        }

        // Un límite BLOQUEAR rebasado por un movimiento ya registrado se
        // registra explícitamente como excedente no prevenido: o la
        // operación no tiene control preventivo, o hubo una carrera entre
        // solicitudes (no existe reserva atómica). El movimiento NO se
        // modifica retroactivamente.
        $outcome = $violation->limit->action === LimitAction::BLOQUEAR
            ? AlertOutcome::EXCEDENTE_NO_PREVENIDO
            : AlertOutcome::REGISTRADA_CON_ALERTA;

        try {
            return DB::connection('sqlsrv')->transaction(
                function () use ($transaction, $entry, $violation, $dedupeKey, $outcome): TransactionAlert {
                    $alert = TransactionAlert::create([
                        'public_id' => (string) Str::uuid(),
                        'dedupe_key' => $dedupeKey,
                        'alert_type' => AlertType::LIMITE_EXCEDIDO,
                        'status' => AlertStatus::ABIERTA,
                        'limit_id' => $violation->limit->public_id,
                        'wallet_id' => $entry->wallet_id,
                        'transaction_id' => $transaction->public_id,
                        'ledger_entry_id' => $entry->public_id,
                        'operation' => $entry->movement_type,
                        'reference_type' => $transaction->reference_type,
                        'reference_id' => $transaction->reference_id,
                        'amount_cents' => abs((int) $entry->amount_cents),
                        'limit_period' => $violation->limit->period,
                        'limit_metric' => $violation->limit->metric,
                        'limit_action' => $violation->limit->action,
                        'observed_value' => $violation->observedValue,
                        'threshold_value' => $violation->thresholdValue,
                        'details' => [
                            'violation' => $violation->toArray(),
                            'idempotency_key' => $transaction->idempotency_key,
                            'ledger_entry_created_at' => $entry->created_at?->toISOString(),
                        ],
                        'detected_at' => now(),
                        'correlation_id' => $this->correlationId(),
                        'outcome' => $outcome,
                        'period_start' => $violation->periodStart,
                        'period_end' => $violation->periodEnd,
                    ]);

                    $this->logStatus(
                        $alert,
                        null,
                        AlertStatus::ABIERTA,
                        self::SYSTEM_ACTOR,
                        $outcome === AlertOutcome::EXCEDENTE_NO_PREVENIDO
                            ? 'Alerta generada: excedente de un límite de bloqueo no prevenido.'
                            : 'Alerta generada por límite excedido.'
                    );

                    return $alert->fresh();
                }
            );
        } catch (QueryException $exception) {
            // Otra ejecución registró la misma alerta en paralelo.
            $existing = TransactionAlert::where('dedupe_key', $dedupeKey)->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * Registra que un movimiento ya contabilizado NO pudo evaluarse contra
     * los límites (p. ej. el proveedor de roles no respondió). Así el
     * control nunca se omite en silencio. Idempotente por movimiento.
     */
    public function recordUnevaluated(
        FinancialTransaction $transaction,
        LedgerEntry $entry,
        string $reason
    ): TransactionAlert {
        $dedupeKey = strtolower('UNEVALUATED:ENTRY:' . $entry->public_id);

        $existing = TransactionAlert::where('dedupe_key', $dedupeKey)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::connection('sqlsrv')->transaction(
                function () use ($transaction, $entry, $reason, $dedupeKey): TransactionAlert {
                    $alert = TransactionAlert::create([
                        'public_id' => (string) Str::uuid(),
                        'dedupe_key' => $dedupeKey,
                        'alert_type' => AlertType::CONTROL_NO_EVALUADO,
                        'status' => AlertStatus::ABIERTA,
                        'limit_id' => null,
                        'wallet_id' => $entry->wallet_id,
                        'transaction_id' => $transaction->public_id,
                        'ledger_entry_id' => $entry->public_id,
                        'operation' => $entry->movement_type,
                        'reference_type' => $transaction->reference_type,
                        'reference_id' => $transaction->reference_id,
                        'amount_cents' => abs((int) $entry->amount_cents),
                        'observed_value' => null,
                        'threshold_value' => null,
                        'details' => [
                            'reason' => Str::limit($reason, 500),
                            'idempotency_key' => $transaction->idempotency_key,
                            'ledger_entry_created_at' => $entry->created_at?->toISOString(),
                        ],
                        'detected_at' => now(),
                        'correlation_id' => $this->correlationId(),
                        'outcome' => AlertOutcome::NO_EVALUADA,
                    ]);

                    $this->logStatus($alert, null, AlertStatus::ABIERTA, self::SYSTEM_ACTOR, 'Alerta generada: el movimiento no pudo evaluarse contra los límites.');

                    return $alert->fresh();
                }
            );
        } catch (QueryException $exception) {
            $existing = TransactionAlert::where('dedupe_key', $dedupeKey)->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function changeStatus(
        TransactionAlert $alert,
        AlertStatus $target,
        string $actorId,
        ?string $note = null
    ): TransactionAlert {
        if (trim($actorId) === '') {
            throw new InvalidArgumentException(
                'Se requiere el identificador del actor.'
            );
        }

        if ($target->isFinal() && trim((string) $note) === '') {
            throw new InvalidArgumentException(
                'Para cerrar una alerta se requiere una nota de resolución.'
            );
        }

        return DB::connection('sqlsrv')->transaction(
            function () use ($alert, $target, $actorId, $note): TransactionAlert {
                $locked = TransactionAlert::where('public_id', $alert->public_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $from = $locked->status;

                if (!$from->canTransitionTo($target)) {
                    throw new InvalidArgumentException(
                        "No se puede cambiar la alerta de {$from->value} a {$target->value}."
                    );
                }

                $locked->status = $target;
                $locked->status_changed_by = $actorId;
                $locked->status_changed_at = now();

                if ($note !== null && trim($note) !== '') {
                    $locked->resolution_note = $note;
                }

                $locked->save();

                $this->logStatus($locked, $from, $target, $actorId, $note);

                return $locked->fresh();
            }
        );
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return TransactionAlert::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['alert_type'] ?? null, fn ($q, $v) => $q->where('alert_type', $v))
            ->when($filters['wallet_id'] ?? null, fn ($q, $v) => $q->where('wallet_id', $v))
            ->when($filters['transaction_id'] ?? null, fn ($q, $v) => $q->where('transaction_id', $v))
            ->when($filters['limit_id'] ?? null, fn ($q, $v) => $q->where('limit_id', $v))
            ->when($filters['outcome'] ?? null, fn ($q, $v) => $q->where('outcome', $v))
            ->when($filters['correlation_id'] ?? null, fn ($q, $v) => $q->where('correlation_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('detected_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('detected_at', '<', $v))
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function correlationId(): string
    {
        return app(FinancialCorrelation::class)->id();
    }

    private function logStatus(
        TransactionAlert $alert,
        ?AlertStatus $from,
        AlertStatus $to,
        string $actorId,
        ?string $note
    ): void {
        TransactionAlertStatusChange::create([
            'public_id' => (string) Str::uuid(),
            'alert_id' => $alert->public_id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actorId,
            'note' => $note,
        ]);
    }
}
