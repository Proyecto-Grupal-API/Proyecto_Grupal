<?php

use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\FinancialLimit;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Reconciliation;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\TopUpService;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () {
    cwCleanup();
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->withoutVite();
});
afterEach(function () { cwCleanup(); });

function cwCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') {
        throw new RuntimeException('Los fixtures de controles web requieren la base financiera de pruebas.');
    }
    // Recuperar únicamente límites/corridas creados por estos fixtures con actor web real.
    FinancialLimit::where('name', 'like', 'test-fc-controlui-%')->update(['created_by' => FC_ACTOR]);
    Reconciliation::where('idempotency_key', 'like', 'test-fc-controlui-%')->update(['executed_by' => FC_ACTOR]);
    fcCleanup();
}
function cwUser(): User
{
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();
    return $user;
}
function cwAllow(array $actions, bool $walletOnly = false): void
{
    app()->instance(FinancialWebAuthorizer::class, new class($actions, $walletOnly) implements FinancialWebAuthorizer {
        public function __construct(private readonly array $actions, private readonly bool $walletOnly) {}
        public function allows(string $userId, string $action, ?string $walletId = null): bool
        {
            return in_array($action, $this->actions, true) && (! $this->walletOnly || $walletId !== null);
        }
    });
}
function cwPayload(): array
{
    return ['name' => fcKey('controlui-limit'), 'subject_type' => 'GLOBAL', 'operation' => 'RECARGA',
        'period' => 'OPERACION', 'metric' => 'MONTO', 'max_amount_cents' => 5000,
        'action' => 'BLOQUEAR', 'active' => true, 'currency' => 'MXN', 'change_reason' => 'Alta aprobada'];
}
function cwEndpoints(): array
{
    $id = '00000000-0000-0000-0000-000000000001';
    return [
        ['get', '/limits', 'limits.view'], ['post', '/limits', 'limits.manage'],
        ['patch', '/limits/' . $id, 'limits.manage'], ['get', '/limits/' . $id . '/history', 'limits.view'],
        ['post', '/limits/evaluate', 'limits.evaluate'], ['get', '/alerts', 'alerts.view'],
        ['get', '/alerts/' . $id, 'alerts.view'], ['post', '/alerts/' . $id . '/status', 'alerts.review'],
        ['get', '/reconciliations', 'reconciliation.view'], ['post', '/reconciliations', 'reconciliation.run'],
        ['get', '/reconciliations/' . $id, 'reconciliation.view'],
        ['get', '/reconciliations/' . $id . '/differences', 'reconciliation.view'],
        ['post', '/reconciliations/' . $id . '/differences/' . $id . '/resolve', 'reconciliation.resolve'],
    ];
}
function cwAlert(): TransactionAlert
{
    $wallet = fcWallet('controlui-alert-' . str()->uuid());
    fcLimit($wallet, ['max_amount_cents' => 100]);
    try { app(TopUpService::class)->create($wallet, 500, TopUpMethod::EFECTIVO); }
    catch (FinancialLimitExceededException $e) { return TransactionAlert::where('public_id', $e->alertId)->firstOrFail(); }
    throw new RuntimeException('Se esperaba una alerta de límite.');
}

test('financial control web requires a session and denies all administrative endpoints with pending authorization', function () {
    $this->get('/finanzas/controles')->assertRedirect('/login');
    $this->postJson('/finanzas/controles/limits', [])->assertUnauthorized();
    $user = cwUser();
    User::whereKey($user->getKey())->update(['roles' => [['name' => 'admin', 'scope_type' => null, 'scope_id' => null]]]);
    $this->actingAs($user->fresh())->get('/finanzas/controles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Financial/Controls')->where('permissions', fn ($values) => collect($values)->every(fn ($v) => $v === false))
        ->missing('limits')->missing('alerts')->missing('reconciliations')->has('catalogs.subjects', 4));
    foreach (cwEndpoints() as [$method, $path]) $this->json(strtoupper($method), '/finanzas/controles' . $path, [])->assertForbidden();
});

