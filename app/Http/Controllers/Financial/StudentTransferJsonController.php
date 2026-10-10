<?php
namespace App\Http\Controllers\Financial;
use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Enums\StudentTransferKind;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\StudentTransfer;
use App\Domains\Financial\Models\StudentTransferConfirmation;
use App\Domains\Financial\Models\StudentTransferPolicyChange;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\StudentTransferConfirmationService;
use App\Domains\Financial\Services\StudentTransferPolicyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;
abstract class StudentTransferJsonController extends Controller
{
    public function __construct(protected readonly StudentTransferConfirmationService $flow,
        protected readonly StudentTransferPolicyService $policies, protected readonly TransferAuthorizationProvider $authorization,
        protected readonly \App\Domains\Financial\Services\TransferAuditContextResolver $audit,
        protected readonly \App\Domains\Financial\Services\StudentTransferQueryService $queries) {}
    abstract protected function actor(Request $r): string;
    public function prepare(Request $r)
    {
        return $this->run(function () use ($r) {
            $actor = $this->actor($r); $wallet = $this->flow->wallet($actor);
            abort_unless($this->authorization->canSend($actor, $wallet), 403);
            $data = $r->validate(['identity_method' => ['required', Rule::in(['USER_ID', 'ENROLLMENT', 'QR'])], 'recipient' => ['required', 'string', 'max:500', 'regex:/\S/u'],
                'amount_cents' => ['required', 'integer', 'min:1', 'max:9007199254740991'], 'kind' => ['required', Rule::enum(StudentTransferKind::class)], 'concept' => ['nullable', 'string', 'max:255']]);
            $key = $this->header($r); $existing = StudentTransferConfirmation::where('request_key', strtolower(trim($key)))->exists();
            $c = $this->flow->prepare($actor, $data['identity_method'], $data['recipient'], (int) $data['amount_cents'], StudentTransferKind::from($data['kind']), $data['concept'] ?? null, $key, $r->ip(), $this->audit->resolve($r, $actor));
            return response()->json(['data' => $this->confirmation($c), 'replayed' => $existing], $existing ? 200 : 201);
        });
    }
    public function pendingConfirmations(Request $r)
    {
        $r->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $rows = StudentTransferConfirmation::where('sender_id', $this->actor($r))->where('status', 'PENDIENTE')->latest('id')->paginate(20);
        return response()->json(['data' => $rows->getCollection()->map(fn ($c) => $this->confirmation($c))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }
    public function showConfirmation(Request $r, string $confirmationId)
    {
        return response()->json(['data' => $this->confirmation($this->flow->owned($this->actor($r), $confirmationId))]);
    }
    public function confirm(Request $r, string $confirmationId)
    {
        return $this->run(function () use ($r, $confirmationId) {
            if (array_diff(array_keys($r->all()), ['confirmed'])) { return response()->json(['message' => 'La confirmación no permite cambiar los datos del envío.'], 422); }
            $r->validate(['confirmed' => ['required', 'accepted']]); $key = $this->header($r);
            $before = $this->flow->owned($this->actor($r), $confirmationId); $replayed = $before->status === 'COMPLETADA';
            $transfer = $this->flow->confirm($this->actor($r), $confirmationId, $key, $r->ip(), $this->audit->resolve($r, $this->actor($r)));
            return response()->json(['data' => $this->transfer($transfer, $this->actor($r)), 'replayed' => $replayed], $replayed ? 200 : 201);
        });
    }
    public function cancel(Request $r, string $confirmationId)
    {
        return $this->run(fn () => response()->json(['data' => $this->confirmation($this->flow->cancel($this->actor($r), $confirmationId, $this->audit->resolve($r, $this->actor($r))))]));
    }
    public function listing(Request $r)
    {
        $filters = $r->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'kind' => ['nullable', Rule::enum(StudentTransferKind::class)], 'direction' => ['nullable', Rule::in(['SALIDA', 'ENTRADA'])]]);
        return response()->json($this->queries->listing($this->actor($r), $filters));
    }
    public function show(Request $r, string $transferId)
    {
        return response()->json(['data' => $this->queries->detail($this->actor($r), $transferId)]);
    }
    public function currentPolicy(Request $r)
    {
        return response()->json(['data' => $this->policies->snapshot($this->policies->current())]);
    }
    public function updatePolicy(Request $r)
    {
        abort_unless($this->authorization->canManagePolicy($this->actor($r)), 403);
        $data = $r->validate(['expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'enabled' => ['sometimes', 'boolean'], 'minimum_cents' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'],
            'maximum_cents' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'], 'daily_cents' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'],
            'monthly_cents' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'], 'confirmation_seconds' => ['sometimes', 'integer', 'min:1', 'max:2147483647']]);
        $changes = array_diff_key($data, array_flip(['expected_version', 'reason']));
        if (isset($changes['enabled'])) { $changes['enabled'] = (bool) $changes['enabled']; }
        foreach (['minimum_cents', 'maximum_cents', 'daily_cents', 'monthly_cents', 'confirmation_seconds'] as $f) { if (isset($changes[$f])) { $changes[$f] = (int) $changes[$f]; } }
        return $this->run(fn () => response()->json(['data' => $this->policies->snapshot($this->policies->update($this->actor($r), $data['expected_version'], $changes, $data['reason'], $this->audit->resolve($r, $this->actor($r))))]));
    }
    public function policyHistory(Request $r)
    {
        abort_unless($this->authorization->canManagePolicy($this->actor($r)), 403);
        $r->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $rows = StudentTransferPolicyChange::where('policy_key', 'STUDENT_MXN')->latest('id')->paginate(20);
        return response()->json(['data' => $rows->getCollection()->map(fn ($a) => ['id' => strtolower($a->public_id), 'version' => $a->version,
            'actor_id' => $a->actor_id, 'reason' => $a->reason, 'before' => $a->before_data, 'after' => $a->after_data,
            'trace' => $a->audit_context ? ['channel' => $a->audit_context['channel'] ?? null, 'correlation_id' => $a->audit_context['correlation_id'] ?? null] : null, 'created_at' => $a->created_at?->toISOString()]),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }
    private function confirmation(StudentTransferConfirmation $c): array
    {
        return ['id' => strtolower($c->public_id), 'recipient' => $c->recipient_snapshot, 'kind' => $c->kind, 'amount_cents' => $c->amount_cents,
            'currency' => 'MXN', 'concept' => $c->concept, 'status' => $c->status === 'PENDIENTE' && now()->gte($c->expires_at) ? 'VENCIDA' : $c->status,
            'retry_key' => $c->execution_key, 'expires_at' => $c->expires_at?->toISOString(), 'policy' => $c->policy_snapshot, 'transfer_id' => $c->student_transfer_id ? strtolower($c->student_transfer_id) : null];
    }
    private function transfer(StudentTransfer $t, string $actor): array
    {
        return $this->queries->serialize($t, $actor);
    }
    private function header(Request $r): string
    {
        $key = $r->header('Idempotency-Key'); if (!is_string($key) || !preg_match('/^[a-zA-Z0-9._:-]{1,255}$/', trim($key))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['Idempotency-Key' => 'El encabezado Idempotency-Key es obligatorio y debe ser válido.']);
        } return $key;
    }
    private function run(callable $callback)
    {
        try { return $callback(); }
        catch (FinancialLimitExceededException $e) { return response()->json(['message' => $e->getMessage()] + $e->toArray(), 409); }
        catch (FinancialDependencyUnavailableException $e) { return response()->json(['message' => $e->getMessage()], 503); }
        catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 409); }
    }
}
