<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletHoldStatus;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\WalletHold;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\ReconciliationService;
use App\Domains\Financial\Services\WalletHoldService;
use Carbon\CarbonImmutable;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

beforeEach(function () {
    // Fixture duration only; this is not a production policy.
    fcHoldPolicy();
});
afterEach(function () {
    fcCleanup();
});

function holdFixture($wallet, string $key, int $amount = 4000, ?CarbonImmutable $expiry = null): WalletHold
{
    return app(WalletHoldService::class)->create($wallet, $amount, 'TEST_CHECKOUT',
        $expiry ?? CarbonImmutable::now()->addMinutes(5)->startOfSecond(), $key,
        FC_ACTOR, 'Reserva de prueba', 'TEST_ORDER', 'order-test');
}

test('holds available funds without changing total monetary value', function () {
    $wallet = fcWallet('hold-create', 10000);
    $hold = holdFixture($wallet, fcKey('hold-create'));
    $wallet->refresh();
    $entry = LedgerEntry::where('transaction_id', $hold->hold_transaction_id)->firstOrFail();
    expect($wallet->available_balance_cents)->toBe(6000)
        ->and($wallet->held_balance_cents)->toBe(4000)
        ->and($entry->amount_cents)->toBe(0)
        ->and($entry->available_delta_cents)->toBe(-4000)
        ->and($entry->held_delta_cents)->toBe(4000)
        ->and($entry->movement_type)->toBe(MovementType::RETENCION);
});

test('retries a hold without duplicating it and rejects changed request data', function () {
    $wallet = fcWallet('hold-retry', 10000);
    $key = fcKey('hold-retry');
    $expiry = CarbonImmutable::now()->addMinutes(5)->startOfSecond();
    $first = holdFixture($wallet, $key, 4000, $expiry);
    $second = holdFixture($wallet, $key, 4000, $expiry);
    expect(strtolower($second->public_id))->toBe(strtolower($first->public_id))
        ->and($wallet->fresh()->held_balance_cents)->toBe(4000)
        ->and(WalletHold::where('wallet_id', $wallet->public_id)->count())->toBe(1);
    expect(fn () => holdFixture($wallet, $key, 4001, $expiry))->toThrow(InvalidArgumentException::class);
});

test('rejects an unsupported duration policy or expiry outside its window', function () {
    $wallet = fcWallet('hold-duration', 10000);
    expect(fn () => holdFixture($wallet, fcKey('hold-long'), 4000, CarbonImmutable::now()->addHour()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => holdFixture($wallet, fcKey('hold-past'), 4000, CarbonImmutable::now()->subSecond()))
        ->toThrow(InvalidArgumentException::class);
    $policy = \App\Domains\Financial\Models\WalletHoldPolicy::where('operation_type', 'TEST_CHECKOUT')->firstOrFail();
    app(\App\Domains\Financial\Services\WalletHoldPolicyService::class)
        ->update($policy, $policy->version, ['active' => false], FC_ACTOR, 'Desactivar operación de prueba');
    expect(fn () => holdFixture($wallet, fcKey('hold-no-policy')))->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)
        ->and(WalletHold::where('wallet_id', $wallet->public_id)->count())->toBe(0);
});

test('rejects insufficient funds and an inactive wallet without pending transactions', function () {
    $wallet = fcWallet('hold-reject', 1000);
    expect(fn () => holdFixture($wallet, fcKey('hold-low')))->toThrow(InvalidArgumentException::class);
    $wallet->status = WalletStatus::BLOQUEADA;
    $wallet->save();
    expect(fn () => holdFixture($wallet, fcKey('hold-inactive'), 500))->toThrow(InvalidArgumentException::class);
    expect(WalletHold::where('wallet_id', $wallet->public_id)->count())->toBe(0)
        ->and($wallet->fresh()->held_balance_cents)->toBe(0);
});

test('cannot spend reserved funds through the ordinary debit path', function () {
    $wallet = fcWallet('hold-no-double-spend', 10000);
    holdFixture($wallet, fcKey('hold-spend'), 8000);
    expect(fn () => app(LedgerService::class)->debit($wallet, 3000, MovementType::PAGO, fcKey('hold-pay')))
        ->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(2000)
        ->and($wallet->fresh()->held_balance_cents)->toBe(8000);
});

test('releases once and permits returning funds even if the wallet was blocked', function () {
    $wallet = fcWallet('hold-release', 10000);
    $hold = holdFixture($wallet, fcKey('hold-release-create'));
    $wallet->update(['status' => WalletStatus::BLOQUEADA]);
    $key = fcKey('hold-release');
    $service = app(WalletHoldService::class);
    $closed = $service->release($hold, $key, FC_ACTOR, 'Cancelar reserva');
    $again = $service->release($hold, $key, FC_ACTOR, 'Cancelar reserva');
    expect($closed->status)->toBe(WalletHoldStatus::LIBERADA)
        ->and(strtolower($again->closing_transaction_id))->toBe(strtolower($closed->closing_transaction_id))
        ->and($wallet->fresh()->available_balance_cents)->toBe(10000)
        ->and($wallet->fresh()->held_balance_cents)->toBe(0);
    expect(fn () => $service->capture($hold, fcKey('hold-after-release'), FC_ACTOR, 'Cobrar'))
        ->toThrow(InvalidArgumentException::class);
});

