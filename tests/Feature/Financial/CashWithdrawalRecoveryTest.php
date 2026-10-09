<?php

use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Enums\CashMovementType;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashRegisterChange;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialWithdrawalRecovery;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\CashSettlementService;
use App\Domains\Financial\Services\CashWithdrawalRecoveryService;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Models\User;
use App\Models\ServiceClient;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;
use Carbon\CarbonImmutable;
use App\Domains\Financial\Adapters\CashShiftReconciliationSource;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () {
    cashRecoveryCleanup();
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->withoutVite();
});
afterEach(function () { cashRecoveryCleanup(); });

function cashRecoveryCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Recuperación de caja requiere la base financiera de pruebas.');
    $walletIds = Wallet::where('owner_id', 'like', 'test-fc-cash-recovery-%')->pluck('public_id');
    $refundIds = FinancialRefundRequest::whereIn('wallet_id', $walletIds)->pluck('public_id');
    FinancialWithdrawalRecovery::whereIn('refund_request_id', $refundIds)->delete();
    FinancialRefundRequest::whereIn('public_id', $refundIds)->delete();
    $registers = CashRegister::where('association_id', 'like', 'test-fc-cash-recovery-%')->pluck('id');
    $shifts = CashShift::whereIn('cash_register_id', $registers)->pluck('id');
    $movements = CashMovement::whereIn('cash_shift_id', $shifts)->pluck('id');
    CashReceipt::whereIn('cash_movement_id', $movements)->delete();
    CashMovement::whereIn('id', $movements)->delete();
    CashShift::whereIn('id', $shifts)->delete(); CashRegisterChange::whereIn('cash_register_id', $registers)->delete();
    CashRegister::whereIn('id', $registers)->delete();
    ServiceClient::where('client_id', 'like', 'test-fc-cash-recovery-%')->delete();
    User::where('email', 'like', 'test-fc-cash-recovery-%')->delete();
    fcCleanup();
}
function cashRecoveryCase(string $actor = FC_ACTOR, bool $approved = true): array
{
    $wallet = fcWallet('cash-recovery-wallet-'.str()->uuid(), 10000);
    $cash = app(CashShiftService::class);
    $register = $cash->createRegister(fcKey('cash-recovery-association'), 'Caja retiro', 'MXN', $actor);
    $shift = $cash->open($register->public_id, $actor, 10000, fcKey('cash-recovery-open'));
    $key = fcKey('cash-recovery-withdraw');
    app(CashSettlementService::class)->withdraw($shift->public_id, $wallet, 4000, $key, $actor, 'Retiro original');
    $original = FinancialTransaction::where('idempotency_key', $key)->firstOrFail();
    $requestTx = app(FinancialAdjustmentService::class)->refund($original, 2000, fcKey('cash-recovery-request'), FC_ACTOR, 'Devolución parcial');
    $refund = FinancialRefundRequest::where('request_transaction_id', $requestTx->public_id)->firstOrFail();
    if ($approved) $refund = app(FinancialAdjustmentService::class)->approveRefund($refund, FC_ACTOR, 'Aprobada');
    return [$shift, $wallet, $original, $refund];
}
function cashRecoveryReceiveShift(CashShift $origin, string $actor = FC_ACTOR, string $currency = 'MXN', ?string $association = null): CashShift
{
    $service = app(CashShiftService::class);
    $register = $service->createRegister($association ?? $origin->cashRegister->association_id, 'Caja recepción', $currency, $actor);
    return $service->open($register->public_id, $actor, 0, fcKey('cash-recovery-receiver'));
}
function cashRecoveryGrant(string $actor, string $association, array $actions): void
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
function cashRecoveryToken($test, array $scopes): array
{
    $id = fcKey('cash-recovery-client');
    ServiceClient::create(['name' => 'Recuperación prueba', 'client_id' => $id, 'secret_hash' => Hash::make('test-secret'), 'scopes' => $scopes, 'active' => true]);
    $token = $test->postJson('/api/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $id,
        'client_secret' => 'test-secret', 'scope' => implode(' ', $scopes)])->assertOk()->json('access_token');
    return [['Authorization' => 'Bearer '.$token], 'service:'.$id];
}

