<?php
namespace App\Domains\Financial\Services;

use App\Domains\Financial\Models\CashOperationConfirmation;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\CashRegisterStatus;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CashOperationConfirmationService
{
    public function request(string $shiftId, Wallet $wallet, string $operation, int $amount, string $actor, string $reason, string $key): CashOperationConfirmation
    {
        $cash = app(CashShiftService::class);
        $cash->text($actor); $cash->text($reason, 1000); $cash->text($key);
        if (! in_array($operation, ['TOPUP', 'WITHDRAWAL'], true) || $amount <= 0) throw new InvalidArgumentException('Operación o importe inválido.');
        $hash = $cash->hash([$shiftId, strtolower($wallet->public_id), $operation, $amount, trim($actor), trim($reason)]);
        return DB::connection('sqlsrv')->transaction(function () use ($cash, $shiftId, $wallet, $operation, $amount, $actor, $reason, $key, $hash) {
            $shift = $cash->lockShift($shiftId);
            if ($shift->agent_id !== trim($actor)) throw new InvalidArgumentException('El turno pertenece a otro operador.');
            $existing = CashOperationConfirmation::where('request_key', trim($key))->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) throw new InvalidArgumentException('La clave pertenece a otra confirmación.');
                return $existing;
            }
            $cash->assertOpen($shift);
            $wallet = Wallet::where('public_id', $wallet->public_id)->lockForUpdate()->firstOrFail();
            if ($shift->cashRegister->status !== CashRegisterStatus::ACTIVE || $wallet->status !== WalletStatus::ACTIVA
                || $wallet->currency !== $shift->cashRegister->currency || $wallet->owner_type !== 'USER' || trim((string) $wallet->owner_id) === '')
                throw new InvalidArgumentException('La confirmación requiere una wallet de usuario activa y de la moneda de la caja.');
            $ttl = (int) config('financial.cash.confirmation_ttl_seconds', 300);
            if ($ttl < 60 || $ttl > 900) throw new InvalidArgumentException('La vigencia de confirmación debe estar entre 60 y 900 segundos.');
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
            if ($approve && $proof->status === 'CONFIRMED' && $proof->expires_at->isFuture()) return $proof;
            if (! $approve && $proof->status === 'REJECTED') return $proof;
            if (! in_array($proof->status, $approve ? ['PENDING'] : ['PENDING', 'CONFIRMED'], true) || ! $proof->expires_at->isFuture())
                throw new InvalidArgumentException('La confirmación venció o ya no admite esta acción.');
            if ($wallet->status !== WalletStatus::ACTIVA) throw new InvalidArgumentException('La wallet no está activa.');
            if ($approve && ($proof->shift->status->value !== 'OPEN' || $proof->shift->cashRegister->status !== CashRegisterStatus::ACTIVE))
                throw new InvalidArgumentException('El turno o la caja ya no admite la operación.');
            $proof->status = $approve ? 'CONFIRMED' : 'REJECTED';
            if ($approve) $proof->fill(['confirmed_by' => 'user:'.$student->getKey(), 'confirmed_at' => now(), 'confirmation_ip' => $ip]);
            else $proof->rejected_at = now();
            $proof->save(); return $proof;
        });
    }

    public function cancel(string $id, string $actor): CashOperationConfirmation
    {
        return DB::connection('sqlsrv')->transaction(function () use ($id, $actor) {
            $proof = CashOperationConfirmation::where('public_id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($proof->operator_id === $actor, 403);
            if ($proof->status === 'CANCELLED') return $proof;
            if (! in_array($proof->status, ['PENDING', 'CONFIRMED'], true)) throw new InvalidArgumentException('La confirmación ya no puede cancelarse.');
            $proof->fill(['status' => 'CANCELLED', 'cancelled_at' => now()])->save(); return $proof;
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
                    || $proof->confirmed_by !== 'user:'.$proof->student_id || ! $proof->confirmed_at)
                    throw new InvalidArgumentException('La confirmación no corresponde al estudiante, turno, operador, importe o datos de esta operación.');
                if ($proof->status === 'CONSUMED') {
                    if ($proof->settlement_key !== trim($key)) throw new InvalidArgumentException('La confirmación ya fue utilizada con otra clave.');
                    $movement = CashMovement::where('public_id', $proof->cash_movement_id)->where('idempotency_key', trim($key))->firstOrFail();
                    if ($movement->cash_shift_id !== $shift->id || $movement->amount_cents !== $amount) throw new InvalidArgumentException('La evidencia de consumo no corresponde a esta confirmación.');
                    return $settle($proof);
                }
                if ($proof->status !== 'CONFIRMED' || ! $proof->expires_at->isFuture()) throw new InvalidArgumentException('Se requiere una confirmación vigente del estudiante.');
                app(CashSupervisorApprovalService::class)->assertApproved($proof, $shift->cashRegister->association_id);
                $proof->settlement_key = trim($key); $proof->save();
                $result = $settle($proof);
                $movement = CashMovement::where('idempotency_key', trim($key))->where('cash_shift_id', $shift->id)->firstOrFail();
                $proof->fill(['status' => 'CONSUMED', 'cash_movement_id' => $movement->public_id, 'consumed_at' => now()])->save();
                return $result;
            });
        } catch (FinancialLimitExceededException $exception) {
            // The surrounding confirmation transaction rolled back its inner blocked alert too.
            $alert = app(TransactionAlertService::class)->recordBlocked($wallet, $exception->evaluation, 'CASH_REQUEST', $shiftId, trim($actor));
            throw new FinancialLimitExceededException($exception->evaluation, $alert->public_id, $alert->correlation_id);
        }
    }
}
