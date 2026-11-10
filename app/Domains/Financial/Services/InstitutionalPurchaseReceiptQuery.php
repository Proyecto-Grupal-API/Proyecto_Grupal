<?php
namespace App\Domains\Financial\Services;

use App\Domains\Financial\Models\{Bonus, Wallet, PurchasePayment, InstitutionalBonusBudget, InstitutionalBonusObligation,
    InstitutionalBonusRefund, InstitutionalBudgetOperation};
use Illuminate\Support\Facades\{DB, Schema};
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Buyer-safe current projection, not a receipt issuance or a merchant settlement certificate. */
final class InstitutionalPurchaseReceiptQuery
{
    public function summary(Wallet $wallet, PurchasePayment $payment, array $allocations): ?array
    {
        abort_unless(strtolower($payment->wallet_id) === strtolower($wallet->public_id), 404);
        $ids = array_values(array_unique(array_column($allocations, 'bonus_id')));
        $debt = Schema::connection('sqlsrv')->hasTable('institutional_bonus_obligations')
            ? InstitutionalBonusObligation::where('purchase_payment_id', $payment->public_id)->first() : null;
        $bonuses = Bonus::whereIn('public_id', $ids)->get();
        $institutional = $debt !== null || $bonuses->contains(fn ($b) => $b->getRawOriginal('institutional_budget_id') !== null);
        foreach (['bonus_budget_commitments','institutional_bonus_obligations'] as $table) {
            if (Schema::connection('sqlsrv')->hasTable($table)
                && DB::connection('sqlsrv')->table($table)->whereIn('bonus_id', $ids)->exists()) $institutional = true;
        }
        if (!$institutional) return null;
        if (!$debt || !$payment->bonus_id) $this->invalid();

        return DB::connection('sqlsrv')->transaction(function () use ($wallet, $payment, $debt) {
            // Same budget->bonus lock order as writers. UPDLOCK serializes the projection
            // without a shared->exclusive upgrade cycle when a settlement updates its debt first.
            // No rows are inserted, changed or repaired by this query.
            $budget = InstitutionalBonusBudget::where('public_id', $debt->budget_id)->lockForUpdate()->first();
            $bonus = Bonus::where('public_id', $payment->bonus_id)->lockForUpdate()->first();
            $current = InstitutionalBonusObligation::where('purchase_payment_id', $payment->public_id)->first();
            if (!$budget || !$bonus || !$current || $wallet->owner_type !== 'USER' || $bonus->beneficiary_type !== 'USER'
                || strtolower($wallet->owner_id) !== strtolower($bonus->beneficiary_id)
                || strtolower($current->bonus_id) !== strtolower($bonus->public_id)
                || strtolower($current->budget_id) !== strtolower($budget->public_id)
                || $current->amount_cents !== $payment->total_amount_cents || $payment->wallet_amount_cents !== 0) $this->invalid();
            app(InstitutionalBudgetIntegrity::class)->assertState($budget);
            app(InstitutionalBonusIntegrity::class)->assertState($budget, $bonus);

            $restored = 0; $released = 0;
            foreach (InstitutionalBonusRefund::where('obligation_id', $current->public_id)->get() as $refund) {
                $operation = InstitutionalBudgetOperation::where('public_id', $refund->operation_id)->first();
                if ($operation?->action === 'REFUND_BECA') $restored += $refund->amount_cents;
                elseif ($operation?->action === 'REFUND_CLOSED_BECA') $released += $refund->amount_cents;
                else $this->invalid();
            }
            if ($restored + $released !== $current->refunded_amount_cents) $this->invalid();
            // No budget/obligation IDs, grants, actors, bank references or merchant settlement amounts leave this boundary.
            return ['funding_type' => 'INSTITUTIONAL_BECA', 'observed_at' => now()->toISOString(),
                'covered_amount_cents' => $current->amount_cents, 'refunded_amount_cents' => $current->refunded_amount_cents,
                'net_amount_cents' => $current->amount_cents - $current->refunded_amount_cents,
                'restored_bonus_amount_cents' => $restored, 'released_budget_amount_cents' => $released];
        });
    }

    private function invalid(): never
    {
        throw new ConflictHttpException('La evidencia del comprobante institucional requiere revision.');
    }
}
