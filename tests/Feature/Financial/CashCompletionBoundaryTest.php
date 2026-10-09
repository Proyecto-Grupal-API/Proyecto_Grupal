<?php

use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Enums\TopUpStatus;
use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Enums\WithdrawalStatus;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Services\CashSettlementService;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\TopUpService;
use App\Domains\Financial\Services\WithdrawalService;
use App\Http\Middleware\ValidateServiceToken;

require_once __DIR__.'/Support/FinancialControlHelpers.php';
require_once __DIR__.'/Support/CashCompletionFixtures.php';

beforeEach(function () { testCashCompletionCleanup(); fcCleanup(); });
afterEach(function () { testCashCompletionCleanup(); fcCleanup(); });

function cashBoundaryShift(int $opening = 10000, string $actor = FC_ACTOR, string $currency = 'MXN')
{
    $cash = app(CashShiftService::class);
    $register = $cash->createRegister('test-cash-gate-fixture-'.str()->uuid(), 'Caja límite', $currency, $actor);
    return $cash->open($register->public_id, $actor, $opening, fcKey('cash-gate-open'));
}

test('generic services cannot complete cash even with a forged shift or in memory method', function () {
    $wallet = fcWallet('cash-gate-denied', 10000);
    $top = app(TopUpService::class)->create($wallet, 2000, TopUpMethod::EFECTIVO);
    $withdrawal = app(WithdrawalService::class)->create($wallet, 2000, WithdrawalMethod::EFECTIVO);
    $shift = cashBoundaryShift();
    $top->cash_shift_id = $shift->public_id;
    $withdrawal->method = WithdrawalMethod::TRANSFERENCIA;
    $withdrawal->cash_shift_id = $shift->public_id;
    expect(fn () => app(TopUpService::class)->complete($top, fcKey('cash-gate-top')))->toThrow(InvalidArgumentException::class, 'Caja y turnos');
    expect(fn () => app(WithdrawalService::class)->complete($withdrawal, fcKey('cash-gate-withdraw')))->toThrow(InvalidArgumentException::class, 'Caja y turnos');
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)
        ->and($top->fresh()->status)->toBe(TopUpStatus::PENDIENTE)
        ->and($withdrawal->fresh()->status)->toBe(WithdrawalStatus::PENDIENTE)
        ->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0);
});

test('cash orchestrator completes existing requests atomically and retries after closing without new operations', function () {
    $wallet = fcWallet('cash-gate-pending', 10000); $shift = cashBoundaryShift();
    $top = app(TopUpService::class)->create($wallet, 5000, TopUpMethod::EFECTIVO, FC_ACTOR);
    $withdrawal = app(WithdrawalService::class)->create($wallet, 3000, WithdrawalMethod::EFECTIVO, FC_ACTOR);
    $cash = app(CashSettlementService::class); $topKey = fcKey('cash-gate-top'); $withdrawKey = fcKey('cash-gate-withdraw');
    $completed = $cash->completePending($shift->public_id, $top, $topKey, FC_ACTOR, 'Recibido');
    $paid = $cash->completePending($shift->public_id, $withdrawal, $withdrawKey, FC_ACTOR, 'Entregado');
    expect(fcSameId($completed->public_id, $top->public_id))->toBeTrue()
        ->and(fcSameId($paid->public_id, $withdrawal->public_id))->toBeTrue()
        ->and($wallet->fresh()->available_balance_cents)->toBe(12000)
        ->and(app(CashShiftService::class)->getSummary($shift->public_id)['expected_amount_cents'])->toBe(12000);
    app(CashShiftService::class)->close($shift->public_id, 12000, fcKey('cash-gate-close'), FC_ACTOR, 'Cierre');
    expect($cash->completePending($shift->public_id, $top, $topKey, FC_ACTOR, 'Recibido')->folio)->toBe($completed->folio)
        ->and($cash->completePending($shift->public_id, $withdrawal, $withdrawKey, FC_ACTOR, 'Entregado')->folio)->toBe($paid->folio)
        ->and(CashReceipt::whereIn('cash_movement_id', CashMovement::where('cash_shift_id', $shift->id)->select('id'))->count())->toBe(2)
        ->and($wallet->fresh()->available_balance_cents)->toBe(12000);
    expect(fn () => $cash->completePending($shift->public_id, $top, fcKey('cash-gate-new-key'), FC_ACTOR, 'Recibido'))->toThrow(InvalidArgumentException::class);
});

