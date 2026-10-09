<?php

use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;
use App\Domains\Financial\Models\WalletHoldPolicy;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';
afterEach(function () {
    fcCleanup();
    ServiceClient::where('client_id', 'like', 'test-fc-policy-api-%')->delete();
});

function holdPolicyApiToken($test, array $scopes): string
{
    $id = 'test-fc-policy-api-' . str()->uuid();
    ServiceClient::create(['name' => 'Hold policy API fixture', 'client_id' => $id,
        'secret_hash' => Hash::make('test-secret'), 'scopes' => $scopes, 'active' => true]);
    return $test->postJson('/api/oauth/token', ['grant_type' => 'client_credentials',
        'client_id' => $id, 'client_secret' => 'test-secret', 'scope' => implode(' ', $scopes)])
        ->assertOk()->json('access_token');
}

function holdPolicyPayload(string $operation = 'TEST_CHECKOUT'): array
{
    return ['operation_type' => $operation, 'max_duration_seconds' => 900,
        'change_reason' => 'Plazo autorizado para prueba', 'actor_id' => 'forged-admin'];
}

test('hold policy api requires authentication and a dedicated management scope', function () {
    $id = '00000000-0000-0000-0000-000000000000';
    $this->getJson('/api/v1/financial/hold-policies')->assertUnauthorized();
    $this->postJson('/api/v1/financial/hold-policies', holdPolicyPayload())->assertUnauthorized();
    $this->patchJson("/api/v1/financial/hold-policies/{$id}", [])->assertUnauthorized();
    foreach (['financial:read', 'financial:write', 'financial:control', 'financial:hold-policy:manage'] as $i => $scope) {
        $headers = ['Authorization' => 'Bearer ' . holdPolicyApiToken($this, [$scope])];
        $this->getJson('/api/v1/financial/hold-policies', $headers)->assertStatus($scope === 'financial:read' ? 200 : 403);
        $this->getJson("/api/v1/financial/hold-policies/{$id}", $headers)->assertStatus($scope === 'financial:read' ? 404 : 403);
        $this->getJson("/api/v1/financial/hold-policies/{$id}/history", $headers)->assertStatus($scope === 'financial:read' ? 404 : 403);
        $this->postJson('/api/v1/financial/hold-policies', holdPolicyPayload('TEST_POLICY_SCOPE_' . $i), $headers)
            ->assertStatus($scope === 'financial:hold-policy:manage' ? 201 : 403);
        $this->patchJson("/api/v1/financial/hold-policies/{$id}", ['expected_version' => 1,
            'active' => false, 'change_reason' => 'Prueba permisos'], $headers)
            ->assertStatus($scope === 'financial:hold-policy:manage' ? 404 : 403);
    }
});

test('hold policy api creates updates and exposes trusted audit history', function () {
    $headers = ['Authorization' => 'Bearer ' . holdPolicyApiToken($this, ['financial:read', 'financial:hold-policy:manage'])];
    $created = $this->postJson('/api/v1/financial/hold-policies', holdPolicyPayload(), $headers)->assertCreated()
        ->assertJsonPath('data.version', 1)->assertJsonPath('data.max_duration_seconds', 900);
    $id = $created->json('data.id');
    expect($created->json('data.created_by'))->toStartWith('service:test-fc-policy-api-');
    $this->patchJson("/api/v1/financial/hold-policies/{$id}", ['expected_version' => 1,
        'active' => false, 'max_duration_seconds' => 600, 'change_reason' => 'Reducir y deshabilitar', 'actor_id' => 'forged-admin'], $headers)
        ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.active', false);
    $this->getJson("/api/v1/financial/hold-policies/{$id}/history", $headers)->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('data.0.before', null)
        ->assertJsonPath('data.1.before.max_duration_seconds', 900)
        ->assertJsonPath('data.1.after.max_duration_seconds', 600)
        ->assertJsonPath('data.1.actor_id', $created->json('data.created_by'));
    $this->getJson('/api/v1/financial/hold-policies?active=0&operation_type=TEST_CHECKOUT', $headers)->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.id', $id);
    $this->getJson("/api/v1/financial/hold-policies/{$id}", $headers)->assertOk()->assertJsonPath('data.id', $id);
});

