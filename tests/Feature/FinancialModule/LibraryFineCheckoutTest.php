<?php

namespace Tests\Feature\FinancialModule;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Models\ServiceClient;
use App\Models\User;
use App\Models\UserSession;
use App\Services\OAuthTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibraryFineCheckoutTest extends TestCase
{
    private const API = '/api/v1/financial/student-services/library-fines/507f1f77bcf86cd799439011';

    private const SCOPES = ['financial:library-fine:prepare', 'financial:library-fine:read', 'financial:library-fine:cancel'];

    private User $student;

    private Wallet $payer;

    private Wallet $recipient;

    private ServiceClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('campus_digital_financial_testing', config('database.connections.sqlsrv.database'));
        DB::connection('sqlsrv')->beginTransaction();
        $this->student = User::factory()->create();
        $this->payer = $this->wallet('USER', (string) $this->student->getKey(), 'WALLET_USUARIO');
        $this->recipient = $this->wallet('ORGANIZATION', 'student-services', 'WALLET_ORGANIZACION');
        app(LedgerService::class)->credit($this->payer, 5000, MovementType::RECARGA, (string) Str::uuid());
        $this->client = $this->newClient();
        config(['financial_library_fines' => [
            'enabled' => true, 'client_id' => $this->client->client_id,
            'recipient_organization_id' => 'student-services', 'recipient_wallet_id' => $this->recipient->public_id,
        ]]);
    }

    protected function tearDown(): void
    {
        while (DB::connection('sqlsrv')->transactionLevel() > 0) {
            DB::connection('sqlsrv')->rollBack();
        }

        parent::tearDown();
    }

    public function test_only_the_student_can_authorize_a_single_payment_and_e5_can_recover_it(): void
    {
        $payment = $this->prepare();
        $path = $payment['confirmation_path'];

        $this->signInStudent(false);
        $review = $this->get($path)->assertOk()->viewData('page')['props']['reviewToken'];
        $this->postJson($path.'/confirmar', ['consent' => true, 'review_token' => $review])->assertConflict();
        $this->post(route('password.confirm'), ['password' => 'password'])->assertRedirect($path);
        $this->postJson($path.'/confirmar', ['consent' => false, 'review_token' => $review])->assertUnprocessable();
        $this->postJson($path.'/confirmar', ['consent' => true, 'review_token' => str_repeat('a', 64)])->assertConflict();
        $this->assertSame(5000, $this->payer->fresh()->available_balance_cents);
        $this->assertSame(0, $this->recipient->fresh()->available_balance_cents);

        $payload = ['consent' => true, 'review_token' => $review];
        $receipt = $this->post($path.'/confirmar', $payload)->assertRedirect()->headers->get('Location');
        $this->post($path.'/confirmar', $payload)->assertRedirect($receipt);

        $proof = $this->getJson(self::API, $this->bearer())->assertOk()
            ->assertJsonPath('data.source_reference', basename(self::API))
            ->assertJsonPath('data.student_id', (string) $this->student->getKey())
            ->assertJsonPath('data.amount_cents', 1500)->assertJsonPath('data.currency', 'MXN')
            ->assertJsonPath('data.concept', 'library_fine')->assertJsonPath('data.status', 'CONFIRMED')->json('data');
        $this->assertStringEndsWith('/'.$proof['payment_id'], $receipt);
        $this->get($receipt)->assertOk();
        $this->assertSame(3500, $this->payer->fresh()->available_balance_cents);
        $this->assertSame(1500, $this->recipient->fresh()->available_balance_cents);
        $this->assertDatabaseCount('ledger_entries', 3, 'sqlsrv');

        $this->postJson(self::API.'/cancel', [], $this->bearer())->assertOk()
            ->assertJsonPath('data.payment_id', $proof['payment_id'])->assertJsonPath('data.status', 'CONFIRMED');
    }

    public function test_origin_is_immutable_and_only_the_configured_client_with_the_correct_scope_can_access_it(): void
    {
        $data = $this->payload();
        $this->postJson(self::API, $data)->assertUnauthorized();
        $this->postJson(self::API, $data, $this->bearer(['financial:library-fine:read']))->assertForbidden();
        $this->postJson(self::API, $data, $this->bearer(client: $this->newClient()))->assertForbidden();
        $this->postJson(self::API, $data + ['wallet_id' => $this->recipient->public_id], $this->bearer())->assertUnprocessable();
        $this->postJson(self::API, array_replace($data, ['amount_cents' => '1500']), $this->bearer())->assertUnprocessable();

        $payment = $this->prepare();
        $this->postJson(self::API, $data, $this->bearer())->assertOk()->assertExactJson(['data' => $payment]);
        $this->postJson(self::API, array_replace($data, ['amount_cents' => 1600]), $this->bearer())->assertConflict();
        $this->getJson(self::API, $this->bearer(client: $this->newClient()))->assertForbidden();
        $read = $this->bearer(['financial:library-fine:read']);
        $this->client->update(['scopes' => ['financial:library-fine:prepare']]);
        $this->getJson(self::API, $read)->assertForbidden();
        config(['financial_library_fines.enabled' => false]);
        $this->getJson(self::API, $read)->assertServiceUnavailable();
    }

    public function test_cancellation_can_block_preparation_or_a_reviewed_payment(): void
    {
        $cancelled = $this->postJson(self::API.'/cancel', [], $this->bearer())->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.payment_id', null)->json();
        $this->postJson(self::API.'/cancel', [], $this->bearer())->assertOk()->assertExactJson($cancelled);
        $this->postJson(self::API, $this->payload(), $this->bearer())->assertConflict();

        $anotherFine = str_replace('9011', '9012', self::API);
        $path = $this->prepare($anotherFine)['confirmation_path'];
        $this->signInStudent();
        $review = $this->get($path)->assertOk()->viewData('page')['props']['reviewToken'];
        $this->postJson($anotherFine.'/cancel', [], $this->bearer())->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson($path.'/confirmar', ['consent' => true, 'review_token' => $review])->assertConflict();
        $this->assertSame(5000, $this->payer->fresh()->available_balance_cents);
        $this->assertDatabaseCount('ledger_entries', 1, 'sqlsrv');
    }

    public function test_insufficient_funds_or_a_changed_recipient_leave_the_fine_unpaid(): void
    {
        $path = $this->prepare(amount: 6000)['confirmation_path'];
        $this->signInStudent();
        $review = $this->get($path)->assertOk()->viewData('page')['props']['reviewToken'];
        $confirmation = ['consent' => true, 'review_token' => $review];
        $this->postJson($path.'/confirmar', $confirmation)->assertConflict();
        $different = $this->wallet('ORGANIZATION', 'other-services', 'WALLET_ORGANIZACION');
        config([
            'financial_library_fines.recipient_wallet_id' => $different->public_id,
            'financial_library_fines.recipient_organization_id' => $different->owner_id,
        ]);
        $this->postJson($path.'/confirmar', $confirmation)->assertConflict();

        $this->getJson(self::API, $this->bearer())->assertOk()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.payment_id', null);
        $this->assertSame(5000, $this->payer->fresh()->available_balance_cents);
        $this->assertSame(0, $this->recipient->fresh()->available_balance_cents);
        $this->assertDatabaseCount('ledger_entries', 1, 'sqlsrv');
    }

    public function test_another_account_cannot_open_the_checkout(): void
    {
        $path = $this->prepare()['confirmation_path'];
        $this->actingAs(User::factory()->create())->get($path)->assertNotFound();
        $this->assertDatabaseCount('ledger_entries', 1, 'sqlsrv');
    }

    private function prepare(string $path = self::API, int $amount = 1500): array
    {
        return $this->postJson($path, $this->payload($amount), $this->bearer())
            ->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data');
    }

    private function payload(int $amount = 1500): array
    {
        return ['student_id' => (string) $this->student->getKey(), 'amount_cents' => $amount, 'currency' => 'MXN'];
    }

    private function signInStudent(bool $recentPassword = true): void
    {
        $session = UserSession::create(['user_id' => (string) $this->student->getKey(), 'started_at' => now()]);
        $this->actingAs($this->student)->withSession([
            'cd_session_id' => (string) $session->getKey(),
            'auth.password_confirmed_at' => $recentPassword ? now()->timestamp : null,
        ]);
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function wallet(string $ownerType, string $ownerId, string $type): Wallet
    {
        return Wallet::create([
            'public_id' => (string) Str::uuid(), 'owner_type' => $ownerType, 'owner_id' => $ownerId,
            'type' => $type, 'status' => 'ACTIVA', 'currency' => 'MXN',
        ]);
    }

    private function newClient(): ServiceClient
    {
        return ServiceClient::create([
            'client_id' => 'fine-test-'.Str::uuid(), 'name' => 'E5 fixture', 'secret_hash' => Hash::make('fine-fixture-secret'),
            'scopes' => self::SCOPES, 'active' => true,
        ]);
    }

    private function bearer(array $scopes = self::SCOPES, ?ServiceClient $client = null): array
    {
        $token = app(OAuthTokenService::class)->issue(($client ?? $this->client)->client_id, 'fine-fixture-secret', $scopes)['access_token'];

        return ['Authorization' => 'Bearer '.$token];
    }
}
