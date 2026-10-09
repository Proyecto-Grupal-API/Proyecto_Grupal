<?php
namespace App\Http\Controllers\Financial;
use App\Domains\Financial\Models\CashAdministrativeRequest;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Services\CashAdministrativeApprovalService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
class CashAdministrativeRequestController extends CashController {
    public function storeAdministrative(Request $request, string $associationId, string $shiftId, CashAdministrativeApprovalService $service): JsonResponse {
        $kind = $request->input('operation');
        $actor = $this->authorizeAssociation($request, $associationId, $kind === 'WITHDRAWAL_RECOVERY' ? 'recover' : 'adjust');
        $shift = $this->shift($associationId, $shiftId);
        $v = $request->validate(['operation' => ['required', Rule::in(['ADJUSTMENT', 'WITHDRAWAL_RECOVERY'])], 'reason' => ['required', 'string', 'max:1000'],
            'amount_cents' => $kind === 'ADJUSTMENT' ? ['required', 'integer', 'not_in:0'] : ['prohibited'],
            'refund_request_id' => $kind === 'WITHDRAWAL_RECOVERY' ? ['required', 'uuid'] : ['prohibited']]);
        $withdrawals = \App\Domains\Financial\Models\Withdrawal::whereIn('cash_shift_id', \App\Domains\Financial\Models\CashShift::whereHas('cashRegister',
            fn ($q) => $q->where('association_id', $associationId))->select('public_id'))->select('public_id');
        $refund = $kind === 'WITHDRAWAL_RECOVERY' ? FinancialRefundRequest::where('public_id', $v['refund_request_id'])
            ->whereHas('originalTransaction', fn ($q) => $q->where('reference_type', 'WITHDRAWAL')->whereIn('reference_id', $withdrawals))->firstOrFail() : null;
        $key = $this->key($request);
        return $this->execute($request, fn () => $this->administrativeData($service->request($associationId, $shift->public_id, $kind,
            $refund ? $refund->amount_cents : $v['amount_cents'], $refund?->public_id, $actor, $v['reason'], $key)));
    }
    public function showAdministrative(Request $request, string $associationId, string $shiftId, string $administrativeId): JsonResponse {
        $p = $this->owned($request, $associationId, $shiftId, $administrativeId);
        return $this->ok($request, $this->administrativeData($p));
    }
    public function cancelAdministrative(Request $request, string $associationId, string $shiftId, string $administrativeId, CashAdministrativeApprovalService $service): JsonResponse {
        $p = $this->owned($request, $associationId, $shiftId, $administrativeId);
        return $this->execute($request, fn () => $this->administrativeData($service->cancel($p->public_id, $p->operator_id)));
    }
    private function owned(Request $request, string $association, string $shiftId, string $id): CashAdministrativeRequest {
        $shift = $this->shift($association, $shiftId);
        $actor = $this->authenticatedActor($request);
        $p = CashAdministrativeRequest::where('cash_shift_id', $shift->id)->where('public_id', $id)->where('operator_id', $actor)->firstOrFail();
        $this->authorizeAssociation($request, $association, $p->operation === 'ADJUSTMENT' ? 'adjust' : 'recover'); return $p;
    }
    protected function administrativeData(CashAdministrativeRequest $p): array {
        return ['id' => strtolower($p->public_id), 'kind' => 'ADMINISTRATIVE', 'status' => in_array($p->status, ['READY', 'APPROVED', 'PENDING'], true) && ! $p->expires_at->isFuture() ? 'EXPIRED' : $p->status,
            'operation' => $p->operation, 'amount_cents' => $p->amount_cents, 'currency' => $p->currency, 'reason' => $p->reason,
            'refund_request_id' => $p->refund_request_id ? strtolower($p->refund_request_id) : null,
            'supervisor_required' => $p->supervisor_required, 'approval_policy_version' => $p->approval_policy_snapshot['version'], 'expires_at' => $p->expires_at->toISOString()];
    }
}