test('hold policy api rejects duplicates stale edits invalid durations and missing audit reasons', function () {
    $headers = ['Authorization' => 'Bearer ' . holdPolicyApiToken($this, ['financial:hold-policy:manage'])];
    foreach ([0, -1, 1.5, 2147483648] as $seconds) {
        $payload = holdPolicyPayload(); $payload['max_duration_seconds'] = $seconds;
        $this->postJson('/api/v1/financial/hold-policies', $payload, $headers)->assertUnprocessable();
    }
    $payload = holdPolicyPayload(); $payload['change_reason'] = ' ';
    $this->postJson('/api/v1/financial/hold-policies', $payload, $headers)->assertUnprocessable();
    $created = $this->postJson('/api/v1/financial/hold-policies', holdPolicyPayload(), $headers)->assertCreated();
    $id = $created->json('data.id');
    $this->postJson('/api/v1/financial/hold-policies', holdPolicyPayload(), $headers)->assertConflict();
    $this->patchJson("/api/v1/financial/hold-policies/{$id}", ['active' => false, 'change_reason' => 'Motivo'], $headers)->assertUnprocessable();
    $this->patchJson("/api/v1/financial/hold-policies/{$id}", ['expected_version' => 1,
        'operation_type' => 'TEST_CHANGED', 'change_reason' => 'Motivo'], $headers)->assertUnprocessable();
    $this->patchJson("/api/v1/financial/hold-policies/{$id}", ['expected_version' => 1,
        'active' => false, 'change_reason' => 'Motivo'], $headers)->assertOk();
    $this->patchJson("/api/v1/financial/hold-policies/{$id}", ['expected_version' => 1,
        'max_duration_seconds' => 600, 'change_reason' => 'Edición obsoleta'], $headers)->assertConflict();
    config(['financial.holds.absolute_max_seconds' => 100]);
    $this->postJson('/api/v1/financial/hold-policies', holdPolicyPayload('TEST_TOO_LONG'), $headers)->assertConflict();
});

test('hold api uses database policy snapshots and rejects newly disabled operations', function () {
    $headers = ['Authorization' => 'Bearer ' . holdPolicyApiToken($this, [
        'financial:read', 'financial:write', 'financial:hold-policy:manage', 'financial:hold:release'])];
    $created = $this->postJson('/api/v1/financial/hold-policies', holdPolicyPayload(), $headers)->assertCreated();
    $policyId = $created->json('data.id');
    $wallet = fcWallet('policy-api-hold', 10000);
    $headers['Idempotency-Key'] = fcKey('policy-api-hold');
    $payload = ['wallet_id' => $wallet->public_id, 'amount_cents' => 1000, 'operation_type' => 'TEST_CHECKOUT',
        'expires_at' => now()->addMinutes(5)->startOfSecond()->toISOString(), 'reason' => 'Compra de prueba',
        'reference_type' => 'TEST_ORDER', 'reference_id' => 'test-policy-api-order'];
    $hold = $this->postJson('/api/v1/financial/holds', $payload, $headers)->assertCreated()
        ->assertJsonPath('data.policy_id', $policyId)->assertJsonPath('data.policy_version', 1)
        ->assertJsonPath('data.max_duration_seconds', 900);
    $this->patchJson("/api/v1/financial/hold-policies/{$policyId}", ['expected_version' => 1,
        'active' => false, 'max_duration_seconds' => 60, 'change_reason' => 'Deshabilitar operación'], $headers)->assertOk();
    $this->postJson('/api/v1/financial/holds', $payload, $headers)->assertOk()
        ->assertJsonPath('data.id', $hold->json('data.id'))->assertJsonPath('data.policy_version', 1);
    $headers['Idempotency-Key'] = fcKey('policy-api-blocked');
    $this->postJson('/api/v1/financial/holds', $payload, $headers)->assertConflict();
    $this->postJson('/api/v1/financial/holds/' . $hold->json('data.id') . '/release', ['reason' => 'Cancelar'], $headers)
        ->assertOk()->assertJsonPath('data.status', 'LIBERADA');
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and($wallet->fresh()->held_balance_cents)->toBe(0);
});
