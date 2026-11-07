<?php
namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization;
use App\Domains\Financial\Models\{InstitutionalBonusObligation, InstitutionalBonusSettlement, InstitutionalBonusBudget,
    InstitutionalBudgetOperation, Bonus, PurchasePayment, PurchaseRefundRequest, FinancialTransaction};
use App\Domains\Financial\Enums\RefundRequestStatus;
use App\Domains\Financial\Support\FinancialJobLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Records one verified external payment against one obligation. Never sends money. */
final class InstitutionalSettlementService
{
    public function __construct(private readonly InstitutionalSettlementAuthorization $authority,
        private readonly InstitutionalBonusIntegrity $integrity, private readonly InstitutionalBudgetService $budgets,
        private readonly FinancialJobLock $locks) {}

    public function record(string $actor, string $obligationId, int $expectedVersion, array $data, string $key): array
    {
        if (!preg_match('/\A[0-9a-f]{24}\z/i',$actor)) throw new AuthorizationException();
        if (!Str::isUuid($obligationId) || $expectedVersion < 1 || !preg_match('/\A[A-Za-z0-9._:-]{1,200}\z/',$key)) throw new InvalidArgumentException('Solicitud de liquidacion no valida.');
        $actor=strtolower($actor); $obligationId=strtolower($obligationId); $key=strtolower($key);
        if (array_diff(array_keys($data),['amount_cents','currency','payment_provider','payment_reference','recipient_reference','paid_at','reason'])
            || !isset($data['amount_cents']) || !is_int($data['amount_cents']) || $data['amount_cents'] <= 0
            || ($data['currency'] ?? null) !== 'MXN') throw new InvalidArgumentException('Importe entero positivo y moneda MXN requeridos.');
        foreach (['payment_provider'=>50,'payment_reference'=>150,'recipient_reference'=>150,'reason'=>1000] as $field=>$max) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === '' || mb_strlen($data[$field]) > $max
                || preg_match('/[\x00-\x1f\x7f]/',$data[$field])) throw new InvalidArgumentException('Referencia o motivo de liquidacion no valido.');
            $data[$field]=trim($data[$field]);
        }
        if (!preg_match('/\A[A-Za-z0-9._:-]+\z/',$data['payment_provider'])
            || !preg_match('/\A[A-Za-z0-9._:\/-]+\z/',$data['payment_reference'])
            || !is_string($data['paid_at'] ?? null)
            || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/',$data['paid_at'])) throw new InvalidArgumentException('Proveedor, pago o fecha no validos.');
        try {
            new \DateTimeImmutable($data['paid_at']); $errors=\DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException();
            $paidAt=CarbonImmutable::parse($data['paid_at'])->utc();
        } catch (Throwable $e) { throw new InvalidArgumentException('Fecha de pago no valida.'); }
        if ($paidAt->gt(now())) throw new InvalidArgumentException('No se registra un pago futuro.');
        $data['paid_at']=$paidAt->toISOString();
        $data['payment_provider']=strtolower($data['payment_provider']); $data['payment_reference']=strtolower($data['payment_reference']);
        $paymentKey=hash('sha256',json_encode([$data['payment_provider'],$data['payment_reference']],JSON_THROW_ON_ERROR));
        $probe=InstitutionalBonusObligation::where('public_id',$obligationId)->firstOrFail();
        $source=InstitutionalBonusBudget::where('public_id',$probe->budget_id)->firstOrFail();
        $context=['obligation_id'=>$obligationId,'budget_id'=>strtolower($source->public_id),
            'institution_reference'=>$source->institution_reference,'payer_reference'=>$source->payer_reference,
            'business_id'=>$probe->business_id,'review_version'=>$expectedVersion,...$data];
        $this->authorize($actor,$context);
        $hash=hash('sha256',json_encode([$actor,'SETTLE_BECA',$context],JSON_THROW_ON_ERROR));
        $lockKey='institution-budget:'.hash('sha256',$key); $token=$this->locks->acquire($lockKey,180);
        if (!$token) throw new ConflictHttpException('Solicitud institucional en curso.');
        try {
            return DB::connection('sqlsrv')->transaction(function () use ($actor,$obligationId,$expectedVersion,$data,$key,$paidAt,$paymentKey,$probe,$context,$hash,$lockKey,$token) {
                $budget=InstitutionalBonusBudget::where('public_id',$probe->budget_id)->lockForUpdate()->firstOrFail();
                $bonus=Bonus::where('public_id',$probe->bonus_id)->lockForUpdate()->firstOrFail();
                $this->integrity->assertState($budget,$bonus);
                $purchase=PurchasePayment::where('public_id',$probe->purchase_payment_id)->lockForUpdate()->firstOrFail();
                $debt=InstitutionalBonusObligation::where('public_id',$obligationId)->lockForUpdate()->firstOrFail();
                if ($context['institution_reference'] !== $budget->institution_reference || $context['payer_reference'] !== $budget->payer_reference
                    || $context['business_id'] !== $debt->business_id || strtolower($debt->budget_id) !== strtolower($budget->public_id)) {
                    throw new ConflictHttpException('La evidencia institucional cambio; requiere revision.');
                }
                $this->authorize($actor,$context);
                $existing=InstitutionalBudgetOperation::where('idempotency_key',$key)->first();
                if ($existing) {
                    $row=InstitutionalBonusSettlement::where('operation_id',$existing->public_id)->first();
                    if (!hash_equals($existing->request_hash,$hash) || $existing->action !== 'SETTLE_BECA' || !$row
                        || strtolower($row->obligation_id) !== $obligationId || $row->payment_key !== $paymentKey
                        || $row->amount_cents !== $data['amount_cents']) throw new ConflictHttpException('La llave pertenece a otra liquidacion o requiere revision.');
                    return ['operation'=>$existing,'settlement_id'=>strtolower($row->public_id),'obligation_id'=>$obligationId,'replayed'=>true];
                }
                if ($budget->version !== $expectedVersion || $budget->version === PHP_INT_MAX || $debt->status !== 'OPEN'
                    || $data['amount_cents'] > $debt->amount_cents - $debt->refunded_amount_cents - $debt->settled_amount_cents
                    || $paidAt->lt($purchase->created_at)) throw new ConflictHttpException('Version, importe o fecha no disponibles para liquidacion.');
                if (InstitutionalBonusSettlement::where('payment_key',$paymentKey)->exists()
                    || FinancialTransaction::where('idempotency_key',$key)->exists() || PurchasePayment::where('idempotency_key',$key)->exists()) {
                    throw new ConflictHttpException('El pago o la llave ya tienen evidencia registrada.');
                }
                // Request/review writers serialize on the purchase row. Do not settle money reserved for a refund.
                if (PurchaseRefundRequest::where('purchase_payment_id',$purchase->public_id)
                    ->whereIn('status',[RefundRequestStatus::PENDIENTE->value,RefundRequestStatus::APROBADA->value])->exists()) {
                    throw new ConflictHttpException('Resolver las devoluciones en curso antes de registrar liquidacion.');
                }
                $before=[...$this->budgets->snapshot($budget),'obligation_id'=>$obligationId,
                    'settled_amount_cents'=>$debt->settled_amount_cents,
                    'unpaid_amount_cents'=>$debt->amount_cents-$debt->refunded_amount_cents-$debt->settled_amount_cents];
                $debt->settled_amount_cents += $data['amount_cents']; $debt->save();
                ++$budget->version; $budget->save(); // Consumed/committed/available amounts do not change when paying a debt.
                $settlementId=(string)Str::uuid(); $this->locks->assertOwned($lockKey,$token,180);
                $op=InstitutionalBudgetOperation::create(['public_id'=>(string)Str::uuid(),'budget_id'=>$budget->public_id,
                    'action'=>'SETTLE_BECA','actor_id'=>$actor,'reason'=>$data['reason'],'idempotency_key'=>$key,'request_hash'=>$hash,
                    'before_data'=>$before,'after_data'=>[...$this->budgets->snapshot($budget),'obligation_id'=>$obligationId,
                        'settlement_id'=>strtolower($settlementId),'settled_delta_cents'=>$data['amount_cents'],
                        'settled_amount_cents'=>$debt->settled_amount_cents,
                        'unpaid_amount_cents'=>$debt->amount_cents-$debt->refunded_amount_cents-$debt->settled_amount_cents,
                        'payment_key'=>$paymentKey,'payment_provider'=>$data['payment_provider'],'payment_reference'=>$data['payment_reference'],'recipient_reference'=>$data['recipient_reference'],'paid_at'=>$data['paid_at']], 'created_at'=>now()]);
                InstitutionalBonusSettlement::create(['public_id'=>$settlementId,'obligation_id'=>$debt->public_id,'budget_id'=>$budget->public_id,
                    'operation_id'=>$op->public_id,'amount_cents'=>$data['amount_cents'],'currency'=>'MXN',
                    'payment_provider'=>$data['payment_provider'],'payment_reference'=>$data['payment_reference'],'payment_key'=>$paymentKey,
                    'recipient_reference'=>$data['recipient_reference'],'paid_at_utc'=>$data['paid_at'],'created_at'=>now()]);
                $this->integrity->assertState($budget,$bonus);
                return ['operation'=>$op,'settlement_id'=>strtolower($settlementId),'obligation_id'=>$obligationId,'replayed'=>false];
            });
        } catch (QueryException $e) {
            if (in_array((int)($e->errorInfo[1] ?? 0),[2601,2627],true)) throw new ConflictHttpException('El pago o la llave ya tienen evidencia registrada.');
            throw $e;
        } finally { $this->locks->release($lockKey,$token); }
    }
    private function authorize(string $actor,array $context): void
    {
        if (!$this->authority->allows($actor,'settlement.record',$context)) throw new AuthorizationException();
    }
}