test('cash withdrawal recovery records one physical entry before the separately executed wallet refund', function () {
    [$origin, $wallet, $original, $refund] = cashRecoveryCase();
    $receiver = cashRecoveryReceiveShift($origin); $key = fcKey('cash-recovery-capture'); $service = app(CashWithdrawalRecoveryService::class);
    $recovery = $service->recover($origin->cashRegister->association_id, $receiver->public_id, $refund, $key, FC_ACTOR, 'Efectivo recibido');
    $receipt = $recovery->cashMovement->receipt;
    expect($wallet->fresh()->available_balance_cents)->toBe(6000)
        ->and(app(CashShiftService::class)->getSummary($receiver->public_id)['expected_amount_cents'])->toBe(2000)
        ->and($refund->fresh()->status->value)->toBe('APROBADA')->and($recovery->recovery_reference)->toBe($receipt->folio);
    expect($receipt->snapshot['refund_request_id'])->toBe(strtolower($refund->public_id));
    app(CashShiftService::class)->close($receiver->public_id, 2000, fcKey('cash-recovery-close'), FC_ACTOR, 'Cierre');
    $executeKey = fcKey('cash-recovery-complete');
    $first = app(FinancialAdjustmentService::class)->completeRefund($refund, $executeKey, FC_ACTOR);
    $retry = app(FinancialAdjustmentService::class)->completeRefund($refund, $executeKey, FC_ACTOR);
    expect(fcSameId($first->public_id, $retry->public_id))->toBeTrue()->and($wallet->fresh()->available_balance_cents)->toBe(8000);
    expect($service->recover($origin->cashRegister->association_id, $receiver->public_id, $refund, $key, FC_ACTOR, 'Efectivo recibido')->recovery_reference)->toBe($receipt->folio);
    expect(CashMovement::where('idempotency_key', $key)->count())->toBe(1);
});

