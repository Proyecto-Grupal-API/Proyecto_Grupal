<?php

use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\WalletService;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Enums\TopUpStatus;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Withdrawal;
use App\Http\Middleware\ValidateServiceToken;
use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;

afterEach(function () {
    $transactions = FinancialTransaction::where(
        'idempotency_key',
        'test-financial-api-ledger-credit'
    )->get();

    foreach ($transactions as $transaction) {
        LedgerEntry::where(
            'transaction_id',
            $transaction->public_id
        )->delete();

        $transaction->delete();
    }

       Wallet::where(
        'owner_id',
        'test-financial-api-user'
    )->delete();

    $topUpWallet = Wallet::where(
        'owner_id',
        'test-financial-api-topup-user'
    )->first();

    if ($topUpWallet) {
    $topUpIds = TopUp::where(
        'wallet_id',
        $topUpWallet->public_id
    )->pluck('public_id');

    $topUpTransactionIds = FinancialTransaction::where(
        'reference_type',
        'TOPUP'
    )
        ->whereIn(
            'reference_id',
            $topUpIds
        )
        ->pluck('public_id');

    LedgerEntry::whereIn(
        'transaction_id',
        $topUpTransactionIds
    )->delete();

    FinancialTransaction::whereIn(
        'public_id',
        $topUpTransactionIds
    )->delete();

    TopUp::where(
        'wallet_id',
        $topUpWallet->public_id
    )->delete();

    $topUpWallet->delete();
}
    $withdrawalOwnerIds = [
        'test-financial-api-withdrawal-user',
        'test-financial-api-withdrawal-show-user',
        'test-financial-api-withdrawal-read-user',
        'test-financial-api-withdrawal-write-user',
    ];

    $withdrawalWallets = Wallet::whereIn(
        'owner_id',
        $withdrawalOwnerIds
    )->get();

    foreach ($withdrawalWallets as $wallet) {
        Withdrawal::where(
            'wallet_id',
            $wallet->public_id
        )->delete();

        $wallet->delete();
    }
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

test('returns wallet ledger entries through the financial api', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-user',
        WalletType::USUARIO
    );

    app(LedgerService::class)->credit(
        $wallet,
        50000,
        MovementType::AJUSTE_CREDITO,
        'test-financial-api-ledger-credit'
    );

    $response = $this->getJson(
        '/api/v1/financial/wallets/' .
        $wallet->public_id .
        '/ledger'
    );

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.wallet_id',
            fn ($id) =>
                strtolower($id)
                === strtolower($wallet->public_id)
        )
        ->assertJsonPath(
            'data.items.0.movement_type',
            MovementType::AJUSTE_CREDITO->value
        )
        ->assertJsonPath(
            'data.items.0.amount_cents',
            50000
        )
        ->assertJsonPath(
            'data.items.0.available_balance_after_cents',
            50000
        )
        ->assertJsonPath(
            'data.items.0.held_balance_after_cents',
            0
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );
});
test('creates a pending top up through the financial api', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-topup-user',
        WalletType::USUARIO
    );

    $response = $this->postJson(
        '/api/v1/financial/topups',
        [
            'wallet_id' => $wallet->public_id,
            'amount_cents' => 25000,
            'method' => 'EFECTIVO',
            'agent_id' => 'test-agent-001',
            'external_reference' => 'test-api-topup-001',
        ]
    );

    $response
        ->assertCreated()
        ->assertJsonPath(
            'data.wallet_id',
            fn ($id) =>
                strtolower($id)
                === strtolower($wallet->public_id)
        )
        ->assertJsonPath(
            'data.amount_cents',
            25000
        )
        ->assertJsonPath(
            'data.method',
            'EFECTIVO'
        )
        ->assertJsonPath(
            'data.status',
            TopUpStatus::PENDIENTE->value
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(0);

    expect(
        TopUp::where(
            'external_reference',
            'test-api-topup-001'
        )->exists()
    )->toBeTrue();
});
test('financial read scope cannot create a top up', function () {
    $client = ServiceClient::create([
        'name' => 'Financial API read only client',
        'client_id' => 'svc_financial_read_topup',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['financial:read'],
        'active' => true,
    ]);

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-topup-user',
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

    $response = $this->postJson(
        '/api/v1/financial/topups',
        [
            'wallet_id' => $wallet->public_id,
            'amount_cents' => 25000,
            'method' => 'EFECTIVO',
        ],
        [
            'Authorization' => "Bearer {$token}",
        ]
    );

    $response->assertForbidden();

    expect(
        TopUp::where(
            'wallet_id',
            $wallet->public_id
        )->exists()
    )->toBeFalse();
});
test('financial write scope can create a top up', function () {
    $client = ServiceClient::create([
        'name' => 'Financial API write client',
        'client_id' => 'svc_financial_write_topup',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['financial:write'],
        'active' => true,
    ]);

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-topup-user',
        WalletType::USUARIO
    );

    $tokenResponse = $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'test-secret',
        'scope' => 'financial:write',
    ]);

    $tokenResponse
        ->assertOk()
        ->assertJsonPath('scope', 'financial:write');

    $token = $tokenResponse->json('access_token');

    $response = $this->postJson(
        '/api/v1/financial/topups',
        [
            'wallet_id' => $wallet->public_id,
            'amount_cents' => 25000,
            'method' => 'EFECTIVO',
            'agent_id' => 'test-agent-write',
            'external_reference' => 'test-api-topup-write',
        ],
        [
            'Authorization' => "Bearer {$token}",
        ]
    );

    $response
        ->assertCreated()
        ->assertJsonPath(
            'data.wallet_id',
            fn ($id) =>
                strtolower($id)
                === strtolower($wallet->public_id)
        )
        ->assertJsonPath('data.amount_cents', 25000)
        ->assertJsonPath('data.method', 'EFECTIVO')
        ->assertJsonPath(
            'data.status',
            TopUpStatus::PENDIENTE->value
        )
        ->assertJsonPath('meta.api_version', 'v1');

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(0);

    expect(
        TopUp::where(
            'external_reference',
            'test-api-topup-write'
        )->exists()
    )->toBeTrue();
});

