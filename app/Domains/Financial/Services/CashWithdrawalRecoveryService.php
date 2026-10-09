<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\CashMovementType;
use App\Domains\Financial\Enums\CashRegisterStatus;
use App\Domains\Financial\Enums\RefundRequestStatus;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\FinancialWithdrawalRecovery;
use App\Domains\Financial\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CashWithdrawalRecoveryService
{
    public function __construct(private readonly CashShiftService $shifts, private readonly FinancialAdjustmentService $adjustments) {}

    public function recover(string $association, string $shiftId, FinancialRefundRequest $refund, string $key, string $actor, string $reason): FinancialWithdrawalRecovery
    {
        $this->shifts->text($key); $this->shifts->text($actor); $this->shifts->text($reason, 1000);
        $hash = $this->shifts->hash([$shiftId, 'WITHDRAWAL_RECOVERY', strtolower($refund->public_id), trim($actor), trim($reason), $association]);
        return DB::connection('sqlsrv')->transaction(function () use ($association, $shiftId, $refund, $key, $actor, $reason, $hash) {
            $shift = $this->shifts->lockShift($shiftId);
            if ($shift->cashRegister->association_id !== $association || $shift->agent_id !== trim($actor))
                throw new InvalidArgumentException('La asociación o el operador no corresponde al turno receptor.');
            if ($existing = $this->shifts->replay($key, $hash)) {
                $recovery = FinancialWithdrawalRecovery::where('cash_movement_id', $existing->public_id)->firstOrFail();
                if (strtolower($recovery->refund_request_id) !== strtolower($refund->public_id)) throw new InvalidArgumentException('La recuperación pertenece a otra solicitud.');
                return $recovery;
            }
            $this->shifts->assertOpen($shift);
            if ($shift->cashRegister->status !== CashRegisterStatus::ACTIVE) throw new InvalidArgumentException('La caja receptora no está activa.');
            $original = FinancialTransaction::where('public_id', $refund->original_transaction_id)->lockForUpdate()->firstOrFail();
            $request = FinancialRefundRequest::where('public_id', $refund->public_id)->lockForUpdate()->firstOrFail();
            if (strtolower($request->original_transaction_id) !== strtolower($original->public_id)
                || $original->reference_type !== 'WITHDRAWAL' || $request->status !== RefundRequestStatus::APROBADA)
                throw new InvalidArgumentException('La recuperación requiere una devolución de retiro aprobada.');
            $withdrawal = Withdrawal::where('public_id', $original->reference_id)->firstOrFail();
            $origin = $withdrawal->cash_shift_id ? CashShift::where('public_id', $withdrawal->cash_shift_id)->firstOrFail() : null;
            if (! $origin || $origin->cashRegister->association_id !== $association
                || $withdrawal->currency !== $shift->cashRegister->currency)
                throw new InvalidArgumentException('El retiro original no pertenece a una caja de esta asociación o tiene otra moneda.');
            if (FinancialWithdrawalRecovery::where('refund_request_id', $request->public_id)->exists())
                throw new InvalidArgumentException('La solicitud ya tiene una recuperación. Reintenta con su turno, clave y datos originales.');
            $movement = CashMovement::create(['public_id' => (string) Str::uuid(), 'cash_shift_id' => $shift->id,
                'type' => CashMovementType::WITHDRAWAL_RECOVERY, 'amount_cents' => $request->amount_cents,
                'idempotency_key' => trim($key), 'request_hash' => $hash, 'actor_id' => trim($actor), 'reason' => trim($reason),
                'wallet_id' => $withdrawal->wallet_id, 'reference_type' => 'WITHDRAWAL_RECOVERY', 'reference_id' => $request->public_id]);
            $receipt = app(CashReceiptService::class)->issue($movement);
            return $this->adjustments->confirmWithdrawalRecovery($request, $receipt->folio, trim($actor), trim($reason), $movement)->fresh();
        });
    }
}
