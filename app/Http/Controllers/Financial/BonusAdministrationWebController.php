<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Enums\BonusRestrictionType;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusAdministrativeOperation;
use App\Domains\Financial\Services\BonusAdministrationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;

class BonusAdministrationWebController extends Controller
{
    public function index(Request $request)
    {
        $permissions = [];
        foreach (['view', 'issue', 'cancel', 'view.all', 'cancel.all'] as $action) {
            $permissions[$action] = $this->allowed($request, 'bonus.' . $action);
        }
        return Inertia::render('Financial/BonusAdministration', [
            'permissions' => $permissions, 'types' => array_map(fn ($case) => $case->value, BonusType::cases()),
            'userId' => (string) $request->user()->getKey(), 'timezone' => config('financial.business_timezone'),
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
            'type' => $bonus->type->value, 'status' => $bonus->status->value, 'currency' => $bonus->currency,
            'remaining_amount_cents' => $bonus->remaining_amount_cents, 'expires_at' => $bonus->expires_at?->toISOString(),
            'can_cancel' => in_array($bonus->status->value, ['ACTIVO', 'PENDIENTE'], true)
                && $bonus->remaining_amount_cents > 0 && $bonus->expires_at?->gt(now())
                && $this->allowed($request, 'bonus.cancel') && ($this->isIssuer($request, $bonus)
                || $this->allowed($request, 'bonus.cancel.all')),
        ])->values(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }
    public function beneficiary(Request $request, string $userId)
    {
        $this->authorizeAction($request, 'bonus.issue');
        $user = User::find(strtolower($userId));
        abort_unless($user && !$user->account_activation_pending, 404);
        return response()->json(['data' => ['id' => (string) $user->getKey(), 'name' => $user->name]]);
    }
    public function store(Request $request)
    {
        $this->authorizeAction($request, 'bonus.issue');
        $data = $request->validate([
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
            (string) $request->user()->getKey(), $data, $this->key($request)));
    }
    public function cancel(Request $request, string $bonusId)
    {
        $this->authorizeAction($request, 'bonus.cancel');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000', 'regex:/\S/u']]);
        $bonus = $this->scoped($request, 'bonus.cancel.all')->where('public_id', $bonusId)->firstOrFail();
        return $this->run(fn () => app(BonusAdministrationService::class)->cancel(
            (string) $request->user()->getKey(), $bonus, $data['reason'], $this->key($request)));
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
        ])->values(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }
    private function scoped(Request $request, string $globalAction)
    {
        return Bonus::query()->when(!$this->allowed($request, $globalAction), fn ($query) => $query
            ->where('issuer_type', 'USER')->where('issuer_id', (string) $request->user()->getKey()));
    }
    private function isIssuer(Request $request, Bonus $bonus): bool
    {
        return $bonus->issuer_type === 'USER' && strtolower($bonus->issuer_id) === strtolower((string) $request->user()->getKey());
    }
    private function allowed(Request $request, string $action): bool
    {
        return app(FinancialWebAuthorizer::class)->allows((string) $request->user()->getKey(), $action);
    }
    private function authorizeAction(Request $request, string $action): void
    {
        abort_unless($this->allowed($request, $action), 403);
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