test('completes a top up through the financial api', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-topup-user',
        WalletType::USUARIO
    );

    $topUp = app(
        \App\Domains\Financial\Services\TopUpService::class
    )->create(
        $wallet,
        25000,
        \App\Domains\Financial\Enums\TopUpMethod::EFECTIVO,
        'test-agent-complete',
        'test-api-topup-complete'
    );

    expect($topUp->status)
        ->toBe(TopUpStatus::PENDIENTE)
        ->and($wallet->available_balance_cents)
        ->toBe(0);

    $response = $this->postJson(
        '/api/v1/financial/topups/'
            . $topUp->public_id
            . '/complete',
        [],
        [
            'Idempotency-Key' =>
                'test-financial-api-topup-complete',
        ]
    );

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.status',
            TopUpStatus::COMPLETADA->value
        )
        ->assertJsonPath(
            'data.amount_cents',
            25000
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );

    $wallet->refresh();
    $topUp->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(25000)
        ->and($topUp->status)
        ->toBe(TopUpStatus::COMPLETADA);

    $transaction = FinancialTransaction::where(
        'idempotency_key',
        'test-financial-api-topup-complete'
    )->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->reference_type)
        ->toBe('TOPUP')
        ->and(strtolower($transaction->reference_id))
        ->toBe(strtolower($topUp->public_id));

    $ledgerEntry = LedgerEntry::where(
        'transaction_id',
        $transaction->public_id
    )->first();

    expect($ledgerEntry)->not->toBeNull()
        ->and($ledgerEntry->movement_type)
        ->toBe(MovementType::RECARGA)
        ->and($ledgerEntry->amount_cents)
        ->toBe(25000)
        ->and($ledgerEntry->available_balance_after_cents)
        ->toBe(25000);
});

