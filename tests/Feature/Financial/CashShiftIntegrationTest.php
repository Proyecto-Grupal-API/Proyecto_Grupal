<?php

use App\Domains\Financial\Adapters\CashShiftReconciliationSource;
use App\Domains\Financial\Enums\CashMovementType;
use App\Domains\Financial\Enums\CashShiftStatus;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\CashSettlementService;
use App\Domains\Financial\Services\ReconciliationService;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use Carbon\CarbonImmutable;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () { cash28Cleanup(); });
afterEach(function () { cash28Cleanup(); });
function cash28Cleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Caja requiere la base financiera de pruebas.');
    $registers = CashRegister::where('association_id', 'like', 'test-fc-cash28-%')->pluck('id');
    $shifts = CashShift::whereIn('cash_register_id', $registers)->pluck('id');
    CashMovement::whereIn('cash_shift_id', $shifts)->delete();
    CashShift::whereIn('id', $shifts)->delete(); CashRegister::whereIn('id', $registers)->delete();
    fcCleanup();
}
function cash28Shift(int $opening = 10000): CashShift
{
    $service = app(CashShiftService::class);
    $register = $service->createRegister(fcKey('cash28-association'), 'Caja de prueba 2.8', 'MXN', FC_ACTOR);
    return $service->open($register->public_id, FC_ACTOR, $opening, fcKey('cash28-open'));
}

test('cash shift opening is scoped to an association and retries without another active shift', function () {
    $shift = cash28Shift(); $service = app(CashShiftService::class);
    $retry = $service->open($shift->cashRegister->public_id, FC_ACTOR, 10000, $shift->opening_key);
    expect(fcSameId($retry->public_id, $shift->public_id))->toBeTrue()->and($retry->status)->toBe(CashShiftStatus::OPEN);
    expect(fn () => $service->open($shift->cashRegister->public_id, FC_ACTOR, 20000, $shift->opening_key))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->open($shift->cashRegister->public_id, FC_ACTOR, 10000, fcKey('cash28-other-open')))->toThrow(InvalidArgumentException::class);
    expect(CashShift::where('cash_register_id', $shift->cash_register_id)->count())->toBe(1);
});

