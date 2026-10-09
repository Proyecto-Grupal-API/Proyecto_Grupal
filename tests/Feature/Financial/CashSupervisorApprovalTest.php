<?php
use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Models\CashApprovalPolicy;
use App\Domains\Financial\Models\CashApprovalPolicyChange;
use App\Domains\Financial\Models\CashOperationConfirmation;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Services\CashApprovalPolicyService;
use App\Domains\Financial\Services\CashSupervisorApprovalService;
use App\Domains\Financial\Services\CashOperationConfirmationService;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\CashSettlementService;
use App\Domains\Financial\Services\WalletService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Enums\MovementType;
use App\Models\User;
use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;
require_once __DIR__.'/Support/FinancialControlHelpers.php';

class TestCashApprovalGrants implements CashAuthorizationProvider {
    public array $grants = [];
    public function allows(string $actorId, string $associationId, string $action): bool {
        return in_array($action, $this->grants[$associationId][$actorId] ?? [], true);
    }
}
beforeEach(function () {
    cashApprovalCleanup(); $this->withoutMiddleware(PreventRequestForgery::class); $this->withoutVite();
    $this->cashApprovalGrants = new TestCashApprovalGrants();
    app()->instance(CashAuthorizationProvider::class, $this->cashApprovalGrants);
    foreach (app('router')->getRoutes() as $route) $route->flushController();
});
afterEach(function () { cashApprovalCleanup(); });
function cashApprovalCleanup(): void {
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Solo base financiera de pruebas.');
    $wallets = Wallet::where('owner_id', 'like', 'f22900%')->pluck('public_id');
    CashOperationConfirmation::whereIn('wallet_id', $wallets)->delete();
    $registers = CashRegister::where('association_id', 'like', 'test-fc-cash-approval-%')->pluck('id');
    $shifts = CashShift::whereIn('cash_register_id', $registers)->pluck('id');
    $movements = CashMovement::whereIn('cash_shift_id', $shifts)->pluck('id');
    CashReceipt::whereIn('cash_movement_id', $movements)->delete(); CashMovement::whereIn('id', $movements)->delete();
    CashShift::whereIn('id', $shifts)->delete(); CashRegister::whereIn('id', $registers)->delete();
    fcCleanup();
    $ids = LedgerEntry::whereIn('wallet_id', $wallets)->pluck('transaction_id'); LedgerEntry::whereIn('wallet_id', $wallets)->delete();
    FinancialTransaction::whereIn('public_id', $ids)->delete(); TopUp::whereIn('wallet_id', $wallets)->delete();
    Withdrawal::whereIn('wallet_id', $wallets)->delete(); Wallet::whereIn('public_id', $wallets)->delete();
    ServiceClient::where('client_id', 'like', 'test-fc-cash-approval-%')->delete(); User::where('email', 'like', 'test-fc-cash-approval-%')->delete();
}
function cashApprovalUser(): User {
    return User::factory()->create(['_id' => new \MongoDB\BSON\ObjectId('f22900'.bin2hex(random_bytes(9))),
        'email' => fcKey('cash-approval-user').'@example.test', 'email_verified_at' => now()]);
}
function cashApprovalCase($test): array {
    $operator = cashApprovalUser(); $student = cashApprovalUser(); $supervisor = cashApprovalUser();
    $wallet = app(WalletService::class)->create('USER', (string) $student->getKey(), WalletType::USUARIO);
    app(LedgerService::class)->credit($wallet, 10000, MovementType::RECARGA, fcKey('cash-approval-seed'), 'TEST_SEED', $wallet->public_id);
    $actor = 'user:'.$operator->getKey(); $association = fcKey('cash-approval-assoc');
    $cash = app(CashShiftService::class); $register = $cash->createRegister($association, 'Caja supervisada', 'MXN', $actor);
    $shift = $cash->open($register->public_id, $actor, 10000, fcKey('cash-approval-open'));
    $test->cashApprovalGrants->grants[$association][$actor] = ['read', 'operate', 'approval_read', 'approval_manage', 'approval_review'];
    $test->cashApprovalGrants->grants[$association]['user:'.$supervisor->getKey()] = ['approval_read', 'approval_review'];
    $test->cashApprovalGrants->grants[$association]['user:'.$student->getKey()] = ['approval_review'];
    return [$operator, $student, $supervisor, $wallet->fresh(), $shift, $association];
}
function cashApprovalPolicy(string $association, string $actor, bool $enabled = true, int $threshold = 2000, int $version = 0, string $operation = 'WITHDRAWAL', ?string $key = null): array {
    return app(CashApprovalPolicyService::class)->configure($association, $operation, 'MXN', $enabled, $threshold, $version, $actor, 'Regla de prueba', $key ?? fcKey('cash-approval-policy'));
}
function cashApprovalProof(Wallet $wallet, CashShift $shift, User $student, string $operation = 'WITHDRAWAL', int $amount = 2000): CashOperationConfirmation {
    $p = app(CashOperationConfirmationService::class)->request($shift->public_id, $wallet, $operation, $amount, $shift->agent_id, 'Operación supervisada', fcKey('cash-approval-request'));
    return app(CashOperationConfirmationService::class)->decide($p->public_id, $student, true, '127.0.0.1');
}
function cashApprovalSettle(CashOperationConfirmation $p, CashShift $shift, Wallet $wallet, string $key) {
    return app(CashSettlementService::class)->settleConfirmed($shift->public_id, $wallet, $p->amount_cents, $key, $shift->agent_id, $p->reason, $p->operation === 'TOPUP', $p->public_id);
}