test('cash withdrawal recovery rejects generic receipt confirmation and completion without cash evidence', function () {
    [$shift, $wallet, $original, $refund] = cashRecoveryCase(); $finance = app(FinancialAdjustmentService::class);
    expect(fn () => $finance->confirmWithdrawalRecovery($refund, fcKey('cash-recovery-fake'), FC_ACTOR, 'Texto'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $finance->completeRefund($refund, fcKey('cash-recovery-complete'), FC_ACTOR))->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(6000)->and(FinancialWithdrawalRecovery::where('refund_request_id', $refund->public_id)->exists())->toBeFalse();
    // Simulates a previously recorded generic confirmation that has no physical cash evidence.
    FinancialWithdrawalRecovery::create(['public_id' => (string) str()->uuid(), 'refund_request_id' => $refund->public_id,
        'withdrawal_id' => $original->reference_id, 'amount_cents' => 2000, 'currency' => 'MXN',
        'recovery_reference' => fcKey('cash-recovery-historical'), 'confirmed_by' => FC_ACTOR, 'confirmed_at' => now()]);
    expect(fn () => $finance->completeRefund($refund, fcKey('cash-recovery-complete'), FC_ACTOR))->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(6000);
});

test('cash withdrawal recovery rejects pending requests wrong association currency and cashier', function () {
    [$shift, $wallet, $original, $pending] = cashRecoveryCase(FC_ACTOR, false); $service = app(CashWithdrawalRecoveryService::class);
    $assoc = $shift->cashRegister->association_id; $key = fcKey('cash-recovery-invalid');
    expect(fn () => $service->recover($assoc, $shift->public_id, $pending, $key, FC_ACTOR, 'Recibir'))->toThrow(InvalidArgumentException::class);
    $refund = app(FinancialAdjustmentService::class)->approveRefund($pending, FC_ACTOR, 'Aprobada');
    $foreign = cashRecoveryReceiveShift($shift, FC_ACTOR, 'MXN', fcKey('cash-recovery-other-assoc'));
    expect(fn () => $service->recover($foreign->cashRegister->association_id, $foreign->public_id, $refund, $key, FC_ACTOR, 'Recibir'))->toThrow(InvalidArgumentException::class);
    $usd = cashRecoveryReceiveShift($shift, FC_ACTOR, 'USD');
    expect(fn () => $service->recover($assoc, $usd->public_id, $refund, $key, FC_ACTOR, 'Recibir'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->recover($assoc, $shift->public_id, $refund, $key, 'other-cashier', 'Recibir'))->toThrow(InvalidArgumentException::class);
    expect(FinancialWithdrawalRecovery::where('refund_request_id', $refund->public_id)->count())->toBe(0)->and($wallet->fresh()->available_balance_cents)->toBe(6000);
});

test('cash withdrawal recovery rejects changed retries and a second capture with another key', function () {
    [$shift, $wallet, $original, $refund] = cashRecoveryCase(); $service = app(CashWithdrawalRecoveryService::class); $assoc = $shift->cashRegister->association_id;
    $key = fcKey('cash-recovery-capture'); $service->recover($assoc, $shift->public_id, $refund, $key, FC_ACTOR, 'Recibir');
    expect(fn () => $service->recover($assoc, $shift->public_id, $refund, $key, FC_ACTOR, 'Motivo distinto'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->recover($assoc, $shift->public_id, $refund, fcKey('cash-recovery-second'), FC_ACTOR, 'Recibir'))->toThrow(InvalidArgumentException::class);
    expect(app(CashShiftService::class)->getSummary($shift->public_id)['expected_amount_cents'])->toBe(8000);
});

test('cash withdrawal recovery rolls back receipt movement and confirmation if recovery persistence fails', function () {
    [$shift, $wallet, $original, $refund] = cashRecoveryCase(); $key = fcKey('cash-recovery-failed'); $service = app(CashWithdrawalRecoveryService::class);
    FinancialWithdrawalRecovery::creating(function () { throw new RuntimeException('Fallo de confirmación'); });
    try {
        expect(fn () => $service->recover($shift->cashRegister->association_id, $shift->public_id, $refund, $key, FC_ACTOR, 'Recibir'))->toThrow(RuntimeException::class);
        expect(CashMovement::where('idempotency_key', $key)->exists())->toBeFalse()
            ->and(FinancialWithdrawalRecovery::where('refund_request_id', $refund->public_id)->exists())->toBeFalse()
            ->and($wallet->fresh()->available_balance_cents)->toBe(6000)
            ->and(CashReceipt::whereIn('cash_movement_id', CashMovement::where('cash_shift_id', $shift->id)->select('id'))->count())->toBe(1);
    } finally { FinancialWithdrawalRecovery::flushEventListeners(); }
    expect($service->recover($shift->cashRegister->association_id, $shift->public_id, $refund, $key, FC_ACTOR, 'Recibir')->cash_movement_id)->not->toBeNull();
});

test('cash withdrawal recovery cannot be fabricated through manual cash movements and reconciles closed physical cash', function () {
    [$shift, $wallet, $original, $refund] = cashRecoveryCase(); $cash = app(CashShiftService::class);
    expect(fn () => $cash->addMovement($shift->public_id, CashMovementType::WITHDRAWAL_RECOVERY, 2000, fcKey('cash-recovery-fake'), FC_ACTOR, 'Falso'))->toThrow(InvalidArgumentException::class);
    app(CashWithdrawalRecoveryService::class)->recover($shift->cashRegister->association_id, $shift->public_id, $refund, fcKey('cash-recovery-real'), FC_ACTOR, 'Recibir');
    $cash->close($shift->public_id, 8000, fcKey('cash-recovery-close'), FC_ACTOR, 'Arqueo');
    $now = CarbonImmutable::now();
    $differences = iterator_to_array(app(CashShiftReconciliationSource::class)->differences($now->startOfDay(), $now->addDay()->startOfDay(), null));
    expect(collect($differences)->filter(fn ($item) => fcSameId($item->entityId, $shift->public_id))->count())->toBe(0);
});

test('cash withdrawal recovery api separates its scope and association permission from ordinary cash operations', function () {
    [$shift, $wallet, $original, $refund] = cashRecoveryCase();
    $url = '/api/v1/financial/cash/associations/'.$shift->cashRegister->association_id.'/shifts/'.$shift->public_id.'/withdrawal-refunds/'.$refund->public_id.'/recover';
    $this->postJson($url, [])->assertUnauthorized();
    foreach (['financial:cash:operate', 'financial:refund:recover', 'financial:cash:read'] as $scope) {
        [$headers, $actor] = cashRecoveryToken($this, [$scope]);
        cashRecoveryGrant($actor, $shift->cashRegister->association_id, ['recover']);
        $this->postJson($url, [], $headers)->assertForbidden();
    }
    [$headers, $actor] = cashRecoveryToken($this, ['financial:cash:recover']);
    cashRecoveryGrant($actor, $shift->cashRegister->association_id, ['operate']);
    $this->postJson($url, [], $headers)->assertForbidden();
});

test('cash withdrawal recovery api records trusted actor returns receipt and scopes its request list', function () {
    [$headers, $actor] = cashRecoveryToken($this, ['financial:cash:read', 'financial:cash:recover']);
    [$shift, $wallet, $original, $refund] = cashRecoveryCase($actor); [$foreign, $foreignWallet, $foreignOriginal, $foreignRefund] = cashRecoveryCase($actor);
    $assoc = $shift->cashRegister->association_id; $base = '/api/v1/financial/cash/associations/'.$assoc;
    cashRecoveryGrant($actor, $assoc, ['read', 'recover']);
    $this->getJson($base.'/withdrawal-refunds', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', strtolower($refund->public_id));
    $url = $base.'/shifts/'.$shift->public_id.'/withdrawal-refunds/'.$refund->public_id.'/recover';
    $this->postJson($url, ['reason' => 'Recibir'], $headers)->assertUnprocessable();
    $headers['Idempotency-Key'] = fcKey('cash-recovery-api');
    $response = $this->postJson($url, ['reason' => 'Recibir', 'confirmed_by' => 'forged'], $headers)->assertOk()->assertJsonPath('data.confirmed_by', $actor);
    $id = $response->json('data.id'); $receipt = $response->json('data.receipt_id');
    $this->postJson($url, ['reason' => 'Recibir'], $headers)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.receipt_id', $receipt);
    $this->getJson($base.'/receipts/'.$receipt, $headers)->assertOk()->assertJsonPath('data.movement_type', 'WITHDRAWAL_RECOVERY');
    $this->postJson($base.'/shifts/'.$shift->public_id.'/withdrawal-refunds/'.$foreignRefund->public_id.'/recover', ['reason' => 'Recibir'], $headers)->assertNotFound();
    expect($wallet->fresh()->available_balance_cents)->toBe(6000);
});

test('cash withdrawal recovery web uses session actor and blocks foreign operators and associations', function () {
    $user = User::factory()->create(['email' => fcKey('cash-recovery-user').'@example.test', 'email_verified_at' => now()]);
    $actor = 'user:'.$user->getKey(); [$shift, $wallet, $original, $refund] = cashRecoveryCase($actor);
    $assoc = $shift->cashRegister->association_id; $base = '/finanzas/caja/asociaciones/'.$assoc;
    $url = $base.'/shifts/'.$shift->public_id.'/withdrawal-refunds/'.$refund->public_id.'/recover';
    $this->postJson($url, [])->assertUnauthorized(); $this->actingAs($user)->postJson($url, [])->assertForbidden();
    cashRecoveryGrant($actor, $assoc, ['read', 'recover']);
    $this->getJson($base.'/withdrawal-refunds')->assertOk()->assertJsonCount(1, 'data');
    $otherShift = cashRecoveryReceiveShift($shift, FC_ACTOR);
    $this->postJson($base.'/shifts/'.$otherShift->public_id.'/withdrawal-refunds/'.$refund->public_id.'/recover', ['reason' => 'Recibir'])->assertForbidden();
    [$foreign, $foreignWallet, $foreignOriginal, $foreignRefund] = cashRecoveryCase($actor);
    $this->postJson($base.'/shifts/'.$shift->public_id.'/withdrawal-refunds/'.$foreignRefund->public_id.'/recover', ['reason' => 'Recibir'])->assertNotFound();
    $headers = ['Idempotency-Key' => fcKey('cash-recovery-web')];
    $this->postJson($url, ['reason' => 'Recibir', 'amount_cents' => 1], $headers)->assertUnprocessable();
    $this->postJson($url, ['reason' => 'Recibir', 'confirmed_by' => 'forged'], $headers)->assertOk()->assertJsonPath('data.confirmed_by', $actor);
    $this->postJson($url, ['reason' => 'Recibir'], $headers)->assertOk();
    expect($wallet->fresh()->available_balance_cents)->toBe(6000);
});
