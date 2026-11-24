<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\CashRegisterStatus;
use App\Domains\Financial\Enums\LimitMetric;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashOperationConfirmation;
use App\Domains\Financial\Models\Wallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CashOperationConfirmationService
{
    /** Quién rechaza una confirmación cuando la liquidación choca con un límite BLOQUEAR (FIN-04). */
    public const LIMIT_REJECTOR = 'system:financial-limits';

    public function request(string $shiftId, Wallet $wallet, string $operation, int $amount, string $actor, string $reason, string $key): CashOperationConfirmation
    {
        $cash = app(CashShiftService::class);
        $cash->text($actor);
        $cash->text($reason, 1000);
        $cash->text($key);
        if (! in_array($operation, ['TOPUP', 'WITHDRAWAL'], true) || $amount <= 0) {
            throw new InvalidArgumentException('Operación o importe inválido.');
        }
        $hash = $cash->hash([$shiftId, strtolower($wallet->public_id), $operation, $amount, trim($actor), trim($reason)]);

        try {
            return $this->createRequest($cash, $shiftId, $wallet, $operation, $amount, $actor, $reason, $key, $hash);
        } catch (FinancialLimitExceededException $exception) {
            // FIN-02: the blocked alert written inside the request transaction rolled back with it; keep the evidence.
            $alert = app(TransactionAlertService::class)->recordBlocked($wallet, $exception->evaluation, 'CASH_REQUEST', $shiftId, trim($actor));
            throw new FinancialLimitExceededException($exception->evaluation, $alert->public_id, $alert->correlation_id);
        }
    }

    private function createRequest(CashShiftService $cash, string $shiftId, Wallet $wallet, string $operation, int $amount, string $actor, string $reason, string $key, string $hash): CashOperationConfirmation
    {
        return DB::connection('sqlsrv')->transaction(function () use ($cash, $shiftId, $wallet, $operation, $amount, $actor, $reason, $key, $hash) {
            $shift = $cash->lockShift($shiftId);
            if ($shift->agent_id !== trim($actor)) {
                throw new InvalidArgumentException('El turno pertenece a otro operador.');
            }
            $existing = CashOperationConfirmation::where('request_key', trim($key))->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new InvalidArgumentException('La clave pertenece a otra confirmación.');
                }

                return $existing;
            }
            $cash->assertOpen($shift);
            $wallet = Wallet::where('public_id', $wallet->public_id)->lockForUpdate()->firstOrFail();
            if ($shift->cashRegister->status !== CashRegisterStatus::ACTIVE || $wallet->status !== WalletStatus::ACTIVA
                || $wallet->currency !== $shift->cashRegister->currency || $wallet->owner_type !== 'USER' || trim((string) $wallet->owner_id) === '') {
                throw new InvalidArgumentException('La confirmación requiere una wallet de usuario activa y de la moneda de la caja.');
            }
            abort_if(trim($actor) === 'user:'.$wallet->owner_id, 403, 'La caja requiere un operador distinto del titular.');
            // FIN-02: an operation that already exceeds a blocking limit is refused before asking the student. Settlement
            // evaluates again inside its own transaction, because limits or period usage may change while waiting.
            app(FinancialLimitGuard::class)->assertAllowed($wallet, $operation === 'TOPUP' ? MovementType::RECARGA : MovementType::RETIRO,
                $amount, 'CASH_REQUEST', $shiftId, trim($actor));
            $ttl = (int) config('financial.cash.confirmation_ttl_seconds', 300);
            if ($ttl < 60 || $ttl > 900) {
                throw new InvalidArgumentException('La vigencia de confirmación debe estar entre 60 y 900 segundos.');
            }
            $policy = app(CashApprovalPolicyService::class)->snapshot($shift->cashRegister->association_id, $operation, $wallet->currency);
            $required = $policy['enabled'] && $amount >= $policy['threshold_cents'];

            return CashOperationConfirmation::create(['public_id' => (string) Str::uuid(), 'cash_shift_id' => $shift->id,
                'wallet_id' => $wallet->public_id, 'operator_id' => trim($actor), 'student_id' => (string) $wallet->owner_id,
                'operation' => $operation, 'amount_cents' => $amount, 'currency' => $wallet->currency, 'reason' => trim($reason),
                'supervisor_required' => $required, 'approval_policy_snapshot' => $policy, 'supervisor_status' => $required ? 'PENDING' : null,
                'status' => 'PENDING', 'request_key' => trim($key), 'request_hash' => $hash, 'expires_at' => now()->addSeconds($ttl)])->fresh();
        });
    }

    public function decide(string $id, User $student, bool $approve, ?string $ip): CashOperationConfirmation
    {
        return DB::connection('sqlsrv')->transaction(function () use ($id, $student, $approve, $ip) {
            $proof = CashOperationConfirmation::where('public_id', $id)->lockForUpdate()->firstOrFail();
            $wallet = Wallet::where('public_id', $proof->wallet_id)->lockForUpdate()->firstOrFail();
            abort_unless($proof->student_id === (string) $student->getKey() && $wallet->owner_type === 'USER'
                && (string) $wallet->owner_id === (string) $student->getKey(), 404);
            if ($approve && $proof->status === 'CONFIRMED' && $proof->expires_at->isFuture()) {
                return $proof;
            }
            if (! $approve && $proof->status === 'REJECTED') {
                return $proof;
            }
            if (! in_array($proof->status, $approve ? ['PENDING'] : ['PENDING', 'CONFIRMED'], true) || ! $proof->expires_at->isFuture()) {
                throw new InvalidArgumentException('La confirmación venció o ya no admite esta acción.');
            }
            if ($wallet->status !== WalletStatus::ACTIVA) {
                throw new InvalidArgumentException('La wallet no está activa.');
            }
            if ($approve && ($proof->shift->status->value !== 'OPEN' || $proof->shift->cashRegister->status !== CashRegisterStatus::ACTIVE)) {
                throw new InvalidArgumentException('El turno o la caja ya no admite la operación.');
            }
            $proof->status = $approve ? 'CONFIRMED' : 'REJECTED';
            if ($approve) {
                $proof->fill(['confirmed_by' => 'user:'.$student->getKey(), 'confirmed_at' => now(), 'confirmation_ip' => $ip]);
            } else {
                $proof->fill(['rejected_at' => now(), 'rejected_by' => 'user:'.$student->getKey()]);
            }
            $proof->save();

            return $proof;
        });
    }

    public function cancel(string $id, string $actor): CashOperationConfirmation
    {
        return DB::connection('sqlsrv')->transaction(function () use ($id, $actor) {
            $proof = CashOperationConfirmation::where('public_id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($proof->operator_id === $actor, 403);
            if ($proof->status === 'CANCELLED') {
                return $proof;
            }
            if (! in_array($proof->status, ['PENDING', 'CONFIRMED'], true)) {
                throw new InvalidArgumentException('La confirmación ya no puede cancelarse.');
            }
            $proof->fill(['status' => 'CANCELLED', 'cancelled_at' => now()])->save();

            return $proof;
        });
    }

    public function execute(string $id, string $shiftId, Wallet $wallet, string $operation, int $amount, string $actor, string $reason, string $key, callable $settle)
    {
        $cash = app(CashShiftService::class);
        try {
            return DB::connection('sqlsrv')->transaction(function () use ($cash, $id, $shiftId, $wallet, $operation, $amount, $actor, $reason, $key, $settle) {
                $shift = $cash->lockShift($shiftId);
                $proof = CashOperationConfirmation::where('public_id', $id)->lockForUpdate()->firstOrFail();
                $wallet = Wallet::where('public_id', $wallet->public_id)->lockForUpdate()->firstOrFail();
                if ($proof->cash_shift_id !== $shift->id || strtolower($proof->wallet_id) !== strtolower($wallet->public_id)
                    || $proof->operation !== $operation || $proof->amount_cents !== $amount || $proof->currency !== $wallet->currency
                    || $proof->operator_id !== trim($actor) || $proof->reason !== trim($reason)
                    || $wallet->owner_type !== 'USER' || (string) $wallet->owner_id !== $proof->student_id
                    || $proof->confirmed_by !== 'user:'.$proof->student_id || ! $proof->confirmed_at) {
                    throw new InvalidArgumentException('La confirmación no corresponde al estudiante, turno, operador, importe o datos de esta operación.');
                }
                if ($proof->status === 'CONSUMED') {
                    if ($proof->settlement_key !== trim($key)) {
                        throw new InvalidArgumentException('La confirmación ya fue utilizada con otra clave.');
                    }
                    $movement = CashMovement::where('public_id', $proof->cash_movement_id)->where('idempotency_key', trim($key))->firstOrFail();
                    if ($movement->cash_shift_id !== $shift->id || $movement->amount_cents !== $amount) {
                        throw new InvalidArgumentException('La evidencia de consumo no corresponde a esta confirmación.');
                    }

                    return $settle($proof);
                }
                abort_if(trim($actor) === 'user:'.$proof->student_id, 403, 'La caja requiere un operador distinto del titular.');
                if ($proof->status === 'REJECTED' && $proof->rejected_by === self::LIMIT_REJECTOR) {
                    throw new InvalidArgumentException('La operación fue rechazada por un límite financiero (alerta '.strtolower((string) $proof->rejection_alert_id)
                        .'). No se registró dinero; prepara una nueva solicitud si corresponde.');
                }
                if ($proof->status !== 'CONFIRMED' || ! $proof->expires_at->isFuture()) {
                    throw new InvalidArgumentException('Se requiere una confirmación vigente del estudiante.');
                }
                app(CashSupervisorApprovalService::class)->assertApproved($proof, $shift->cashRegister->association_id);
                $proof->settlement_key = trim($key);
                $proof->save();
                $result = $settle($proof);
                $movement = CashMovement::where('idempotency_key', trim($key))->where('cash_shift_id', $shift->id)->firstOrFail();
                $proof->fill(['status' => 'CONSUMED', 'cash_movement_id' => $movement->public_id, 'consumed_at' => now()])->save();

                return $result;
            });
        } catch (FinancialLimitExceededException $exception) {
            // The surrounding confirmation transaction rolled back its inner blocked alert too. FIN-04: a blocking limit is a
            // definitive outcome for this confirmation, so it ends REJECTED with the alert, in one transaction with the evidence.
            $alert = DB::connection('sqlsrv')->transaction(function () use ($id, $wallet, $exception, $shiftId, $actor) {
                $alert = app(TransactionAlertService::class)->recordBlocked($wallet, $exception->evaluation, 'CASH_REQUEST', $shiftId, trim($actor));
                $proof = CashOperationConfirmation::where('public_id', $id)->lockForUpdate()->first();
                if ($proof && $proof->status === 'CONFIRMED' && $proof->settlement_key === null) {
                    $proof->fill(['status' => 'REJECTED', 'rejected_at' => now(), 'rejected_by' => self::LIMIT_REJECTOR,
                        'rejection_reason' => $this->limitReason($exception), 'rejection_alert_id' => $alert->public_id])->save();
                }

                return $alert;
            });
            throw new FinancialLimitExceededException($exception->evaluation, $alert->public_id, $alert->correlation_id);
        }
    }

    private function limitReason(FinancialLimitExceededException $exception): string
    {
        $violation = $exception->evaluation->blocking()[0] ?? null;
        if (! $violation) {
            return 'La operación excede un límite financiero configurado.';
        }
        $money = fn (int $cents) => '$'.number_format($cents / 100, 2).' '.$violation->limit->currency;
        $detail = $violation->limit->metric === LimitMetric::MONTO
            ? 'máximo '.$money($violation->thresholdValue).', observado '.$money($violation->observedValue)
            : 'máximo '.$violation->thresholdValue.' operaciones, observado '.$violation->observedValue;

        return 'La operación excede un límite financiero configurado ('.$detail.').';
    }
}