test('top up completion requires an idempotency key', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-topup-user',
        WalletType::USUARIO
    );

    $topUp = app(
        \App\Domains\Financial\Services\TopUpService::class
    )->create(
        $wallet,
        25000,
        \App\Domains\Financial\Enums\TopUpMethod::EFECTIVO
    );

    $response = $this->postJson(
        '/api/v1/financial/topups/'
            . $topUp->public_id
            . '/complete'
    );

    $response->assertStatus(422);

    $wallet->refresh();
    $topUp->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(0)
        ->and($topUp->status)
        ->toBe(TopUpStatus::PENDIENTE);

    expect(
        FinancialTransaction::where(
            'reference_type',
            'TOPUP'
        )
            ->where(
                'reference_id',
                $topUp->public_id
            )
            ->exists()
    )->toBeFalse();
});
test('retrying top up completion with the same idempotency key does not duplicate money', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-topup-user',
        WalletType::USUARIO
    );

    $topUp = app(
        \App\Domains\Financial\Services\TopUpService::class
    )->create(
        $wallet,
        25000,
        \App\Domains\Financial\Enums\TopUpMethod::EFECTIVO
    );

    $url = '/api/v1/financial/topups/'
        . $topUp->public_id
        . '/complete';

    $headers = [
        'Idempotency-Key' =>
            'test-financial-api-topup-retry',
    ];

    $firstResponse = $this->postJson(
        $url,
        [],
        $headers
    );

    $secondResponse = $this->postJson(
        $url,
        [],
        $headers
    );

    $firstResponse
        ->assertOk()
        ->assertJsonPath(
            'data.status',
            TopUpStatus::COMPLETADA->value
        );

    $secondResponse
        ->assertOk()
        ->assertJsonPath(
            'data.status',
            TopUpStatus::COMPLETADA->value
        );

    $wallet->refresh();
    $topUp->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(25000)
        ->and($topUp->status)
        ->toBe(TopUpStatus::COMPLETADA);

    $transactions = FinancialTransaction::where(
        'idempotency_key',
        'test-financial-api-topup-retry'
    )->get();

    expect($transactions)->toHaveCount(1);

    $transaction = $transactions->first();

    expect(
        LedgerEntry::where(
            'transaction_id',
            $transaction->public_id
        )->count()
    )->toBe(1);
});
test('top up completion rejects a different idempotency key after completion', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-topup-user',
        WalletType::USUARIO
    );

    $topUp = app(
        \App\Domains\Financial\Services\TopUpService::class
    )->create(
        $wallet,
        25000,
        \App\Domains\Financial\Enums\TopUpMethod::EFECTIVO
    );

    $url = '/api/v1/financial/topups/'
        . $topUp->public_id
        . '/complete';

    $this->postJson(
        $url,
        [],
        [
            'Idempotency-Key' => 'test-topup-key-first',
        ]
    )->assertOk();

    $response = $this->postJson(
        $url,
        [],
        [
            'Idempotency-Key' => 'test-topup-key-different',
        ]
    );

    $response->assertStatus(409);

    $wallet->refresh();
    $topUp->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(25000)
        ->and($topUp->status)
        ->toBe(TopUpStatus::COMPLETADA);

    expect(
        FinancialTransaction::where(
            'reference_type',
            'TOPUP'
        )
            ->where(
                'reference_id',
                $topUp->public_id
            )
            ->count()
    )->toBe(1);
});

