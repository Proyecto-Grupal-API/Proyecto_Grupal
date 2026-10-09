<?php
namespace App\Http\Controllers\Financial;
use App\Domains\Financial\Models\CashApprovalPolicy;
use App\Domains\Financial\Models\CashApprovalPolicyChange;
use App\Domains\Financial\Models\CashOperationConfirmation;
use App\Domains\Financial\Services\CashApprovalPolicyService;
use App\Domains\Financial\Services\CashSupervisorApprovalService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
class CashApprovalController extends CashController {
    public function policies(Request $request, string $associationId): JsonResponse {
        $this->authorizeAssociation($request, $associationId, 'approval_read');
        return $this->paginated($request, CashApprovalPolicy::where('association_id', $associationId)->orderBy('operation')->orderBy('currency')
            ->paginate($this->pageSize($request)), fn ($p) => app(CashApprovalPolicyService::class)->data($p));
    }
    public function configurePolicy(Request $request, string $associationId, CashApprovalPolicyService $service): JsonResponse {
        $actor = $this->authorizeAssociation($request, $associationId, 'approval_manage');
        $v = $request->validate(['operation' => ['required', Rule::in(['TOPUP', 'WITHDRAWAL', 'ADJUSTMENT', 'WITHDRAWAL_RECOVERY'])], 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'enabled' => ['required', 'boolean'], 'threshold_cents' => ['required', 'integer', 'min:1'], 'version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:1000']]); $key = $this->key($request);
        return $this->execute($request, fn () => $service->configure($associationId, $v['operation'], $v['currency'], $v['enabled'],
            $v['threshold_cents'], $v['version'], $actor, $v['reason'], $key));
    }
    public function policyHistory(Request $request, string $associationId): JsonResponse {
        $this->authorizeAssociation($request, $associationId, 'approval_read');
        $ids = CashApprovalPolicy::where('association_id', $associationId)->pluck('id');
        return $this->paginated($request, CashApprovalPolicyChange::whereIn('cash_approval_policy_id', $ids)->orderByDesc('id')
            ->paginate($this->pageSize($request)), fn ($c) => ['actor_id' => $c->actor_id, 'reason' => $c->reason,
                'before' => $c->before_snapshot, 'after' => $c->after_snapshot, 'created_at' => $c->created_at->toISOString()]);
    }
    public function reviewQueue(Request $request, string $associationId): JsonResponse {
        $actor = $this->authorizeAssociation($request, $associationId, 'approval_read');
        $query = CashOperationConfirmation::whereHas('shift.cashRegister', fn ($q) => $q->where('association_id', $associationId))
            ->where('supervisor_required', true)->where('supervisor_status', 'PENDING')->whereIn('status', ['PENDING', 'CONFIRMED'])->where('expires_at', '>', now());
        return $this->paginated($request, $query->orderBy('expires_at')->paginate($this->pageSize($request)), fn ($p) => [
            'id' => strtolower($p->public_id), 'operation' => $p->operation, 'amount_cents' => $p->amount_cents, 'currency' => $p->currency,
            'wallet_id' => strtolower($p->wallet_id), 'operator_id' => $p->operator_id, 'student_id' => $p->student_id,
            'reason' => $p->reason, 'student_status' => $p->status, 'expires_at' => $p->expires_at->toISOString(),
            'policy' => $p->approval_policy_snapshot, 'can_review' => str_starts_with($actor, 'user:')
                && $actor !== $p->operator_id && $actor !== 'user:'.$p->student_id]);
    }
    public function reviewOperation(Request $request, string $associationId, string $confirmationId, string $decision, CashSupervisorApprovalService $service): JsonResponse {
        $actor = $this->authorizeAssociation($request, $associationId, 'approval_review');
        $v = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return $this->execute($request, function () use ($service, $confirmationId, $associationId, $actor, $decision, $v) {
            $p = $service->review($confirmationId, $associationId, $actor, $decision === 'approve', $v['reason']);
            return ['id' => strtolower($p->public_id), 'supervisor_status' => $p->supervisor_status, 'supervisor_id' => $p->supervisor_id];
        });
    }
    public function administrativeQueue(Request $request, string $associationId): JsonResponse {
        $actor = $this->authorizeAssociation($request, $associationId, 'approval_read');
        $query = \App\Domains\Financial\Models\CashAdministrativeRequest::whereHas('shift.cashRegister', fn ($q) => $q->where('association_id', $associationId))
            ->where('supervisor_required', true)->where('status', 'PENDING')->where('expires_at', '>', now());
        return $this->paginated($request, $query->with('shift')->orderBy('expires_at')->paginate($this->pageSize($request)), fn ($p) => [
            'id' => strtolower($p->public_id), 'operation' => $p->operation, 'amount_cents' => $p->amount_cents, 'currency' => $p->currency,
            'operator_id' => $p->operator_id, 'reason' => $p->reason, 'refund_request_id' => $p->refund_request_id ? strtolower($p->refund_request_id) : null,
            'expires_at' => $p->expires_at->toISOString(), 'policy' => $p->approval_policy_snapshot,
            'can_review' => str_starts_with($actor, 'user:') && $actor !== $p->operator_id && $actor !== $p->shift->agent_id && (! $p->student_id || $actor !== 'user:'.$p->student_id)]);
    }
    public function reviewAdministrative(Request $request, string $associationId, string $administrativeId, string $decision,
        \App\Domains\Financial\Services\CashAdministrativeApprovalService $service): JsonResponse {
        $actor = $this->authorizeAssociation($request, $associationId, 'approval_review');
        $v = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return $this->execute($request, function () use ($service, $administrativeId, $associationId, $actor, $decision, $v) {
            $p = $service->review($administrativeId, $associationId, $actor, $decision === 'approve', $v['reason']);
            return ['id' => strtolower($p->public_id), 'status' => $p->status, 'supervisor_id' => $p->supervisor_id];
        });
    }

}
