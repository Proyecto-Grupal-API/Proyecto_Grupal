<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\PurchasePaymentBonus;
use App\Domains\Financial\Models\Wallet;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Read-only projections. Never issues a receipt or posts an operation. */
class PersonalReceiptQueryService
{
    public function listing(Wallet $wallet, int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $transactions = DB::connection('sqlsrv')->table('financial_transactions as t')
            ->selectRaw("t.public_id as operation_id, 'TRANSACTION' as source, t.created_at")
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as e')
                ->whereColumn('e.transaction_id', 't.public_id')->where('e.wallet_id', $wallet->public_id))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('purchase_payments as p')
                ->whereColumn('p.idempotency_key', 't.idempotency_key')->where('p.wallet_id', $wallet->public_id)
                ->where('t.reference_type', 'PURCHASE'));
        $payments = DB::connection('sqlsrv')->table('purchase_payments as p')
            ->selectRaw("p.public_id as operation_id, 'PURCHASE' as source, p.created_at")
            ->where('p.wallet_id', $wallet->public_id)->where('p.status', 'COMPLETADO');

        return DB::connection('sqlsrv')->query()->fromSub($transactions->unionAll($payments), 'receipts')
            ->orderByDesc('created_at')->orderBy('source')->orderByDesc('operation_id')
            ->paginate(min(50, max(1, $perPage)), ['*'], 'page', max(1, $page));
    }

    public function transaction(Wallet $wallet, string $id): array
    {
        // Ownership before transaction lookup: foreign and absent resources are both 404.
        LedgerEntry::where('transaction_id', $id)->where('wallet_id', $wallet->public_id)->firstOrFail();
        $transaction = FinancialTransaction::where('public_id', $id)->firstOrFail();
        if ($transaction->reference_type === 'PURCHASE') {
            $payment = PurchasePayment::where('idempotency_key', $transaction->idempotency_key)
                ->where('wallet_id', $wallet->public_id)->first();
            if ($payment) {
                return $this->purchase($wallet, $payment->public_id);
            }
        }
        if ($transaction->status->value !== 'COMPLETADA') {
            throw new ConflictHttpException('La operación no está completada.');
        }

        return [
            'id' => strtolower($transaction->public_id), 'source' => 'TRANSACTION',
            'status' => $transaction->status->value, 'created_at' => $transaction->created_at?->toISOString(),
            'currency' => $wallet->currency, 'reference_type' => $transaction->reference_type,
            'original_transaction_id' => $transaction->original_transaction_id,
            'entries' => $this->entries($wallet, $transaction->public_id), 'purchase' => null,
            'cash_receipts' => $this->cash($wallet, $transaction),
            'historical_notice' => app(LegacyCashReceiptEvidenceService::class)->notice($wallet, $transaction),
        ];
    }

    public function purchase(Wallet $wallet, string $id): array
    {
        $payment = PurchasePayment::where('public_id', $id)->where('wallet_id', $wallet->public_id)->firstOrFail();
        if ($payment->status !== 'COMPLETADO') {
            throw new ConflictHttpException('El pago no está completado.');
        }
        if ($payment->total_amount_cents <= 0 || $payment->wallet_amount_cents < 0 || $payment->bonus_amount_cents < 0
            || $payment->wallet_amount_cents + $payment->bonus_amount_cents !== $payment->total_amount_cents
            || strtoupper($payment->currency) !== strtoupper($wallet->currency)) {
            throw new ConflictHttpException('El desglose registrado del pago requiere revisión.');
        }
        $allocations = PurchasePaymentBonus::where('purchase_payment_id', $payment->public_id)->orderBy('id')->get()
            ->map(fn ($row) => ['bonus_id' => strtolower($row->bonus_id), 'amount_cents' => $row->amount_cents])->all();
        if (! $allocations && $payment->bonus_amount_cents > 0 && $payment->bonus_id) {
            $allocations = [['bonus_id' => strtolower($payment->bonus_id), 'amount_cents' => $payment->bonus_amount_cents]];
        }
        if (collect($allocations)->contains(fn ($a) => $a['amount_cents'] <= 0)
            || array_sum(array_column($allocations, 'amount_cents')) !== $payment->bonus_amount_cents) {
            throw new ConflictHttpException('La aplicación de bonos registrada requiere revisión.');
        }
        $transaction = FinancialTransaction::where('idempotency_key', $payment->idempotency_key)
            ->where('reference_type', 'PURCHASE')->first();
        $entries = $transaction ? $this->entries($wallet, $transaction->public_id) : [];
        if ($payment->wallet_amount_cents > 0 && (! $transaction || $transaction->status->value !== 'COMPLETADA'
            || array_sum(array_column($entries, 'amount_cents')) !== -$payment->wallet_amount_cents)) {
            throw new ConflictHttpException('El pago no tiene evidencia contable consistente de la wallet.');
        }
        if ($payment->wallet_amount_cents === 0 && $entries) {
            throw new ConflictHttpException('El pago tiene evidencia contable inesperada.');
        }

        return [
            'id' => strtolower($payment->public_id), 'source' => 'PURCHASE', 'status' => $payment->status,
            'created_at' => $payment->created_at?->toISOString(), 'currency' => $payment->currency,
            'reference_type' => 'PURCHASE', 'original_transaction_id' => null,
            'entries' => $entries, 'cash_receipts' => [],
            'purchase' => ['total_amount_cents' => $payment->total_amount_cents,
                'wallet_amount_cents' => $payment->wallet_amount_cents, 'bonus_amount_cents' => $payment->bonus_amount_cents,
                'bonuses' => $allocations],
        ];
    }

    private function entries(Wallet $wallet, string $transaction): array
    {
        return LedgerEntry::where('transaction_id', $transaction)->where('wallet_id', $wallet->public_id)
            ->orderBy('created_at')->orderBy('id')->get()->map(fn ($e) => [
                'id' => strtolower($e->public_id), 'movement_type' => $e->movement_type->value,
                'amount_cents' => $e->amount_cents, 'available_balance_after_cents' => $e->available_balance_after_cents,
                'held_balance_after_cents' => $e->held_balance_after_cents, 'created_at' => $e->created_at?->toISOString(),
            ])->all();
    }

    private function cash(Wallet $wallet, FinancialTransaction $transaction): array
    {
        return CashMovement::with('receipt')->where('wallet_id', $wallet->public_id)
            ->where('idempotency_key', $transaction->idempotency_key)
            ->where('reference_type', $transaction->reference_type)->where('reference_id', $transaction->reference_id)
            ->get()->filter(fn ($m) => $m->receipt && strtolower((string) ($m->receipt->snapshot['financial_transaction_id'] ?? ''))
                === strtolower($transaction->public_id))
            ->map(fn ($m) => ['id' => strtolower($m->receipt->public_id), 'folio' => $m->receipt->folio,
                'issued_at' => $m->receipt->issued_at?->toISOString()])->values()->all();
    }
}
