<?php
use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Models\CashOperationConfirmation;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\CashSettlementService;
use App\Domains\Financial\Services\CashOperationConfirmationService;
use App\Domains\Financial\Services\WalletService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Models\User;
use App\Models\ServiceClient;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/FinancialControlHelpers.php';

beforeEach(function () { studentCashCleanup(); $this->withoutMiddleware(PreventRequestForgery::class); $this->withoutVite(); });
afterEach(function () { studentCashCleanup(); });
function studentCashCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Solo base financiera de pruebas.');
    $wallets = Wallet::where('owner_id', 'like', 'f22800%')->pluck('public_id');
    CashOperationConfirmation::whereIn('wallet_id', $wallets)->delete();
    $registers = CashRegister::where('association_id', 'like', 'test-fc-cash-confirm-%')->pluck('id');
    $shifts = CashShift::whereIn('cash_register_id', $registers)->pluck('id');
    $movements = CashMovement::whereIn('cash_shift_id', $shifts)->pluck('id');
    CashReceipt::whereIn('cash_movement_id', $movements)->delete(); CashMovement::whereIn('id', $movements)->delete();
    CashShift::whereIn('id', $shifts)->delete(); CashRegister::whereIn('id', $registers)->delete();
    fcCleanup();
    $transactions = LedgerEntry::whereIn('wallet_id', $wallets)->pluck('transaction_id');
    LedgerEntry::whereIn('wallet_id', $wallets)->delete();
    FinancialTransaction::whereIn('public_id', $transactions)->delete();
    TopUp::whereIn('wallet_id', $wallets)->delete(); Withdrawal::whereIn('wallet_id', $wallets)->delete(); Wallet::whereIn('public_id', $wallets)->delete();
    ServiceClient::where('client_id', 'like', 'test-fc-cash-confirm-%')->delete();
    User::where('email', 'like', 'test-fc-cash-confirm-%')->delete();
}
function studentCashUser(): User
{
    return User::factory()->create(['_id' => new \MongoDB\BSON\ObjectId('f22800'.bin2hex(random_bytes(9))),
        'email' => fcKey('cash-confirm-user').'@example.test', 'email_verified_at' => now()]);
}
function studentCashCase(string $actor = FC_ACTOR): array
{
    $student = studentCashUser();
    $wallet = app(WalletService::class)->create('USER', (string) $student->getKey(), WalletType::USUARIO);
    app(LedgerService::class)->credit($wallet, 10000, MovementType::RECARGA, fcKey('cash-confirm-seed'), 'TEST_SEED', $wallet->public_id);
    $cash = app(CashShiftService::class); $register = $cash->createRegister(fcKey('cash-confirm-assoc'), 'Caja confirmación', 'MXN', $actor);
    $shift = $cash->open($register->public_id, $actor, 10000, fcKey('cash-confirm-open'));
    return [$student, $wallet->fresh(), $shift];
}
function studentCashGrant(string $actor, string $association, array $actions = ['operate', 'read']): void
{
    app()->instance(CashAuthorizationProvider::class, new class($actor, $association, $actions) implements CashAuthorizationProvider {
        public function __construct(private string $actor, private string $association, private array $actions) {}
        public function allows(string $actorId, string $associationId, string $action): bool { return $actorId === $this->actor && $associationId === $this->association && in_array($action, $this->actions, true); }
    });
    foreach (app('router')->getRoutes() as $route) $route->flushController();
}
function studentCashToken($test, array $scopes): array
{
    $id = fcKey('cash-confirm-client');
    ServiceClient::create(['name' => 'Confirmación prueba', 'client_id' => $id, 'secret_hash' => Hash::make('test-secret'), 'scopes' => $scopes, 'active' => true]);
    $token = $test->postJson('/api/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => 'test-secret', 'scope' => implode(' ', $scopes)])->assertOk()->json('access_token');
    return [['Authorization' => 'Bearer '.$token], 'service:'.$id];
}

test('cash confirmation request is idempotent does not move money and can only be viewed and approved by its wallet owner', function () {
    [$student, $wallet, $shift] = studentCashCase(); $service = app(CashOperationConfirmationService::class); $key = fcKey('cash-confirm-request');
    $proof = $service->request($shift->public_id, $wallet, 'WITHDRAWAL', 2000, FC_ACTOR, 'Retiro', $key);
    expect(fcSameId($service->request($shift->public_id, $wallet, 'WITHDRAWAL', 2000, FC_ACTOR, 'Retiro', $key)->public_id, $proof->public_id))->toBeTrue();
    expect(fn () => $service->request($shift->public_id, $wallet, 'WITHDRAWAL', 3000, FC_ACTOR, 'Retiro', $key))->toThrow(InvalidArgumentException::class);
    $url = '/finanzas/confirmaciones-caja/'.$proof->public_id;
    $this->getJson($url)->assertUnauthorized();
    $other = studentCashUser(); $this->actingAs($other)->get($url)->assertNotFound();
    $this->post($url.'/approve', ['confirmed_by' => 'user:'.$student->getKey()])->assertNotFound();
    $this->actingAs($student)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Financial/CashConfirmations')->where('selected.amount_cents', 2000));
    $this->post($url.'/approve', ['confirmed_by' => 'forged'])->assertRedirect();
    expect($proof->fresh()->confirmed_by)->toBe('user:'.$student->getKey())
        ->and($wallet->fresh()->available_balance_cents)->toBe(10000)->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0);
});