test('financial control web requires global permissions and separates every action', function () {
    $this->actingAs(cwUser());
    $all = array_values(array_unique(array_column(cwEndpoints(), 2)));
    cwAllow($all, walletOnly: true);
    foreach (cwEndpoints() as [$method, $path]) $this->json(strtoupper($method), '/finanzas/controles' . $path, [])->assertForbidden();
    foreach ($all as $permission) {
        cwAllow([$permission]);
        foreach (cwEndpoints() as [$method, $path, $needed]) {
            $response = $this->json(strtoupper($method), '/finanzas/controles' . $path, []);
            if ($needed !== $permission) $response->assertForbidden();
            else expect(in_array($response->getStatusCode(), [200, 404, 422], true))->toBeTrue();
        }
    }
});

test('web limit changes audit the session actor and reject stale edits and immutable fields', function () {
    $user = cwUser(); $this->actingAs($user); cwAllow(['limits.view', 'limits.manage']);
    $payload = cwPayload(); $payload['actor_id'] = 'forged';
    $first = $this->postJson('/finanzas/controles/limits', $payload)->assertCreated();
    $id = $first->json('data.id'); $revision = $first->json('data.revision');
    $first->assertJsonPath('data.created_by', 'user:' . $user->getKey());
    $this->patchJson('/finanzas/controles/limits/' . $id, ['max_amount_cents' => 3000, 'active' => false,
        'change_reason' => 'Reducción aprobada', 'expected_revision' => $revision, 'actor_id' => 'forged'])->assertOk()
        ->assertJsonPath('data.updated_by', 'user:' . $user->getKey())->assertJsonPath('data.active', false);
    $this->patchJson('/finanzas/controles/limits/' . $id, ['max_amount_cents' => 2000,
        'change_reason' => 'Edición obsoleta', 'expected_revision' => $revision])->assertConflict();
    $fresh = $this->getJson('/finanzas/controles/limits?active=0&per_page=1')->assertOk()->assertJsonPath('meta.pagination.total', 1);
    $this->patchJson('/finanzas/controles/limits/' . $id, ['operation' => 'RETIRO',
        'change_reason' => 'Intento inválido', 'expected_revision' => $fresh->json('data.0.revision')])->assertUnprocessable();
    $this->getJson('/finanzas/controles/limits/' . $id . '/history')->assertOk()->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonPath('data.0.actor_id', 'user:' . $user->getKey())->assertJsonPath('data.0.reason', 'Reducción aprobada');
    $this->postJson('/finanzas/controles/limits', array_replace(cwPayload(), ['change_reason' => '  ']))->assertUnprocessable();
});

test('web limit evaluation is read only and reports unavailable role dependencies', function () {
    $this->actingAs(cwUser()); cwAllow(['limits.evaluate']);
    $wallet = fcWallet('controlui-evaluate', 10000); fcLimit($wallet, ['max_amount_cents' => 100]);
    $entries = LedgerEntry::where('wallet_id', $wallet->public_id)->count();
    $this->postJson('/finanzas/controles/limits/evaluate', ['wallet_id' => $wallet->public_id, 'operation' => 'RECARGA', 'amount_cents' => 500])
        ->assertOk()->assertJsonPath('data.decision', 'BLOQUEADA');
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)
        ->and(LedgerEntry::where('wallet_id', $wallet->public_id)->count())->toBe($entries)
        ->and(TransactionAlert::where('wallet_id', $wallet->public_id)->count())->toBe(0);
    fcLimit($wallet, ['subject_type' => 'ROLE', 'subject_id' => 'financial_admin', 'max_amount_cents' => 100]);
    $this->postJson('/finanzas/controles/limits/evaluate', ['wallet_id' => $wallet->public_id, 'operation' => 'RECARGA', 'amount_cents' => 500])
        ->assertStatus(503)->assertJsonPath('code', 'DEPENDENCY_UNAVAILABLE');
});