test('cash pending completion rejects a foreign operator currency and non cash method without moving funds', function () {
    $wallet = fcWallet('cash-gate-context', 10000);
    $top = app(TopUpService::class)->create($wallet, 1000, TopUpMethod::EFECTIVO, FC_ACTOR);
    $cash = app(CashSettlementService::class); $shift = cashBoundaryShift(); $usd = cashBoundaryShift(10000, FC_ACTOR, 'USD');
    expect(fn () => $cash->completePending($shift->public_id, $top, fcKey('cash-gate-actor'), 'otro', 'Recibir'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $cash->completePending($usd->public_id, $top, fcKey('cash-gate-currency'), FC_ACTOR, 'Recibir'))->toThrow(InvalidArgumentException::class);
    $transfer = app(WithdrawalService::class)->create($wallet, 1000, WithdrawalMethod::TRANSFERENCIA, FC_ACTOR);
    expect(fn () => $cash->completePending($shift->public_id, $transfer, fcKey('cash-gate-method'), FC_ACTOR, 'Entregar'))->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and($top->fresh()->status)->toBe(TopUpStatus::PENDIENTE);
});

test('cash pending completion rolls back the existing request wallet and movement when its receipt fails', function () {
    $wallet = fcWallet('cash-gate-rollback', 10000); $shift = cashBoundaryShift();
    $top = app(TopUpService::class)->create($wallet, 1000, TopUpMethod::EFECTIVO, FC_ACTOR);
    $key = fcKey('cash-gate-failure'); $cash = app(CashSettlementService::class);
    CashReceipt::creating(function () { throw new RuntimeException('Fallo comprobante'); });
    try { expect(fn () => $cash->completePending($shift->public_id, $top, $key, FC_ACTOR, 'Recibir'))->toThrow(RuntimeException::class); }
    finally { CashReceipt::flushEventListeners(); }
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)
        ->and($top->fresh()->status)->toBe(TopUpStatus::PENDIENTE)
        ->and($top->fresh()->cash_shift_id)->toBeNull()
        ->and(FinancialTransaction::where('idempotency_key', $key)->exists())->toBeFalse()
        ->and(CashMovement::where('idempotency_key', $key)->exists())->toBeFalse();
    expect($cash->completePending($shift->public_id, $top, $key, FC_ACTOR, 'Recibir')->status)->toBe(TopUpStatus::COMPLETADA)
        ->and($wallet->fresh()->available_balance_cents)->toBe(11000);
});

test('generic top up HTTP completion ignores forged cash data and cannot use an existing shift', function () {
    $this->withoutMiddleware(ValidateServiceToken::class);
    $wallet = fcWallet('cash-gate-api', 10000); $shift = cashBoundaryShift();
    $top = app(TopUpService::class)->create($wallet, 1000, TopUpMethod::EFECTIVO, FC_ACTOR);
    foreach ([fcKey('cash-gate-http'), fcKey('cash-gate-http')] as $key) {
        $this->postJson('/api/v1/financial/topups/'.$top->public_id.'/complete',
            ['cash_shift_id' => $shift->public_id, 'agent_id' => FC_ACTOR, 'cash_authorized' => true], ['Idempotency-Key' => $key])
            ->assertStatus(409)->assertJsonPath('message', 'Las operaciones en efectivo solo pueden completarse desde Caja y turnos.');
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)->and($top->fresh()->status)->toBe(TopUpStatus::PENDIENTE);
});

test('non cash withdrawals retain generic completion without a cash movement', function () {
    $wallet = fcWallet('cash-gate-transfer', 10000); $service = app(WithdrawalService::class);
    $withdrawal = $service->create($wallet, 2000, WithdrawalMethod::TRANSFERENCIA);
    $completed = $service->complete($withdrawal, fcKey('cash-gate-bank'));
    expect($completed->status)->toBe(WithdrawalStatus::COMPLETADA)
        ->and($completed->cash_shift_id)->toBeNull()->and($wallet->fresh()->available_balance_cents)->toBe(8000)
        ->and(CashMovement::where('wallet_id', $wallet->public_id)->exists())->toBeFalse();
});
