<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\CashMovementType;
use App\Domains\Financial\Enums\CashRegisterStatus;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CashSettlementService
{
    public function __construct(private readonly CashShiftService $shifts, private readonly FinancialLimitGuard $limits) {}

    public function topUp(string $shiftId, Wallet $wallet, int $amount, string $key, string $actor, string $reason): TopUp
    {
        return $this->settle($shiftId, $wallet, $amount, $key, $actor, $reason, true);
    }
    public function withdraw(string $shiftId, Wallet $wallet, int $amount, string $key, string $actor, string $reason): Withdrawal
    {
        return $this->settle($shiftId, $wallet, $amount, $key, $actor, $reason, false);
    }

    private function settle(string $shiftId, Wallet $wallet, int $amount, string $key, string $actor, string $reason, bool $incoming): TopUp|Withdrawal
    {
        $this->shifts->text($key); $this->shifts->text($actor); $this->shifts->text($reason, 1000);
        if ($amount <= 0) throw new InvalidArgumentException('El importe debe ser mayor que cero.');
        $hash = $this->shifts->hash([$shiftId, $incoming ? 'TOPUP' : 'WITHDRAWAL', $amount, strtolower($wallet->public_id), trim($actor), trim($reason)]);
        $model = $incoming ? TopUp::class : Withdrawal::class;
        $existing = $this->shifts->replay($key, $hash);
        if ($existing) return $model::where('public_id', $existing->reference_id)->firstOrFail();
        $shift = CashShift::where('public_id', $shiftId)->firstOrFail();
        $this->assertContext($shift, $wallet, $actor);
        // Preventive blocked alerts must survive; no money has moved at this point.
        $this->limits->assertAllowed($wallet, $incoming ? MovementType::RECARGA : MovementType::RETIRO,
            $amount, 'CASH_REQUEST', $shiftId, trim($actor));
        try {
            return DB::connection('sqlsrv')->transaction(function () use ($shiftId, $wallet, $amount, $key, $actor, $reason, $incoming, $hash, $model) {
            $shift = $this->shifts->lockShift($shiftId);
            $existing = $this->shifts->replay($key, $hash);
            if ($existing) return $model::where('public_id', $existing->reference_id)->firstOrFail();
            $this->assertContext($shift, $wallet, $actor);
            if (! $incoming && $this->shifts->calculate($shift)['expected_amount_cents'] < $amount)
                throw new InvalidArgumentException('No hay efectivo suficiente en el turno.');
            if ($incoming) {
                $service = app(TopUpService::class);
                $operation = $service->create($wallet, $amount, TopUpMethod::EFECTIVO, trim($actor), trim($key));
            } else {
                $service = app(WithdrawalService::class);
                $operation = $service->create($wallet, $amount, WithdrawalMethod::EFECTIVO, trim($actor), trim($key));
            }
            $operation = $service->complete($operation, trim($key));
            $operation->cash_shift_id = $shift->public_id;
            $operation->save();
            CashMovement::create(['public_id' => (string) Str::uuid(), 'cash_shift_id' => $shift->id,
                'type' => $incoming ? CashMovementType::TOPUP : CashMovementType::WITHDRAWAL,
                'amount_cents' => $amount, 'idempotency_key' => trim($key), 'request_hash' => $hash,
                'actor_id' => trim($actor), 'reason' => trim($reason), 'wallet_id' => $wallet->public_id,
                'reference_type' => $incoming ? 'TOPUP' : 'WITHDRAWAL', 'reference_id' => $operation->public_id]);
            return $operation->fresh();
            });
        } catch (FinancialLimitExceededException $exception) {
            // A second evaluation can reject inside the atomic operation. Its alert
            // rolled back with the money; recreate the same evidence after rollback.
            $alert = app(TransactionAlertService::class)->recordBlocked($wallet, $exception->evaluation,
                'CASH_REQUEST', $shiftId, trim($actor));
            throw new FinancialLimitExceededException($exception->evaluation, $alert->public_id, $alert->correlation_id);
        }
    }

    private function assertContext(CashShift $shift, Wallet $wallet, string $actor): void
    {
        $this->shifts->assertOpen($shift);
        $register = $shift->cashRegister;
        if ($register->status !== CashRegisterStatus::ACTIVE) throw new InvalidArgumentException('La caja no está activa.');
        if ($register->currency !== $wallet->currency) throw new InvalidArgumentException('La caja y la wallet deben usar la misma moneda.');
        if ($shift->agent_id !== trim($actor)) throw new InvalidArgumentException('El agente no es responsable de este turno.');
    }
}