test('web alert review keeps evidence and history and cannot reopen a final state', function () {
    $user = cwUser(); $this->actingAs($user); cwAllow(['alerts.view', 'alerts.review']);
    $alert = cwAlert();
    $this->getJson('/finanzas/controles/alerts?wallet_id=' . $alert->wallet_id)->assertOk()->assertJsonPath('meta.pagination.total', 1);
    $this->postJson('/finanzas/controles/alerts/' . $alert->public_id . '/status', ['status' => 'EN_REVISION', 'note' => 'Revisando evidencia', 'actor_id' => 'forged'])
        ->assertOk()->assertJsonPath('data.status_changed_by', 'user:' . $user->getKey());
    $this->postJson('/finanzas/controles/alerts/' . $alert->public_id . '/status', ['status' => 'RESUELTA', 'note' => '  '])->assertUnprocessable();
    $this->postJson('/finanzas/controles/alerts/' . $alert->public_id . '/status', ['status' => 'RESUELTA', 'note' => 'Revisión terminada'])->assertOk();
    $this->postJson('/finanzas/controles/alerts/' . $alert->public_id . '/status', ['status' => 'EN_REVISION', 'note' => 'Reabrir'])->assertConflict();
    $this->getJson('/finanzas/controles/alerts/' . $alert->public_id)->assertOk()->assertJsonPath('data.status', 'RESUELTA')->assertJsonCount(3, 'data.history');
});

test('web reconciliation requires a header and retries the same run without changing balances', function () {
    $user = cwUser(); $this->actingAs($user); cwAllow(['reconciliation.view', 'reconciliation.run']);
    $wallet = fcWallet('controlui-run', 10000);
    $payload = ['business_date' => now(config('financial.business_timezone'))->toDateString(), 'wallet_ids' => [$wallet->public_id], 'actor_id' => 'forged'];
    $this->postJson('/finanzas/controles/reconciliations', $payload)->assertUnprocessable();
    $headers = ['Idempotency-Key' => fcKey('controlui-run')];
    $first = $this->postJson('/finanzas/controles/reconciliations', $payload, $headers)->assertCreated()
        ->assertJsonPath('data.executed_by', 'user:' . $user->getKey())->assertJsonPath('data.status', 'CUADRADA')
        ->assertJsonPath('data.cash_status', 'NO_INTEGRADA')->assertJsonPath('data.trigger_source', 'INTERFAZ');
    $this->postJson('/finanzas/controles/reconciliations', $payload, $headers)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    $this->getJson('/finanzas/controles/reconciliations?business_date=' . $payload['business_date'])->assertOk()->assertJsonPath('meta.pagination.total', 1);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000);
});

test('web reconciliation resolves only observed differences with session audit and without adjusting money', function () {
    $user = cwUser(); $this->actingAs($user); cwAllow(['reconciliation.view', 'reconciliation.run', 'reconciliation.resolve']);
    $wallet = fcWallet('controlui-difference', 10000); Wallet::where('public_id', $wallet->public_id)->update(['available_balance_cents' => 15000]);
    $payload = ['business_date' => now(config('financial.business_timezone'))->toDateString(), 'wallet_ids' => [$wallet->public_id]];
    $run = $this->postJson('/finanzas/controles/reconciliations', $payload, ['Idempotency-Key' => fcKey('controlui-difference')])->assertCreated();
    $id = $run->json('data.id');
    $difference = $this->getJson('/finanzas/controles/reconciliations/' . $id . '/differences')->assertOk()->json('data.0.id');
    expect($difference)->not->toBeNull();
    $this->postJson('/finanzas/controles/reconciliations/00000000-0000-0000-0000-000000000001/differences/' . $difference . '/resolve', ['note' => 'Revisada'])->assertNotFound();
    $this->postJson('/finanzas/controles/reconciliations/' . $id . '/differences/' . $difference . '/resolve', ['note' => 'Revisión documentada', 'actor_id' => 'forged'])
        ->assertOk()->assertJsonPath('data.status', 'RESUELTA')->assertJsonPath('data.resolved_by', 'user:' . $user->getKey());
    $this->postJson('/finanzas/controles/reconciliations/' . $id . '/differences/' . $difference . '/resolve', ['note' => 'Repetir'])->assertConflict();
    expect($wallet->fresh()->available_balance_cents)->toBe(15000);
});