test('cash approval policies retain versions history and immutable idempotent responses', function () {
    [$op, , , , , $assoc] = cashApprovalCase($this); $actor = 'user:'.$op->getKey(); $key = fcKey('cash-approval-policy');
    $first = cashApprovalPolicy($assoc, $actor, true, 2000, 0, 'WITHDRAWAL', $key);
    expect($first['version'])->toBe(1)->and($first['enabled'])->toBeTrue();
    cashApprovalPolicy($assoc, $actor, false, 5000, 1);
    expect(cashApprovalPolicy($assoc, $actor, true, 2000, 0, 'WITHDRAWAL', $key))->toBe($first);
    expect(fn () => cashApprovalPolicy($assoc, $actor, true, 2001, 0, 'WITHDRAWAL', $key))->toThrow(InvalidArgumentException::class);
    expect(fn () => cashApprovalPolicy($assoc, $actor, true, 1, 1))->toThrow(InvalidArgumentException::class);
    expect(CashApprovalPolicyChange::where('actor_id', $actor)->count())->toBe(2);
    $history = CashApprovalPolicyChange::where('actor_id', $actor)->orderBy('id')->get();
    expect($history[0]->before_snapshot)->toBeNull()->and($history[1]->before_snapshot['version'])->toBe(1)
        ->and($history[1]->actor_id)->toBe($actor)->and($history[1]->after_snapshot['enabled'])->toBeFalse();
});

test('cash supervisor threshold is inclusive scoped by operation currency association and fixed at request time', function () {
    [$op, $student, , $wallet, $shift, $assoc] = cashApprovalCase($this); $actor = 'user:'.$op->getKey();
    cashApprovalPolicy($assoc, $actor);
    $below = cashApprovalProof($wallet, $shift, $student, 'WITHDRAWAL', 1999);
    $at = cashApprovalProof($wallet, $shift, $student);
    $topup = cashApprovalProof($wallet, $shift, $student, 'TOPUP');
    expect($below->supervisor_required)->toBeFalse()->and($at->supervisor_required)->toBeTrue()->and($topup->supervisor_required)->toBeFalse();
    expect(app(CashApprovalPolicyService::class)->snapshot($assoc, 'WITHDRAWAL', 'USD')['enabled'])->toBeFalse()
        ->and(app(CashApprovalPolicyService::class)->snapshot('foreign', 'WITHDRAWAL', 'MXN')['enabled'])->toBeFalse();
    cashApprovalPolicy($assoc, $actor, false, 2000, 1);
    expect($at->fresh()->supervisor_required)->toBeTrue()->and($at->fresh()->approval_policy_snapshot['version'])->toBe(1)
        ->and(cashApprovalProof($wallet, $shift, $student)->supervisor_required)->toBeFalse();
});

