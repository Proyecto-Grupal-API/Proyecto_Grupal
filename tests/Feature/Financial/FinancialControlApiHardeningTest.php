<?php

/*
| REQ-E2-2.10-01 — contrato API: 409 con código/evaluación/correlación,
| 503 identificable con rol inaccesible, idempotencia y lock de
| conciliación, historial de límites y permisos por scope.
*/

use App\Domains\Financial\Contracts\FinancialRoleProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Models\FinancialLimit;
use App\Domains\Financial\Models\Reconciliation;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Support\FinancialJobLock;
use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

const FC_API_CLIENTS = ['svc_test_fch_read', 'svc_test_fch_write', 'svc_test_fch_control'];

afterEach(function () {
    fcCleanup();
    ServiceClient::whereIn('client_id', FC_API_CLIENTS)->delete();
});

if (!function_exists('fchToken')) {
    function fchToken($test, string $clientId, string $scope): string
    {
        ServiceClient::where('client_id', $clientId)->delete();

        ServiceClient::create([
            'name' => 'Cliente de prueba 2.10 (hardening)',
            'client_id' => $clientId,
            'secret_hash' => Hash::make('test-secret'),
            'scopes' => [$scope],
            'active' => true,
        ]);

        return $test->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => 'test-secret',
            'scope' => $scope,
        ])->assertOk()->json('access_token');
    }

    function fchToday(): string
    {
        return now(config('financial.business_timezone'))->format('Y-m-d');
    }
}

test('a blocked top up answers 409 with stable code, evaluation and the request correlation', function () {
    $wallet = fcWallet('api-h-409');
    fcLimit($wallet, ['max_amount_cents' => 1000]);
    $token = fchToken($this, 'svc_test_fch_write', 'financial:write');

    $response = $this->postJson('/api/v1/financial/topups', [
        'wallet_id' => $wallet->public_id,
        'amount_cents' => 5000,
        'method' => 'EFECTIVO',
    ], [
        'Authorization' => "Bearer {$token}",
        'X-Correlation-Id' => 'test-fc-corr-api',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('code', 'FINANCIAL_LIMIT_EXCEEDED')
        ->assertJsonPath('correlation_id', 'test-fc-corr-api')
        ->assertJsonPath('evaluation.decision', 'BLOQUEADA')
        ->assertJsonPath('evaluation.violations.0.threshold_value', 1000)
        ->assertHeader('X-Correlation-Id', 'test-fc-corr-api');

    $alert = TransactionAlert::where('public_id', $response->json('alert_id'))->first();

    expect($alert->correlation_id)->toBe('test-fc-corr-api')
        ->and(TopUp::where('wallet_id', $wallet->public_id)->count())->toBe(0);
});

test('a role limit with identity unavailable answers 503 with an identifiable code', function () {
    $wallet = fcWallet('api-h-503');
    foreach (['RECARGA', 'RETIRO'] as $operation) {
        fcLimit($wallet, [
            'subject_type' => 'ROLE',
            'subject_id' => 'test-fc-role',
            'operation' => $operation,
            'max_amount_cents' => 100,
        ]);
    }

    $this->app->instance(FinancialRoleProvider::class, new class implements FinancialRoleProvider {
        public function rolesOf(string $ownerType, string $ownerId): array
        {
            throw new FinancialDependencyUnavailableException('Identidad no disponible.');
        }
    });

    $token = fchToken($this, 'svc_test_fch_write', 'financial:write');

    foreach (['/api/v1/financial/topups', '/api/v1/financial/withdrawals'] as $endpoint) {
        $this->postJson($endpoint, [
            'wallet_id' => $wallet->public_id,
            'amount_cents' => 500,
            'method' => 'EFECTIVO',
        ], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(503)
            ->assertJsonPath('code', 'DEPENDENCY_UNAVAILABLE');
    }

    expect(TopUp::where('wallet_id', $wallet->public_id)->count())->toBe(0)
        ->and(\App\Domains\Financial\Models\Withdrawal::where('wallet_id', $wallet->public_id)->count())->toBe(0);
});

test('limit history is readable with the read scope and records actor and reason', function () {
    $wallet = fcWallet('api-h-history');
    $control = fchToken($this, 'svc_test_fch_control', 'financial:control');
    $read = fchToken($this, 'svc_test_fch_read', 'financial:read');

    $id = $this->postJson('/api/v1/financial/limits', [
        'name' => 'Límite API',
        'subject_type' => 'OWNER',
        'subject_id' => $wallet->owner_id,
        'operation' => 'RECARGA',
        'period' => 'OPERACION',
        'metric' => 'MONTO',
        'max_amount_cents' => 10000,
        'action' => 'BLOQUEAR',
        'actor_id' => FC_ACTOR,
        'change_reason' => 'Alta por prueba',
    ], ['Authorization' => "Bearer {$control}"])->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/financial/limits/{$id}", [
        'max_amount_cents' => 5000,
        'actor_id' => 'test-fc-auditor',
        'change_reason' => 'Reducción',
    ], ['Authorization' => "Bearer {$control}"])->assertOk();

    $this->getJson("/api/v1/financial/limits/{$id}/history", ['Authorization' => "Bearer {$read}"])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.change_type', 'CREACION')
        ->assertJsonPath('data.0.reason', 'Alta por prueba')
        ->assertJsonPath('data.1.actor_id', 'service:svc_test_fch_control')
        ->assertJsonPath('data.1.before.max_amount_cents', 10000)
        ->assertJsonPath('data.1.after.max_amount_cents', 5000);
});

test('a read token cannot change alerts or run reconciliations', function () {
    $read = fchToken($this, 'svc_test_fch_read', 'financial:read');
    $headers = ['Authorization' => "Bearer {$read}"];

    $this->postJson('/api/v1/financial/alerts/00000000-0000-0000-0000-000000000000/status', [
        'status' => 'RESUELTA', 'actor_id' => FC_ACTOR, 'note' => 'x',
    ], $headers)->assertForbidden();

    $this->postJson('/api/v1/financial/reconciliations', [
        'business_date' => fchToday(), 'actor_id' => 'test-fc-api',
    ], $headers)->assertForbidden();

    expect(Reconciliation::where('executed_by', 'test-fc-api')->exists())->toBeFalse();
});

test('a reconciliation request is idempotent and a concurrent one answers 409', function () {
    $wallet = fcWallet('api-h-rec');
    $control = fchToken($this, 'svc_test_fch_control', 'financial:control');
    $headers = ['Authorization' => "Bearer {$control}", 'Idempotency-Key' => 'test-fc-api-rec-1'];
    $payload = [
        'business_date' => fchToday(),
        'wallet_ids' => [$wallet->public_id],
        'actor_id' => 'test-fc-api',
    ];

    $first = $this->postJson('/api/v1/financial/reconciliations', $payload, $headers)
        ->assertCreated()
        ->assertJsonPath('data.trigger_source', 'API')
        ->assertJsonPath('data.cash_status', 'NO_INTEGRADA');

    $this->postJson('/api/v1/financial/reconciliations', $payload, $headers)
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));

    $key = 'reconciliation:' . fchToday();
    $token = app(FinancialJobLock::class)->acquire($key, 600);

    $this->postJson('/api/v1/financial/reconciliations', $payload, ['Authorization' => "Bearer {$control}"])
        ->assertStatus(409)
        ->assertJsonPath('code', 'RECONCILIATION_IN_PROGRESS');

    app(FinancialJobLock::class)->release($key, $token);

    expect(Reconciliation::where('executed_by', 'service:svc_test_fch_control')->count())->toBe(1);
});
