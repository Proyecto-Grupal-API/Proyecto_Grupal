<?php
namespace App\Domains\Financial\Services;

use App\Domains\Financial\Models\{Bonus, BonusBudgetCommitment, BonusLedgerEntry, InstitutionalBonusObligation,
    InstitutionalBonusRefund, InstitutionalBonusBudget, InstitutionalBudgetOperation, PurchasePayment, PurchaseRefundRequest, FinancialTransaction, Wallet, InstitutionalBonusSettlement};
use App\Domains\Financial\Enums\{BonusType, BonusStatus, BonusMovementType, RefundRequestStatus, TransactionStatus};
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Invoke while holding budget then bonus row locks. Never repairs inconsistencies. */
final class InstitutionalBonusIntegrity
{
    public function assertState(InstitutionalBonusBudget $budget, Bonus $bonus): BonusBudgetCommitment
    {
        $fail = fn () => throw new ConflictHttpException('La evidencia institucional requiere revision.');
        $id = strtolower($bonus->public_id); $budgetId = strtolower($budget->public_id);
        $link = BonusBudgetCommitment::where('bonus_id', $id)->first();
        $issue = $link ? InstitutionalBudgetOperation::where('public_id', $link->issue_operation_id)->first() : null;
        if (!$link || !$issue || $issue->action !== 'ISSUE_BECA' || strtolower($issue->budget_id) !== $budgetId
            || strtolower($link->budget_id) !== $budgetId || strtolower((string)$bonus->getRawOriginal('institutional_budget_id')) !== $budgetId
            || strtolower((string)($issue->after_data['bonus_id'] ?? '')) !== $id
            || strtolower((string)($issue->after_data['commitment_id'] ?? '')) !== strtolower($link->public_id)
            || ($issue->after_data['award_reference'] ?? null) !== $link->award_reference
            || ($issue->after_data['committed_delta_cents'] ?? null) !== $link->amount_cents
            || $link->amount_cents <= 0 || $link->amount_cents !== $bonus->original_amount_cents
            || $link->status !== 'COMMITTED' || $bonus->type !== BonusType::BECA || $budget->bonus_type !== 'BECA'
            || $link->currency !== 'MXN' || $bonus->currency !== 'MXN' || $budget->currency !== 'MXN') $fail();
        $original = $link->amount_cents; $net = 0; $expected = [];
        foreach (InstitutionalBonusObligation::where('bonus_id', $id)->get() as $debt) {
            $payment = PurchasePayment::where('public_id', $debt->purchase_payment_id)->first();
            $op = InstitutionalBudgetOperation::where('public_id', $debt->operation_id)->first();
            $wallet = $payment ? Wallet::where('public_id',$payment->wallet_id)->first() : null;
            if (!$payment || !$wallet || $wallet->owner_type !== 'USER' || strtolower($wallet->owner_id) !== strtolower($bonus->beneficiary_id)
                || !$op || $op->action !== 'CONSUME_BECA' || strtolower($op->budget_id) !== $budgetId
                || strtolower($debt->budget_id) !== $budgetId || $debt->currency !== 'MXN' || $debt->status !== 'OPEN'
                || $debt->amount_cents <= 0 || $debt->refunded_amount_cents < 0 || $debt->refunded_amount_cents > $debt->amount_cents
                || !is_int($debt->settled_amount_cents) || $debt->settled_amount_cents < 0
                || $debt->settled_amount_cents > $debt->amount_cents - $debt->refunded_amount_cents
                || $debt->payer_reference !== $budget->payer_reference || $payment->business_id !== $debt->business_id
                || strtolower((string)$payment->bonus_id) !== $id || $payment->currency !== 'MXN' || $payment->status !== 'COMPLETADO'
                || $payment->total_amount_cents !== $debt->amount_cents || $payment->bonus_amount_cents !== $debt->amount_cents
                || $payment->wallet_amount_cents !== 0 || !empty($payment->requested_bonus_ids) || $payment->bonuses()->exists()
                || strtolower((string)($op->after_data['purchase_payment_id'] ?? '')) !== strtolower($payment->public_id)
                || strtolower((string)($op->after_data['obligation_id'] ?? '')) !== strtolower($debt->public_id)
                || ($op->after_data['consumed_delta_cents'] ?? null) !== $debt->amount_cents) $fail();
            $delta = $debt->amount_cents - $debt->refunded_amount_cents;
            if ($delta > $original - $net) $fail(); $net += $delta;
            $expected['BUDGET_PURCHASE:'.strtolower($payment->public_id)] = [-$debt->amount_cents, BonusMovementType::CONSUMO];
            $refunded = 0;
            foreach (InstitutionalBonusRefund::where('obligation_id', $debt->public_id)->get() as $line) {
                $r = PurchaseRefundRequest::where('public_id', $line->refund_request_id)->first();
                $rop = InstitutionalBudgetOperation::where('public_id', $line->operation_id)->first();
                $tx = $r ? FinancialTransaction::where('public_id',$r->financial_transaction_id)->first() : null;
                $allocations = $r ? $r->bonuses()->get() : collect(); $allocation = $allocations->first();
                if (!$r || !$tx || $tx->status !== TransactionStatus::COMPLETADA || $tx->reference_type !== 'PURCHASE_REFUND'
                    || strtolower((string)$tx->reference_id) !== strtolower($r->public_id)
                    || $allocations->count() !== 1 || !$allocation || strtolower($allocation->bonus_id) !== $id || $allocation->amount_cents !== $line->amount_cents
                    || !$rop || $rop->action !== 'REFUND_BECA' || strtolower($rop->budget_id) !== $budgetId
                    || $r->status !== RefundRequestStatus::COMPLETADA || strtolower($r->purchase_payment_id) !== strtolower($payment->public_id)
                    || $line->amount_cents <= 0 || $line->amount_cents !== $r->total_amount_cents || $r->wallet_amount_cents !== 0
                    || $r->bonus_amount_cents !== $line->amount_cents || $r->currency !== 'MXN'
                    || ($rop->after_data['restored_amount_cents'] ?? null) !== $line->amount_cents
                    || strtolower((string)($rop->after_data['obligation_id'] ?? '')) !== strtolower($debt->public_id)
                    || strtolower((string)($rop->after_data['bonus_id'] ?? '')) !== $id
                    || strtolower((string)($rop->after_data['refund_request_id'] ?? '')) !== strtolower($r->public_id)
                    || $line->amount_cents > $debt->amount_cents - $refunded) $fail();
                $refunded += $line->amount_cents;
                $expected['PURCHASE_REFUND:'.strtolower($r->public_id)] = [$line->amount_cents, BonusMovementType::DEVOLUCION];
            }
            if ($refunded !== $debt->refunded_amount_cents) $fail();
            $settled = 0;
            foreach (InstitutionalBonusSettlement::where('obligation_id',$debt->public_id)->get() as $paymentRecord) {
                $sop = InstitutionalBudgetOperation::where('public_id',$paymentRecord->operation_id)->first();
                $expectedKey = hash('sha256',json_encode([$paymentRecord->payment_provider,$paymentRecord->payment_reference],JSON_THROW_ON_ERROR));
                if (!$sop || $sop->action !== 'SETTLE_BECA' || strtolower($sop->budget_id) !== $budgetId
                    || strtolower($paymentRecord->budget_id) !== $budgetId || $paymentRecord->currency !== 'MXN'
                    || $paymentRecord->amount_cents <= 0 || $paymentRecord->amount_cents > $debt->amount_cents - $refunded - $settled
                    || !hash_equals($expectedKey,$paymentRecord->payment_key)
                    || strtolower((string)($sop->after_data['obligation_id'] ?? '')) !== strtolower($debt->public_id)
                    || strtolower((string)($sop->after_data['settlement_id'] ?? '')) !== strtolower($paymentRecord->public_id)
                    || ($sop->after_data['settled_delta_cents'] ?? null) !== $paymentRecord->amount_cents
                    || ($sop->after_data['payment_key'] ?? null) !== $paymentRecord->payment_key
                    || ($sop->after_data['payment_provider'] ?? null) !== $paymentRecord->payment_provider
                    || ($sop->after_data['payment_reference'] ?? null) !== $paymentRecord->payment_reference
                    || ($sop->after_data['recipient_reference'] ?? null) !== $paymentRecord->recipient_reference
                    || ($sop->after_data['paid_at'] ?? null) !== $paymentRecord->paid_at_utc
                    || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/',$paymentRecord->paid_at_utc)) $fail();
                try { $date=\Carbon\CarbonImmutable::parse($paymentRecord->paid_at_utc); }
                catch (\Throwable $e) { $fail(); }
                if ($date->toISOString() !== $paymentRecord->paid_at_utc || $date->lt($payment->created_at) || $date->gt(now())) $fail();
                $settled += $paymentRecord->amount_cents;
            }
            if ($settled !== $debt->settled_amount_cents) $fail();

        }
        $entries = BonusLedgerEntry::where('bonus_id', $id)->orderBy('id')->get();
        $balance = 0; $released = 0; $initial = true;
        foreach ($entries as $entry) {
            if ($initial) {
                if ($entry->movement_type !== BonusMovementType::EMISION || $entry->amount_cents !== $original
                    || $entry->reference_type !== 'BONUS_ISSUANCE' || strtolower((string)$entry->reference_id) !== $id) $fail();
                $initial = false;
            } elseif ($entry->reference_type === 'BUDGET_RELEASE') {
                $op = InstitutionalBudgetOperation::where('public_id', $entry->reference_id)->first();
                $target = $op?->action === 'CANCEL_BECA' ? BonusStatus::CANCELADO : BonusStatus::EXPIRADO;
                $movement = $target === BonusStatus::CANCELADO ? BonusMovementType::CANCELACION : BonusMovementType::EXPIRACION;
                if (!$op || !in_array($op->action, ['CANCEL_BECA','EXPIRE_BECA'], true) || strtolower($op->budget_id) !== $budgetId
                    || strtolower((string)($op->after_data['bonus_id'] ?? '')) !== $id || $entry->amount_cents >= 0 || $released !== 0
                    || ($op->after_data['released_amount_cents'] ?? null) !== -$entry->amount_cents
                    || $entry->movement_type !== $movement || $bonus->status !== $target) $fail();
                $released = -$entry->amount_cents;
            } else {
                $k = $entry->reference_type.':'.strtolower((string)$entry->reference_id);
                if (!isset($expected[$k]) || $expected[$k] !== [$entry->amount_cents, $entry->movement_type]) $fail();
                unset($expected[$k]);
            }
            if ($entry->amount_cents > 0 && $entry->amount_cents > $original - $balance) $fail();
            if ($entry->amount_cents < 0 && $entry->amount_cents < -$balance) $fail();
            $balance += $entry->amount_cents;
            if ($entry->remaining_after_cents !== $balance) $fail();
        }
        if ($initial || $expected !== [] || $balance !== $bonus->remaining_amount_cents || $released > $original - $net
            || $balance !== $original - $net - $released
            || (in_array($bonus->status, [BonusStatus::CANCELADO,BonusStatus::EXPIRADO], true) && ($balance !== 0 || $released <= 0))
            || ($bonus->status === BonusStatus::AGOTADO && ($balance !== 0 || $released !== 0))
            || (in_array($bonus->status, [BonusStatus::ACTIVO,BonusStatus::PENDIENTE], true) && ($balance <= 0 || $released !== 0))) $fail();
        return $link;
    }
}
