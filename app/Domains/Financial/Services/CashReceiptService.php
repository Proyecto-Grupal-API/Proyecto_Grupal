<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\CashMovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\FinancialTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class CashReceiptService
{
    // Issue only as part of the transaction that just created the movement.
    // Reads and retries never reconstruct historical receipts with today's data.
    public function issue(CashMovement $movement): CashReceipt
    {
        if (DB::connection('sqlsrv')->transactionLevel() < 1) throw new LogicException('El comprobante requiere la transacción del movimiento de caja.');
        if ($existing = CashReceipt::where('cash_movement_id', $movement->id)->first()) return $existing;
        $shift = $movement->shift;
        $register = $shift->cashRegister;
        $transaction = null;
        if (in_array($movement->type, [CashMovementType::TOPUP, CashMovementType::WITHDRAWAL], true)) {
            $transaction = FinancialTransaction::where('idempotency_key', $movement->idempotency_key)
                ->where('reference_type', $movement->reference_type)->where('reference_id', $movement->reference_id)->firstOrFail();
            if ($transaction->status !== TransactionStatus::COMPLETADA) throw new LogicException('La liquidación debe estar completada para emitir su comprobante.');
        }
        $id = (string) Str::uuid();
        $issued = now();
        $folio = 'CAJ-'.$issued->format('Ymd').'-'.strtoupper(str_replace('-', '', $id));
        $snapshot = ['id' => strtolower($id), 'format_version' => 1, 'folio' => $folio, 'issued_at' => $issued->toISOString(),
            'movement_id' => strtolower($movement->public_id), 'cash_shift_id' => strtolower($shift->public_id),
            'cash_register_id' => strtolower($register->public_id), 'cash_register_name' => $register->name,
            'cash_register_version' => $register->version, 'association_id' => $register->association_id,
            'currency' => $register->currency, 'movement_type' => $movement->type->value, 'amount_cents' => $movement->amount_cents,
            'actor_id' => $movement->actor_id, 'reason' => $movement->reason,
            'wallet_id' => $movement->wallet_id ? strtolower($movement->wallet_id) : null,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id && $transaction ? strtolower($movement->reference_id) : $movement->reference_id,
            'financial_transaction_id' => $transaction ? strtolower($transaction->public_id) : null];
        return CashReceipt::create(['public_id' => $id, 'cash_movement_id' => $movement->id, 'folio' => $folio,
            'snapshot' => $snapshot, 'issued_at' => $issued])->fresh();
    }
}
