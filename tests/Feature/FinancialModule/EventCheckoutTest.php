<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\RefundRequestStatus;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Models\ServiceClient;
use App\Models\User;
use App\Models\UserSession;
use App\Services\OAuthTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.connections.sqlsrv.database'))->toBe('campus_digital_financial_testing');
    DB::connection('sqlsrv')->beginTransaction();
    $this->student = User::factory()->create();
    $this->organization = '507f1f77bcf86cd799439013';
    $this->source = '507f1f77bcf86cd799439010';
    $this->url = '/api/v1/financial/community/event-reservations/'.$this->source.'/generations/1/payment';
    $this->client = ServiceClient::create(['client_id' => 'comunidad-fixture', 'name' => 'Comunidad de prueba',
        'secret_hash' => Hash::make('fixture-oauth'), 'active' => true,
        'scopes' => ['financial:event-payment:prepare', 'financial:event-payment:read', 'financial:event-payment:cancel']]);
    $this->headers = ['Authorization' => 'Bearer '.app(OAuthTokenService::class)
        ->issue('comunidad-fixture', 'fixture-oauth', iterator_to_array($this->client->scopes))['access_token'],
        'Idempotency-Key' => 'event-reservation:'.$this->source.':1'];
    $this->receiver = Wallet::create(['public_id' => (string) Str::uuid(), 'type' => 'WALLET_ORGANIZACION',
        'owner_type' => 'ORGANIZATION', 'owner_id' => $this->organization, 'status' => 'ACTIVA', 'currency' => 'MXN']);
    $this->payer = Wallet::create(['public_id' => (string) Str::uuid(), 'type' => 'WALLET_USUARIO',
        'owner_type' => 'USER', 'owner_id' => (string) $this->student->id, 'status' => 'ACTIVA', 'currency' => 'MXN']);
    app(LedgerService::class)->credit($this->payer, 5000, MovementType::RECARGA, (string) Str::uuid());
    config(['financial_event_payments' => ['enabled' => true, 'client_id' => 'comunidad-fixture',
        'recipients' => [$this->organization => ['wallet_id' => $this->receiver->public_id, 'name' => 'Consejo de prueba']]]]);
    $this->expiry = now()->addMinutes(10)->startOfSecond()->addMilliseconds(123);
    $this->body = ['student_id' => (string) $this->student->id, 'event_id' => '507f1f77bcf86cd799439012',
        'organization_id' => $this->organization, 'amount_cents' => 2500, 'currency' => 'MXN',
        'reservation_expires_at' => $this->expiry->format('Y-m-d\TH:i:s.v\Z')];
    $this->session = function () {
        $session = UserSession::create(['user_id' => (string) $this->student->id, 'started_at' => now()]);
        $this->actingAs($this->student)->withSession(['cd_session_id' => (string) $session->id, 'auth.password_confirmed_at' => now()->timestamp]);
        $this->withCookie(config('session.cookie'), session()->getId());
    };
});

afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});

it('binds the original reservation to one owner-consented ledger transfer and one receipt', function () {
    $data = $this->postJson($this->url, $this->body, $this->headers)->assertCreated()->json('data');
    $this->postJson($this->url, $this->body, $this->headers)->assertOk()->assertExactJson(['data' => $data]);
    $this->postJson($this->url, array_replace($this->body, ['amount_cents' => 2600]), $this->headers)->assertConflict();
    ($this->session)();
    $path = $data['confirmation_path'];
    $token = $this->get($path)->assertOk()->inertiaProps('reviewToken');
    $this->postJson($path.'/confirmar', ['consent' => false, 'review_token' => $token])->assertUnprocessable();
    $receipt = $this->post($path.'/confirmar', ['consent' => true, 'review_token' => $token])->assertRedirect()->headers->get('Location');
    $this->post($path.'/confirmar', ['consent' => true, 'review_token' => $token])->assertRedirect($receipt);
    $this->getJson($this->url, $this->headers)->assertOk()->assertJsonPath('data.status', 'CONFIRMED')
        ->assertJsonPath('data.payment_id', basename($receipt))
        ->assertJsonPath('data.reservation_expires_at', $this->expiry->format('Y-m-d\TH:i:s.v\Z'));
    $this->postJson($this->url.'/cancel', [], $this->headers)->assertOk()->assertJsonPath('data.status', 'CONFIRMED');
    expect($this->payer->fresh()->available_balance_cents)->toBe(2500)->and($this->receiver->fresh()->available_balance_cents)->toBe(2500);
    $this->assertDatabaseCount('ledger_entries', 3, 'sqlsrv');

    $original = FinancialTransaction::where('public_id', basename($receipt))->firstOrFail();
    $refunds = app(FinancialAdjustmentService::class);
    $requester = 'user:'.$this->student->id;
    $request = $refunds->refund($original, 2500, 'event-refund-request', $requester);
    $refund = FinancialRefundRequest::where('request_transaction_id', $request->public_id)->sole();
    expect($refunds->reviewWalletId($refund))->toBe(strtolower($this->receiver->public_id));
    expect(fn () => $refunds->approveRefund($refund, $requester))->toThrow(InvalidArgumentException::class);
    expect($refund->fresh()->status)->toBe(RefundRequestStatus::PENDIENTE);
    $this->getJson($this->url, $this->headers)->assertOk()->assertJsonPath('data.refund', null);

    $refunds->approveRefund($refund, 'user:reviewer');
    $completed = $refunds->completeRefund($refund, 'event-refund-execute', 'user:executor');
    expect(strtolower($refunds->completeRefund($refund, 'event-refund-execute', 'user:executor')->public_id))
        ->toBe(strtolower($completed->public_id));
    $this->getJson($this->url, $this->headers)->assertOk()
        ->assertJsonPath('data.refund.refund_id', strtolower($completed->public_id))
        ->assertJsonPath('data.refund.original_payment_id', strtolower($original->public_id))
        ->assertJsonPath('data.refund.amount_cents', 2500);
    expect($this->payer->fresh()->available_balance_cents)->toBe(5000)->and($this->receiver->fresh()->available_balance_cents)->toBe(0);
    $this->assertDatabaseCount('ledger_entries', 5, 'sqlsrv');
});

it('records cancellation before preparation and rejects a charge at the exclusive deadline', function () {
    $this->postJson($this->url.'/cancel', [], $this->headers)->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    $this->postJson($this->url, $this->body, $this->headers)->assertConflict();
    $second = str_replace('/1/payment', '/2/payment', $this->url);
    $headers = array_replace($this->headers, ['Idempotency-Key' => 'event-reservation:'.$this->source.':2']);
    $path = $this->postJson($second, $this->body, $headers)->assertCreated()->json('data.confirmation_path');
    $this->travelTo($this->expiry);
    ($this->session)();
    $token = $this->get($path)->assertOk()->inertiaProps('reviewToken');
    $this->postJson($path.'/confirmar', ['consent' => true, 'review_token' => $token])->assertConflict();
    $this->postJson($second.'/cancel', [], $headers)->assertOk()->assertJsonPath('data.status', 'EXPIRED');
    expect($this->payer->fresh()->available_balance_cents)->toBe(5000)->and($this->receiver->fresh()->available_balance_cents)->toBe(0);
});
