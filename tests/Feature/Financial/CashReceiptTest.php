<?php

use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Enums\CashMovementType;
use App\Domains\Financial\Enums\CashRegisterStatus;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashRegisterChange;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\CashSettlementService;
use App\Domains\Financial\Services\CashRegisterAdministrationService;
use App\Models\ServiceClient;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () {
    cashReceiptCleanup();
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->withoutVite();
});
afterEach(function () { cashReceiptCleanup(); });

function cashReceiptCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Comprobantes requieren la base financiera de pruebas.');
    $ids = CashRegister::where('association_id', 'like', 'test-fc-cash-receipt-%')->pluck('id');
    $shifts = CashShift::whereIn('cash_register_id', $ids)->pluck('id');
    $movements = CashMovement::whereIn('cash_shift_id', $shifts)->pluck('id');
    CashReceipt::whereIn('cash_movement_id', $movements)->delete();
    CashMovement::whereIn('id', $movements)->delete();
    CashShift::whereIn('id', $shifts)->delete(); CashRegisterChange::whereIn('cash_register_id', $ids)->delete();
    CashRegister::whereIn('id', $ids)->delete();
    ServiceClient::where('client_id', 'like', 'test-fc-cash-receipt-%')->delete();
    User::where('email', 'like', 'test-fc-cash-receipt-%')->delete();
    fcCleanup();
}
function cashReceiptShift(string $actor = FC_ACTOR): CashShift
{
    $service = app(CashShiftService::class);
    $register = $service->createRegister(fcKey('cash-receipt-association'), 'Caja original', 'MXN', $actor);
    return $service->open($register->public_id, $actor, 10000, fcKey('cash-receipt-open'));
}
function cashReceiptGrant(string $actor, string $association, array $actions): void
{
    app()->instance(CashAuthorizationProvider::class, new class($actor, $association, $actions) implements CashAuthorizationProvider {
        public function __construct(private string $actor, private string $association, private array $actions) {}
        public function allows(string $actorId, string $associationId, string $action): bool
        {
            return $actorId === $this->actor && $associationId === $this->association && in_array($action, $this->actions, true);
        }
    });
    foreach (app('router')->getRoutes() as $route) $route->flushController();
}
function cashReceiptToken($test, array $scopes): array
{
    $id = fcKey('cash-receipt-client');
    ServiceClient::create(['client_id' => $id, 'name' => 'Comprobante prueba', 'secret_hash' => Hash::make('test-secret'), 'scopes' => $scopes, 'active' => true]);
    $token = $test->postJson('/api/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $id,
        'client_secret' => 'test-secret', 'scope' => implode(' ', $scopes)])->assertOk()->json('access_token');
    return [['Authorization' => 'Bearer '.$token], 'service:'.$id];
}

test('cash receipts preserve one original folio and snapshot after retries closing and register renaming', function () {
    $shift = cashReceiptShift(); $service = app(CashShiftService::class); $key = fcKey('cash-receipt-in');
    $movement = $service->addMovement($shift->public_id, CashMovementType::CASH_IN, 500, $key, FC_ACTOR, 'Ingreso');
    $receipt = $movement->receipt; $original = $receipt->snapshot;
    expect($receipt->folio)->toStartWith('CAJ-')->and(strlen($receipt->folio))->toBeLessThanOrEqual(50)
        ->and($original['cash_register_name'])->toBe('Caja original')->and($original['amount_cents'])->toBe(500);
    app(CashRegisterAdministrationService::class)->update($shift->cashRegister->association_id, $shift->cashRegister->public_id,
        'Caja renombrada', CashRegisterStatus::ACTIVE, 1, FC_ACTOR, 'Renombrar', fcKey('cash-receipt-rename'));
    $service->close($shift->public_id, 10500, fcKey('cash-receipt-close'), FC_ACTOR, 'Arqueo');
    $retry = $service->addMovement(strtoupper($shift->public_id), CashMovementType::CASH_IN, 500, $key, FC_ACTOR, 'Ingreso');
    expect($retry->receipt->snapshot)->toBe($original)->and(CashReceipt::where('cash_movement_id', $movement->id)->count())->toBe(1);
});

