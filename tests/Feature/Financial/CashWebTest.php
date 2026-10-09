<?php

require_once __DIR__.'/Support/CashConfirmationFixtures.php';

use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Services\CashShiftService;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () { cashConfirmationCleanup();
    cashWebCleanup();
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->withoutVite();
});
afterEach(function () { cashConfirmationCleanup(); cashWebCleanup(); });

function cashWebCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Caja web requiere la base financiera de pruebas.');
    $ids = CashRegister::where('association_id', 'like', 'test-fc-cash-web-%')->pluck('id');
    $shifts = CashShift::whereIn('cash_register_id', $ids)->pluck('id');
    \App\Domains\Financial\Models\CashReceipt::whereIn('cash_movement_id', CashMovement::whereIn('cash_shift_id', $shifts)->select('id'))->delete();
    CashMovement::whereIn('cash_shift_id', $shifts)->delete();
    CashShift::whereIn('id', $shifts)->delete(); CashRegister::whereIn('id', $ids)->delete();
    fcCleanup();
    User::where('email', 'like', 'test-fc-cash-web-%')->delete();
}
function cashWebUser(): User
{
    return User::factory()->create(['email' => fcKey('cash-web-user').'@example.test', 'email_verified_at' => now()]);
}
function cashWebRegister(): CashRegister
{
    return app(CashShiftService::class)->createRegister(fcKey('cash-web-association'), 'Caja web', 'MXN', FC_ACTOR);
}
function cashWebGrant(User $user, string $association, array $actions): void
{
    app()->instance(CashAuthorizationProvider::class, new class('user:'.$user->getKey(), $association, $actions) implements CashAuthorizationProvider {
        public function __construct(private string $actor, private string $association, private array $actions) {}
        public function allows(string $actorId, string $associationId, string $action): bool
        {
            return $actorId === $this->actor && $associationId === $this->association && in_array($action, $this->actions, true);
        }
    });
    foreach (app('router')->getRoutes() as $route) $route->flushController();
}
function cashWebUrl(CashRegister $register): string
{
    return '/finanzas/caja/asociaciones/'.$register->association_id;
}
function cashWebEndpoints(): array
{
    $id = '00000000-0000-0000-0000-000000000000';
    return [['get', '/registers', 'read'], ['get', '/registers/'.$id.'/shifts', 'read'],
        ['get', '/shifts/'.$id, 'read'], ['get', '/shifts/'.$id.'/movements', 'read'],
        ['post', '/registers/'.$id.'/shifts', 'operate'], ['post', '/shifts/'.$id.'/movements', 'operate'],
        ['post', '/shifts/'.$id.'/topups', 'operate'], ['post', '/shifts/'.$id.'/withdrawals', 'operate'],
        ['post', '/shifts/'.$id.'/adjustments', 'adjust'], ['post', '/shifts/'.$id.'/close', 'close']];
}

test('cash web shows a protected shell but pending permissions never expose association data', function () {
    $this->get('/finanzas/caja')->assertRedirect('/login');
    cashConfirmedPost($this, '/finanzas/caja/asociaciones/test/registers/00000000-0000-0000-0000-000000000000/shifts', [])->assertUnauthorized();
    $user = cashWebUser();
    User::whereKey($user->getKey())->update(['roles' => [['name' => 'admin', 'scope_type' => null, 'scope_id' => null]]]);
    $register = cashWebRegister();
    $this->actingAs($user->fresh())->get('/finanzas/caja')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Financial/Cash')->missing('registers')->missing('shifts')->missing('movements'));
    $this->getJson('/finanzas/caja/context?association_id='.$register->association_id)->assertOk()
        ->assertJsonPath('data.permissions', ['read' => false, 'operate' => false, 'adjust' => false, 'close' => false, 'manage' => false, 'recover' => false, 'approval_read' => false, 'approval_manage' => false, 'approval_review' => false]);
    foreach (cashWebEndpoints() as [$method, $path]) $this->json(strtoupper($method), cashWebUrl($register).$path, [])->assertForbidden();
});