test('cash api rejects a wallet uuid without confirmation and completes a student confirmed withdrawal once', function () {
    [$headers, $actor] = studentCashToken($this, ['financial:cash:operate']); [$student, $wallet, $shift] = studentCashCase($actor);
    studentCashGrant($actor, $shift->cashRegister->association_id);
    $base = '/api/v1/financial/cash/associations/'.$shift->cashRegister->association_id.'/shifts/'.$shift->public_id;
    $body = ['wallet_id' => $wallet->public_id, 'amount_cents' => 2000, 'reason' => 'Retiro'];
    $headers['Idempotency-Key'] = fcKey('cash-confirm-request');
    $this->postJson($base.'/withdrawals', $body, $headers)->assertUnprocessable()->assertJsonValidationErrors('confirmation_id');
    $proof = $this->postJson($base.'/confirmations', array_merge($body, ['operation' => 'WITHDRAWAL']), $headers)->assertOk()->json('data.id');
    $headers['Idempotency-Key'] = fcKey('cash-confirm-execute'); $body['confirmation_id'] = $proof;
    $this->postJson($base.'/withdrawals', $body, $headers)->assertConflict();
    $this->actingAs($student)->post('/finanzas/confirmaciones-caja/'.$proof.'/approve')->assertRedirect();
    $result = $this->postJson($base.'/withdrawals', $body, $headers)->assertOk(); $id = $result->json('data.id');
    $this->postJson($base.'/withdrawals', $body, $headers)->assertOk()->assertJsonPath('data.id', $id);
    expect($wallet->fresh()->available_balance_cents)->toBe(8000)
        ->and(CashOperationConfirmation::where('public_id', $proof)->firstOrFail()->status)->toBe('CONSUMED');
    $transaction = FinancialTransaction::where('idempotency_key', $headers['Idempotency-Key'])->firstOrFail();
    expect($transaction->metadata['student_confirmed_by'])->toBe('user:'.$student->getKey());
    $receipt = CashMovement::where('idempotency_key', $headers['Idempotency-Key'])->firstOrFail()->receipt;
    expect(strtolower($receipt->snapshot['cash_confirmation_id']))->toBe(strtolower($proof));
});