test('cash receipts link each cash settlement folio to its completed financial transaction without duplicate money', function () {
    $shift = cashReceiptShift(); $wallet = fcWallet('cash-receipt-wallet'); $service = app(CashSettlementService::class);
    $keys = ['topUp' => fcKey('cash-receipt-topup'), 'withdraw' => fcKey('cash-receipt-withdraw')];
    foreach (['topUp' => 1000, 'withdraw' => 300] as $action => $amount) {
        $operation = $service->$action($shift->public_id, $wallet, $amount, $keys[$action], FC_ACTOR, 'Entrega');
        $movement = CashMovement::where('idempotency_key', $keys[$action])->firstOrFail(); $receipt = $movement->receipt;
        $transaction = FinancialTransaction::where('idempotency_key', $keys[$action])->firstOrFail();
        expect($operation->folio)->toBe($receipt->folio)->and($receipt->snapshot['financial_transaction_id'])->toBe(strtolower($transaction->public_id));
        expect($service->$action($shift->public_id, $wallet, $amount, $keys[$action], FC_ACTOR, 'Entrega')->folio)->toBe($receipt->folio);
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(700)
        ->and(CashReceipt::whereIn('cash_movement_id', CashMovement::where('cash_shift_id', $shift->id)->select('id'))->count())->toBe(2);
});

test('cash receipts roll back wallet ledger operation and movement if receipt issuance fails and allow retry', function () {
    $shift = cashReceiptShift(); $wallet = fcWallet('cash-receipt-rollback'); $key = fcKey('cash-receipt-failed');
    CashReceipt::creating(function () { throw new RuntimeException('Fallo de comprobante'); });
    try {
        expect(fn () => app(CashSettlementService::class)->topUp($shift->public_id, $wallet, 1000, $key, FC_ACTOR, 'Recarga'))->toThrow(RuntimeException::class);
        expect($wallet->fresh()->available_balance_cents)->toBe(0)
            ->and(CashMovement::where('idempotency_key', $key)->exists())->toBeFalse()
            ->and(FinancialTransaction::where('idempotency_key', $key)->exists())->toBeFalse()
            ->and(TopUp::where('wallet_id', $wallet->public_id)->count())->toBe(0);
    } finally { CashReceipt::flushEventListeners(); }
    expect(app(CashSettlementService::class)->topUp($shift->public_id, $wallet, 1000, $key, FC_ACTOR, 'Recarga')->folio)->toStartWith('CAJ-');
    expect($wallet->fresh()->available_balance_cents)->toBe(1000);
});

test('cash receipts roll back a manual movement when its receipt fails', function () {
    $shift = cashReceiptShift(); $key = fcKey('cash-receipt-manual');
    CashReceipt::creating(function () { throw new RuntimeException('Fallo de comprobante'); });
    try {
        expect(fn () => app(CashShiftService::class)->addMovement($shift->public_id, CashMovementType::ADJUSTMENT, -100, $key, FC_ACTOR, 'Ajuste'))->toThrow(RuntimeException::class);
        expect(CashMovement::where('idempotency_key', $key)->exists())->toBeFalse();
        expect(app(CashShiftService::class)->getSummary($shift->public_id)['expected_amount_cents'])->toBe(10000);
    } finally { CashReceipt::flushEventListeners(); }
});

test('cash receipts do not fabricate snapshots for historical movements without a receipt', function () {
    $shift = cashReceiptShift(); $service = app(CashShiftService::class); $key = fcKey('cash-receipt-legacy');
    // Simulates a pre-migration movement without issuing a document from today's register state.
    $movement = CashMovement::create(['public_id' => (string) str()->uuid(), 'cash_shift_id' => $shift->id,
        'type' => CashMovementType::CASH_IN, 'amount_cents' => 100, 'idempotency_key' => $key,
        'request_hash' => $service->hash([$shift->public_id, 'CASH_IN', 100, FC_ACTOR, 'Anterior', null, null]),
        'actor_id' => FC_ACTOR, 'reason' => 'Anterior']);
    $retry = $service->addMovement($shift->public_id, CashMovementType::CASH_IN, 100, $key, FC_ACTOR, 'Anterior');
    expect($retry->receipt)->toBeNull()->and(CashReceipt::where('cash_movement_id', $movement->id)->count())->toBe(0);
});

test('cash receipt api requires read scope and association permission and never issues on lookup', function () {
    $shift = cashReceiptShift(); $foreign = cashReceiptShift();
    $first = app(CashShiftService::class)->addMovement($shift->public_id, CashMovementType::CASH_IN, 100, fcKey('cash-receipt-in'), FC_ACTOR, 'Ingreso')->receipt;
    $other = app(CashShiftService::class)->addMovement($foreign->public_id, CashMovementType::CASH_IN, 100, fcKey('cash-receipt-other'), FC_ACTOR, 'Otro')->receipt;
    $base = '/api/v1/financial/cash/associations/'.$shift->cashRegister->association_id;
    $this->getJson($base.'/receipts/'.$first->public_id)->assertUnauthorized();
    [$headers, $actor] = cashReceiptToken($this, ['financial:cash:operate']);
    cashReceiptGrant($actor, $shift->cashRegister->association_id, ['read']);
    $this->getJson($base.'/receipts/'.$first->public_id, $headers)->assertForbidden();
    [$headers, $actor] = cashReceiptToken($this, ['financial:cash:read']);
    $this->getJson($base.'/receipts/'.$first->public_id, $headers)->assertForbidden();
    cashReceiptGrant($actor, $shift->cashRegister->association_id, ['read']);
    foreach ([1, 2] as $attempt) $this->getJson($base.'/receipts/'.$first->public_id, $headers)->assertOk()->assertJsonPath('data.folio', $first->folio);
    $this->getJson($base.'/receipts/'.$other->public_id, $headers)->assertNotFound();
    $this->getJson($base.'/receipts/00000000-0000-0000-0000-000000000000', $headers)->assertNotFound();
    expect(CashReceipt::where('cash_movement_id', $first->cash_movement_id)->count())->toBe(1);
});

test('cash receipt web print uses original snapshot checks session association and current permission', function () {
    $user = User::factory()->create(['email' => fcKey('cash-receipt-user').'@example.test', 'email_verified_at' => now()]);
    $shift = cashReceiptShift(); $foreign = cashReceiptShift();
    $receipt = app(CashShiftService::class)->addMovement($shift->public_id, CashMovementType::CASH_IN, 100, fcKey('cash-receipt-in'), FC_ACTOR, 'Ingreso')->receipt;
    $base = '/finanzas/caja/asociaciones/'.$shift->cashRegister->association_id;
    $url = $base.'/receipts/'.$receipt->public_id.'/imprimir';
    $this->get($url)->assertRedirect('/login');
    $this->actingAs($user)->get($url)->assertForbidden();
    cashReceiptGrant('user:'.$user->getKey(), $shift->cashRegister->association_id, ['read']);
    foreach ([1, 2] as $attempt) $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Financial/CashReceipt')->where('receipt', $receipt->snapshot));
    $this->getJson($base.'/receipts/'.$receipt->public_id)->assertOk()->assertJsonPath('data.folio', $receipt->folio);
    $this->get('/finanzas/caja/asociaciones/'.$foreign->cashRegister->association_id.'/receipts/'.$receipt->public_id.'/imprimir')->assertForbidden();
    cashReceiptGrant('user:'.$user->getKey(), $shift->cashRegister->association_id, ['operate']);
    $this->get($url)->assertForbidden();
    expect(CashReceipt::where('cash_movement_id', $receipt->cash_movement_id)->count())->toBe(1);
});

test('cash receipt identifiers are returned by settlement and movement endpoints', function () {
    [$headers, $actor] = cashReceiptToken($this, ['financial:cash:operate', 'financial:cash:read']);
    $shift = cashReceiptShift($actor); $wallet = fcWallet('cash-receipt-api-wallet');
    cashReceiptGrant($actor, $shift->cashRegister->association_id, ['read', 'operate']);
    $base = '/api/v1/financial/cash/associations/'.$shift->cashRegister->association_id;
    $headers['Idempotency-Key'] = fcKey('cash-receipt-api-topup');
    $url = $base.'/shifts/'.$shift->public_id.'/topups';
    $body = ['wallet_id' => $wallet->public_id, 'amount_cents' => 1000, 'reason' => 'Recarga'];
    $response = $this->postJson($url, $body, $headers)->assertOk(); $id = $response->json('data.receipt_id'); $folio = $response->json('data.folio');
    expect($id)->toBeString()->and($folio)->toStartWith('CAJ-');
    $this->postJson($url, $body, $headers)->assertOk()->assertJsonPath('data.receipt_id', $id)->assertJsonPath('data.folio', $folio);
    $this->getJson($base.'/shifts/'.$shift->public_id.'/movements', $headers)->assertOk()->assertJsonPath('data.0.receipt_id', $id);
    $this->getJson($base.'/receipts/'.$id, $headers)->assertOk()->assertJsonPath('data.amount_cents', 1000)->assertJsonPath('data.actor_id', $actor);
});
