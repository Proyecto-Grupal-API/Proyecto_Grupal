<?php

require_once __DIR__ . '/Support/CashCompletionFixtures.php';

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
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Http\Middleware\ValidateServiceToken;
use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusLedgerEntry;
use App\Domains\Financial\Services\BonusService;
use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Models\FinancialWithdrawalRecovery;
use App\Domains\Financial\Services\WithdrawalService;
use App\Domains\Financial\Enums\WithdrawalMethod;

beforeEach(function () {
    $authorization = $this->createMock(
        BonusAuthorizationProvider::class
    );

    $authorization
        ->method('canIssueBonus')
        ->willReturn(true);

    $this->app->instance(
        BonusAuthorizationProvider::class,
        $authorization
    );
});

afterEach(function () {
    $wallets = Wallet::whereIn('owner_id', [
        'test-financial-api-user',
        'test-financial-api-topup-user',
        'test-financial-api-withdrawal-user',
        'test-financial-api-withdrawal-show-user',
        'test-financial-api-withdrawal-read-user',
        'test-financial-api-withdrawal-write-user',
    ])->get();

    $walletIds = $wallets->pluck('public_id');

    if ($walletIds->isNotEmpty()) {
        $topUpIds = TopUp::whereIn(
            'wallet_id',
            $walletIds
        )->pluck('public_id');

        $transactionIds = LedgerEntry::whereIn(
            'wallet_id',
            $walletIds
        )->pluck('transaction_id');

        $topUpTransactionIds = FinancialTransaction::where(
            'reference_type',
            'TOPUP'
        )
            ->whereIn('reference_id', $topUpIds)
            ->pluck('public_id');

        $allTransactionIds = $transactionIds
            ->merge($topUpTransactionIds)
            ->unique();

        LedgerEntry::whereIn(
            'transaction_id',
            $allTransactionIds
        )->delete();

        FinancialTransaction::whereIn(
            'public_id',
            $allTransactionIds
        )->delete();

        TopUp::whereIn('wallet_id', $walletIds)->delete();

        Withdrawal::whereIn('wallet_id', $walletIds)->delete();

        Wallet::whereIn('public_id', $walletIds)->delete();
    }

    $bonus = Bonus::where(
        'external_reference',
        'TEST-FINANCIAL-API-BONUS'
    )->first();

    if ($bonus) {
        BonusLedgerEntry::where(
            'bonus_id',
            $bonus->public_id
        )->delete();

        $bonus->restrictions()->delete();
        $bonus->delete();
    }
});

