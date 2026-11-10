<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\PurchaseRefundRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** Uses credited entries to find candidates, then verifies the original financial evidence. */
final class RefundRecipientQueue
{
    public function __construct(private readonly FinancialAdjustmentService $adjustments,
        private readonly PurchaseRefundService $purchases) {}

    public function forWallet(string $wallet): array
    {
        $services = FinancialRefundRequest::whereExists(function ($query) use ($wallet) {
            $query->selectRaw('1')->from('ledger_entries')
                ->whereColumn('ledger_entries.transaction_id', 'financial_refund_requests.original_transaction_id')
                ->where('ledger_entries.wallet_id', $wallet)->where('ledger_entries.amount_cents', '>', 0)
                ->where('ledger_entries.movement_type', MovementType::COBRO_SERVICIO->value);
        })->with('originalTransaction')->latest('id')->limit(50)->get();

        $purchases = PurchaseRefundRequest::whereExists(function ($query) use ($wallet) {
            $query->selectRaw('1')->from('purchase_payments')
                ->join('financial_transactions', 'financial_transactions.idempotency_key', '=', 'purchase_payments.idempotency_key')
                ->join('ledger_entries', 'ledger_entries.transaction_id', '=', 'financial_transactions.public_id')
                ->whereColumn('purchase_payments.public_id', 'purchase_refund_requests.purchase_payment_id')
                ->where('financial_transactions.reference_type', 'PURCHASE')
                ->where('ledger_entries.wallet_id', $wallet)->where('ledger_entries.amount_cents', '>', 0)
                ->where('ledger_entries.movement_type', MovementType::LIQUIDACION_COMPRA->value);
        })->latest('id')->limit(50)->get();

        return [
            $this->verified($services, fn ($row) => $this->adjustments->reviewWalletId($row, lock: false), $wallet),
            $this->verified($purchases, fn ($row) => $this->purchases->reviewWalletId($row, lock: false), $wallet),
        ];
    }

    private function verified(Collection $rows, callable $recipient, string $wallet): Collection
    {
        return $rows->filter(function ($row) use ($recipient, $wallet) {
            try {
                return $recipient($row) === $wallet;
            } catch (InvalidArgumentException|ModelNotFoundException) {
                return false;
            }
        })->values();
    }
}
