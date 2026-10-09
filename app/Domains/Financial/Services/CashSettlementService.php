<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\CashMovementType;
use App\Domains\Financial\Enums\CashRegisterStatus;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Enums\TopUpStatus;
use App\Domains\Financial\Enums\WithdrawalStatus;
use App\Domains\Financial\Enums\WalletStatus;
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

    // External cash endpoints must use this path; the student's confirmation is consumed with the money.
    public function settleConfirmed(string $shiftId, Wallet $wallet, int $amount, string $key, string $actor, string $reason, bool $incoming, string $confirmationId): TopUp|Withdrawal
    {
        return app(CashOperationConfirmationService::class)->execute($confirmationId, $shiftId, $wallet,
            $incoming ? 'TOPUP' : 'WITHDRAWAL', $amount, $actor, $reason, $key,
            fn ($confirmation) => $this->settle($shiftId, $wallet, $amount, $key, $actor, $reason, $incoming, null, $confirmation));
    }

    // Complete a pending request through the same atomic cash flow, never the generic completion endpoint.
    public function completePending(string $shiftId, TopUp|Withdrawal $request, string $key, string $actor, string $reason): TopUp|Withdrawal
    {
        $request = $request->fresh();
        $wallet = Wallet::where('public_id', $request->wallet_id)->firstOrFail();
        return $this->settle($shiftId, $wallet, $request->amount_cents, $key, $actor, $reason, $request instanceof TopUp, $request);
    }

    private function settle(string $shiftId, Wallet $wallet, int $amount, string $key, string $actor, string $reason, bool $incoming, TopUp|Withdrawal|null $pending = null, ?\App\Domains\Financial\Models\CashOperationConfirmation $confirmation = null): TopUp|Withdrawal
    {
        $this->shifts->text($key); $this->shifts->text($actor); $this->shifts->text($reason, 1000);
        if ($amount <= 0) throw new InvalidArgumentException('El importe debe ser mayor que cero.');
        $hash = $this->shifts->hash([$shiftId, $incoming ? 'TOPUP' : 'WITHDRAWAL', $amount, strtolower($wallet->public_id), trim($actor), trim($reason)]);
        if ($confirmation) $hash = $this->shifts->hash([$shiftId, $hash, strtolower($confirmation->public_id)]);
        if ($pending) $hash = $this->shifts->hash([$shiftId, $hash, strtolower($pending->public_id)]);
        $model = $incoming ? TopUp::class : Withdrawal::class;
        $existing = $this->shifts->replay($key, $hash);
        if ($existing) return $model::where('public_id', $existing->reference_id)->firstOrFail();
        $shift = CashShift::where('public_id', $shiftId)->firstOrFail();
        $this->assertContext($shift, $wallet, $actor);
        // Preventive blocked alerts must survive; no money has moved at this point.
        $this->limits->assertAllowed($wallet, $incoming ? MovementType::RECARGA : MovementType::RETIRO,
            $amount, 'CASH_REQUEST', $shiftId, trim($actor));
        try {
            return DB::connection('sqlsrv')->transaction(function () use ($shiftId, $wallet, $amount, $key, $actor, $reason, $incoming, $hash, $model, $pending, $confirmation) {
            $shift = $this->shifts->lockShift($shiftId);
            $existing = $this->shifts->replay($key, $hash);
            if ($existing) return $model::where('public_id', $existing->reference_id)->firstOrFail();
            $this->assertContext($shift, $wallet, $actor);
            if (! $incoming && $this->shifts->calculate($shift)['expected_amount_cents'] < $amount)
                throw new InvalidArgumentException('No hay efectivo suficiente en el turno.');
            if ($pending) {
                $operation = $model::where('public_id', $pending->public_id)->lockForUpdate()->firstOrFail();
                $expectedStatus = $incoming ? TopUpStatus::PENDIENTE : WithdrawalStatus::PENDIENTE;
                $expectedMethod = $incoming ? TopUpMethod::EFECTIVO : WithdrawalMethod::EFECTIVO;
                if ($operation->status !== $expectedStatus || $operation->method !== $expectedMethod
                    || strtolower($operation->wallet_id) !== strtolower($wallet->public_id)
                    || $operation->amount_cents !== $amount || $operation->currency !== $wallet->currency
                    || ($operation->agent_id !== null && $operation->agent_id !== trim($actor)) || $operation->cash_shift_id !== null)
                    throw new InvalidArgumentException('La solicitud pendiente no corresponde a esta liquidación de caja.');
                $this->limits->assertAllowed($wallet, $incoming ? MovementType::RECARGA : MovementType::RETIRO,
                    $amount, 'CASH_REQUEST', $shiftId, trim($actor));
                $operation->agent_id = trim($actor);
            } elseif ($incoming) {
                $service = app(TopUpService::class);
                $operation = $service->create($wallet, $amount, TopUpMethod::EFECTIVO, trim($actor), trim($key));
            } else {
                $service = app(WithdrawalService::class);
                $operation = $service->create($wallet, $amount, WithdrawalMethod::EFECTIVO, trim($actor), trim($key));
            }
            $currentWallet = Wallet::where('public_id', $wallet->public_id)->lockForUpdate()->firstOrFail();
            if ($currentWallet->status !== WalletStatus::ACTIVA)
                throw new InvalidArgumentException($incoming ? 'La wallet debe estar activa para recibir una recarga.' : 'La wallet debe estar activa para completar el retiro.');
            // The cash orchestrator owns completion. Ledger, operation, physical entry and receipt share this transaction.
            $ledger = app(LedgerService::class);
            $identity = $confirmation ? ['cash_confirmation_id' => strtolower($confirmation->public_id),
                'student_id' => $confirmation->student_id, 'student_confirmed_by' => $confirmation->confirmed_by,
                'student_confirmed_at' => $confirmation->confirmed_at->toISOString()] : [];
            if ($incoming) $ledger->credit($currentWallet, $amount, MovementType::RECARGA, trim($key), 'TOPUP', $operation->public_id,
                array_merge(['topup_method' => TopUpMethod::EFECTIVO->value], $identity));
            else $ledger->debit($currentWallet, $amount, MovementType::RETIRO, trim($key), 'WITHDRAWAL', $operation->public_id,
                array_merge(['withdrawal_method' => WithdrawalMethod::EFECTIVO->value], $identity));
            $operation->status = $incoming ? TopUpStatus::COMPLETADA : WithdrawalStatus::COMPLETADA;
            $operation->cash_shift_id = $shift->public_id;
            $operation->save();
            $movement = CashMovement::create(['public_id' => (string) Str::uuid(), 'cash_shift_id' => $shift->id,
                'type' => $incoming ? CashMovementType::TOPUP : CashMovementType::WITHDRAWAL,
                'amount_cents' => $amount, 'idempotency_key' => trim($key), 'request_hash' => $hash,
                'actor_id' => trim($actor), 'reason' => trim($reason), 'wallet_id' => $wallet->public_id,
                'reference_type' => $incoming ? 'TOPUP' : 'WITHDRAWAL', 'reference_id' => $operation->public_id]);
            $receipt = app(CashReceiptService::class)->issue($movement);
            $operation->folio = $receipt->folio;
            $operation->save();
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