test('cash confirmation is bound to wallet amount operation operator reason and receiving shift', function () {
    [$student, $wallet, $shift] = studentCashCase(); $service = app(CashOperationConfirmationService::class);
    $proof = $service->request($shift->public_id, $wallet, 'WITHDRAWAL', 2000, FC_ACTOR, 'Retiro', fcKey('cash-confirm-request'));
    $service->decide($proof->public_id, $student, true, '127.0.0.1'); $cash = app(CashSettlementService::class);
    foreach ([[3000, FC_ACTOR, 'Retiro', false], [2000, 'otro', 'Retiro', false], [2000, FC_ACTOR, 'Cambiado', false], [2000, FC_ACTOR, 'Retiro', true]] as [$amount, $actor, $reason, $incoming])
        expect(fn () => $cash->settleConfirmed($shift->public_id, $wallet, $amount, fcKey('cash-confirm-invalid'), $actor, $reason, $incoming, $proof->public_id))->toThrow(InvalidArgumentException::class);
    [$otherStudent, $otherWallet, $otherShift] = studentCashCase();
    expect(fn () => $cash->settleConfirmed($shift->public_id, $otherWallet, 2000, fcKey('cash-confirm-wallet'), FC_ACTOR, 'Retiro', false, $proof->public_id))->toThrow(InvalidArgumentException::class);
    expect(fn () => $cash->settleConfirmed($otherShift->public_id, $wallet, 2000, fcKey('cash-confirm-shift'), FC_ACTOR, 'Retiro', false, $proof->public_id))->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and($proof->fresh()->status)->toBe('CONFIRMED');
});

test('cash confirmations rejected cancelled or expired cannot move funds', function () {
    [$student, $wallet, $shift] = studentCashCase(); $service = app(CashOperationConfirmationService::class); $cash = app(CashSettlementService::class);
    foreach (['REJECTED', 'CANCELLED', 'EXPIRED'] as $state) {
        $proof = $service->request($shift->public_id, $wallet, 'TOPUP', 2000, FC_ACTOR, 'Recarga', fcKey('cash-confirm-request'));
        $service->decide($proof->public_id, $student, true, '127.0.0.1');
        if ($state === 'REJECTED') $service->decide($proof->public_id, $student, false, '127.0.0.1');
        elseif ($state === 'CANCELLED') $service->cancel($proof->public_id, FC_ACTOR);
        else $proof->update(['expires_at' => now()->subSecond()]);
        expect(fn () => $cash->settleConfirmed($shift->public_id, $wallet, 2000, fcKey('cash-confirm-invalid'), FC_ACTOR, 'Recarga', true, $proof->public_id))->toThrow(InvalidArgumentException::class);
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0);
});

test('cash confirmation consumption and money roll back together when receipt creation fails and permit retry', function () {
    [$student, $wallet, $shift] = studentCashCase(); $service = app(CashOperationConfirmationService::class);
    $proof = $service->request($shift->public_id, $wallet, 'TOPUP', 2000, FC_ACTOR, 'Recarga', fcKey('cash-confirm-request'));
    $service->decide($proof->public_id, $student, true, '127.0.0.1'); $key = fcKey('cash-confirm-failure'); $cash = app(CashSettlementService::class);
    CashReceipt::creating(function () { throw new RuntimeException('Fallo de comprobante'); });
    try { expect(fn () => $cash->settleConfirmed($shift->public_id, $wallet, 2000, $key, FC_ACTOR, 'Recarga', true, $proof->public_id))->toThrow(RuntimeException::class); }
    finally { CashReceipt::flushEventListeners(); }
    expect($proof->fresh()->status)->toBe('CONFIRMED')->and($proof->fresh()->settlement_key)->toBeNull()
        ->and($wallet->fresh()->available_balance_cents)->toBe(10000)->and(FinancialTransaction::where('idempotency_key', $key)->exists())->toBeFalse();
    $cash->settleConfirmed($shift->public_id, $wallet, 2000, $key, FC_ACTOR, 'Recarga', true, $proof->public_id);
    expect($wallet->fresh()->available_balance_cents)->toBe(12000)->and($proof->fresh()->status)->toBe('CONSUMED');
});

