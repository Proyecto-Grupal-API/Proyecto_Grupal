<?php

use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Services\LedgerService;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';
afterEach(function () {
    fcCleanup();
    ServiceClient::where('client_id', 'like', 'test-fc-reverse-api-%')->delete();
});
function reversalApiToken($test, array $scopes): string {
    $id = 'test-fc-reverse-api-' . str()->uuid();
    ServiceClient::create(['name' => 'Reversal fixture', 'client_id' => $id,
        'secret_hash' => Hash::make('test-secret'), 'scopes' => $scopes, 'active' => true]);
    return $test->postJson('/api/oauth/token', ['grant_type' => 'client_credentials',
        'client_id' => $id, 'client_secret' => 'test-secret', 'scope' => implode(' ', $scopes)])
        ->assertOk()->json('access_token');
}

test('reversal api requires authentication and a dedicated reverse scope', function () {
    $id = '00000000-0000-0000-0000-000000000000';
    $this->postJson("/api/v1/financial/transactions/{$id}/reverse")->assertUnauthorized();
    $this->getJson("/api/v1/financial/reversals/{$id}")->assertUnauthorized();
    foreach (['financial:read', 'financial:write', 'financial:control', 'financial:refund:execute', 'financial:reverse'] as $scope) {
        $headers = ['Authorization' => 'Bearer ' . reversalApiToken($this, [$scope]), 'Idempotency-Key' => fcKey('reverse-scope')];
        $this->postJson("/api/v1/financial/transactions/{$id}/reverse", ['reason' => 'Motivo'], $headers)
            ->assertStatus($scope === 'financial:reverse' ? 404 : 403);
        $this->getJson("/api/v1/financial/reversals/{$id}", $headers)
            ->assertStatus($scope === 'financial:read' ? 404 : 403);
    }
});

test('reversal api audits the token actor and retries without duplicating money', function () {
    $wallet = fcWallet('reverse-api', 10000);
    $payment = app(LedgerService::class)->debit($wallet, 3000, MovementType::PAGO,
        fcKey('reverse-api-payment'), 'TEST_PAYMENT', fcKey('order'));
    $headers = ['Authorization' => 'Bearer ' . reversalApiToken($this, ['financial:reverse', 'financial:read']),
        'Idempotency-Key' => fcKey('reverse-api')];
    $url = '/api/v1/financial/transactions/' . $payment->public_id . '/reverse';
    $payload = ['reason' => 'Corrección autorizada', 'executed_by' => 'forged-admin'];
    $first = $this->postJson($url, $payload, $headers)->assertCreated();
    $id = $first->json('data.id');
    expect($first->json('data.executed_by'))->toStartWith('service:test-fc-reverse-api-');
    $this->postJson($url, $payload, $headers)->assertOk()->assertJsonPath('data.id', $id);
    $this->getJson('/api/v1/financial/reversals/' . $id, $headers)->assertOk()
        ->assertJsonPath('data.reason', $payload['reason']);
    $this->postJson($url, ['reason' => 'Otro motivo'], $headers)->assertConflict();
    $otherHeaders = $headers;
    $otherHeaders['Authorization'] = 'Bearer ' . reversalApiToken($this, ['financial:reverse']);
    $this->postJson($url, $payload, $otherHeaders)->assertConflict();
    $headers['Idempotency-Key'] = fcKey('reverse-second');
    $this->postJson($url, $payload, $headers)->assertConflict();
    expect($wallet->fresh()->available_balance_cents)->toBe(10000);
});

test('reversal api requires the idempotency header and a meaningful reason', function () {
    $wallet = fcWallet('reverse-api-validation', 10000);
    $payment = app(LedgerService::class)->debit($wallet, 3000, MovementType::PAGO,
        fcKey('reverse-api-validation'), 'TEST_PAYMENT', fcKey('order'));
    $headers = ['Authorization' => 'Bearer ' . reversalApiToken($this, ['financial:reverse'])];
    $url = '/api/v1/financial/transactions/' . $payment->public_id . '/reverse';
    $this->postJson($url, ['reason' => 'Motivo'], $headers)->assertUnprocessable();
    $headers['Idempotency-Key'] = fcKey('reverse-validation');
    $this->postJson($url, ['reason' => '  '], $headers)->assertUnprocessable();
    $this->postJson($url, [], $headers)->assertUnprocessable();
    expect($wallet->fresh()->available_balance_cents)->toBe(7000);
});
