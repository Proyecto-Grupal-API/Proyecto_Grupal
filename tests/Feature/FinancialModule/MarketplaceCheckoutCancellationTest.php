<?php

namespace Tests\Feature\FinancialModule;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Models\MarketplacePaymentRequest;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\MarketplaceCheckoutService;
use App\Models\ServiceClient;
use App\Models\User;
use App\Models\UserSession;
use App\Services\OAuthTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MarketplaceCheckoutCancellationTest extends TestCase
{
    private const PATH = '/api/v1/financial/marketplace/payment-requests/by-order/cancel';

    private const SCOPE = 'financial:purchase:cancel';

    private User $buyer;

    private ServiceClient $client;

    private ServiceClient $otherClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('campus_digital_financial_testing', config('database.connections.sqlsrv.database'));
        DB::connection('sqlsrv')->beginTransaction();

        config(['financial_marketplace.enabled' => true]);
        $this->buyer = User::factory()->create();
        $this->client = $this->newClient();
        $this->otherClient = $this->newClient();
        $recipient = Wallet::create([
            'public_id' => (string) Str::uuid(), 'owner_type' => 'BUSINESS', 'owner_id' => 'BUS-1',
            'type' => 'WALLET_NEGOCIO', 'status' => 'ACTIVA', 'currency' => 'MXN',
        ]);
        $merchant = ['name' => 'Marketplace', 'businesses' => ['BUS-1' => 'Tienda', 'BUS-2' => 'Otra tienda']];
        config(['financial_marketplace.clients' => [
            $this->client->client_id => $merchant + ['recipient_wallets' => ['BUS-1' => $recipient->public_id]],
            $this->otherClient->client_id => $merchant,
        ]]);
    }

    protected function tearDown(): void
    {
        while (DB::connection('sqlsrv')->transactionLevel() > 0) {
            DB::connection('sqlsrv')->rollBack();
        }

        parent::tearDown();
    }

    private function newClient(): ServiceClient
    {
        return ServiceClient::create([
            'client_id' => 'test-cancel-'.Str::uuid(), 'name' => 'Marketplace de prueba',
            'secret_hash' => Hash::make('fixture-cancel-oauth'),
            'scopes' => ['financial:purchase:prepare', 'financial:purchase:read', self::SCOPE], 'active' => true,
        ]);
    }

    public function test_cancellation_requires_a_current_scope_and_the_original_service_business_and_order(): void
    {
        $row = $this->prepare('ORDER-1');
        $body = ['order_id' => $row->order_id, 'business_id' => $row->business_id];
        $headers = $this->bearer();

        $this->postJson(self::PATH, $body)->assertUnauthorized();
        $this->postJson(self::PATH, $body, $this->bearer(scopes: ['financial:purchase:prepare', 'financial:purchase:read']))->assertForbidden();
        $this->postJson(self::PATH, $body + ['client_id' => 'marketplace'], $headers)->assertUnprocessable();
        $this->postJson(self::PATH.'?order_id=ORDER-1', $body, $headers)->assertUnprocessable();
        $this->postJson(self::PATH, array_replace($body, ['business_id' => 'UNLISTED']), $headers)->assertForbidden();
        $this->postJson(self::PATH, array_replace($body, ['business_id' => 'BUS-2']), $headers)->assertNotFound();
        $this->postJson(self::PATH, array_replace($body, ['order_id' => 'order-1']), $headers)->assertNotFound();
        $this->postJson(self::PATH, $body, $this->bearer($this->otherClient))->assertNotFound();

        $lookup = '/api/v1/financial/marketplace/payment-requests/by-order';
        $readHeaders = $this->bearer(scopes: ['financial:purchase:read']);
        $this->getJson($lookup.'?business_id=BUS-1&order_id=ORDER-1', $readHeaders)->assertOk();
        $this->getJson($lookup.'?business_id=BUS-1&order_id=order-1', $readHeaders)->assertNotFound();

        $this->client->update(['scopes' => ['financial:purchase:prepare']]);
        $this->postJson(self::PATH, $body, $headers)->assertForbidden();

        $this->client->update(['scopes' => ['financial:purchase:prepare', self::SCOPE]]);
        config(['financial_marketplace.enabled' => false]);
        $this->postJson(self::PATH, $body, $headers)->assertServiceUnavailable();

        $this->assertSame('PENDING', $row->fresh()->status);
    }

    public function test_cancellation_replays_and_blocks_an_already_reviewed_buyer_confirmation(): void
    {
        $row = $this->prepare('ORDER-1');
        $checkout = app(MarketplaceCheckoutService::class);
        $request = $this->buyerRequest();
        $reviewToken = $checkout->reviewToken($request, $row);

        $body = ['order_id' => $row->order_id, 'business_id' => $row->business_id];
        $cancelled = $this->postJson(self::PATH, $body, $this->bearer())->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.payment_request_id', strtolower($row->public_id))
            ->assertJsonPath('data.payment_intent_id', null)->json();
        $this->postJson(self::PATH, $body, $this->bearer())->assertOk()->assertExactJson($cancelled);
        $this->assertSame('CANCELLED', $row->fresh()->status);
        $this->assertDatabaseCount('purchase_payments', 0, 'sqlsrv');

        $this->assertThrows(
            fn () => $checkout->confirm($request, $row->public_id, $reviewToken),
            fn (HttpException $e) => $e->getStatusCode() === 409 && $e->getMessage() === 'La solicitud ya no se puede pagar.',
        );
    }

    public function test_cancellation_returns_paid_evidence_or_persists_expiry(): void
    {
        $paid = $this->prepare('ORDER-PAID');
        $wallet = Wallet::create([
            'public_id' => (string) Str::uuid(), 'owner_type' => 'USER', 'owner_id' => (string) $this->buyer->getKey(),
            'type' => 'WALLET_USUARIO', 'status' => 'ACTIVA', 'currency' => 'MXN',
        ]);
        app(LedgerService::class)->credit($wallet, 5000, MovementType::RECARGA, (string) Str::uuid());
        $service = app(MarketplaceCheckoutService::class);
        $request = $this->buyerRequest();
        $paid = $service->confirm($request, $paid->public_id, $service->reviewToken($request, $paid));
        $paymentId = strtolower($paid->purchase_payment_id);
        $this->postJson(self::PATH, ['order_id' => $paid->order_id, 'business_id' => $paid->business_id], $this->bearer())
            ->assertOk()->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.payment_intent_id', $paymentId);
        $this->assertSame('PAID', $paid->fresh()->status);
        $this->assertDatabaseCount('purchase_payments', 1, 'sqlsrv');

        $expired = $this->prepare('ORDER-EXPIRED');
        $expired->update(['expires_at' => now()->subSecond()]);
        $this->postJson(self::PATH, ['order_id' => $expired->order_id, 'business_id' => $expired->business_id], $this->bearer())
            ->assertOk()->assertJsonPath('data.status', 'EXPIRED')->assertJsonPath('data.payment_intent_id', null);
        $this->assertSame('EXPIRED', $expired->fresh()->status);
    }

    private function prepare(string $order): MarketplacePaymentRequest
    {
        return app(MarketplaceCheckoutService::class)->prepare($this->client->client_id, $order, [
            'order_id' => $order, 'business_id' => 'BUS-1', 'buyer_id' => (string) $this->buyer->getKey(),
            'amount_cents' => 3000, 'currency' => 'MXN',
        ]);
    }

    private function buyerRequest(): Request
    {
        $session = UserSession::create(['user_id' => (string) $this->buyer->getKey(), 'started_at' => now()]);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->buyer);
        $request->setLaravelSession(app('session.store'));
        $request->session()->put([
            'cd_session_id' => (string) $session->getKey(), 'auth.password_confirmed_at' => now()->timestamp,
        ]);

        return $request;
    }

    private function bearer(?ServiceClient $client = null, array $scopes = [self::SCOPE]): array
    {
        $token = app(OAuthTokenService::class)->issue(
            ($client ?? $this->client)->client_id, 'fixture-cancel-oauth', $scopes
        )['access_token'];

        return ['Authorization' => 'Bearer '.$token];
    }
}