test('consumed confirmations retry after expiry and closing but never authorize another key', function () {
    [$student, $wallet, $shift] = studentCashCase(); $service = app(CashOperationConfirmationService::class); $cash = app(CashSettlementService::class);
    $proof = $service->request($shift->public_id, $wallet, 'WITHDRAWAL', 2000, FC_ACTOR, 'Retiro', fcKey('cash-confirm-request'));
    $service->decide($proof->public_id, $student, true, null); $key = fcKey('cash-confirm-execute');
    $result = $cash->settleConfirmed($shift->public_id, $wallet, 2000, $key, FC_ACTOR, 'Retiro', false, $proof->public_id);
    app(CashShiftService::class)->close($shift->public_id, 8000, fcKey('cash-confirm-close'), FC_ACTOR, 'Cierre');
    $proof->refresh()->update(['expires_at' => now()->subSecond()]);
    expect($cash->settleConfirmed($shift->public_id, $wallet, 2000, $key, FC_ACTOR, 'Retiro', false, $proof->public_id)->folio)->toBe($result->folio);
    expect(fn () => $cash->settleConfirmed($shift->public_id, $wallet, 2000, fcKey('cash-confirm-another'), FC_ACTOR, 'Retiro', false, $proof->public_id))->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(8000)->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(1);
});

test('blocked cash limits preserve their alert without consuming the student confirmation', function () {
    [$student, $wallet, $shift] = studentCashCase();
    fcLimit($wallet, ['operation' => 'RETIRO', 'max_amount_cents' => 500]);
    $service = app(CashOperationConfirmationService::class); $proof = $service->request($shift->public_id, $wallet, 'WITHDRAWAL', 2000, FC_ACTOR, 'Retiro', fcKey('cash-confirm-request'));
    $service->decide($proof->public_id, $student, true, null);
    expect(fn () => app(CashSettlementService::class)->settleConfirmed($shift->public_id, $wallet, 2000, fcKey('cash-confirm-blocked'), FC_ACTOR, 'Retiro', false, $proof->public_id))->toThrow(FinancialLimitExceededException::class);
    expect(fcAlertsForWallet($wallet)->count())->toBe(1)->and($proof->fresh()->status)->toBe('CONFIRMED')->and($wallet->fresh()->available_balance_cents)->toBe(10000);
});

test('cash confirmation API requires its cash scope and association grant and web settlement uses a separate student session', function () {
    [$headers, $actor] = studentCashToken($this, ['financial:write']); [$student, $wallet, $shift] = studentCashCase($actor);
    studentCashGrant($actor, $shift->cashRegister->association_id);
    $url = '/api/v1/financial/cash/associations/'.$shift->cashRegister->association_id.'/shifts/'.$shift->public_id.'/confirmations';
    $this->postJson($url, [], $headers)->assertForbidden();
    $operator = studentCashUser(); $operatorActor = 'user:'.$operator->getKey(); [$student2, $wallet2, $shift2] = studentCashCase($operatorActor);
    $base = '/finanzas/caja/asociaciones/'.$shift2->cashRegister->association_id.'/shifts/'.$shift2->public_id;
    studentCashGrant($operatorActor, $shift2->cashRegister->association_id);
    $body = ['wallet_id' => $wallet2->public_id, 'operation' => 'TOPUP', 'amount_cents' => 2000, 'reason' => 'Recarga'];
    $this->actingAs($operator)->postJson($base.'/confirmations', $body, ['Idempotency-Key' => fcKey('cash-confirm-request')])->assertOk();
    $proof = CashOperationConfirmation::where('wallet_id', $wallet2->public_id)->firstOrFail();
    $this->post('/finanzas/confirmaciones-caja/'.$proof->public_id.'/approve')->assertNotFound();
    $this->actingAs($student2)->post('/finanzas/confirmaciones-caja/'.$proof->public_id.'/approve')->assertRedirect();
    $this->get('/finanzas/confirmaciones-caja')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Financial/CashConfirmations')->has('confirmations.data', 1));
    $this->actingAs($operator)->postJson($base.'/topups', array_merge($body, ['confirmation_id' => $proof->public_id]), ['Idempotency-Key' => fcKey('cash-confirm-execute')])->assertOk();
    expect($wallet2->fresh()->available_balance_cents)->toBe(12000);
});
