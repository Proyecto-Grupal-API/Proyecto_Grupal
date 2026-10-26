<?php

use App\Domains\Financial\Adapters\{IdentityBonusAuthorizationProvider, PendingBonusAuthorizationProvider, PendingFinancialWebAuthorizer};
use App\Domains\Financial\Contracts\{BonusAuthorizationProvider, FinancialWebAuthorizer};
use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Exceptions\IdentityFinancialAuthorizationUnavailable;
use App\Domains\Financial\Integrations\Identity\IdentityFinancialAuthorizationClient;
use App\Domains\Financial\Models\{Bonus, BonusLedgerEntry, BonusAdministrativeOperation, Wallet};
use App\Domains\Financial\Services\BonusService;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\{Artisan, DB, Http, Route};
use Illuminate\Http\Client\Factory;

beforeEach(function () {
    if (!app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') {
        throw new RuntimeException('Solo bases de pruebas.');
    }
    DB::connection('sqlsrv')->beginTransaction();
    Http::preventStrayRequests();
    config(['financial_identity_authorization.enabled' => false,
        'financial_identity_authorization.origin' => 'http://127.0.0.1:8086',
        'financial_identity_authorization.client_id' => 'fixture-finance-client',
        'financial_identity_authorization.client_secret' => 'fixture-finance-secret']);
});

afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});

function ibaiFake(bool $decision = true, int $status = 200): void
{
    // Each scenario needs a new HTTP factory; repeated fake() registrations can retain earlier callbacks.
    Http::swap(new Factory());
    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:8086/api/oauth/token' => Http::response(['access_token' => 'fixture-e1-token',
            'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'identity:financial-authorization:check']),
        'http://127.0.0.1:8086/api/v1/identity/financial-authorization/check' => Http::response(['authorized' => $decision], $status),
    ]);
}

function ibaiCounts(): array
{
    return [Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray()];
}

test('identity bonus consumer is disabled by default and only replaces the bonus binding when enabled', function () {
    expect(app(BonusAuthorizationProvider::class))->toBeInstanceOf(PendingBonusAuthorizationProvider::class);
    expect(app(BonusAuthorizationProvider::class)->canIssueBonus('USER', '507f1f77bcf86cd799439011', BonusType::BECA))->toBeFalse();
    config(['financial_identity_authorization.enabled' => true]);
    expect(app(BonusAuthorizationProvider::class))->toBeInstanceOf(IdentityBonusAuthorizationProvider::class)
        ->and(app(FinancialWebAuthorizer::class))->toBeInstanceOf(PendingFinancialWebAuthorizer::class)
        ->and(app(BonusService::class))->toBeInstanceOf(BonusService::class);
    foreach (['bonus.issue', 'bonus.view', 'bonus.view.all', 'bonus.cancel', 'bonus.cancel.all', 'cash.operate'] as $action) {
        expect(app(FinancialWebAuthorizer::class)->allows('507f1f77bcf86cd799439011', $action))->toBeFalse();
    }
    Http::assertNothingSent();
});

test('identity diagnostic reports evaluated decisions without creating bonuses ledger operations or wallet money', function () {
    config(['financial_identity_authorization.enabled' => true]); ibaiFake(true);
    $before = ibaiCounts();
    $exit = Artisan::call('financial:bonus-authorization-check', ['subject' => '507f1f77bcf86cd799439011', 'type' => 'BECA']);
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($result['status'])->toBe('EVALUATED')
        ->and($result['authorized'])->toBeTrue()->and($result['bonus_issued'])->toBeFalse()
        ->and($result['human_consent_proven'])->toBeFalse()->and(ibaiCounts())->toBe($before);
    Http::assertSentCount(2);
    ibaiFake(false);
    $exit = Artisan::call('financial:bonus-authorization-check', ['subject' => '507f1f77bcf86cd799439011', 'type' => 'BECA']);
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($result['status'])->toBe('EVALUATED')
        ->and($result['authorized'])->toBeFalse()->and($result['bonus_issued'])->toBeFalse()
        ->and(ibaiCounts())->toBe($before);
    Http::assertSentCount(2);
});

test('identity diagnostic disabled malformed subject and forbidden context never consult the network', function () {
    $this->artisan('financial:bonus-authorization-check', ['subject' => '507f1f77bcf86cd799439011', 'type' => 'BECA'])
        ->expectsOutputToContain('INTEGRATION_DISABLED')->assertExitCode(2);
    config(['financial_identity_authorization.enabled' => true]);
    $this->artisan('financial:bonus-authorization-check', ['subject' => 'not-a-person', 'type' => 'BECA'])->assertFailed();
    $this->artisan('financial:bonus-authorization-check', ['subject' => '507f1f77bcf86cd799439011', 'type' => 'CAMPANIA',
        '--scope-type' => 'business', '--scope-id' => 'business-A'])->assertFailed();
    $this->artisan('financial:bonus-authorization-check', ['subject' => '507f1f77bcf86cd799439011', 'type' => 'beca'])->assertFailed();
    Http::assertNothingSent();
});

