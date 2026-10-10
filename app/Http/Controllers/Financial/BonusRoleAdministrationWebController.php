<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Enums\BonusRestrictionType;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusAdministrativeOperation;
use App\Domains\Financial\Services\BonusAdministrationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;

class BonusRoleAdministrationWebController extends Controller
{
    public function index(Request $request)
    {
        $access = app(\App\Domains\Financial\Services\BonusRoleAccess::class); $id = (string)$request->user()->getKey();
        $options = $access->options($id);
        $permissions = ['view' => $access->has($id, 'bonus.view'), 'issue' => $options !== [],
            'cancel' => $access->has($id, 'bonus.cancel'), 'view.all' => $access->allows($id, 'bonus.view.all'),
            'cancel.all' => $access->allows($id, 'bonus.cancel.all'), 'manage_roles' => $access->allows($id, 'identity.bonus_roles.manage')];
        return Inertia::render('Financial/BonusAdministration', [
            'permissions' => $permissions, 'types' => array_map(fn ($case) => $case->value, BonusType::cases()),
            'userId' => $id, 'timezone' => config('financial.business_timezone'), 'issuerContexts' => $options, 'roleIntegration' => true,
        ]);
    }
    public function listing(Request $request)
    {
        $this->authorizeAction($request, 'bonus.view');
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'beneficiary_id' => ['nullable', 'string', 'size:24', 'regex:/\A[0-9a-fA-F]{24}\z/']]);
        $query = $this->scoped($request, 'bonus.view.all')->when($data['beneficiary_id'] ?? null,
            fn ($query, $id) => $query->where('beneficiary_id', strtolower($id)));
        $rows = $query->latest('id')->paginate(20);
        return response()->json(['data' => $rows->getCollection()->map(fn ($bonus) => [
            'id' => strtolower($bonus->public_id), 'beneficiary_id' => $bonus->beneficiary_id,
            'issuer_scope_type' => $bonus->issuer_scope_type, 'issuer_scope_id' => $bonus->issuer_scope_id,
            'type' => $bonus->type->value, 'status' => $bonus->status->value, 'currency' => $bonus->currency,
            'remaining_amount_cents' => $bonus->remaining_amount_cents, 'expires_at' => $bonus->expires_at?->toISOString(),
            'can_cancel' => in_array($bonus->status->value, ['ACTIVO', 'PENDIENTE'], true)
                && $bonus->remaining_amount_cents > 0 && $bonus->expires_at?->gt(now())
                && app(\App\Domains\Financial\Services\BonusRoleAccess::class)->canCancel((string)$request->user()->getKey(), $bonus),
        ])->values(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }
    public function beneficiary(Request $request, string $userId)
    {
        $this->authorizeAction($request, 'bonus.issue');
        $account = app(\App\Domains\Financial\Contracts\BonusBeneficiaryProvider::class)->findActive($userId);
        abort_unless($account, 404);
        return response()->json(['data' => $account]);
    }
    public function store(Request $request)
    {
        $this->authorizeAction($request, 'bonus.issue');
        $data = $request->validate([
            'issuer_scope_type' => ['nullable', Rule::in(['business','association','service','council']), 'required_with:issuer_scope_id'],
            'issuer_scope_id' => ['nullable', 'string', 'max:100', 'required_with:issuer_scope_type', 'regex:/\A[^\x00-\x20\x7f]+\z/'],
            'issuer_id' => ['prohibited'], 'actor_id' => ['prohibited'], 'issuer_type' => ['prohibited'],
            'beneficiary_id' => ['required', 'string', 'size:24', 'regex:/\A[0-9a-fA-F]{24}\z/'],
            'type' => ['required', Rule::enum(BonusType::class)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'valid_from' => ['required', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/'],
            'expires_at' => ['required', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/'],
            'combinable' => ['required', 'boolean'], 'allows_partial_use' => ['required', 'boolean'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'restrictions' => ['nullable', 'array', 'max:50'],
            'restrictions.*.type' => ['required', Rule::enum(BonusRestrictionType::class)],
            'restrictions.*.target_id' => ['required', 'string', 'max:255', 'regex:/\S/u'],
        ]);
        return $this->run(fn () => app(BonusAdministrationService::class)->issue(
            (string) $request->user()->getKey(), $data, $this->key($request), $this->audit($request)));
    }
    public function cancel(Request $request, string $bonusId)
    {
        $this->authorizeAction($request, 'bonus.cancel');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000', 'regex:/\S/u']]);
        $bonus = $this->scoped($request, 'bonus.cancel.all')->where('public_id', $bonusId)->firstOrFail();
        return $this->run(fn () => app(BonusAdministrationService::class)->cancel(
            (string) $request->user()->getKey(), $bonus, $data['reason'], $this->key($request), $this->audit($request)));
    }
    public function history(Request $request, string $bonusId)
    {
        $this->authorizeAction($request, 'bonus.view');
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $bonus = $this->scoped($request, 'bonus.view.all')->where('public_id', $bonusId)->firstOrFail();
        $rows = BonusAdministrativeOperation::where('bonus_id', $bonus->public_id)->latest('id')->paginate(20);
        return response()->json(['data' => $rows->getCollection()->map(fn ($row) => [
            'id' => strtolower($row->public_id), 'action' => $row->action, 'actor_id' => $row->actor_id,
            'reason' => $row->reason, 'before' => $row->before_data, 'after' => $row->after_data,
            'created_at' => $row->created_at?->toISOString(),
            'trace' => isset($row->request_data['audit_context']) ? array_intersect_key($row->request_data['audit_context'], array_flip(['channel','correlation_id'])) : null,
        ])->values(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }
    private function scoped(Request $request, string $globalAction)
    {
        return app(\App\Domains\Financial\Services\BonusRoleAccess::class)->query((string)$request->user()->getKey(), str_replace('.all', '', $globalAction));
    }
    private function authorizeAction(Request $request, string $action): void
    {
        $access = app(\App\Domains\Financial\Services\BonusRoleAccess::class); $id = (string)$request->user()->getKey();
        abort_unless($action === 'bonus.issue' ? $access->options($id) !== [] : $access->has($id, $action), 403);
    }
    private function audit(Request $request): array
    {
        return ['channel' => 'WEB', 'ip' => $request->ip(), 'correlation_id' => app(\App\Domains\Financial\Support\FinancialCorrelation::class)->id()];
    }
    private function key(Request $request): string
    {
        $validated = validator(['key' => $request->header('Idempotency-Key')], [
            'key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
        ])->validate();
        return $validated['key'];
    }
    private function run(callable $callback)
    {
        try {
            $result = $callback(); $operation = $result['operation'];
            return response()->json(['data' => ['operation_id' => strtolower($operation->public_id),
                'bonus_id' => strtolower($operation->bonus_id), 'action' => $operation->action,
                'snapshot' => $operation->after_data], 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
