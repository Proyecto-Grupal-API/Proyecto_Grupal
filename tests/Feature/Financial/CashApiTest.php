<?php

use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Adapters\PendingCashAuthorizationProvider;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Services\CashShiftService;
use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () { cashApiCleanup(); });
afterEach(function () { cashApiCleanup(); });

function cashApiCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Usar exclusivamente la base financiera de pruebas.');
    $registers = CashRegister::where('association_id', 'like', 'test-fc-cash-api-%')->pluck('id');
    $shifts = CashShift::whereIn('cash_register_id', $registers)->pluck('id');
    CashMovement::whereIn('cash_shift_id', $shifts)->delete();
    CashShift::whereIn('id', $shifts)->delete(); CashRegister::whereIn('id', $registers)->delete();
    ServiceClient::where('client_id', 'like', 'test-fc-cash-api-%')->delete();
    fcCleanup();
}

function cashApiGrant(string $actor, string $association, array $actions): void
{
    app()->instance(CashAuthorizationProvider::class, new class($actor, $association, $actions) implements CashAuthorizationProvider {
        public function __construct(private string $actor, private string $association, private array $actions) {}
        public function allows(string $actorId, string $associationId, string $action): bool
        {
            return $actorId === $this->actor && $associationId === $this->association && in_array($action, $this->actions, true);
        }
    });
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
}

function cashApiRegister(): CashRegister
{
    return app(CashShiftService::class)->createRegister(fcKey('cash-api-association'), 'Caja API', 'MXN', FC_ACTOR);
}

// Called with the test instance because HTTP helpers are not global framework methods.
function cashApiToken($test, array $scopes): array
{
    $id = fcKey('cash-api-client');
    ServiceClient::create(['name' => 'Caja API prueba', 'client_id' => $id, 'secret_hash' => Hash::make('test-secret'),
        'scopes' => $scopes, 'active' => true]);
    $token = $test->postJson('/api/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $id,
        'client_secret' => 'test-secret', 'scope' => implode(' ', $scopes)])->assertOk()->json('access_token');
    return [['Authorization' => 'Bearer '.$token], 'service:'.$id];
}

function cashApiUrl(CashRegister $register): string
{
    return '/api/v1/financial/cash/associations/'.$register->association_id;
}

test('cash api requires token scope and association grant independently', function () {
    $register = cashApiRegister(); $url = cashApiUrl($register).'/registers';
    $this->getJson($url)->assertUnauthorized();
    [$headers, $actor] = cashApiToken($this, ['financial:read', 'financial:write']);
    cashApiGrant($actor, $register->association_id, ['read', 'operate']);
    $this->getJson($url, $headers)->assertForbidden();
    [$headers, $actor] = cashApiToken($this, ['financial:cash:read']);
    app()->instance(CashAuthorizationProvider::class, new PendingCashAuthorizationProvider());
    $this->getJson($url, $headers)->assertForbidden();
    cashApiGrant($actor, $register->association_id, ['read']);
    $this->getJson($url, $headers)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', strtolower($register->public_id));
});

test('cash api isolates associations and paginates without leaking foreign shifts', function () {
    $register = cashApiRegister(); $foreign = cashApiRegister();
    $shift = app(CashShiftService::class)->open($foreign->public_id, FC_ACTOR, 100, fcKey('cash-api-open'));
    [$headers, $actor] = cashApiToken($this, ['financial:cash:read', 'financial:cash:operate']);
    cashApiGrant($actor, $register->association_id, ['read', 'operate']);
    $this->getJson(cashApiUrl($foreign).'/registers', $headers)->assertForbidden();
    $this->getJson(cashApiUrl($register).'/shifts/'.$shift->public_id, $headers)->assertNotFound();
    $this->postJson(cashApiUrl($register).'/registers/'.$foreign->public_id.'/shifts', ['opening_amount_cents' => 0], $headers)->assertNotFound();
    $this->getJson(cashApiUrl($register).'/registers?per_page=1', $headers)->assertOk()->assertJsonPath('meta.pagination.total', 1);
    $this->getJson(cashApiUrl($register).'/registers?per_page=101', $headers)->assertUnprocessable();
});