test('cash second approval requires an independent human and cannot move money without both approvals', function () {
    [$op, $student, $sup, $wallet, $shift, $assoc] = cashApprovalCase($this); cashApprovalPolicy($assoc, $shift->agent_id);
    $p = cashApprovalProof($wallet, $shift, $student); $key = fcKey('cash-approval-settle'); $base = '/finanzas/caja/asociaciones/'.$assoc;
    expect(fn () => cashApprovalSettle($p, $shift, $wallet, $key))->toThrow(InvalidArgumentException::class);
    foreach ([$op, $student] as $forbidden) $this->actingAs($forbidden)->postJson($base.'/approval-requests/'.$p->public_id.'/approve', ['reason' => 'Intento propio'])->assertForbidden();
    $this->cashApprovalGrants->grants[$assoc]['service:external'] = ['approval_review'];
    expect(fn () => app(CashSupervisorApprovalService::class)->review($p->public_id, $assoc, 'service:external', true, 'No persona'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->actingAs($sup)->postJson($base.'/approval-requests/'.$p->public_id.'/approve', ['reason' => 'Revisado', 'supervisor_id' => 'forged'])->assertOk()->assertJsonPath('data.supervisor_id', 'user:'.$sup->getKey());
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0);
    $settled = cashApprovalSettle($p, $shift, $wallet, $key);
    expect($wallet->fresh()->available_balance_cents)->toBe(8000)->and($p->fresh()->status)->toBe('CONSUMED');
    $receipt = CashReceipt::whereHas('movement', fn ($q) => $q->where('cash_shift_id', $shift->id))->firstOrFail();
    expect($receipt->snapshot['supervisor_id'])->toBe('user:'.$sup->getKey())->and($receipt->snapshot['approval_policy']['version'])->toBe(1);
    expect(fcSameId(cashApprovalSettle($p, $shift, $wallet, $key)->public_id, $settled->public_id))->toBeTrue();
    expect($wallet->fresh()->available_balance_cents)->toBe(8000);
});

test('cash supervisor approval cannot replace an absent student confirmation', function () {
    [, $student, $sup, $wallet, $shift, $assoc] = cashApprovalCase($this); cashApprovalPolicy($assoc, $shift->agent_id);
    $p = app(CashOperationConfirmationService::class)->request($shift->public_id, $wallet, 'WITHDRAWAL', 2000, $shift->agent_id, 'Operación supervisada', fcKey('cash-approval-request'));
    app(CashSupervisorApprovalService::class)->review($p->public_id, $assoc, 'user:'.$sup->getKey(), true, 'Revisado');
    expect(fn () => cashApprovalSettle($p, $shift, $wallet, fcKey('cash-approval-settle')))->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and($p->fresh()->status)->toBe('PENDING');
});

test('cash supervisor rejection expiry and permission revocation block settlement without bypass through policy changes', function () {
    [, $student, $sup, $wallet, $shift, $assoc] = cashApprovalCase($this); cashApprovalPolicy($assoc, $shift->agent_id);
    $service = app(CashSupervisorApprovalService::class); $reviewer = 'user:'.$sup->getKey();
    $rejected = cashApprovalProof($wallet, $shift, $student); $service->review($rejected->public_id, $assoc, $reviewer, false, 'No procede');
    expect(fn () => cashApprovalSettle($rejected, $shift, $wallet, fcKey('cash-approval-settle')))->toThrow(InvalidArgumentException::class);
    $expired = cashApprovalProof($wallet, $shift, $student); $expired->update(['expires_at' => now()->subSecond()]);
    expect(fn () => $service->review($expired->public_id, $assoc, $reviewer, true, 'Tarde'))->toThrow(InvalidArgumentException::class);
    $approved = cashApprovalProof($wallet, $shift, $student); $service->review($approved->public_id, $assoc, $reviewer, true, 'Revisado');
    $this->cashApprovalGrants->grants[$assoc][$reviewer] = [];
    cashApprovalPolicy($assoc, $shift->agent_id, false, 1, 1);
    expect(fn () => cashApprovalSettle($approved, $shift, $wallet, fcKey('cash-approval-settle')))->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and($approved->fresh()->status)->toBe('CONFIRMED');
});

test('cash approval persists through settlement rollback and original retries bypass expired or revoked approval without duplicate money', function () {
    [, $student, $sup, $wallet, $shift, $assoc] = cashApprovalCase($this); cashApprovalPolicy($assoc, $shift->agent_id);
    $p = cashApprovalProof($wallet, $shift, $student); $key = fcKey('cash-approval-settle');
    app(CashSupervisorApprovalService::class)->review($p->public_id, $assoc, 'user:'.$sup->getKey(), true, 'Revisado');
    CashReceipt::creating(function () { throw new RuntimeException('Fallo comprobante'); });
    try { expect(fn () => cashApprovalSettle($p, $shift, $wallet, $key))->toThrow(RuntimeException::class); }
    finally { CashReceipt::flushEventListeners(); }
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and($p->fresh()->status)->toBe('CONFIRMED')
        ->and($p->fresh()->supervisor_status)->toBe('APPROVED')->and($p->fresh()->settlement_key)->toBeNull();
    $result = cashApprovalSettle($p, $shift, $wallet, $key);
    $p->update(['expires_at' => now()->subSecond()]); $this->cashApprovalGrants->grants[$assoc]['user:'.$sup->getKey()] = [];
    expect(fcSameId(cashApprovalSettle($p, $shift, $wallet, $key)->public_id, $result->public_id))->toBeTrue();
    expect(fn () => cashApprovalSettle($p, $shift, $wallet, fcKey('cash-approval-other-key')))->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(8000)->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(1);
});

test('cash approval configuration rolls back history failures and supervisor cannot reapprove or change another decision', function () {
    [, $student, $sup, $wallet, $shift, $assoc] = cashApprovalCase($this); $key = fcKey('cash-approval-policy');
    CashApprovalPolicyChange::creating(function () { throw new RuntimeException('Fallo historial'); });
    try { expect(fn () => cashApprovalPolicy($assoc, $shift->agent_id, true, 2000, 0, 'WITHDRAWAL', $key))->toThrow(RuntimeException::class); }
    finally { CashApprovalPolicyChange::flushEventListeners(); }
    expect(CashApprovalPolicy::where('association_id', $assoc)->count())->toBe(0);
    cashApprovalPolicy($assoc, $shift->agent_id, true, 2000, 0, 'WITHDRAWAL', $key);
    $p = cashApprovalProof($wallet, $shift, $student); $service = app(CashSupervisorApprovalService::class); $actor = 'user:'.$sup->getKey();
    $service->review($p->public_id, $assoc, $actor, true, 'Revisado');
    expect($service->review($p->public_id, $assoc, $actor, true, 'Revisado')->supervisor_status)->toBe('APPROVED');
    expect(fn () => $service->review($p->public_id, $assoc, $actor, false, 'Otro motivo'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->review($p->public_id, $assoc, $actor, true, 'Cambio de motivo'))->toThrow(InvalidArgumentException::class);
});

test('cash approval HTTP separates read manage review scopes and association grants with pagination and trusted audit', function () {
    [$op, $student, $sup, $wallet, $shift, $assoc] = cashApprovalCase($this); $web = '/finanzas/caja/asociaciones/'.$assoc;
    $body = ['operation' => 'WITHDRAWAL', 'currency' => 'MXN', 'enabled' => true, 'threshold_cents' => 2000, 'version' => 0, 'reason' => 'Regla HTTP'];
    $this->getJson($web.'/approval-policies')->assertUnauthorized();
    $this->actingAs($sup)->postJson($web.'/approval-policies', $body, ['Idempotency-Key' => fcKey('cash-approval-http')])->assertForbidden();
    $this->actingAs($op)->postJson($web.'/approval-policies', $body)->assertUnprocessable();
    $key = fcKey('cash-approval-http'); $this->postJson($web.'/approval-policies', $body + ['actor_id' => 'forged'], ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.version', 1);
    $p = cashApprovalProof($wallet, $shift, $student);
    $this->actingAs($sup)->getJson($web.'/approval-requests?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 1);
    $this->getJson($web.'/approval-policies/history')->assertOk()->assertJsonPath('data.0.actor_id', $shift->agent_id);
    $other = fcKey('cash-approval-other-assoc'); $this->cashApprovalGrants->grants[$other]['user:'.$sup->getKey()] = ['approval_read', 'approval_review'];
    $this->getJson('/finanzas/caja/asociaciones/'.$other.'/approval-requests')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/finanzas/caja/asociaciones/'.$other.'/approval-requests/'.$p->public_id.'/approve', ['reason' => 'Ajeno'])->assertNotFound();
    $this->get('/finanzas/caja/autorizaciones')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Financial/CashApprovals'));
    // OAuth cannot borrow financial:write or ordinary cash scopes for policy administration.
    foreach (['financial:cash:operate', 'financial:cash:approval:read', 'financial:cash:approval:manage'] as $scope) {
        $id = fcKey('cash-approval-client'); ServiceClient::create(['name' => 'Prueba', 'client_id' => $id, 'secret_hash' => Hash::make('test-secret'), 'scopes' => [$scope], 'active' => true]);
        $token = $this->postJson('/api/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => 'test-secret', 'scope' => $scope])->assertOk()->json('access_token');
        $this->cashApprovalGrants->grants[$assoc]['service:'.$id] = ['approval_read', 'approval_manage'];
        $api = '/api/v1/financial/cash/associations/'.$assoc; $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => fcKey('cash-approval-api')];
        $this->getJson($api.'/approval-policies', $headers)->assertStatus($scope === 'financial:cash:approval:read' ? 200 : 403);
        if ($scope === 'financial:cash:approval:read') {
            $this->cashApprovalGrants->grants[$assoc]['service:'.$id] = [];
            $this->getJson($api.'/approval-policies', $headers)->assertForbidden();
            $this->cashApprovalGrants->grants[$assoc]['service:'.$id] = ['approval_read', 'approval_manage'];
        }
        $this->postJson($api.'/approval-policies', array_merge($body, ['version' => 1]), $headers)->assertStatus($scope === 'financial:cash:approval:manage' ? 200 : 403);
    }
});


test('cash supervised topups credit once and approved proofs cannot be transferred to another operator or amount', function () {
    [, $student, $sup, $wallet, $shift, $assoc] = cashApprovalCase($this);
    cashApprovalPolicy($assoc, $shift->agent_id, true, 2000, 0, 'TOPUP');
    $p = cashApprovalProof($wallet, $shift, $student, 'TOPUP');
    app(CashSupervisorApprovalService::class)->review($p->public_id, $assoc, 'user:'.$sup->getKey(), true, 'Recarga revisada');
    $service = app(CashSettlementService::class); $key = fcKey('cash-approval-topup');
    expect(fn () => $service->settleConfirmed($shift->public_id, $wallet, 2001, $key, $shift->agent_id, $p->reason, true, $p->public_id))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->settleConfirmed($shift->public_id, $wallet, 2000, $key, 'user:other', $p->reason, true, $p->public_id))->toThrow(InvalidArgumentException::class);
    cashApprovalSettle($p, $shift, $wallet, $key); cashApprovalSettle($p, $shift, $wallet, $key);
    expect($wallet->fresh()->available_balance_cents)->toBe(12000)->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(1);
});
