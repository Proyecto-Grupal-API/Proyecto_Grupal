<?php
namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization;
use App\Domains\Financial\Enums\{BonusStatus, BonusMovementType, BonusRestrictionType, WalletStatus, WalletType, TransactionStatus, RefundRequestStatus};
use App\Domains\Financial\Models\{Bonus, BonusLedgerEntry, Wallet, PurchasePayment, PurchaseRefundRequest, FinancialTransaction,
    InstitutionalBonusBudget, InstitutionalBudgetOperation, InstitutionalBonusObligation, InstitutionalBonusRefund};
use App\Domains\Financial\Integrations\Team4\{PaymentEventPayload, PurchasePaymentEvents};
use App\Domains\Financial\Support\FinancialJobLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** One institutional BECA covers the entire purchase. No cash creation or institutional wallet. */
final class InstitutionalBecaPurchaseService
{
    public function __construct(private readonly InstitutionalPurchaseAuthorization $authorization,
        private readonly InstitutionalBonusIntegrity $integrity, private readonly InstitutionalBudgetService $budgets,
        private readonly FinancialJobLock $locks) {}

    public function pay(Wallet $wallet, string $bonusId, mixed $amount, string $key, string $actor,
        string $authorizationReference, array $marketplace, ?string $categoryId = null): array
    {
        $actor = $this->actor($actor); $key = $this->key($key);
        if (!Str::isUuid($bonusId) || !is_int($amount) || $amount <= 0 || $amount > 9007199254740991
            || !$this->text($authorizationReference, 150) || ($categoryId !== null && !$this->text($categoryId,100))
            || array_diff(array_keys($marketplace), ['order_id','business_id','items']) || !isset($marketplace['order_id'],$marketplace['business_id'])) {
            throw new InvalidArgumentException('Compra institucional no valida.');
        }
        $bonusId = strtolower($bonusId);
        $canonical = PaymentEventPayload::canonical(['payment_intent_id' => (string)Str::uuid(),
            'order_id' => $marketplace['order_id'], 'business_id' => $marketplace['business_id'], 'items' => $marketplace['items'] ?? [],
            'amount_cents' => $amount, 'currency' => 'MXN', 'status' => 'PAID', 'occurred_at' => now()->toISOString()]);
        if (!$this->text($canonical['business_id'],100)) throw new InvalidArgumentException('Negocio no valido.');
        $context = ['order_id' => $canonical['order_id'], 'business_id' => $canonical['business_id'], 'items' => $canonical['items']];
        $evidence = ['wallet_id' => strtolower($wallet->public_id), 'buyer_id' => $actor, 'bonus_id' => $bonusId,
            'amount_cents' => $amount, 'currency' => 'MXN', 'category_id' => $categoryId,
            'authorization_reference' => $authorizationReference, 'marketplace' => $context];
        $probe = Bonus::where('public_id',$bonusId)->first();
        $budgetId = $probe?->getRawOriginal('institutional_budget_id');
        if (!$budgetId) throw new ConflictHttpException('La compra requiere una BECA con presupuesto.');
        $evidence['budget_id'] = strtolower($budgetId);
        $this->authorize($actor, 'purchase.pay', $evidence);
        $hash = hash('sha256', json_encode([$actor,'CONSUME_BECA',$evidence],JSON_THROW_ON_ERROR));
        return $this->run($key, function ($lockKey, $token) use ($actor,$key,$bonusId,$budgetId,$wallet,$amount,$context,$categoryId,$evidence,$hash) {
            $budget = InstitutionalBonusBudget::where('public_id',$budgetId)->lockForUpdate()->firstOrFail();
            app(InstitutionalBudgetIntegrity::class)->assertState($budget);
            $bonus = Bonus::where('public_id',$bonusId)->lockForUpdate()->firstOrFail();
            $this->integrity->assertState($budget,$bonus);
            $currentWallet = Wallet::where('public_id',$wallet->public_id)->lockForUpdate()->firstOrFail();
            if ($currentWallet->owner_type !== 'USER' || $currentWallet->type !== WalletType::USUARIO
                || strtolower($currentWallet->owner_id) !== $actor || $bonus->beneficiary_type !== 'USER'
                || strtolower($bonus->beneficiary_id) !== $actor || $currentWallet->currency !== 'MXN') throw new AuthorizationException();
            $this->authorize($actor,'purchase.pay',$evidence);
            $existing = InstitutionalBudgetOperation::where('idempotency_key',$key)->first();
            if ($existing) {
                if (!hash_equals($existing->request_hash,$hash) || $existing->action !== 'CONSUME_BECA') throw new ConflictHttpException('La llave pertenece a otra operacion.');
                $debt = InstitutionalBonusObligation::where('operation_id',$existing->public_id)->first();
                $payment = $debt ? PurchasePayment::where('public_id',$debt->purchase_payment_id)->first() : null;
                if (!$payment || strtolower($payment->wallet_id) !== strtolower($currentWallet->public_id)
                    || $payment->idempotency_key !== $key || $payment->category_id !== $categoryId) throw new ConflictHttpException('La compra requiere revision.');
                app(PurchasePaymentEvents::class)->paid($payment,$context,true);
                return $this->result($payment,$debt,true);
            }
            if (PurchasePayment::where('idempotency_key',$key)->exists() || FinancialTransaction::where('idempotency_key',$key)->exists()) {
                throw new ConflictHttpException('La llave ya tiene evidencia financiera.');
            }
            if ($currentWallet->status !== WalletStatus::ACTIVA || $budget->status !== 'ACTIVE'
                || now()->lt($budget->valid_from) || now()->gte($budget->expires_at)
                || !in_array($bonus->status,[BonusStatus::ACTIVO,BonusStatus::PENDIENTE],true)
                || now()->lt($bonus->valid_from) || now()->gte($bonus->expires_at)
                || $amount > $bonus->remaining_amount_cents || $amount > $budget->committed_amount_cents
                || (!$bonus->allows_partial_use && $amount !== $bonus->remaining_amount_cents)
                || $budget->version === PHP_INT_MAX) throw new ConflictHttpException('Bono o presupuesto no disponibles para esta compra.');
            foreach ([BonusRestrictionType::NEGOCIO->value => $context['business_id'], BonusRestrictionType::CATEGORIA->value => $categoryId] as $type => $target) {
                $allowed = $bonus->restrictions()->where('restriction_type',$type)->pluck('target_id');
                if ($allowed->isNotEmpty() && !$allowed->containsStrict($target)) throw new ConflictHttpException('La compra no cumple las restricciones del bono.');
            }
            $before = $this->budgets->snapshot($budget);
            $budget->committed_amount_cents -= $amount; $budget->consumed_amount_cents += $amount; ++$budget->version; $budget->save();
            $bonus->remaining_amount_cents -= $amount; $bonus->status = $bonus->remaining_amount_cents === 0 ? BonusStatus::AGOTADO : BonusStatus::ACTIVO; $bonus->save();
            $payment = PurchasePayment::create(['public_id' => (string)Str::uuid(),'idempotency_key' => $key,
                'wallet_id' => $currentWallet->public_id,'bonus_id' => $bonus->public_id,'business_id' => $context['business_id'],
                'category_id' => $categoryId,'currency' => 'MXN','total_amount_cents' => $amount,'bonus_amount_cents' => $amount,
                'wallet_amount_cents' => 0,'status' => 'COMPLETADO']);
            $debtId = (string)Str::uuid();
            $this->locks->assertOwned($lockKey,$token,180);
            $op = InstitutionalBudgetOperation::create(['public_id' => (string)Str::uuid(),'budget_id' => $budget->public_id,
                'action' => 'CONSUME_BECA','actor_id' => $actor,'reason' => 'Compra con BECA institucional.',
                'idempotency_key' => $key,'request_hash' => $hash,'before_data' => $before,
                'after_data' => [...$this->budgets->snapshot($budget),'bonus_id' => $bonusId,
                    'purchase_payment_id' => strtolower($payment->public_id),'obligation_id' => strtolower($debtId),
                    'consumed_delta_cents' => $amount,'authorization_reference' => $evidence['authorization_reference']], 'created_at' => now()]);
            $debt = InstitutionalBonusObligation::create(['public_id' => $debtId,'purchase_payment_id' => $payment->public_id,
                'bonus_id' => $bonus->public_id,'budget_id' => $budget->public_id,'operation_id' => $op->public_id,
                'amount_cents' => $amount,'refunded_amount_cents' => 0,'currency' => 'MXN','business_id' => $context['business_id'],
                'payer_reference' => $budget->payer_reference,'status' => 'OPEN']);
            BonusLedgerEntry::create(['public_id' => (string)Str::uuid(),'bonus_id' => $bonus->public_id,
                'movement_type' => BonusMovementType::CONSUMO,'amount_cents' => -$amount,'remaining_after_cents' => $bonus->remaining_amount_cents,
                'reference_type' => 'BUDGET_PURCHASE','reference_id' => $payment->public_id,'actor_id' => $actor,'reason' => 'Compra con BECA institucional.']);
            $this->integrity->assertState($budget,$bonus);
            app(InstitutionalBudgetIntegrity::class)->assertState($budget);
            $this->locks->assertOwned($lockKey,$token,180);
            app(PurchasePaymentEvents::class)->paid($payment,$context);
            return $this->result($payment,$debt,false);
        });
    }