test('cash api opens settles closes and retries with server derived audit actor', function () {
    $register = cashApiRegister(); $base = cashApiUrl($register);
    [$headers, $actor] = cashApiToken($this, ['financial:cash:read', 'financial:cash:operate', 'financial:cash:close']);
    cashApiGrant($actor, $register->association_id, ['read', 'operate', 'close']);
    $open = $base.'/registers/'.$register->public_id.'/shifts';
    $headers['Idempotency-Key'] = fcKey('cash-api-open');
    $payload = ['opening_amount_cents' => 10000, 'agent_id' => 'forged-admin'];
    $id = $this->postJson($open, $payload, $headers)->assertOk()->assertJsonPath('data.agent_id', $actor)->json('data.id');
    $this->postJson($open, $payload, $headers)->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson($open, ['opening_amount_cents' => 1], $headers)->assertConflict();
    $url = $base.'/shifts/'.$id;
    $wallet = fcWallet('cash-api-wallet');
    foreach (['topups' => 5000, 'withdrawals' => 2000] as $action => $amount) {
        $headers['Idempotency-Key'] = fcKey('cash-api-'.$action);
        $body = ['wallet_id' => $wallet->public_id, 'amount_cents' => $amount, 'reason' => 'Entrega física', 'actor_id' => 'forged'];
        $operation = $this->postJson($url.'/'.$action, $body, $headers)->assertOk()->assertJsonPath('data.actor_id', $actor)->json('data.id');
        $this->postJson($url.'/'.$action, $body, $headers)->assertOk()->assertJsonPath('data.id', $operation);
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(3000);
    $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.expected_amount_cents', 13000);
    $this->getJson($url.'/movements', $headers)->assertOk()->assertJsonCount(2, 'data');
    $headers['Idempotency-Key'] = fcKey('cash-api-close');
    $body = ['counted_amount_cents' => 12900, 'reason' => 'Arqueo final', 'closed_by' => 'forged'];
    foreach ([1, 2] as $attempt) $this->postJson($url.'/close', $body, $headers)->assertOk()
        ->assertJsonPath('data.closed_by', $actor)->assertJsonPath('data.difference_cents', -100)->assertJsonPath('data.status', 'CLOSED');
    $headers['Idempotency-Key'] = fcKey('cash-api-late');
    $this->postJson($url.'/movements', ['type' => 'CASH_IN', 'amount_cents' => 1, 'reason' => 'Tarde'], $headers)->assertConflict();
    expect(CashShift::where('cash_register_id', $register->id)->count())->toBe(1);
});

test('cash api separates adjustment and close permission and refuses fabricated settlement movements', function () {
    $register = cashApiRegister();
    [$headers, $actor] = cashApiToken($this, ['financial:cash:operate', 'financial:cash:adjust', 'financial:cash:close']);
    cashApiGrant($actor, $register->association_id, ['operate']);
    $shift = app(CashShiftService::class)->open($register->public_id, $actor, 1000, fcKey('cash-api-open'));
    $url = cashApiUrl($register).'/shifts/'.$shift->public_id;
    $headers['Idempotency-Key'] = fcKey('cash-api-adjust');
    $this->postJson($url.'/adjustments', ['amount_cents' => -100, 'reason' => 'Diferencia'], $headers)->assertForbidden();
    $this->postJson($url.'/close', ['counted_amount_cents' => 1000, 'reason' => 'Cierre'], $headers)->assertForbidden();
    $this->postJson($url.'/movements', ['type' => 'TOPUP', 'amount_cents' => 1, 'reason' => 'Falso'], $headers)->assertUnprocessable();
    cashApiGrant($actor, $register->association_id, ['adjust']);
    $this->postJson($url.'/adjustments', ['amount_cents' => -100, 'reason' => 'Diferencia'], $headers)->assertOk()->assertJsonPath('data.actor_id', $actor);
    expect(app(CashShiftService::class)->getSummary($shift->public_id)['expected_amount_cents'])->toBe(900);
});

test('cash api requires idempotency and forbids operating another cashier shift', function () {
    $register = cashApiRegister();
    [$headers, $actor] = cashApiToken($this, ['financial:cash:operate']);
    cashApiGrant($actor, $register->association_id, ['operate']);
    $this->postJson(cashApiUrl($register).'/registers/'.$register->public_id.'/shifts', ['opening_amount_cents' => 0], $headers)->assertUnprocessable();
    $shift = app(CashShiftService::class)->open($register->public_id, FC_ACTOR, 100, fcKey('cash-api-open'));
    $headers['Idempotency-Key'] = fcKey('cash-api-forged');
    $this->postJson(cashApiUrl($register).'/shifts/'.$shift->public_id.'/movements',
        ['type' => 'CASH_OUT', 'amount_cents' => 1, 'reason' => 'Salida', 'agent_id' => FC_ACTOR], $headers)->assertForbidden();
    expect(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0);
});

test('cash api enforces each dedicated scope before controller access', function () {
    $register = cashApiRegister(); $base = cashApiUrl($register); $missing = '00000000-0000-0000-0000-000000000000';
    $cases = [
        ['get', '/registers/'.$missing.'/shifts', 'read'],
        ['get', '/shifts/'.$missing, 'read'],
        ['get', '/shifts/'.$missing.'/movements', 'read'],
        ['post', '/registers/'.$missing.'/shifts', 'operate'],
        ['post', '/shifts/'.$missing.'/movements', 'operate'],
        ['post', '/shifts/'.$missing.'/topups', 'operate'],
        ['post', '/shifts/'.$missing.'/withdrawals', 'operate'],
        ['post', '/shifts/'.$missing.'/adjustments', 'adjust'],
        ['post', '/shifts/'.$missing.'/close', 'close'],
    ];
    foreach (['read', 'operate', 'adjust', 'close'] as $scope) {
        [$headers, $actor] = cashApiToken($this, ['financial:cash:'.$scope]);
        cashApiGrant($actor, $register->association_id, ['read', 'operate', 'adjust', 'close']);
        foreach ($cases as [$method, $path, $required]) {
            $response = $method === 'get' ? $this->getJson($base.$path, $headers) : $this->postJson($base.$path, [], $headers);
            if ($scope === $required) $response->assertNotFound(); else $response->assertForbidden();
        }
    }
});

test('cash api rejects unavailable wallet funds atomically and permits the same request after funding', function () {
    $register = cashApiRegister();
    [$headers, $actor] = cashApiToken($this, ['financial:cash:operate']);
    cashApiGrant($actor, $register->association_id, ['operate']);
    $shift = app(CashShiftService::class)->open($register->public_id, $actor, 1000, fcKey('cash-api-open'));
    $wallet = fcWallet('cash-api-empty'); $url = cashApiUrl($register).'/shifts/'.$shift->public_id;
    $withdrawKey = fcKey('cash-api-retry'); $headers['Idempotency-Key'] = $withdrawKey;
    $body = ['wallet_id' => $wallet->public_id, 'amount_cents' => 100, 'reason' => 'Retiro'];
    $this->postJson($url.'/withdrawals', $body, $headers)->assertConflict();
    expect($wallet->fresh()->available_balance_cents)->toBe(0)
        ->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0);
    $headers['Idempotency-Key'] = fcKey('cash-api-fund');
    $this->postJson($url.'/topups', array_merge($body, ['reason' => 'Recarga']), $headers)->assertOk();
    $headers['Idempotency-Key'] = $withdrawKey;
    $id = $this->postJson($url.'/withdrawals', $body, $headers)->assertOk()->json('data.id');
    $this->postJson($url.'/withdrawals', $body, $headers)->assertOk()->assertJsonPath('data.id', $id);
    expect($wallet->fresh()->available_balance_cents)->toBe(0)
        ->and(app(CashShiftService::class)->getSummary($shift->public_id)['expected_amount_cents'])->toBe(1000);
});
