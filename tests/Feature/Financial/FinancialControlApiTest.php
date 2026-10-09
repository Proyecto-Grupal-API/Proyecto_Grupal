<?php

use App\Domains\Financial\Models\FinancialLimit;
use App\Domains\Financial\Models\TransactionAlert;
use App\Http\Middleware\ValidateServiceToken;
use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () {
    fcAuthenticateControls($this);
});

afterEach(function () {
    fcCleanup();

    ServiceClient::whereIn('client_id', [
        'svc_test_fc_read',
        'svc_test_fc_control',
    ])->delete();
});

function fcServiceToken($test, string $clientId, string $scope): string
{
    ServiceClient::create([
        'name' => 'Cliente de prueba 2.10',
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

function fcLimitPayload(string $ownerId, array $overrides = []): array
{
    return array_merge([
        'name' => 'Límite API',
        'subject_type' => 'OWNER',
        'subject_id' => $ownerId,
        'operation' => 'RECARGA',
        'period' => 'OPERACION',
        'metric' => 'MONTO',
        'max_amount_cents' => 10000,
        'action' => 'BLOQUEAR',
        'actor_id' => FC_ACTOR,
    ], $overrides);
}

test('control endpoints require a service token', function () {
    $this->flushHeaders();
    $this->getJson('/api/v1/financial/limits')->assertUnauthorized();
    $this->postJson('/api/v1/financial/limits', [])->assertUnauthorized();
    $this->getJson('/api/v1/financial/alerts')->assertUnauthorized();
    $this->postJson('/api/v1/financial/reconciliations', [])->assertUnauthorized();
});

test('a read token can query but cannot configure limits', function () {
    $wallet = fcWallet('api-scope-read');
    $token = fcServiceToken($this, 'svc_test_fc_read', 'financial:read');

    $this->getJson('/api/v1/financial/limits', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('meta.api_version', 'v1');

    $this->postJson(
        '/api/v1/financial/limits',
        fcLimitPayload($wallet->owner_id),
        ['Authorization' => "Bearer {$token}"]
    )->assertForbidden();

    expect(FinancialLimit::where('created_by', FC_ACTOR)->count())->toBe(0);
});

test('a control token can configure limits', function () {
    $wallet = fcWallet('api-scope-control');
    $token = fcServiceToken($this, 'svc_test_fc_control', 'financial:control');

    $this->postJson(
        '/api/v1/financial/limits',
        fcLimitPayload($wallet->owner_id),
        ['Authorization' => "Bearer {$token}"]
    )
        ->assertCreated()
        ->assertJsonPath('data.subject_id', $wallet->owner_id)
        ->assertJsonPath('data.max_amount_cents', 10000)
        ->assertJsonPath('data.created_by', 'service:svc_test_fc_control');
});

test('validates limit requests', function () {
    $wallet = fcWallet('api-validation');

    $this->postJson('/api/v1/financial/limits', fcLimitPayload($wallet->owner_id, ['actor_id' => 'forged-admin']))
        ->assertCreated()
        ->assertJsonPath('data.created_by', FC_API_ACTOR);

    $this->postJson('/api/v1/financial/limits', fcLimitPayload($wallet->owner_id, ['operation' => 'NO_EXISTE']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('operation');

    $this->postJson('/api/v1/financial/limits', fcLimitPayload($wallet->owner_id, ['max_amount_cents' => 10.5]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_amount_cents');

    $this->postJson('/api/v1/financial/limits', fcLimitPayload('x', ['subject_type' => 'GLOBAL']))
        ->assertStatus(409)
        ->assertJsonPath('message', 'Un límite GLOBAL no debe indicar subject_id.');
});

test('updates a limit and rejects changes to what it measures', function () {
    $wallet = fcWallet('api-update');

    $id = $this->postJson('/api/v1/financial/limits', fcLimitPayload($wallet->owner_id))
        ->assertCreated()
        ->json('data.id');

    $this->patchJson("/api/v1/financial/limits/{$id}", [
        'max_amount_cents' => 20000,
        'active' => false,
        'actor_id' => 'test-fc-editor',
    ])
        ->assertOk()
        ->assertJsonPath('data.max_amount_cents', 20000)
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('data.updated_by', FC_API_ACTOR);

    $this->patchJson("/api/v1/financial/limits/{$id}", [
        'operation' => 'RETIRO',
        'actor_id' => 'test-fc-editor',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('operation');
});

test('evaluates an operation without recording anything', function () {
    $wallet = fcWallet('api-evaluate');
    fcLimit($wallet, ['max_amount_cents' => 10000]);

    $this->postJson('/api/v1/financial/limits/evaluate', [
        'wallet_id' => $wallet->public_id,
        'operation' => 'RECARGA',
        'amount_cents' => 15000,
    ])
        ->assertOk()
        ->assertJsonPath('data.decision', 'BLOQUEADA')
        ->assertJsonPath('data.violations.0.threshold_value', 10000)
        ->assertJsonPath('data.violations.0.observed_value', 15000);

    expect(fcAlertsForWallet($wallet))->toHaveCount(0);
});

test('a blocked top up returns a conflict with the alert, which can be reviewed', function () {
    $wallet = fcWallet('api-blocked');
    fcLimit($wallet, ['max_amount_cents' => 10000]);

    $response = $this->postJson('/api/v1/financial/topups', [
        'wallet_id' => $wallet->public_id,
        'amount_cents' => 50000,
        'method' => 'EFECTIVO',
    ]);

    $response
        ->assertStatus(409)
        ->assertJsonPath('code', 'FINANCIAL_LIMIT_EXCEEDED')
        ->assertJsonPath('evaluation.decision', 'BLOQUEADA')
        ->assertJsonPath('meta.api_version', 'v1');

    $alertId = $response->json('alert_id');

    expect($alertId)->not->toBeNull()
        ->and($wallet->fresh()->available_balance_cents)->toBe(0);

    $this->getJson("/api/v1/financial/alerts/{$alertId}")
        ->assertOk()
        ->assertJsonPath('data.alert_type', 'OPERACION_BLOQUEADA')
        ->assertJsonPath('data.status', 'ABIERTA')
        ->assertJsonCount(1, 'data.history');

    $this->getJson('/api/v1/financial/alerts?status=ABIERTA&wallet_id=' . $wallet->public_id)
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1);

    $this->postJson("/api/v1/financial/alerts/{$alertId}/status", [
        'status' => 'DESCARTADA',
        'actor_id' => 'test-fc-reviewer',
    ])
        ->assertStatus(409);

    $this->postJson("/api/v1/financial/alerts/{$alertId}/status", [
        'status' => 'RESUELTA',
        'actor_id' => 'test-fc-reviewer',
        'note' => 'Recarga autorizada fuera del sistema.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'RESUELTA')
        ->assertJsonCount(2, 'data.history');
});

test('a blocked withdrawal returns a conflict with the alert', function () {
    $wallet = fcWallet('api-blocked-withdrawal', 50000);
    fcLimit($wallet, ['operation' => 'RETIRO', 'max_amount_cents' => 1000]);

    $this->postJson('/api/v1/financial/withdrawals', [
        'wallet_id' => $wallet->public_id,
        'amount_cents' => 5000,
        'method' => 'EFECTIVO',
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'FINANCIAL_LIMIT_EXCEEDED');

    expect(TransactionAlert::where('wallet_id', $wallet->public_id)->count())->toBe(1)
        ->and($wallet->fresh()->available_balance_cents)->toBe(50000);
});

test('runs a reconciliation and exposes its differences', function () {
    $wallet = fcWallet('api-reconcile', 1000);

    \App\Domains\Financial\Models\Wallet::where('public_id', $wallet->public_id)
        ->update(['available_balance_cents' => 1200]);

    $today = now(config('financial.business_timezone'))->format('Y-m-d');

    $response = $this->postJson('/api/v1/financial/reconciliations', [
        'business_date' => $today,
        'wallet_ids' => [$wallet->public_id],
        'actor_id' => 'test-fc-api-reconciler',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', 'CON_DIFERENCIAS')
        ->assertJsonPath('data.scope', 'WALLETS');

    $id = $response->json('data.id');

    $differences = $this->getJson("/api/v1/financial/reconciliations/{$id}/differences?check_code=SALDO_WALLET_VS_LEDGER")
        ->assertOk()
        ->assertJsonPath('data.0.expected_cents', 1000)
        ->assertJsonPath('data.0.actual_cents', 1200)
        ->assertJsonPath('data.0.difference_cents', 200);

    $differenceId = $differences->json('data.0.id');

    $this->postJson("/api/v1/financial/reconciliations/{$id}/differences/{$differenceId}/resolve", [
        'actor_id' => 'test-fc-auditor',
        'note' => 'Documentado.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'RESUELTA');

    $this->postJson('/api/v1/financial/reconciliations', [
        'business_date' => 'ayer',
        'actor_id' => 'test-fc-api-reconciler',
    ])->assertUnprocessable();
});