afterEach(function () {
    $wallets = Wallet::whereIn('owner_id', [
        'test-financial-api-refund-user',
        'test-financial-api-refund-receiver',
    ])->get();

    $walletIds = $wallets->pluck('public_id');

    if ($walletIds->isNotEmpty()) {
        $transactionIds = LedgerEntry::whereIn(
            'wallet_id',
            $walletIds
        )->pluck('transaction_id');

        $refunds = FinancialRefundRequest::whereIn(
            'wallet_id',
            $walletIds
        )->get();

        $refundTransactionIds = $refunds
            ->pluck('request_transaction_id')
            ->merge($refunds->pluck('financial_transaction_id'))
            ->filter();

        $allTransactionIds = $transactionIds
            ->merge($refundTransactionIds)
            ->unique();
             
        FinancialWithdrawalRecovery::whereIn(
            'refund_request_id',
            $refunds->pluck('public_id')
        )->delete();     
             
        FinancialRefundRequest::whereIn(
            'wallet_id',
            $walletIds
        )->delete();

        LedgerEntry::whereIn(
            'transaction_id',
            $allTransactionIds
        )->delete();

        FinancialTransaction::whereIn(
            'original_transaction_id',
            $allTransactionIds
        )->delete();

        FinancialTransaction::whereIn(
            'public_id',
            $allTransactionIds
        )->delete();
    
        Withdrawal::whereIn('wallet_id', $walletIds)->delete();
        Wallet::whereIn('public_id', $walletIds)->delete();
    }

    ServiceClient::where(
        'client_id',
        'svc_financial_refund_test'
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

test('generic top up api refuses cash completion without changing money', function () {
    $this->withoutMiddleware(ValidateServiceToken::class);
    $wallet = app(WalletService::class)->create('USER', 'test-financial-api-topup-user', WalletType::USUARIO);
    $topUp = app(\App\Domains\Financial\Services\TopUpService::class)->create($wallet, 25000, \App\Domains\Financial\Enums\TopUpMethod::EFECTIVO);
    foreach (['test-financial-api-topup-complete'] as $key) {
        $this->postJson('/api/v1/financial/topups/'.$topUp->public_id.'/complete', [], ['Idempotency-Key' => $key])
            ->assertStatus(409)->assertJsonPath('meta.api_version', 'v1');
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(0)
        ->and($topUp->fresh()->status)->toBe(TopUpStatus::PENDIENTE)
        ->and(FinancialTransaction::where('reference_type', 'TOPUP')->where('reference_id', $topUp->public_id)->count())->toBe(0);
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
test('generic cash completion remains blocked on repeated requests', function () {
    $this->withoutMiddleware(ValidateServiceToken::class);
    $wallet = app(WalletService::class)->create('USER', 'test-financial-api-topup-user', WalletType::USUARIO);
    $topUp = app(\App\Domains\Financial\Services\TopUpService::class)->create($wallet, 25000, \App\Domains\Financial\Enums\TopUpMethod::EFECTIVO);
    foreach (['test-financial-api-topup-retry', 'test-financial-api-topup-retry'] as $key) {
        $this->postJson('/api/v1/financial/topups/'.$topUp->public_id.'/complete', [], ['Idempotency-Key' => $key])
            ->assertStatus(409)->assertJsonPath('meta.api_version', 'v1');
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(0)
        ->and($topUp->fresh()->status)->toBe(TopUpStatus::PENDIENTE)
        ->and(FinancialTransaction::where('reference_type', 'TOPUP')->where('reference_id', $topUp->public_id)->count())->toBe(0);
});
test('generic cash completion rejects different keys without creating movements', function () {
    $this->withoutMiddleware(ValidateServiceToken::class);
    $wallet = app(WalletService::class)->create('USER', 'test-financial-api-topup-user', WalletType::USUARIO);
    $topUp = app(\App\Domains\Financial\Services\TopUpService::class)->create($wallet, 25000, \App\Domains\Financial\Enums\TopUpMethod::EFECTIVO);
    foreach (['test-topup-key-first', 'test-topup-key-different'] as $key) {
        $this->postJson('/api/v1/financial/topups/'.$topUp->public_id.'/complete', [], ['Idempotency-Key' => $key])
            ->assertStatus(409)->assertJsonPath('meta.api_version', 'v1');
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(0)
        ->and($topUp->fresh()->status)->toBe(TopUpStatus::PENDIENTE)
        ->and(FinancialTransaction::where('reference_type', 'TOPUP')->where('reference_id', $topUp->public_id)->count())->toBe(0);
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
test('returns a bonus through the financial api', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'test-financial-api-bonus-user',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'TEST-FINANCIAL-API-BONUS'
    );

    $response = $this->getJson(
        '/api/v1/financial/bonuses/' .
        $bonus->public_id
    );

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.id',
            fn ($id) =>
                strtolower($id)
                === strtolower($bonus->public_id)
        )
        ->assertJsonPath(
            'data.beneficiary_type',
            'STUDENT'
        )
        ->assertJsonPath(
            'data.beneficiary_id',
            'test-financial-api-bonus-user'
        )
        ->assertJsonPath(
            'data.type',
            BonusType::BENEFICIO->value
        )
        ->assertJsonPath(
            'data.original_amount_cents',
            50000
        )
        ->assertJsonPath(
            'data.remaining_amount_cents',
            50000
        )
        ->assertJsonPath(
            'data.currency',
            'MXN'
        )
        ->assertJsonPath(
            'data.status',
            'ACTIVO'
        )
        ->assertJsonPath(
            'data.combinable',
            true
        )
        ->assertJsonPath(
            'data.allows_partial_use',
            true
        )
        ->assertJsonPath(
            'data.external_reference',
            'TEST-FINANCIAL-API-BONUS'
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );
});
test('returns not found when the bonus does not exist', function () {
    $this->withoutMiddleware(
        ValidateServiceToken::class
    );

    $response = $this->getJson(
        '/api/v1/financial/bonuses/' .
        '00000000-0000-0000-0000-000000000000'
    );

    $response->assertNotFound();
});
test('financial read scope can access a bonus', function () {
    $client = ServiceClient::create([
        'name' => 'Financial API bonus read client',
        'client_id' => 'svc_financial_bonus_read',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['financial:read'],
        'active' => true,
    ]);

    $bonus = app(BonusService::class)->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: 'test-financial-api-bonus-user',
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 50000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'TEST-FINANCIAL-API-BONUS'
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

    $response = $this->getJson(
        '/api/v1/financial/bonuses/' .
        $bonus->public_id,
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
                === strtolower($bonus->public_id)
        )
        ->assertJsonPath(
            'data.beneficiary_id',
            'test-financial-api-bonus-user'
        )
        ->assertJsonPath(
            'meta.api_version',
            'v1'
        );
});
test('financial api rejects bonus access without financial read scope', function () {
    $client = ServiceClient::create([
        'name' => 'Financial API bonus without read scope',
        'client_id' => 'svc_financial_bonus_without_read',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['students:read'],
        'active' => true,
    ]);

    $tokenResponse = $this->postJson(
        '/api/oauth/token',
        [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret',
            'scope' => 'students:read',
        ]
    );

    $tokenResponse->assertOk();

    $token = $tokenResponse->json(
        'access_token'
    );

    $response = $this->getJson(
        '/api/v1/financial/bonuses/' .
        '00000000-0000-0000-0000-000000000000',
        [
            'Authorization' => "Bearer {$token}",
        ]
    );

    $response->assertForbidden();
});
afterEach(function () {
    $wallet = Wallet::where(
        'owner_id',
        'test-financial-api-refund-user'
    )->first();

    if ($wallet) {
        $transactionIds = LedgerEntry::where(
            'wallet_id',
            $wallet->public_id
        )->pluck('transaction_id');

        FinancialRefundRequest::where(
            'wallet_id',
            $wallet->public_id
        )->delete();

        LedgerEntry::whereIn(
            'transaction_id',
            $transactionIds
        )->delete();

        // Eliminar solicitudes antes de sus operaciones originales.
        FinancialTransaction::whereIn(
            'original_transaction_id',
            $transactionIds
        )->delete();

        FinancialTransaction::whereIn(
            'public_id',
            $transactionIds
        )->delete();

        $wallet->delete();
    }

    ServiceClient::where(
        'client_id',
        'svc_financial_refund_test'
    )->delete();
});

test('refund api creates and retries a request without moving money', function () {
    $client = ServiceClient::create([
        'name' => 'Financial refund API test client',
        'client_id' => 'svc_financial_refund_test',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['financial:write', 'financial:read'],
        'active' => true,
    ]);

    $tokenResponse = $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'test-secret',
        'scope' => 'financial:write',
    ]);

    $tokenResponse->assertOk();
    $writeToken = $tokenResponse->json('access_token');

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-refund-user',
        WalletType::USUARIO
    );

    $ledger = app(LedgerService::class);

    $ledger->credit(
        wallet: $wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'api-refund-credit-' . str()->uuid()
    );

    $payment = $ledger->debit(
        wallet: $wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'api-refund-payment-' . str()->uuid()
    );

    $entriesBefore = LedgerEntry::where(
        'wallet_id',
        $wallet->public_id
    )->count();

    $key = 'api-refund-request-' . str()->uuid();

    $headers = [
        'Authorization' => "Bearer {$writeToken}",
        'Idempotency-Key' => $key,
    ];

    $payload = [
        'original_transaction_id' => $payment->public_id,
        'amount_cents' => 10000,
        'reason' => 'Solicitud de prueba por API',

        // El solicitante debe salir del token, no de este campo.
        'requested_by' => 'usuario-falso',
    ];

    $first = $this->postJson(
        '/api/v1/financial/refunds',
        $payload,
        $headers
    );

    $first
        ->assertCreated()
        ->assertJsonPath('data.amount_cents', 10000)
        ->assertJsonPath('data.status', 'PENDIENTE')
        ->assertJsonPath(
            'data.requested_by',
            'service:' . $client->client_id
        )
        ->assertJsonPath('meta.api_version', 'v1');

    $refundId = $first->json('data.id');

    $retry = $this->postJson(
        '/api/v1/financial/refunds',
        $payload,
        $headers
    );

    $retry
        ->assertOk()
        ->assertJsonPath(
            'data.id',
            fn ($id) => strtolower($id) === strtolower($refundId)
        );

    // La misma clave con otro monto debe rechazarse.
    $differentPayload = $payload;
    $differentPayload['amount_cents'] = 20000;

    $this->postJson(
        '/api/v1/financial/refunds',
        $differentPayload,
        $headers
    )->assertStatus(409);

    // Sin la cabecera de idempotencia, la solicitud no es válida.
    $this->postJson(
        '/api/v1/financial/refunds',
        $payload,
        ['Authorization' => "Bearer {$writeToken}"]
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('idempotency_key');

    // Obtener un token de lectura para consultar la solicitud.
    $readTokenResponse = $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'test-secret',
        'scope' => 'financial:read',
    ]);

    $readTokenResponse->assertOk();
    $readToken = $readTokenResponse->json('access_token');

    $this->getJson(
        '/api/v1/financial/refunds/' . $refundId,
        ['Authorization' => "Bearer {$readToken}"]
    )
        ->assertOk()
        ->assertJsonPath('data.status', 'PENDIENTE')
        ->assertJsonPath('data.amount_cents', 10000)
        ->assertJsonPath(
            'data.original_transaction_id',
            fn ($id) =>
                strtolower($id) === strtolower($payment->public_id)
        );

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(70000);

    expect(
        LedgerEntry::where(
            'wallet_id',
            $wallet->public_id
        )->count()
    )->toBe($entriesBefore);

    expect(
        FinancialRefundRequest::where(
            'original_transaction_id',
            $payment->public_id
        )->count()
    )->toBe(1);

    expect(
        FinancialTransaction::where(
            'idempotency_key',
            $key
        )->count()
    )->toBe(1);
});
test('refund api requires a service token', function () {
    $this->postJson(
        '/api/v1/financial/refunds',
        [
            'original_transaction_id' =>
                '00000000-0000-0000-0000-000000000000',
            'amount_cents' => 10000,
        ],
        ['Idempotency-Key' => 'test-refund-without-token']
    )->assertUnauthorized();

    $this->getJson(
        '/api/v1/financial/refunds/' .
        '00000000-0000-0000-0000-000000000000'
    )->assertUnauthorized();
});

test('refund api enforces read and write scopes', function () {
    // La limpieza que agregamos elimina este cliente al terminar.
    $client = ServiceClient::create([
        'name' => 'Financial refund permissions test',
        'client_id' => 'svc_financial_refund_test',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => ['financial:read', 'financial:write'],
        'active' => true,
    ]);

    $readResponse = $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'test-secret',
        'scope' => 'financial:read',
    ]);

    $readResponse->assertOk();
    $readToken = $readResponse->json('access_token');

    $key = 'api-refund-forbidden-' . str()->uuid();

    // Un token de lectura no puede crear solicitudes.
    $this->postJson(
        '/api/v1/financial/refunds',
        [
            'original_transaction_id' =>
                '00000000-0000-0000-0000-000000000000',
            'amount_cents' => 10000,
        ],
        [
            'Authorization' => "Bearer {$readToken}",
            'Idempotency-Key' => $key,
        ]
    )->assertForbidden();

    expect(
        FinancialTransaction::where(
            'idempotency_key',
            $key
        )->exists()
    )->toBeFalse();

    $writeResponse = $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'test-secret',
        'scope' => 'financial:write',
    ]);

    $writeResponse->assertOk();
    $writeToken = $writeResponse->json('access_token');

    // El permiso de escritura no incluye automáticamente lectura.
    $this->getJson(
        '/api/v1/financial/refunds/' .
        '00000000-0000-0000-0000-000000000000',
        ['Authorization' => "Bearer {$writeToken}"]
    )->assertForbidden();

    // Con lectura sí llega al controlador, que responde 404.
    $this->getJson(
        '/api/v1/financial/refunds/' .
        '00000000-0000-0000-0000-000000000000',
        ['Authorization' => "Bearer {$readToken}"]
    )->assertNotFound();
});
test('refund api separates administrative permissions', function () {
    $client = ServiceClient::create([
        'name' => 'Refund administrative permissions test',
        'client_id' => 'svc_financial_refund_test',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => [
            'financial:write',
            'financial:refund:review',
            'financial:refund:execute',
        ],
        'active' => true,
    ]);

    $baseUrl = '/api/v1/financial/refunds/' .
        '00000000-0000-0000-0000-000000000000';

    $permissions = [
        'financial:write' => [],
        'financial:refund:review' => ['approve', 'reject'],
        'financial:refund:execute' => ['complete'],
    ];

    foreach ($permissions as $scope => $allowedActions) {
        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret',
            'scope' => $scope,
        ]);

        $tokenResponse
            ->assertOk()
            ->assertJsonPath('scope', $scope);

        $token = $tokenResponse->json('access_token');

        foreach (['approve', 'reject', 'complete'] as $action) {
            $response = $this->postJson(
                $baseUrl . '/' . $action,
                ['review_reason' => 'Prueba de permisos'],
                [
                    'Authorization' => "Bearer {$token}",
                    'Idempotency-Key' =>
                        'admin-refund-' . str()->uuid(),
                ]
            );

            if (in_array($action, $allowedActions, true)) {
                // El permiso permite entrar, pero la solicitud no existe.
                $response->assertNotFound();
            } else {
                // Sin el permiso específico, no llega al controlador.
                $response->assertForbidden();
            }
        }
    }

    foreach (['approve', 'reject', 'complete'] as $action) {
        $this->postJson(
            $baseUrl . '/' . $action,
            ['review_reason' => 'Prueba sin token'],
            ['Idempotency-Key' => 'no-token-' . str()->uuid()]
        )->assertUnauthorized();
    }
});
test('refund api approves and completes without duplicating money', function () {
    $client = ServiceClient::create([
        'name' => 'Refund administrative flow test',
        'client_id' => 'svc_financial_refund_test',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => [
            'financial:write',
            'financial:refund:review',
            'financial:refund:execute',
        ],
        'active' => true,
    ]);

    $tokens = [];

    foreach ([
        'financial:write',
        'financial:refund:review',
        'financial:refund:execute',
    ] as $scope) {
        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret',
            'scope' => $scope,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('scope', $scope);

        $tokens[$scope] = $response->json('access_token');
    }

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-refund-user',
        WalletType::USUARIO
    );

    $ledger = app(LedgerService::class);

    $ledger->credit(
        wallet: $wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'api-refund-credit-' . str()->uuid()
    );

    $payment = $ledger->debit(
        wallet: $wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'api-refund-payment-' . str()->uuid()
    );

    $created = $this->postJson(
        '/api/v1/financial/refunds',
        [
            'original_transaction_id' => $payment->public_id,
            'amount_cents' => 10000,
            'reason' => 'Prueba del flujo administrativo',
        ],
        [
            'Authorization' =>
                'Bearer ' . $tokens['financial:write'],
            'Idempotency-Key' =>
                'api-refund-request-' . str()->uuid(),
        ]
    );

    $created->assertCreated();

    $refundId = $created->json('data.id');
    $url = '/api/v1/financial/refunds/' . $refundId;

    $executeHeaders = [
        'Authorization' =>
            'Bearer ' . $tokens['financial:refund:execute'],
        'Idempotency-Key' =>
            'api-refund-complete-' . str()->uuid(),
    ];

    // Todavía no está aprobada.
    $this->postJson(
        $url . '/complete',
        [],
        $executeHeaders
    )->assertStatus(409);

    $approved = $this->postJson(
        $url . '/approve',
        [
            'review_reason' => 'Devolución autorizada',
            'reviewed_by' => 'responsable-falso',
        ],
        [
            'Authorization' =>
                'Bearer ' . $tokens['financial:refund:review'],
        ]
    );

    $approved
        ->assertOk()
        ->assertJsonPath('data.status', 'APROBADA')
        ->assertJsonPath(
            'data.reviewed_by',
            'service:' . $client->client_id
        );

    $wallet->refresh();
    expect($wallet->available_balance_cents)->toBe(70000);

    // La ejecución exige su propia clave de idempotencia.
    $this->postJson(
        $url . '/complete',
        [],
        [
            'Authorization' =>
                'Bearer ' . $tokens['financial:refund:execute'],
        ]
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('idempotency_key');

    $completed = $this->postJson(
        $url . '/complete',
        ['executed_by' => 'ejecutor-falso'],
        $executeHeaders
    );

    $completed
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETADA');

    $transactionId = $completed->json(
        'data.financial_transaction_id'
    );

    $this->postJson(
        $url . '/complete',
        [],
        $executeHeaders
    )
        ->assertOk()
        ->assertJsonPath(
            'data.financial_transaction_id',
            fn ($id) =>
                strtolower($id) === strtolower($transactionId)
        );

    $wallet->refresh();
    expect($wallet->available_balance_cents)->toBe(80000);

    $transaction = FinancialTransaction::where(
        'public_id',
        $transactionId
    )->firstOrFail();

    expect($transaction->metadata['executed_by'])
        ->toBe('service:' . $client->client_id);

    expect(
        LedgerEntry::where(
            'transaction_id',
            $transactionId
        )->count()
    )->toBe(1);

    expect(
        FinancialTransaction::where(
            'idempotency_key',
            $executeHeaders['Idempotency-Key']
        )->count()
    )->toBe(1);

    $refund = FinancialRefundRequest::where(
        'public_id',
        $refundId
    )->firstOrFail();

    expect($refund->completed_at)->not->toBeNull();

    // Otra clave no permite ejecutar nuevamente la devolución.
    $differentHeaders = $executeHeaders;
    $differentHeaders['Idempotency-Key'] =
        'api-refund-other-key-' . str()->uuid();

    $this->postJson(
        $url . '/complete',
        [],
        $differentHeaders
    )->assertStatus(409);

    $wallet->refresh();
    expect($wallet->available_balance_cents)->toBe(80000);
});
test('refund api rejects a request without moving money', function () {
    $client = ServiceClient::create([
        'name' => 'Refund rejection API test',
        'client_id' => 'svc_financial_refund_test',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => [
            'financial:write',
            'financial:refund:review',
            'financial:refund:execute',
        ],
        'active' => true,
    ]);

    $tokens = [];

    foreach ([
        'financial:write',
        'financial:refund:review',
        'financial:refund:execute',
    ] as $scope) {
        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret',
            'scope' => $scope,
        ]);

        $response->assertOk();
        $tokens[$scope] = $response->json('access_token');
    }

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-refund-user',
        WalletType::USUARIO
    );

    $ledger = app(LedgerService::class);

    $ledger->credit(
        wallet: $wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'api-refund-credit-' . str()->uuid()
    );

    $payment = $ledger->debit(
        wallet: $wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'api-refund-payment-' . str()->uuid()
    );

    $created = $this->postJson(
        '/api/v1/financial/refunds',
        [
            'original_transaction_id' => $payment->public_id,
            'amount_cents' => 10000,
        ],
        [
            'Authorization' =>
                'Bearer ' . $tokens['financial:write'],
            'Idempotency-Key' =>
                'api-refund-request-' . str()->uuid(),
        ]
    );

    $created->assertCreated();

    $refundId = $created->json('data.id');
    $url = '/api/v1/financial/refunds/' . $refundId;

    $reviewHeaders = [
        'Authorization' =>
            'Bearer ' . $tokens['financial:refund:review'],
    ];

    $entriesBefore = LedgerEntry::where(
        'wallet_id',
        $wallet->public_id
    )->count();

    // No se permite rechazar sin indicar un motivo.
    $this->postJson(
        $url . '/reject',
        [],
        $reviewHeaders
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('review_reason');

    $this->postJson(
        $url . '/reject',
        [
            'review_reason' => 'El pago original es correcto.',
            'reviewed_by' => 'responsable-falso',
        ],
        $reviewHeaders
    )
        ->assertOk()
        ->assertJsonPath('data.status', 'RECHAZADA')
        ->assertJsonPath(
            'data.reviewed_by',
            'service:' . $client->client_id
        )
        ->assertJsonPath(
            'data.review_reason',
            'El pago original es correcto.'
        );

    // Una solicitud rechazada no puede aprobarse ni ejecutarse.
    $this->postJson(
        $url . '/approve',
        [],
        $reviewHeaders
    )->assertStatus(409);

    $this->postJson(
        $url . '/complete',
        [],
        [
            'Authorization' =>
                'Bearer ' . $tokens['financial:refund:execute'],
            'Idempotency-Key' =>
                'api-refund-complete-' . str()->uuid(),
        ]
    )->assertStatus(409);

    $wallet->refresh();

    expect($wallet->available_balance_cents)->toBe(70000);

    expect(
        LedgerEntry::where(
            'wallet_id',
            $wallet->public_id
        )->count()
    )->toBe($entriesBefore);

    $refund = FinancialRefundRequest::where(
        'public_id',
        $refundId
    )->firstOrFail();

    expect($refund->completed_at)->toBeNull();
    expect($refund->financial_transaction_id)->toBeNull();

    expect($refund->requestTransaction->status->value)
        ->toBe('FALLIDA');
});
test('refund api returns transferred money without duplication', function () {
    $client = ServiceClient::create([
        'name' => 'Transfer refund API test',
        'client_id' => 'svc_financial_refund_test',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => [
            'financial:write',
            'financial:refund:review',
            'financial:refund:execute',
        ],
        'active' => true,
    ]);

    $tokens = [];

    foreach ([
        'financial:write',
        'financial:refund:review',
        'financial:refund:execute',
    ] as $scope) {
        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret',
            'scope' => $scope,
        ]);

        $response->assertOk();
        $tokens[$scope] = $response->json('access_token');
    }

    $walletService = app(WalletService::class);
    $ledger = app(LedgerService::class);

    $sender = $walletService->create(
        'USER',
        'test-financial-api-refund-user',
        WalletType::USUARIO
    );

    $receiver = $walletService->create(
        'USER',
        'test-financial-api-refund-receiver',
        WalletType::USUARIO
    );

    $ledger->credit(
        wallet: $sender,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'api-transfer-credit-' . str()->uuid()
    );

    $original = $ledger->transfer(
        sourceWallet: $sender,
        destinationWallet: $receiver,
        amountCents: 30000,
        idempotencyKey: 'api-transfer-original-' . str()->uuid()
    );

    $created = $this->postJson(
        '/api/v1/financial/refunds',
        [
            'original_transaction_id' => $original->public_id,
            'amount_cents' => 10000,
            'reason' => 'Devolución parcial de transferencia',
        ],
        [
            'Authorization' =>
                'Bearer ' . $tokens['financial:write'],
            'Idempotency-Key' =>
                'api-transfer-request-' . str()->uuid(),
        ]
    );

    $created
        ->assertCreated()
        ->assertJsonPath('data.status', 'PENDIENTE')
        ->assertJsonPath(
            'data.wallet_id',
            fn ($id) =>
                strtolower($id) === strtolower($sender->public_id)
        );

    $url = '/api/v1/financial/refunds/' .
        $created->json('data.id');

    $this->postJson(
        $url . '/approve',
        ['review_reason' => 'Devolución autorizada'],
        [
            'Authorization' =>
                'Bearer ' . $tokens['financial:refund:review'],
        ]
    )
        ->assertOk()
        ->assertJsonPath('data.status', 'APROBADA');

    $sender->refresh();
    $receiver->refresh();

    expect($sender->available_balance_cents)->toBe(70000);
    expect($receiver->available_balance_cents)->toBe(30000);

    $headers = [
        'Authorization' =>
            'Bearer ' . $tokens['financial:refund:execute'],
        'Idempotency-Key' =>
            'api-transfer-complete-' . str()->uuid(),
    ];

    $completed = $this->postJson(
        $url . '/complete',
        [],
        $headers
    );

    $completed
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETADA');

    $transactionId = $completed->json(
        'data.financial_transaction_id'
    );

    $this->postJson($url . '/complete', [], $headers)
        ->assertOk()
        ->assertJsonPath(
            'data.financial_transaction_id',
            fn ($id) =>
                strtolower($id) === strtolower($transactionId)
        );

    $sender->refresh();
    $receiver->refresh();

    expect($sender->available_balance_cents)->toBe(80000);
    expect($receiver->available_balance_cents)->toBe(20000);

    expect(
        $sender->available_balance_cents +
        $receiver->available_balance_cents
    )->toBe(100000);

    $entries = LedgerEntry::where(
        'transaction_id',
        $transactionId
    )->get();

    expect($entries)->toHaveCount(2);
    expect((int) $entries->sum('amount_cents'))->toBe(0);

    $this->assertDatabaseHas('ledger_entries', [
        'transaction_id' => $transactionId,
        'wallet_id' => $sender->public_id,
        'movement_type' => 'DEVOLUCION',
        'amount_cents' => 10000,
    ], 'sqlsrv');

    $this->assertDatabaseHas('ledger_entries', [
        'transaction_id' => $transactionId,
        'wallet_id' => $receiver->public_id,
        'movement_type' => 'DEVOLUCION',
        'amount_cents' => -10000,
    ], 'sqlsrv');
});
test('refund api requires a specific recovery permission', function () {
    $scopes = [
        'financial:read',
        'financial:write',
        'financial:refund:review',
        'financial:refund:execute',
        'financial:refund:recover',
    ];

    $client = ServiceClient::create([
        'name' => 'Withdrawal recovery permissions test',
        'client_id' => 'svc_financial_refund_test',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => $scopes,
        'active' => true,
    ]);

    $url = '/api/v1/financial/refunds/' .
        '00000000-0000-0000-0000-000000000000/recover';

    $this->postJson($url, [
        'recovery_reference' => 'test-recovery-reference',
    ])->assertUnauthorized();

    foreach ($scopes as $scope) {
        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret',
            'scope' => $scope,
        ]);

        $tokenResponse
            ->assertOk()
            ->assertJsonPath('scope', $scope);

        $response = $this->postJson(
            $url,
            ['recovery_reference' => 'test-recovery-reference'],
            [
                'Authorization' =>
                    'Bearer ' . $tokenResponse->json('access_token'),
            ]
        );

        if ($scope === 'financial:refund:recover') {
            // Tiene permiso, pero la solicitud no existe.
            $response->assertNotFound();
        } else {
            $response->assertForbidden();
        }
    }
});
test('refund api completes a withdrawal only after recovery', function () {
    $scopes = [
        'financial:write',
        'financial:refund:review',
        'financial:refund:recover',
        'financial:refund:execute',
    ];

    $client = ServiceClient::create([
        'name' => 'Withdrawal recovery API flow test',
        'client_id' => 'svc_financial_refund_test',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => $scopes,
        'active' => true,
    ]);

    $tokens = [];

    foreach ($scopes as $scope) {
        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret',
            'scope' => $scope,
        ]);

        $response->assertOk();
        $tokens[$scope] = $response->json('access_token');
    }

    $wallet = app(WalletService::class)->create(
        'USER',
        'test-financial-api-refund-user',
        WalletType::USUARIO
    );

    app(LedgerService::class)->credit(
        wallet: $wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'api-withdrawal-credit-' . str()->uuid()
    );

    $withdrawalService = app(WithdrawalService::class);

    $withdrawal = $withdrawalService->create(
        wallet: $wallet,
        amountCents: 30000,
        method: WithdrawalMethod::EFECTIVO
    );

    $withdrawalKey = 'api-withdrawal-original-' . str()->uuid();
    testHistoricalWithdrawal($withdrawal, $withdrawalKey);

    $original = FinancialTransaction::where(
        'idempotency_key',
        $withdrawalKey
    )->firstOrFail();

    $created = $this->postJson(
        '/api/v1/financial/refunds',
        [
            'original_transaction_id' => $original->public_id,
            'amount_cents' => 10000,
        ],
        [
            'Authorization' =>
                'Bearer ' . $tokens['financial:write'],
            'Idempotency-Key' =>
                'api-withdrawal-request-' . str()->uuid(),
        ]
    );

    $created->assertCreated();

    $refundId = $created->json('data.id');
    $url = '/api/v1/financial/refunds/' . $refundId;

    $recoveryHeaders = [
        'Authorization' =>
            'Bearer ' . $tokens['financial:refund:recover'],
    ];

    $reference = 'api-recovery-receipt-' . str()->uuid();

    $recoveryPayload = [
        'recovery_reference' => $reference,
        'notes' => 'Recuperación de $100.00 en efectivo.',
        'confirmed_by' => 'responsable-falso',
    ];

    // No puede confirmarse una solicitud pendiente.
    $this->postJson(
        $url . '/recover',
        $recoveryPayload,
        $recoveryHeaders
    )->assertStatus(409);

    $this->postJson(
        $url . '/approve',
        [],
        [
            'Authorization' =>
                'Bearer ' . $tokens['financial:refund:review'],
        ]
    )
        ->assertOk()
        ->assertJsonPath('data.status', 'APROBADA');

    $executeHeaders = [
        'Authorization' =>
            'Bearer ' . $tokens['financial:refund:execute'],
        'Idempotency-Key' =>
            'api-withdrawal-complete-' . str()->uuid(),
    ];

    // Aunque esté aprobada, falta recuperar el dinero.
    $this->postJson(
        $url . '/complete',
        [],
        $executeHeaders
    )->assertStatus(409);

    $this->postJson(
        $url . '/recover',
        [],
        $recoveryHeaders
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('recovery_reference');

    $recovered = $this->postJson(
        $url . '/recover',
        $recoveryPayload,
        $recoveryHeaders
    );

    $recovered
        ->assertCreated()
        ->assertJsonPath('data.amount_cents', 10000)
        ->assertJsonPath('data.currency', 'MXN')
        ->assertJsonPath(
            'data.confirmed_by',
            'service:' . $client->client_id
        );

    $recoveryId = $recovered->json('data.id');

    $this->postJson(
        $url . '/recover',
        $recoveryPayload,
        $recoveryHeaders
    )
        ->assertOk()
        ->assertJsonPath(
            'data.id',
            fn ($id) =>
                strtolower($id) === strtolower($recoveryId)
        );

    // Registrar la recuperación no realiza el abono.
    $wallet->refresh();
    expect($wallet->available_balance_cents)->toBe(70000);

    $completed = $this->postJson(
        $url . '/complete',
        [],
        $executeHeaders
    );

    $completed
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETADA');

    $transactionId = $completed->json(
        'data.financial_transaction_id'
    );

    $this->postJson(
        $url . '/complete',
        [],
        $executeHeaders
    )
        ->assertOk()
        ->assertJsonPath(
            'data.financial_transaction_id',
            fn ($id) =>
                strtolower($id) === strtolower($transactionId)
        );

    $wallet->refresh();
    expect($wallet->available_balance_cents)->toBe(80000);

    $transaction = FinancialTransaction::where(
        'public_id',
        $transactionId
    )->firstOrFail();

    expect(strtolower($transaction->metadata['recovery_id']))
        ->toBe(strtolower($recoveryId));

    expect($transaction->metadata['recovery_reference'])
        ->toBe($reference);

    expect(
        FinancialWithdrawalRecovery::where(
            'refund_request_id',
            $refundId
        )->count()
    )->toBe(1);

    expect(
        LedgerEntry::where(
            'transaction_id',
            $transactionId
        )->count()
    )->toBe(1);
});
test('purchase refund api enforces permissions for every action', function () {
    $permissions = [
        'financial:read' => ['show'],
        'financial:write' => ['store'],
        'financial:refund:review' => ['approve', 'reject'],
        'financial:refund:execute' => ['complete'],
    ];

    $client = ServiceClient::create([
        'name' => 'Purchase refund API permissions test',
        'client_id' => 'svc_financial_refund_test',
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => array_keys($permissions),
        'active' => true,
    ]);

    $baseUrl = '/api/v1/financial/purchase-refunds';
    $missingId = '00000000-0000-0000-0000-000000000000';

    $payload = [
        'purchase_payment_id' => $missingId,
        'wallet_amount_cents' => 1,
        'bonus_refunds' => [],
        'review_reason' => 'Prueba de permisos',
    ];

    $actions = ['show', 'store', 'approve', 'reject', 'complete'];

    // Todas las acciones deben exigir un token.
    foreach ($actions as $action) {
        $url = match ($action) {
            'store' => $baseUrl,
            'show' => $baseUrl . '/' . $missingId,
            default => $baseUrl . '/' . $missingId . '/' . $action,
        };

        if ($action === 'show') {
            $this->getJson($url)->assertUnauthorized();
        } else {
            $this->postJson($url, $payload, [
                'Idempotency-Key' => 'no-token-' . str()->uuid(),
            ])->assertUnauthorized();
        }
    }

    foreach ($permissions as $scope => $allowedActions) {
        $tokenResponse = $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'test-secret',
            'scope' => $scope,
        ]);

        $tokenResponse
            ->assertOk()
            ->assertJsonPath('scope', $scope);

        foreach ($actions as $action) {
            $url = match ($action) {
                'store' => $baseUrl,
                'show' => $baseUrl . '/' . $missingId,
                default => $baseUrl . '/' . $missingId . '/' . $action,
            };

            $headers = [
                'Authorization' =>
                    'Bearer ' . $tokenResponse->json('access_token'),
                'Idempotency-Key' => 'permission-test-' . str()->uuid(),
            ];

            $response = $action === 'show'
                ? $this->getJson($url, $headers)
                : $this->postJson($url, $payload, $headers);

            if (in_array($action, $allowedActions, true)) {
                // Tiene permiso, pero la compra o solicitud no existe.
                $response->assertNotFound();
            } else {
                $response->assertForbidden();
            }
        }
    }
});