test('captures once from held funds without a second available balance debit', function () {
    $wallet = fcWallet('hold-capture', 10000);
    $hold = holdFixture($wallet, fcKey('hold-capture-create'));
    $key = fcKey('hold-capture');
    $service = app(WalletHoldService::class);
    $closed = $service->capture($hold, $key, FC_ACTOR, 'Cobrar reserva');
    $service->capture($hold, $key, FC_ACTOR, 'Cobrar reserva');
    $entry = LedgerEntry::where('transaction_id', $closed->closing_transaction_id)->firstOrFail();
    expect($closed->status)->toBe(WalletHoldStatus::CAPTURADA)
        ->and($wallet->fresh()->available_balance_cents)->toBe(6000)
        ->and($wallet->fresh()->held_balance_cents)->toBe(0)
        ->and($entry->amount_cents)->toBe(-4000)
        ->and($entry->available_delta_cents)->toBe(0)
        ->and($entry->held_delta_cents)->toBe(-4000);
    expect(fn () => $service->release($hold, fcKey('hold-after-capture'), FC_ACTOR, 'Liberar'))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects closing idempotency keys reused for another hold', function () {
    $wallet = fcWallet('hold-close-key', 10000);
    $first = holdFixture($wallet, fcKey('hold-first'), 2000);
    $second = holdFixture($wallet, fcKey('hold-second'), 2000);
    $key = fcKey('hold-shared-close');
    app(WalletHoldService::class)->release($first, $key, FC_ACTOR, 'Liberar');
    expect(fn () => app(WalletHoldService::class)->release($second, $key, FC_ACTOR, 'Liberar'))
        ->toThrow(InvalidArgumentException::class);
    expect($second->fresh()->status)->toBe(WalletHoldStatus::ACTIVA)
        ->and($wallet->fresh()->held_balance_cents)->toBe(2000);
});

test('expires holds idempotently and never captures expired funds', function () {
    $wallet = fcWallet('hold-expire', 10000);
    $hold = holdFixture($wallet, fcKey('hold-expire-create'));
    $this->travel(6)->minutes();
    try {
        expect(fn () => app(WalletHoldService::class)->capture($hold, fcKey('hold-expired-capture'), FC_ACTOR, 'Cobrar'))
            ->toThrow(InvalidArgumentException::class);
        expect(app(WalletHoldService::class)->expireDue())->toBe(1)
            ->and(app(WalletHoldService::class)->expireDue())->toBe(0)
            ->and($hold->fresh()->status)->toBe(WalletHoldStatus::EXPIRADA)
            ->and($wallet->fresh()->available_balance_cents)->toBe(10000)
            ->and($wallet->fresh()->held_balance_cents)->toBe(0);
    } finally {
        $this->travelBack();
    }
});

test('prevents changing held balances through generic credit or debit movement types', function () {
    $wallet = fcWallet('hold-no-bypass', 10000);
    foreach ([MovementType::RETENCION, MovementType::LIBERACION] as $movement) {
        expect(fn () => app(LedgerService::class)->credit($wallet, 100, $movement, fcKey('hold-bypass-credit')))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => app(LedgerService::class)->debit($wallet, 100, $movement, fcKey('hold-bypass-debit')))
            ->toThrow(InvalidArgumentException::class);
    }
});

test('reconciles historical entries and holds through capture without false differences', function () {
    $wallet = fcWallet('hold-reconcile', 10000);
    $hold = holdFixture($wallet, fcKey('hold-reconcile-create'));
    $date = now(config('financial.business_timezone'))->format('Y-m-d');
    $service = app(ReconciliationService::class);
    expect($service->run($date, FC_ACTOR, [$wallet->public_id])->differences_count)->toBe(0);
    app(WalletHoldService::class)->capture($hold, fcKey('hold-reconcile-capture'), FC_ACTOR, 'Cobrar');
    expect($service->run($date, FC_ACTOR, [$wallet->public_id])->differences_count)->toBe(0);
});

test('does not permit legacy refunds or reversals to mutate the hold lifecycle', function () {
    $wallet = fcWallet('hold-no-legacy', 10000);
    $hold = holdFixture($wallet, fcKey('hold-no-legacy-create'));
    $transaction = FinancialTransaction::where('public_id', $hold->hold_transaction_id)->firstOrFail();
    $service = app(FinancialAdjustmentService::class);
    expect(fn () => $service->refund($transaction, 100, fcKey('hold-legacy-refund'), FC_ACTOR))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->reverse($transaction, fcKey('hold-legacy-reverse'), 'Reverso'))
        ->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->held_balance_cents)->toBe(4000);
});

test('rolls back a failed capture and permits retry with the same key', function () {
    $wallet = fcWallet('hold-rollback', 10000);
    $hold = holdFixture($wallet, fcKey('hold-rollback-create'));
    $key = fcKey('hold-rollback-capture');
    $event = 'eloquent.updating: ' . WalletHold::class;
    \Illuminate\Support\Facades\Event::listen($event, function () {
        throw new RuntimeException('Fallo simulado al cerrar la retención.');
    });
    try {
        expect(fn () => app(WalletHoldService::class)->capture($hold, $key, FC_ACTOR, 'Cobrar'))
            ->toThrow(RuntimeException::class);
    } finally {
        \Illuminate\Support\Facades\Event::forget($event);
    }
    expect($hold->fresh()->status)->toBe(WalletHoldStatus::ACTIVA)
        ->and($wallet->fresh()->held_balance_cents)->toBe(4000)
        ->and($wallet->fresh()->available_balance_cents)->toBe(6000)
        ->and(FinancialTransaction::where('idempotency_key', $key)->exists())->toBeFalse();
    $closed = app(WalletHoldService::class)->capture($hold, $key, FC_ACTOR, 'Cobrar');
    expect($closed->status)->toBe(WalletHoldStatus::CAPTURADA)
        ->and($wallet->fresh()->held_balance_cents)->toBe(0);
});