test('returns a top up through the financial api', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-topup-user',
        WalletType::USUARIO
    );

    $topUp = app(
        \App\Domains\Financial\Services\TopUpService::class
    )->create(
        $wallet,
        25000,
        \App\Domains\Financial\Enums\TopUpMethod::EFECTIVO,
        'test-agent-show',
        'test-api-topup-show'
    );

    $response = $this->getJson(
        '/api/v1/financial/topups/'
            . $topUp->public_id
    );

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.amount_cents',
            25000
        )
        ->assertJsonPath(
            'data.currency',
            'MXN'
        )
        ->assertJsonPath(
            'data.method',
            'EFECTIVO'
        )
        ->assertJsonPath(
            'data.status',
            TopUpStatus::PENDIENTE->value
        )
        ->assertJsonPath(
            'data.agent_id',
            'test-agent-show'
        )
        ->assertJsonPath(
            'data.external_reference',
            'test-api-topup-show'
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );
    expect(
        strtolower($response->json('data.id'))
    )->toBe(
        strtolower($topUp->public_id)
    );

    expect(
        strtolower($response->json('data.wallet_id'))
    )->toBe(
        strtolower($wallet->public_id)
    );

    $wallet->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(0);
});
test('returns not found when the top up does not exist', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $response = $this->getJson(
        '/api/v1/financial/topups/'
            . '00000000-0000-0000-0000-000000000000'
    );

    $response->assertNotFound();
});
test('creates a pending withdrawal through the financial api', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-withdrawal-user',
        WalletType::USUARIO
    );

    $response = $this->postJson(
        '/api/v1/financial/withdrawals',
        [
            'wallet_id' => $wallet->public_id,
            'amount_cents' => 10000,
            'method' => 'EFECTIVO',
            'agent_id' => 'test-withdrawal-agent',
            'external_reference' =>
                'test-withdrawal-reference',
        ]
    );

    $response
        ->assertCreated()
        ->assertJsonPath(
            'data.amount_cents',
            10000
        )
        ->assertJsonPath(
            'data.currency',
            'MXN'
        )
        ->assertJsonPath(
            'data.method',
            'EFECTIVO'
        )
        ->assertJsonPath(
            'data.status',
            'PENDIENTE'
        )
        ->assertJsonPath(
            'data.agent_id',
            'test-withdrawal-agent'
        )
        ->assertJsonPath(
            'data.external_reference',
            'test-withdrawal-reference'
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );

    expect(
        strtolower($response->json('data.wallet_id'))
    )->toBe(
        strtolower($wallet->public_id)
    );

    $wallet->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(0);

    expect($wallet->held_balance_cents)
        ->toBe(0);
});
test('returns a withdrawal through the financial api', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-withdrawal-show-user',
        WalletType::USUARIO
    );

    $withdrawal = app(
        \App\Domains\Financial\Services\WithdrawalService::class
    )->create(
        $wallet,
        15000,
        \App\Domains\Financial\Enums\WithdrawalMethod::EFECTIVO,
        'test-agent-withdrawal-show',
        'test-api-withdrawal-show'
    );

    $response = $this->getJson(
        '/api/v1/financial/withdrawals/'
            . $withdrawal->public_id
    );

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.amount_cents',
            15000
        )
        ->assertJsonPath(
            'data.currency',
            'MXN'
        )
        ->assertJsonPath(
            'data.method',
            'EFECTIVO'
        )
        ->assertJsonPath(
            'data.status',
            'PENDIENTE'
        )
        ->assertJsonPath(
            'data.agent_id',
            'test-agent-withdrawal-show'
        )
        ->assertJsonPath(
            'data.external_reference',
            'test-api-withdrawal-show'
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );

    expect(
        strtolower($response->json('data.id'))
    )->toBe(
        strtolower($withdrawal->public_id)
    );

    expect(
        strtolower($response->json('data.wallet_id'))
    )->toBe(
        strtolower($wallet->public_id)
    );

    $wallet->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(0);

    expect($wallet->held_balance_cents)
        ->toBe(0);
});
test('returns not found when the withdrawal does not exist', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $response = $this->getJson(
        '/api/v1/financial/withdrawals/'
            . '00000000-0000-0000-0000-000000000000'
    );

    $response->assertNotFound();
});
test('financial read scope cannot create a withdrawal', function () {
    $client = ServiceClient::create([
        'name' => 'Financial API read withdrawal client',
        'client_id' => 'svc_financial_read_withdrawal',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['financial:read'],
        'active' => true,
    ]);

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-withdrawal-read-user',
        WalletType::USUARIO
    );

    $tokenResponse = $this->postJson(
        '/api/oauth/token',
        [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret',
            'scope' => 'financial:read',
        ]
    );

    $tokenResponse
        ->assertOk()
        ->assertJsonPath(
            'scope',
            'financial:read'
        );

    $token = $tokenResponse->json(
        'access_token'
    );

    $response = $this->postJson(
        '/api/v1/financial/withdrawals',
        [
            'wallet_id' => $wallet->public_id,
            'amount_cents' => 10000,
            'method' => 'EFECTIVO',
        ],
        [
            'Authorization' => "Bearer {$token}",
        ]
    );

    $response->assertForbidden();

    expect(
        \App\Domains\Financial\Models\Withdrawal::where(
            'wallet_id',
            $wallet->public_id
        )->exists()
    )->toBeFalse();
});
test('financial write scope can create a withdrawal', function () {
    $client = ServiceClient::create([
        'name' => 'Financial API write withdrawal client',
        'client_id' => 'svc_financial_write_withdrawal',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['financial:write'],
        'active' => true,
    ]);

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-withdrawal-write-user',
        WalletType::USUARIO
    );

    $tokenResponse = $this->postJson(
        '/api/oauth/token',
        [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret',
            'scope' => 'financial:write',
        ]
    );

    $tokenResponse
        ->assertOk()
        ->assertJsonPath(
            'scope',
            'financial:write'
        );

    $token = $tokenResponse->json(
        'access_token'
    );

    $response = $this->postJson(
        '/api/v1/financial/withdrawals',
        [
            'wallet_id' => $wallet->public_id,
            'amount_cents' => 10000,
            'method' => 'EFECTIVO',
            'agent_id' => 'test-write-agent',
            'external_reference' =>
                'test-write-withdrawal',
        ],
        [
            'Authorization' => "Bearer {$token}",
        ]
    );

    $response
        ->assertCreated()
        ->assertJsonPath(
            'data.amount_cents',
            10000
        )
        ->assertJsonPath(
            'data.method',
            'EFECTIVO'
        )
        ->assertJsonPath(
            'data.status',
            'PENDIENTE'
        );

    expect(
        \App\Domains\Financial\Models\Withdrawal::where(
            'external_reference',
            'test-write-withdrawal'
        )->exists()
    )->toBeTrue();

    $wallet->refresh();

    expect($wallet->available_balance_cents)
        ->toBe(0);
});