    /** Called by the existing approved purchase-refund flow. No cash refund is accepted. */
    public function completeRefund(PurchaseRefundRequest $input, string $key, string $actor): FinancialTransaction
    {
        $actor = $this->actor($actor); $key = $this->key($key);
        $probe = InstitutionalBonusObligation::where('purchase_payment_id',$input->purchase_payment_id)->first();
        if (!$probe) throw new ConflictHttpException('No existe obligacion institucional para esta compra.');
        return $this->run($key, function ($lockKey,$token) use ($input,$probe,$key,$actor) {
            $budget = InstitutionalBonusBudget::where('public_id',$probe->budget_id)->lockForUpdate()->firstOrFail();
            app(InstitutionalBudgetIntegrity::class)->assertState($budget);
            $bonus = Bonus::where('public_id',$probe->bonus_id)->lockForUpdate()->firstOrFail();
            $this->integrity->assertState($budget,$bonus);
            $payment = PurchasePayment::where('public_id',$probe->purchase_payment_id)->lockForUpdate()->firstOrFail();
            $debt = InstitutionalBonusObligation::where('public_id',$probe->public_id)->lockForUpdate()->firstOrFail();
            $refund = PurchaseRefundRequest::where('public_id',$input->public_id)->lockForUpdate()->firstOrFail();
            $lines = $refund->bonuses()->get(); $line = $lines->first();
            if (strtolower($refund->purchase_payment_id) !== strtolower($payment->public_id) || $refund->wallet_amount_cents !== 0
                || $refund->total_amount_cents <= 0 || $refund->bonus_amount_cents !== $refund->total_amount_cents
                || $lines->count() !== 1 || !$line || strtolower($line->bonus_id) !== strtolower($bonus->public_id)
                || $line->amount_cents !== $refund->total_amount_cents || $refund->currency !== 'MXN'
                || strtolower($refund->wallet_id) !== strtolower($payment->wallet_id)
                || ($refund->reason !== null && !$this->text($refund->reason,1000))) throw new ConflictHttpException('Reparto de devolucion institucional no valido.');
            $context = ['budget_id' => strtolower($budget->public_id),'purchase_payment_id' => strtolower($payment->public_id),'refund_request_id' => strtolower($refund->public_id),
                'bonus_id' => strtolower($bonus->public_id),'buyer_id' => strtolower($bonus->beneficiary_id),
                'business_id' => $debt->business_id,'amount_cents' => $refund->total_amount_cents,'currency' => 'MXN',
                'requested_by' => $refund->requested_by,'reviewed_by' => $refund->reviewed_by,'reason' => $refund->reason];
            $this->authorize($actor,'purchase.refund',$context);
            $hash = hash('sha256',json_encode([$actor,'REFUND_BECA',$context],JSON_THROW_ON_ERROR));
            $existing = InstitutionalBudgetOperation::where('idempotency_key',$key)->first();
            if ($existing) {
                $row = InstitutionalBonusRefund::where('operation_id',$existing->public_id)->first();
                $tx = FinancialTransaction::where('public_id',$refund->financial_transaction_id)->first();
                if (!hash_equals($existing->request_hash,$hash) || $existing->action !== 'REFUND_BECA' || !$row
                    || strtolower($row->refund_request_id) !== strtolower($refund->public_id) || !$tx
                    || $refund->status !== RefundRequestStatus::COMPLETADA || $tx->idempotency_key !== $key
                    || $tx->status !== TransactionStatus::COMPLETADA || $tx->reference_type !== 'PURCHASE_REFUND'
                    || strtolower((string)$tx->reference_id) !== strtolower($refund->public_id)) throw new ConflictHttpException('La repeticion de devolucion requiere revision.');
                return $tx;
            }
            if ($refund->status !== RefundRequestStatus::APROBADA || !$this->text((string)$refund->reviewed_by,255)
                || $debt->status !== 'OPEN' || $budget->status !== 'ACTIVE' || now()->gte($budget->expires_at)
                || now()->lt($budget->valid_from) || now()->gte($bonus->expires_at) || now()->lt($bonus->valid_from)
                || !in_array($bonus->status,[BonusStatus::ACTIVO,BonusStatus::AGOTADO],true)
                || $refund->total_amount_cents > $debt->amount_cents - $debt->refunded_amount_cents - $debt->settled_amount_cents
                || $refund->total_amount_cents > $budget->consumed_amount_cents || $budget->version === PHP_INT_MAX
                || FinancialTransaction::where('idempotency_key',$key)->exists()) throw new ConflictHttpException('Devolucion institucional no disponible.');
            $reserved = (int)PurchaseRefundRequest::where('purchase_payment_id',$payment->public_id)
                ->whereIn('status',[RefundRequestStatus::PENDIENTE->value,RefundRequestStatus::APROBADA->value,RefundRequestStatus::COMPLETADA->value])->sum('total_amount_cents');
            if ($reserved > $debt->amount_cents) throw new ConflictHttpException('Las devoluciones superan la compra.');
            $requestTx = FinancialTransaction::where('public_id',$refund->request_transaction_id)->lockForUpdate()->firstOrFail();
            if ($requestTx->status !== TransactionStatus::PENDIENTE || $requestTx->reference_type !== 'PURCHASE_REFUND_REQUEST'
                || strtolower((string)$requestTx->reference_id) !== strtolower($payment->public_id)) throw new ConflictHttpException('Solicitud de devolucion inconsistente.');
            $before = $this->budgets->snapshot($budget); $amount = $refund->total_amount_cents;
            $budget->consumed_amount_cents -= $amount; $budget->committed_amount_cents += $amount; ++$budget->version; $budget->save();
            $bonus->remaining_amount_cents += $amount; $bonus->status = BonusStatus::ACTIVO; $bonus->save();
            $debt->refunded_amount_cents += $amount; $debt->save();
            $tx = FinancialTransaction::create(['public_id' => (string)Str::uuid(),'idempotency_key' => $key,
                'status' => TransactionStatus::COMPLETADA,'reference_type' => 'PURCHASE_REFUND','reference_id' => $refund->public_id,
                'metadata' => ['operation' => 'PURCHASE_REFUND','purchase_payment_id' => strtolower($payment->public_id),
                    'wallet_amount_cents' => 0,'bonus_amount_cents' => $amount,'executed_by' => $actor,'institutional' => true]]);
            $refund->status = RefundRequestStatus::COMPLETADA; $refund->financial_transaction_id = $tx->public_id; $refund->completed_at = now(); $refund->save();
            $requestTx->status = TransactionStatus::COMPLETADA; $requestTx->save();
            $this->locks->assertOwned($lockKey,$token,180);
            $op = InstitutionalBudgetOperation::create(['public_id' => (string)Str::uuid(),'budget_id' => $budget->public_id,
                'action' => 'REFUND_BECA','actor_id' => $actor,'reason' => $refund->reason ?: 'Devolucion de compra institucional.',
                'idempotency_key' => $key,'request_hash' => $hash,'before_data' => $before,
                'after_data' => [...$this->budgets->snapshot($budget),'bonus_id' => strtolower($bonus->public_id),
                    'refund_request_id' => strtolower($refund->public_id),'obligation_id' => strtolower($debt->public_id),'restored_amount_cents' => $amount], 'created_at' => now()]);
            InstitutionalBonusRefund::create(['public_id' => (string)Str::uuid(),'obligation_id' => $debt->public_id,
                'refund_request_id' => $refund->public_id,'operation_id' => $op->public_id,'amount_cents' => $amount,'created_at' => now()]);
            BonusLedgerEntry::create(['public_id' => (string)Str::uuid(),'bonus_id' => $bonus->public_id,
                'movement_type' => BonusMovementType::DEVOLUCION,'amount_cents' => $amount,'remaining_after_cents' => $bonus->remaining_amount_cents,
                'reference_type' => 'PURCHASE_REFUND','reference_id' => $refund->public_id,'actor_id' => $actor,'reason' => $refund->reason]);
            $this->integrity->assertState($budget,$bonus);
            app(InstitutionalBudgetIntegrity::class)->assertState($budget);
            $this->locks->assertOwned($lockKey,$token,180);
            app(PurchasePaymentEvents::class)->refunded($payment,$refund);
            return $tx;
        });
    }

