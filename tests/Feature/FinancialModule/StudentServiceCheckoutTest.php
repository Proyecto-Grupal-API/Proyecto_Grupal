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

/**
 * Cobros de Servicios al Estudiante en Finanzas: pago de locker o de
 * impresiones que el alumno autoriza con su wallet.
 */
class StudentServiceCheckoutTest extends TestCase
{
    private const API = '/api/v1/financial/student-services';

    private const SOURCE = '507f1f77bcf86cd799439021';

    private const SCOPES = [
        'financial:student-service:prepare', 'financial:student-service:read', 'financial:student-service:cancel',
    ];

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
        app(LedgerService::class)->credit($this->payer, 100000, MovementType::RECARGA, (string) Str::uuid());
        $this->client = $this->newClient();
        config(['financial_student_services' => [
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

    public function test_a_locker_payment_is_authorized_once_by_the_student_and_recovered_by_e5(): void
    {
        $url = self::API.'/payments/locker_rental/'.self::SOURCE;
        $payment = $this->postJson($url, $this->payload(25000), $this->bearer())
            ->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data');
        $this->postJson($url, $this->payload(25000), $this->bearer())->assertOk()->assertExactJson(['data' => $payment]);
        $this->postJson($url, $this->payload(26000), $this->bearer())->assertConflict();
        $this->assertStringStartsWith('/finanzas/servicios-estudiante/solicitudes/', $payment['confirmation_path']);

        $this->signInStudent();
        $page = $this->get($payment['confirmation_path'])->assertOk()->viewData('page')['props'];
        $this->assertSame('Pago de locker', $page['checkout']['title']);
        $confirmation = ['consent' => true, 'review_token' => $page['reviewToken']];
        $receipt = $this->post($payment['confirmation_path'].'/confirmar', $confirmation)->assertRedirect()->headers->get('Location');
        $this->post($payment['confirmation_path'].'/confirmar', $confirmation)->assertRedirect($receipt);

        $proof = $this->getJson($url, $this->bearer())->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED')->assertJsonPath('data.concept', 'locker_rental')
            ->assertJsonPath('data.amount_cents', 25000)->json('data');
        $this->assertStringEndsWith('/'.$proof['payment_id'], $receipt);
        $this->assertSame(75000, $this->payer->fresh()->available_balance_cents);
        $this->assertSame(25000, $this->recipient->fresh()->available_balance_cents);

        // Cancelar después de pagar devuelve el pago confirmado; E5 lo registra.
        $this->postJson($url.'/cancel', [], $this->bearer())->assertOk()->assertJsonPath('data.status', 'CONFIRMED');
    }

    public function test_only_the_configured_client_and_scope_can_prepare_and_a_cancelled_request_cannot_be_paid(): void
    {
        $url = self::API.'/payments/print_order/'.self::SOURCE;
        $this->postJson($url, $this->payload(800))->assertUnauthorized();
        $this->postJson($url, $this->payload(800), $this->bearer(['financial:student-service:read']))->assertForbidden();
        $this->postJson($url, $this->payload(800), $this->bearer(client: $this->newClient()))->assertForbidden();
        $this->postJson($url, $this->payload(800) + ['wallet_id' => $this->recipient->public_id], $this->bearer())->assertUnprocessable();
        $this->postJson($url, array_replace($this->payload(800), ['amount_cents' => '800']), $this->bearer())->assertUnprocessable();
        $this->postJson(self::API.'/payments/rental_deposit/'.self::SOURCE, $this->payload(800), $this->bearer())->assertNotFound();

        $path = $this->postJson($url, $this->payload(800), $this->bearer())->assertCreated()->json('data.confirmation_path');
        $this->signInStudent();
        $review = $this->get($path)->assertOk()->viewData('page')['props']['reviewToken'];
        $this->postJson($url.'/cancel', [], $this->bearer())->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson($path.'/confirmar', ['consent' => true, 'review_token' => $review])->assertConflict();
        $this->postJson($url, $this->payload(800), $this->bearer())->assertConflict();
        $this->assertSame(100000, $this->payer->fresh()->available_balance_cents);

        $this->actingAs(User::factory()->create())->get($path)->assertNotFound();
    }

    private function payload(int $amount): array
    {
        return ['student_id' => (string) $this->student->getKey(), 'amount_cents' => $amount, 'currency' => 'MXN'];
    }

    private function signInStudent(): void
    {
        $session = UserSession::create(['user_id' => (string) $this->student->getKey(), 'started_at' => now()]);
        $this->actingAs($this->student)->withSession([
            'cd_session_id' => (string) $session->getKey(),
            'auth.password_confirmed_at' => now()->timestamp,
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
            'client_id' => 'e5-services-test-'.Str::uuid(), 'name' => 'E5 fixture', 'secret_hash' => Hash::make('e5-fixture-secret'),
            'scopes' => self::SCOPES, 'active' => true,
        ]);
    }

    private function bearer(array $scopes = self::SCOPES, ?ServiceClient $client = null): array
    {
        $token = app(OAuthTokenService::class)->issue(($client ?? $this->client)->client_id, 'e5-fixture-secret', $scopes)['access_token'];

        return ['Authorization' => 'Bearer '.$token];
    }
}