test('identity diagnostic distinguishes unavailable business validation from a negative decision', function () {
    config(['financial_identity_authorization.enabled' => true]); ibaiFake(true, 503);
    $before = ibaiCounts();
    $exit = Artisan::call('financial:bonus-authorization-check', ['subject' => '507f1f77bcf86cd799439011', 'type' => 'NEGOCIO',
        '--scope-type' => 'business', '--scope-id' => 'business-A']);
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($exit)->toBe(2)->and($result['status'])->toBe('UNAVAILABLE')
        ->and($result['dependency_code'])->toBe('CHECK_HTTP_503')->and($result['upstream_http_status'])->toBe(503)
        ->and($result['bonus_issued'])->toBeFalse()->and($result)->not->toHaveKey('authorized')
        ->and(ibaiCounts())->toBe($before);
    Http::assertSentCount(2);
});

test('identity adapter denials and dependency errors leave domain issuance without rows or money', function () {
    config(['financial_identity_authorization.enabled' => true]); $before = ibaiCounts();
    ibaiFake(false);
    expect(fn () => app(BonusService::class)->issue('USER', '507f1f77bcf86cd799439012', 'USER',
        '507f1f77bcf86cd799439011', BonusType::BENEFICIO, 100, now()->subMinute(), now()->addDay()))
        ->toThrow(InvalidArgumentException::class);
    expect(ibaiCounts())->toBe($before);
    Http::assertSentCount(2);
    ibaiFake(true, 503);
    expect(fn () => app(BonusService::class)->issue('USER', '507f1f77bcf86cd799439012', 'USER',
        '507f1f77bcf86cd799439011', BonusType::BENEFICIO, 100, now()->subMinute(), now()->addDay()))
        ->toThrow(IdentityFinancialAuthorizationUnavailable::class);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/financial-authorization/check'));
    Http::assertSentCount(2);
    expect(ibaiCounts())->toBe($before);
});

test('identity adapter preserves exact issuer context in the existing domain service under a positive contract response', function () {
    config(['financial_identity_authorization.enabled' => true]); ibaiFake(true);
    $wallets = Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray();
    $bonus = app(BonusService::class)->issue('USER', '507f1f77bcf86cd799439012', 'USER', '507f1f77bcf86cd799439011',
        BonusType::NEGOCIO, 100, now()->subMinute(), now()->addDay(), false, false, null, new BonusIssuerContext('business', 'business-A'));
    expect($bonus->issuer_scope_type)->toBe('business')->and($bonus->issuer_scope_id)->toBe('business-A')
        ->and($bonus->issuer_id)->toBe('507f1f77bcf86cd799439011')->and($bonus->ledgerEntries()->count())->toBe(1)
        ->and(Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray())->toBe($wallets);
    Http::assertSentCount(2);
});

test('identity technical capacity does not bypass the existing web actor and administrative access boundary', function () {
    config(['financial_identity_authorization.enabled' => true]); ibaiFake(true);
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now(), 'account_activation_pending' => false])->save();
    $before = ibaiCounts();
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->actingAs($user)->postJson('/finanzas/bonos/administracion/issue', [
        'subject_id' => '507f1f77bcf86cd799439011', 'issuer_id' => '507f1f77bcf86cd799439011',
        'type' => 'BECA', 'amount_cents' => 100,
    ])->assertForbidden();
    expect(ibaiCounts())->toBe($before);
    Http::assertNothingSent();
});

test('identity dependency HTTP rendering is sanitized 503 and does not expose upstream bodies or credentials', function () {
    config(['financial_identity_authorization.enabled' => true]); ibaiFake(true, 403);
    // Test-only route. No production HTTP endpoint is installed by this package.
    Route::get('/_test/identity-financial-unavailable', fn () => app(IdentityFinancialAuthorizationClient::class)
        ->check('507f1f77bcf86cd799439011', BonusType::BECA));
    $this->getJson('/_test/identity-financial-unavailable')->assertStatus(503)
        ->assertJsonStructure(['message'])->assertDontSee('fixture-e1-token')->assertDontSee('fixture-finance-secret')
        ->assertDontSee('CHECK_HTTP_403');
});