    private function authorize(string $actor, string $action, array $context): void
    {
        if (!$this->authorization->allows($actor,$action,$context)) throw new AuthorizationException();
    }
    private function actor(string $actor): string
    {
        if (!preg_match('/\A[0-9a-f]{24}\z/i',$actor)) throw new AuthorizationException(); return strtolower($actor);
    }
    private function key(string $key): string
    {
        if (!preg_match('/\A[A-Za-z0-9._:-]{1,200}\z/',$key)) throw new InvalidArgumentException('Llave no valida.'); return strtolower($key);
    }
    private function text(string $v, int $max): bool { return trim($v) !== '' && mb_strlen($v) <= $max && !preg_match('/[\x00-\x1f\x7f]/',$v); }
    private function result(PurchasePayment $p, InstitutionalBonusObligation $d, bool $replayed): array
    {
        return ['purchase_payment_id' => strtolower($p->public_id),'obligation_id' => strtolower($d->public_id),
            'total_amount_cents' => $p->total_amount_cents,'bonus_amount_cents' => $p->bonus_amount_cents,'wallet_amount_cents' => 0,'replayed' => $replayed];
    }
    private function run(string $key, callable $work): mixed
    {
        $lockKey = 'institution-budget:'.hash('sha256',$key); $token = $this->locks->acquire($lockKey,180);
        if (!$token) throw new ConflictHttpException('Solicitud institucional en curso.');
        try { return DB::connection('sqlsrv')->transaction(fn () => $work($lockKey,$token)); }
        catch (QueryException $e) {
            if (in_array((int)($e->errorInfo[1] ?? 0),[2601,2627],true)) throw new ConflictHttpException('La compra o llave ya tienen evidencia registrada.');
            throw $e;
        } finally { $this->locks->release($lockKey,$token); }
    }
}
