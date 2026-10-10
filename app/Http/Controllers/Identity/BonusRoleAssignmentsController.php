<?php
namespace App\Http\Controllers\Identity;
use App\Domains\Identity\Authorization\FinancialRoleCatalog as Catalog;
use App\Domains\Identity\Contracts\ScopedCapabilityAuthorization;
use App\Domains\Identity\Services\BonusRoleAssignmentAdministration;
use App\Http\Controllers\Controller;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
final class BonusRoleAssignmentsController extends Controller
{
    private function actor(Request $request, bool $write = false): string
    {
        $id = (string)$request->user()->getKey();
        abort_unless(app(ScopedCapabilityAuthorization::class)->authorized($id, 'identity.bonus_roles.manage'), 403);
        if ($write) { abort_unless($request->user()->two_factor_secret && $request->user()->two_factor_confirmed_at, 403, 'Confirma la autenticación de dos factores antes de administrar roles.'); }
        return $id;
    }
    public function index(Request $request)
    {
        $this->actor($request);
        return Inertia::render('Financial/BonusRoles', ['roles' => collect(Catalog::ROLES)->map(fn ($row, $key) => ['key' => $key, 'label' => $row[0], 'scope_type' => $row[1]])->values(),
            'twoFactorReady' => (bool)($request->user()->two_factor_secret && $request->user()->two_factor_confirmed_at)]);
    }
    public function lookup(Request $request)
    {
        $this->actor($request); $d = $request->validate(['email' => ['required','email','max:255']]);
        $u = User::where('email', $d['email'])->whereNull('deleted_at')->firstOrFail();
        return response()->json(['data' => ['id' => (string)$u->getKey(), 'name' => $u->name, 'email' => $u->email]]);
    }
    public function records(Request $request)
    {
        $this->actor($request); $d = $request->validate(['user_id' => ['required','regex:/\A[a-f0-9]{24}\z/i'], 'page' => ['nullable','integer','min:1','max:1000000']]);
        $rows = RoleAssignment::where('user_id', strtolower($d['user_id']))->whereIn('role_key', array_keys(Catalog::ROLES))->latest('assigned_at')->paginate(20);
        return response()->json(['data' => $rows->getCollection()->map(fn ($row) => $this->serialize($row))->values(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]]);
    }
    public function assign(Request $request)
    {
        $actor = $this->actor($request, true);
        $d = $request->validate(['user_id' => ['required','regex:/\A[a-f0-9]{24}\z/i'], 'role_key' => ['required',Rule::in(array_keys(Catalog::ROLES))],
            'scope_type' => ['nullable',Rule::in(Catalog::SCOPES),'required_with:scope_id'], 'scope_id' => ['nullable','string','max:100','required_with:scope_type','regex:/\A[^\x00-\x20\x7f]+\z/'],
            'starts_at' => ['nullable','date'], 'ends_at' => ['nullable','date','after:now', Rule::when($request->filled('starts_at'), 'after:starts_at')], 'reason' => ['required','string','max:1000','regex:/\S/u']]);
        return $this->run(fn () => app(BonusRoleAssignmentAdministration::class)->assign($actor, $d, $this->audit($request)));
    }
    public function revoke(Request $request, string $assignmentId)
    {
        $actor = $this->actor($request, true); $d = $request->validate(['reason' => ['required','string','max:1000','regex:/\S/u']]);
        return $this->run(fn () => app(BonusRoleAssignmentAdministration::class)->revoke($actor, $assignmentId, $d['reason'], $this->audit($request)));
    }
    private function audit(Request $request): array
    { return ['channel' => 'WEB', 'ip' => $request->ip(), 'correlation_id' => app(\App\Domains\Financial\Support\FinancialCorrelation::class)->id()]; }
    private function run(callable $callback)
    {
        try { return response()->json(['data' => $this->serialize($callback())]); }
        catch (\InvalidArgumentException|\Illuminate\Validation\ValidationException $e) { return response()->json(['message' => $e->getMessage()], 409); }
    }
    private function serialize(RoleAssignment $row): array
    {
        return ['id' => (string)$row->getKey(), 'role_key' => $row->role_key, 'scope_type' => $row->scope_type, 'scope_id' => $row->scope_id,
            'status' => $row->status, 'is_current' => $row->is_current, 'generation' => $row->generation,
            'assigned_by' => $row->assigned_by, 'reason' => $row->reason, 'initial_reason' => $row->initial_reason, 'transitions' => $row->transitions ?? [], 'starts_at' => $row->starts_at?->toISOString(),
            'ends_at' => $row->ends_at?->toISOString(), 'assigned_at' => $row->assigned_at?->toISOString()];
    }
}