test('cash web permissions are scoped to current session actor association and each action', function () {
    $user = cashWebUser(); $register = cashWebRegister(); $foreign = cashWebRegister();
    $this->actingAs($user);
    foreach (['read', 'operate', 'adjust', 'close'] as $allowed) {
        cashWebGrant($user, $register->association_id, [$allowed]);
        $this->getJson('/finanzas/caja/context?association_id='.$register->association_id)->assertOk()->assertJsonPath('data.permissions.'.$allowed, true);
        foreach (cashWebEndpoints() as [$method, $path, $required]) {
            $response = $this->json(strtoupper($method), cashWebUrl($register).$path, []);
            if ($allowed !== $required) $response->assertForbidden();
            elseif ($path === '/registers') $response->assertOk();
            else $response->assertNotFound();
        }
    }
    cashWebGrant($user, $register->association_id, ['read', 'operate']);
    $this->getJson(cashWebUrl($foreign).'/registers')->assertForbidden();
    $this->getJson(cashWebUrl($register).'/registers/'.$foreign->public_id.'/shifts')->assertNotFound();
    $this->actingAs(cashWebUser())->getJson(cashWebUrl($register).'/registers')->assertForbidden();
});

test('cash web settles and closes idempotently with session audit identity instead of supplied actor', function () {
    $user = cashWebUser(); $register = cashWebRegister(); $actor = 'user:'.$user->getKey();
    cashWebGrant($user, $register->association_id, ['read', 'operate', 'close']);
    $this->actingAs($user);
    $base = cashWebUrl($register); $headers = ['Idempotency-Key' => fcKey('cash-web-open')];
    $payload = ['opening_amount_cents' => 1000, 'agent_id' => 'forged'];
    $shift = cashConfirmedPost($this, $base.'/registers/'.$register->public_id.'/shifts', $payload, $headers)->assertOk()
        ->assertJsonPath('data.agent_id', $actor)->assertJsonPath('data.is_operator', true)->json('data.id');
    cashConfirmedPost($this, $base.'/registers/'.$register->public_id.'/shifts', $payload, $headers)->assertOk()->assertJsonPath('data.id', $shift);
    $url = $base.'/shifts/'.$shift; $wallet = fcWallet('cash-web-wallet');
    foreach (['topups' => 500, 'withdrawals' => 200] as $action => $amount) {
        $headers['Idempotency-Key'] = fcKey('cash-web-'.$action);
        $body = ['wallet_id' => $wallet->public_id, 'amount_cents' => $amount, 'reason' => 'Efectivo físico', 'actor_id' => 'forged'];
        $id = cashConfirmedPost($this, $url.'/'.$action, $body, $headers)->assertOk()->assertJsonPath('data.actor_id', $actor)->json('data.id');
        cashConfirmedPost($this, $url.'/'.$action, $body, $headers)->assertOk()->assertJsonPath('data.id', $id);
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(300);
    $this->getJson($url)->assertOk()->assertJsonPath('data.expected_amount_cents', 1300);
    $this->getJson($url.'/movements')->assertOk()->assertJsonCount(2, 'data');
    $headers['Idempotency-Key'] = fcKey('cash-web-close');
    foreach ([1, 2] as $attempt) cashConfirmedPost($this, $url.'/close', ['counted_amount_cents' => 1250, 'reason' => 'Arqueo', 'closed_by' => 'forged'], $headers)
        ->assertOk()->assertJsonPath('data.closed_by', $actor)->assertJsonPath('data.difference_cents', -50);
});

test('cash web requires idempotency and rejects operations by another cashier without changing money', function () {
    $user = cashWebUser(); $register = cashWebRegister();
    cashWebGrant($user, $register->association_id, ['read', 'operate']);
    $this->actingAs($user);
    cashConfirmedPost($this, cashWebUrl($register).'/registers/'.$register->public_id.'/shifts', ['opening_amount_cents' => 0])->assertUnprocessable();
    $shift = app(CashShiftService::class)->open($register->public_id, FC_ACTOR, 1000, fcKey('cash-web-open'));
    $wallet = fcWallet('cash-web-foreign'); $url = cashWebUrl($register).'/shifts/'.$shift->public_id;
    $this->getJson($url)->assertOk()->assertJsonPath('data.is_operator', false);
    cashConfirmedPost($this, $url.'/topups', ['wallet_id' => $wallet->public_id, 'amount_cents' => 500, 'reason' => 'Intento', 'agent_id' => FC_ACTOR],
        ['Idempotency-Key' => fcKey('cash-web-forged')])->assertForbidden();
    expect($wallet->fresh()->available_balance_cents)->toBe(0)->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0);
});
