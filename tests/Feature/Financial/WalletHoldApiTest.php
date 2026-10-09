<?php

use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () {
    fcHoldPolicy();
});
afterEach(function () {
    fcCleanup();
    ServiceClient::where('client_id', 'like', 'test-fc-hold-api-%')->delete();
});

function holdApiToken($test, array $scopes): string
{
    $id = 'test-fc-hold-api-' . str()->uuid();
    ServiceClient::create([
        'name' => 'Hold API fixture', 'client_id' => $id,
        'secret_hash' => Hash::make('test-secret'), 'scopes' => $scopes, 'active' => true,
    ]);
    return $test->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials', 'client_id' => $id,
        'client_secret' => 'test-secret', 'scope' => implode(' ', $scopes),
    ])->assertOk()->json('access_token');
}

function holdApiPayload($wallet): array
{
    return ['wallet_id' => $wallet->public_id, 'amount_cents' => 4000,
        'operation_type' => 'TEST_CHECKOUT', 'expires_at' => now()->addMinutes(5)->startOfSecond()->toISOString(),
        'reason' => 'Reserva API', 'reference_type' => 'TEST_ORDER', 'reference_id' => 'test-order-api',
        'actor_id' => 'forged-admin'];
}

test('hold api requires a service token', function () {
    $id = '00000000-0000-0000-0000-000000000000';
    $this->getJson("/api/v1/financial/holds/{$id}")->assertUnauthorized();
    $this->postJson('/api/v1/financial/holds', [])->assertUnauthorized();
    $this->postJson("/api/v1/financial/holds/{$id}/release", [])->assertUnauthorized();
    $this->postJson("/api/v1/financial/holds/{$id}/capture", [])->assertUnauthorized();
});

test('hold api separates read write release and capture permissions', function () {
    $id = '00000000-0000-0000-0000-000000000000';
    foreach (['financial:read', 'financial:write', 'financial:hold:release', 'financial:hold:capture'] as $scope) {
        $token = holdApiToken($this, [$scope]);
        $headers = ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => fcKey('hold-permission')];
        $this->getJson("/api/v1/financial/holds/{$id}", $headers)
            ->assertStatus($scope === 'financial:read' ? 404 : 403);
        foreach (['release', 'capture'] as $action) {
            $this->postJson("/api/v1/financial/holds/{$id}/{$action}", ['reason' => 'Prueba'], $headers)
                ->assertStatus($scope === "financial:hold:{$action}" ? 404 : 403);
        }
        $this->postJson('/api/v1/financial/holds', [
            'wallet_id' => $id, 'amount_cents' => 1, 'operation_type' => 'TEST_CHECKOUT',
            'expires_at' => now()->addMinute()->toISOString(), 'reason' => 'Prueba',
            'reference_type' => 'TEST', 'reference_id' => 'test',
        ], $headers)->assertStatus($scope === 'financial:write' ? 404 : 403);
    }
});

test('hold api creates and captures idempotently with a trusted audit actor', function () {
    $wallet = fcWallet('hold-api-capture', 10000);
    $token = holdApiToken($this, ['financial:read', 'financial:write', 'financial:hold:capture']);
    $headers = ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => fcKey('hold-api-create')];
    $payload = holdApiPayload($wallet);
    $first = $this->postJson('/api/v1/financial/holds', $payload, $headers)->assertCreated();
    $id = $first->json('data.id');
    expect($first->json('data.requested_by'))->toStartWith('service:test-fc-hold-api-');
    $this->postJson('/api/v1/financial/holds', $payload, $headers)
        ->assertOk()->assertJsonPath('data.id', $id);
    $this->getJson("/api/v1/financial/holds/{$id}", $headers)
        ->assertOk()->assertJsonPath('data.status', 'ACTIVA');
    $headers['Idempotency-Key'] = fcKey('hold-api-capture');
    foreach ([1, 2] as $attempt) {
        $this->postJson("/api/v1/financial/holds/{$id}/capture", ['reason' => 'Cobrar'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'CAPTURADA');
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(6000)
        ->and($wallet->fresh()->held_balance_cents)->toBe(0);
    $this->getJson('/api/v1/financial/wallets/' . $wallet->public_id . '/ledger', $headers)
        ->assertOk()->assertJsonPath('data.items.0.amount_cents', -4000)
        ->assertJsonPath('data.items.0.available_delta_cents', 0)
        ->assertJsonPath('data.items.0.held_delta_cents', -4000);
});

test('hold api requires an idempotency header and releases without adding money', function () {
    $wallet = fcWallet('hold-api-release', 10000);
    $token = holdApiToken($this, ['financial:write', 'financial:hold:release']);
    $headers = ['Authorization' => "Bearer {$token}"];
    $payload = holdApiPayload($wallet);
    $this->postJson('/api/v1/financial/holds', $payload, $headers)->assertUnprocessable();
    $headers['Idempotency-Key'] = fcKey('hold-api-release-create');
    $id = $this->postJson('/api/v1/financial/holds', $payload, $headers)->assertCreated()->json('data.id');
    $headers['Idempotency-Key'] = fcKey('hold-api-release-close');
    $this->postJson("/api/v1/financial/holds/{$id}/release", ['reason' => 'Cancelar'], $headers)
        ->assertOk()->assertJsonPath('data.status', 'LIBERADA');
    $this->postJson("/api/v1/financial/holds/{$id}/release", ['reason' => 'Cancelar'], $headers)->assertOk();
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)
        ->and($wallet->fresh()->held_balance_cents)->toBe(0);
});