test('cash movements audit the actor retry once reject changed keys and cannot fabricate topups', function () {
    $shift = cash28Shift(); $service = app(CashShiftService::class); $key = fcKey('cash28-in');
    $first = $service->addMovement($shift->public_id, CashMovementType::CASH_IN, 1000, $key, FC_ACTOR, 'Ingreso físico');
    $retry = $service->addMovement(strtoupper($shift->public_id), CashMovementType::CASH_IN, 1000, $key, FC_ACTOR, 'Ingreso físico');
    expect(fcSameId($first->public_id, $retry->public_id))->toBeTrue()->and($first->actor_id)->toBe(FC_ACTOR);
    expect(fn () => $service->addMovement($shift->public_id, CashMovementType::CASH_IN, 2000, $key, FC_ACTOR, 'Ingreso físico'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->addMovement($shift->public_id, CashMovementType::TOPUP, 1000, fcKey('cash28-fake'), FC_ACTOR, 'Falso'))->toThrow(InvalidArgumentException::class);
    expect($service->getSummary($shift->public_id)['expected_amount_cents'])->toBe(11000);
});

test('cash close snapshots signed adjustments and differences and forbids late movements', function () {
    $shift = cash28Shift(); $service = app(CashShiftService::class);
    $service->addMovement($shift->public_id, CashMovementType::CASH_IN, 2000, fcKey('cash28-in'), FC_ACTOR, 'Entrada');
    $service->addMovement($shift->public_id, CashMovementType::CASH_OUT, 3000, fcKey('cash28-out'), FC_ACTOR, 'Salida');
    $service->addMovement($shift->public_id, CashMovementType::ADJUSTMENT, -500, fcKey('cash28-adjust'), FC_ACTOR, 'Corrección justificada');
    $key = fcKey('cash28-close'); $closed = $service->close($shift->public_id, 8000, $key, FC_ACTOR, 'Arqueo realizado');
    expect($closed['expected_amount_cents'])->toBe(8500)->and($closed['difference_cents'])->toBe(-500)
        ->and($closed['shift']->closed_by)->toBe(FC_ACTOR)->and($closed['shift']->status)->toBe(CashShiftStatus::CLOSED);
    expect($service->close($shift->public_id, 8000, $key, FC_ACTOR, 'Arqueo realizado')['difference_cents'])->toBe(-500);
    expect(fn () => $service->close($shift->public_id, 9000, $key, FC_ACTOR, 'Arqueo realizado'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->addMovement($shift->public_id, CashMovementType::CASH_IN, 100, fcKey('cash28-late'), FC_ACTOR, 'Tardío'))->toThrow(InvalidArgumentException::class);
});

test('cash settlement links real topups and withdrawals atomically and retries after shift close', function () {
    $shift = cash28Shift(); $wallet = fcWallet('cash28-wallet', 10000); $settlements = app(CashSettlementService::class);
    $topupKey = fcKey('cash28-topup'); $withdrawKey = fcKey('cash28-withdraw');
    $topup = $settlements->topUp($shift->public_id, $wallet, 3000, $topupKey, FC_ACTOR, 'Efectivo recibido');
    $withdrawal = $settlements->withdraw($shift->public_id, $wallet->fresh(), 2000, $withdrawKey, FC_ACTOR, 'Efectivo entregado');
    expect(fcSameId($topup->cash_shift_id, $shift->public_id))->toBeTrue()
        ->and(fcSameId($withdrawal->cash_shift_id, $shift->public_id))->toBeTrue()
        ->and($wallet->fresh()->available_balance_cents)->toBe(11000)
        ->and(app(CashShiftService::class)->getSummary($shift->public_id)['expected_amount_cents'])->toBe(11000);
    app(CashShiftService::class)->close($shift->public_id, 11000, fcKey('cash28-close'), FC_ACTOR, 'Cierre');
    $settlements->topUp($shift->public_id, $wallet->fresh(), 3000, $topupKey, FC_ACTOR, 'Efectivo recibido');
    $settlements->withdraw($shift->public_id, $wallet->fresh(), 2000, $withdrawKey, FC_ACTOR, 'Efectivo entregado');
    expect($wallet->fresh()->available_balance_cents)->toBe(11000)->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(2);
});

test('cash withdrawal requires both physical cash and wallet funds and supports retry after failure', function () {
    $shift = cash28Shift(1000); $wallet = fcWallet('cash28-insufficient', 10000); $service = app(CashSettlementService::class); $key = fcKey('cash28-retry');
    expect(fn () => $service->withdraw($shift->public_id, $wallet, 2000, $key, FC_ACTOR, 'Retiro'))->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and(Withdrawal::where('wallet_id', $wallet->public_id)->count())->toBe(0);
    app(CashShiftService::class)->addMovement($shift->public_id, CashMovementType::CASH_IN, 2000, fcKey('cash28-funding'), FC_ACTOR, 'Fondo adicional');
    $service->withdraw($shift->public_id, $wallet, 2000, $key, FC_ACTOR, 'Retiro');
    expect($wallet->fresh()->available_balance_cents)->toBe(8000);
    $poor = fcWallet('cash28-poor');
    expect(fn () => $service->withdraw($shift->public_id, $poor, 100, fcKey('cash28-poor'), FC_ACTOR, 'Sin saldo'))->toThrow(InvalidArgumentException::class);
    expect(Withdrawal::where('wallet_id', $poor->public_id)->count())->toBe(0);
});

test('cash settlement rolls back cash operation ledger and wallet together on recording failure', function () {
    $shift = cash28Shift(); $wallet = fcWallet('cash28-rollback', 10000); $key = fcKey('cash28-rollback');
    CashMovement::creating(function ($movement) use ($key) { if ($movement->idempotency_key === $key) throw new RuntimeException('Fallo de prueba al registrar caja'); });
    try {
        expect(fn () => app(CashSettlementService::class)->topUp($shift->public_id, $wallet, 1000, $key, FC_ACTOR, 'Ingreso'))->toThrow(RuntimeException::class);
    } finally { CashMovement::flushEventListeners(); }
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and(TopUp::where('wallet_id', $wallet->public_id)->count())->toBe(0)
        ->and(LedgerEntry::where('wallet_id', $wallet->public_id)->count())->toBe(1);
    app(CashSettlementService::class)->topUp($shift->public_id, $wallet->fresh(), 1000, $key, FC_ACTOR, 'Ingreso');
    expect($wallet->fresh()->available_balance_cents)->toBe(11000);
});

test('cash settlement enforces currency cashier identity and existing blocking limits', function () {
    $shift = cash28Shift(); $wallet = fcWallet('cash28-context', 10000); $service = app(CashSettlementService::class);
    expect(fn () => $service->topUp($shift->public_id, $wallet, 100, fcKey('cash28-agent'), 'other', 'Ingreso'))->toThrow(InvalidArgumentException::class);
    Wallet::where('public_id', $wallet->public_id)->update(['currency' => 'USD']);
    expect(fn () => $service->topUp($shift->public_id, $wallet->fresh(), 100, fcKey('cash28-currency'), FC_ACTOR, 'Ingreso'))->toThrow(InvalidArgumentException::class);
    Wallet::where('public_id', $wallet->public_id)->update(['currency' => 'MXN']);
    fcLimit($wallet->fresh(), ['max_amount_cents' => 100]);
    expect(fn () => $service->topUp($shift->public_id, $wallet->fresh(), 500, fcKey('cash28-limit'), FC_ACTOR, 'Ingreso'))->toThrow(FinancialLimitExceededException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0)
        ->and(fcAlertsForWallet($wallet)->count())->toBe(1);
});

test('cash adapter reports closed drawer differences with association references without changing cash', function () {
    $shift = cash28Shift(); app(CashShiftService::class)->close($shift->public_id, 9500, fcKey('cash28-close'), FC_ACTOR, 'Faltante contado');
    config(['financial.cash.reconciliation_enabled' => true]);
    $source = app(CashShiftReconciliationSource::class); $start = CarbonImmutable::now()->startOfDay();
    $differences = iterator_to_array($source->differences($start, $start->addDay(), null));
    expect($source->isIntegrated())->toBeTrue()->and($differences)->toHaveCount(1)
        ->and($differences[0]->expectedCents)->toBe(10000)->and($differences[0]->actualCents)->toBe(9500)
        ->and($differences[0]->walletId)->toBeNull()->and($differences[0]->references['association_id'])->toBe($shift->cashRegister->association_id);
    expect(iterator_to_array($source->differences($start, $start->addDay(), ['00000000-0000-0000-0000-000000000001'])))->toBe([]);
    expect($shift->fresh()->counted_amount_cents)->toBe(9500);
    $run = app(ReconciliationService::class)->run(now(config('financial.business_timezone'))->toDateString(), FC_ACTOR);
    expect($run->cash_status->value)->toBe('COMPARADA')->and($run->differences()->where('entity_type', 'CASH_SHIFT')->count())->toBe(1);
});

test('a limit rejected during atomic settlement keeps its blocked evidence after rollback', function () {
    $shift = cash28Shift(); $wallet = fcWallet('cash28-late-limit', 10000);
    $limit = fcLimit($wallet, ['max_amount_cents' => 100]);
    $evaluation = app(\App\Domains\Financial\Services\FinancialLimitService::class)->evaluate($wallet, \App\Domains\Financial\Enums\MovementType::RECARGA, 500);
    $guard = $this->createMock(\App\Domains\Financial\Services\FinancialLimitGuard::class);
    $calls = 0;
    $guard->method('assertAllowed')->willReturnCallback(function () use (&$calls, $evaluation, $wallet) {
        if (++$calls === 1) return new \App\Domains\Financial\Data\LimitEvaluation($wallet->public_id, \App\Domains\Financial\Enums\MovementType::RECARGA, 500, 0, []);
        throw new FinancialLimitExceededException($evaluation);
    });
    $this->app->instance(\App\Domains\Financial\Services\FinancialLimitGuard::class, $guard);
    expect(fn () => app(CashSettlementService::class)->topUp($shift->public_id, $wallet, 500, fcKey('cash28-late-limit'), FC_ACTOR, 'Ingreso'))->toThrow(FinancialLimitExceededException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and(TopUp::where('wallet_id', $wallet->public_id)->count())->toBe(0)
        ->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0)->and(fcAlertsForWallet($wallet)->count())->toBe(1);
});
