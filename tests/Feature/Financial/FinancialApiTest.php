<?php

use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\WalletService;
use App\Http\Middleware\ValidateServiceToken;
use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;

afterEach(function () {
    Wallet::where(
        'owner_id',
        'test-financial-api-user'
    )->delete();
});

test('financial api requires a service access token', function () {
    $response = $this->getJson(
        '/api/v1/financial/wallets/test-wallet'
    );

    $response->assertUnauthorized();
});

test('financial api rejects a token without financial read scope', function () {
    $client = ServiceClient::create([
        'name' => 'Financial API test client',
        'client_id' => 'svc_financial_without_scope',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['students:read'],
        'active' => true,
    ]);

    $tokenResponse = $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'test-secret',
        'scope' => 'students:read',
    ]);

    $tokenResponse->assertOk();

    $token = $tokenResponse->json('access_token');

    $response = $this->getJson(
        '/api/v1/financial/wallets/00000000-0000-0000-0000-000000000000',
        [
            'Authorization' => "Bearer {$token}",
        ]
    );

    $response->assertForbidden();
});

test('returns a wallet through the financial api', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-user',
        WalletType::USUARIO
    );

    $response = $this->getJson(
        '/api/v1/financial/wallets/' . $wallet->public_id
    );

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.id',
            fn ($id) =>
                strtolower($id)
                === strtolower($wallet->public_id)
        )
        ->assertJsonPath(
            'data.owner_type',
            'USER'
        )
        ->assertJsonPath(
            'data.owner_id',
            'test-financial-api-user'
        )
        ->assertJsonPath(
            'data.type',
            WalletType::USUARIO->value
        )
        ->assertJsonPath(
            'data.currency',
            'MXN'
        )
        ->assertJsonPath(
            'data.status',
            'ACTIVA'
        )
        ->assertJsonPath(
            'data.available_balance_cents',
            0
        )
        ->assertJsonPath(
            'data.held_balance_cents',
            0
        );
});

test('returns not found when the wallet does not exist', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $response = $this->getJson(
        '/api/v1/financial/wallets/00000000-0000-0000-0000-000000000000'
    );

    $response->assertNotFound();
});

test('financial api accepts a token with financial read scope', function () {
    $client = ServiceClient::create([
        'name' => 'Financial API authorized client',
        'client_id' => 'svc_financial_read',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['financial:read'],
        'active' => true,
    ]);

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-user',
        WalletType::USUARIO
    );

    $tokenResponse = $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'test-secret',
        'scope' => 'financial:read',
    ]);

    $tokenResponse
        ->assertOk()
        ->assertJsonPath('scope', 'financial:read');

    $token = $tokenResponse->json('access_token');

    $response = $this->getJson(
        '/api/v1/financial/wallets/' . $wallet->public_id,
        [
            'Authorization' => "Bearer {$token}",
        ]
    );

     $response
        ->assertOk()
        ->assertJsonPath(
            'data.id',
            fn ($id) =>
                strtolower($id)
                === strtolower($wallet->public_id)
        )
        ->assertJsonPath(
            'data.owner_id',
            'test-financial-api-user'
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );
});