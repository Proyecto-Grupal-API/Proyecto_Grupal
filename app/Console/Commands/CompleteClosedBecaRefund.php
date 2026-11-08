<?php
namespace App\Console\Commands;

use App\Domains\Financial\Models\{PurchaseRefundRequest, InstitutionalBonusObligation, Bonus};
use App\Domains\Financial\Services\InstitutionalBecaPurchaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/** Trusted server operator entry point, not an OAuth service-to-human delegation mechanism. */
final class CompleteClosedBecaRefund extends Command
{
    protected $signature = 'financial:complete-closed-beca-refund {refundId}
        {--actor= : Identidad auditada con ambas autorizaciones especificas}
        {--key= : Llave de idempotencia de la ejecucion}
        {--confirm : Completar; sin esta opcion solo consultar}';
    protected $description = 'Devuelve al presupuesto una compra de BECA ya cancelada o expirada sin reactivar saldo.';

    public function handle(InstitutionalBecaPurchaseService $service): int
    {
        $id = $this->argument('refundId'); $actor = $this->option('actor'); $key = $this->option('key');
        if (!is_string($id) || !Str::isUuid($id) || ($this->option('confirm')
            && (!is_string($actor) || !preg_match('/\A[0-9a-f]{24}\z/i',$actor)
                || !is_string($key) || !preg_match('/\A[A-Za-z0-9._:-]{1,200}\z/',$key)))) {
            $this->line('{"status":"INVALID_INPUT"}'); return self::INVALID;
        }
        try {
            $r = PurchaseRefundRequest::where('public_id',strtolower($id))->first();
            $debt = $r ? InstitutionalBonusObligation::where('purchase_payment_id',$r->purchase_payment_id)->first() : null;
            $bonus = $debt ? Bonus::where('public_id',$debt->bonus_id)->first() : null;
            if (!$r || !$debt || !$bonus) { $this->line('{"status":"NOT_FOUND"}'); return self::FAILURE; }
            if (!$this->option('confirm')) {
                $this->line(json_encode(['status'=>'PREVIEW','refund_request_id'=>strtolower($r->public_id),
                    'refund_status'=>$r->status->value,'bonus_status'=>$bonus->status->value,
                    'amount_cents'=>(string)$r->total_amount_cents,'currency'=>$r->currency,
                    'destination'=>'BUDGET_AVAILABLE','authorization_checked'=>false,'money_moved'=>false],JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            }
            $tx = $service->completeClosedRefund($r,$key,$actor);
            $this->line(json_encode(['status'=>'COMPLETED','refund_request_id'=>strtolower($r->public_id),
                'transaction_id'=>strtolower($tx->public_id),'destination'=>'BUDGET_AVAILABLE','amount_cents'=>(string)$tx->metadata['released_budget_amount_cents'],
                'wallet_credited'=>false,'bonus_reactivated'=>false],JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (AuthorizationException) { $this->line('{"status":"DENIED"}'); }
        catch (ConflictHttpException) { $this->line('{"status":"REVIEW_REQUIRED"}'); }
        catch (Throwable) { $this->line('{"status":"UNAVAILABLE"}'); }
        return self::FAILURE;
    }
}
