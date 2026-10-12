<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Contracts\ReceiptAdministrativeAuthorization;
use App\Domains\Financial\Models\{Wallet,PurchasePayment,FinancialTransaction,LedgerEntry,CashMovement};
use App\Domains\Financial\Enums\WalletType;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
class AdministrativeReceiptQueryService
{
    public function __construct(private ReceiptAdministrativeAuthorization $authorization, private PersonalReceiptQueryService $receipts) {}
    public function show(string $actor, string $source, string $operationId, string $walletId, ?array $requestedScope): array {
        // Check before resource lookup; a revoked operator cannot probe operation IDs.
        $this->authorization->assertRead($actor, $requestedScope);
        $wallet=Wallet::where('public_id',$walletId)->where('owner_type','USER')->where('type',WalletType::USUARIO)->firstOrFail();
        $scope=$this->scope($wallet,$source,$operationId,0,$actor);
        if ($scope !== $requestedScope) abort(404);
        // Recheck live grant with the resource's authoritative context, not a client assertion.
        $this->authorization->assertRead($actor,$scope);
        return match($source) {
            'purchase'=>$this->receipts->purchase($wallet,$operationId),
            'transaction'=>$this->receipts->transaction($wallet,$operationId), default=>abort(404),
        };
    }
    private function scope(Wallet $wallet, string $source, string $id, int $depth = 0, ?string $actor = null): ?array {
        if ($depth > 5) throw new ConflictHttpException('La cadena de operaciones originales requiere revisión.');
        if ($source==='purchase') {
            $payment=PurchasePayment::where('public_id',$id)->where('wallet_id',$wallet->public_id)->firstOrFail();
            return ($payment->business_id !== null && $payment->business_id !== '') ? ['type'=>'business','id'=>$payment->business_id] : null;
        }
        if ($source!=='transaction') abort(404);
        LedgerEntry::where('transaction_id',$id)->where('wallet_id',$wallet->public_id)->firstOrFail();
        $t=FinancialTransaction::where('public_id',$id)->firstOrFail();
        if ($t->original_transaction_id) return $this->scope($wallet,'transaction',$t->original_transaction_id,$depth+1,$actor);
        if ($t->reference_type==='PURCHASE') {
            $payment=PurchasePayment::where('idempotency_key',$t->idempotency_key)->where('wallet_id',$wallet->public_id)->firstOrFail();
            return ($payment->business_id !== null && $payment->business_id !== '') ? ['type'=>'business','id'=>$payment->business_id] : null;
        }
        $contexts=CashMovement::with('shift.cashRegister')->where('wallet_id',$wallet->public_id)
            ->where('idempotency_key',$t->idempotency_key)->where('reference_type',$t->reference_type)->where('reference_id',$t->reference_id)
            ->get()->map(fn($m)=>$m->shift?->cashRegister?->association_id)->unique()->values();
        if ($contexts->count()>1 || ($contexts->isNotEmpty() && !$contexts->first())) throw new ConflictHttpException('El contexto de caja requiere revisión.');
        if ($contexts->isNotEmpty()) return ['type'=>'association','id'=>$contexts->first()];
        // A cash operation without a cash link cannot be treated as globally unscoped.
        if (in_array($t->reference_type,['TOPUP','WITHDRAWAL'],true)) {
            $model=$t->reference_type==='TOPUP' ? \App\Domains\Financial\Models\TopUp::class : \App\Domains\Financial\Models\Withdrawal::class;
            $operation=$model::where('public_id',$t->reference_id)->firstOrFail();
            if ($operation->method->value==='EFECTIVO') {
                app(LegacyCashReceiptReviewService::class)->assertReviewed((string)$actor,$wallet,$t);
            }
        }
        return null;
    }
}
