<?php

require_once __DIR__ . '/Support/CashCompletionFixtures.php';
beforeEach(function () { testCashCompletionCleanup(); });

use App\Domains\Financial\Enums\AlertOutcome;
use App\Domains\Financial\Enums\AlertStatus;
use App\Domains\Financial\Enums\AlertType;
use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\TopUpService;
use App\Domains\Financial\Services\UnusualActivityDetector;
use App\Domains\Financial\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

afterEach(function () {
    testCashCompletionCleanup();
    fcCleanup();
});

test('blocks a top up request above a blocking limit without moving money', function () {
    $wallet = fcWallet('guard-topup');
    $limit = fcLimit($wallet, ['max_amount_cents' => 10000]);

    $caught = null;

    try {
        app(TopUpService::class)->create(
            $wallet,
            10001,
            TopUpMethod::EFECTIVO,
            'test-fc-agent',
            'test-fc-ref'
        );
    } catch (FinancialLimitExceededException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->alertId)->not->toBeNull()
        ->and($caught->evaluation->decision())->toBe('BLOQUEADA');

    expect(TopUp::where('wallet_id', $wallet->public_id)->count())->toBe(0)
        ->and(LedgerEntry::where('wallet_id', $wallet->public_id)->count())->toBe(0)
        ->and($wallet->fresh()->available_balance_cents)->toBe(0);

    $alerts = fcAlertsForWallet($wallet);

    expect($alerts)->toHaveCount(1);

    $alert = $alerts->first();

    expect($alert->alert_type)->toBe(AlertType::OPERACION_BLOQUEADA)
        ->and($alert->status)->toBe(AlertStatus::ABIERTA)
        ->and($alert->transaction_id)->toBeNull()
        ->and(fcSameId($alert->limit_id, $limit->public_id))->toBeTrue()
        ->and(fcSameId($alert->public_id, $caught->alertId))->toBeTrue()
        ->and($alert->operation)->toBe(MovementType::RECARGA)
        ->and($alert->reference_type)->toBe('TOPUP_REQUEST')
        ->and($alert->amount_cents)->toBe(10001)
        ->and($alert->observed_value)->toBe(10001)
        ->and($alert->threshold_value)->toBe(10000)
        ->and($alert->details['requested_by'])->toBe('test-fc-agent')
        ->and($alert->statusChanges()->count())->toBe(1);
});

test('accepts a top up request within the configured limits', function () {
    $wallet = fcWallet('guard-topup-ok');
    fcLimit($wallet, ['max_amount_cents' => 10000]);

    $topUp = app(TopUpService::class)->create(
        $wallet,
        10000,
        TopUpMethod::EFECTIVO
    );

    expect($topUp->public_id)->not->toBeEmpty()
        ->and(fcAlertsForWallet($wallet))->toHaveCount(0);
});

test('blocks a withdrawal request above a blocking limit', function () {
    $wallet = fcWallet('guard-withdrawal', 50000);
    fcLimit($wallet, [
        'operation' => 'RETIRO',
        'max_amount_cents' => 20000,
    ]);

    expect(fn () => app(WithdrawalService::class)->create(
        $wallet,
        30000,
        WithdrawalMethod::EFECTIVO
    ))->toThrow(FinancialLimitExceededException::class);

    expect(Withdrawal::where('wallet_id', $wallet->public_id)->count())->toBe(0)
        ->and($wallet->fresh()->available_balance_cents)->toBe(50000)
        ->and(fcAlertsForWallet($wallet)->first()->operation)->toBe(MovementType::RETIRO);
});

test('records a traceable alert linked to the transaction for alert only limits', function () {
    $wallet = fcWallet('alert-credit');
    $limit = fcLimit($wallet, [
        'max_amount_cents' => 10000,
        'action' => 'ALERTAR',
    ]);

    $transaction = app(LedgerService::class)->credit(
        $wallet,
        15000,
        MovementType::RECARGA,
        fcKey('alert-credit'),
        'TEST',
        null
    );

    $entries = LedgerEntry::where('wallet_id', $wallet->public_id)->get();

    expect($transaction->status)->toBe(TransactionStatus::COMPLETADA)
        ->and($wallet->fresh()->available_balance_cents)->toBe(15000)
        ->and($entries)->toHaveCount(1);

    $alerts = fcAlertsForWallet($wallet);

    expect($alerts)->toHaveCount(1);

    $alert = $alerts->first();

    expect($alert->alert_type)->toBe(AlertType::LIMITE_EXCEDIDO)
        ->and($alert->limit_action)->toBe(LimitAction::ALERTAR)
        ->and(fcSameId($alert->transaction_id, $transaction->public_id))->toBeTrue()
        ->and(fcSameId($alert->ledger_entry_id, $entries->first()->public_id))->toBeTrue()
        ->and(fcSameId($alert->limit_id, $limit->public_id))->toBeTrue()
        ->and($alert->observed_value)->toBe(15000)
        ->and($alert->threshold_value)->toBe(10000);
});

test('links the alert to the completed top up', function () {
    $wallet = fcWallet('alert-topup');
    fcLimit($wallet, [
        'max_amount_cents' => 1000,
        'action' => 'ALERTAR',
    ]);

    $service = app(TopUpService::class);

    $topUp = $service->create($wallet, 5000, TopUpMethod::EFECTIVO);

    expect(fcAlertsForWallet($wallet))->toHaveCount(0);

    testCompleteCashTopUp($topUp, fcKey('alert-topup-complete'));

    $alert = fcAlertsForWallet($wallet)->first();

    expect($alert)->not->toBeNull()
        ->and($alert->reference_type)->toBe('TOPUP')
        ->and(fcSameId($alert->reference_id, $topUp->public_id))->toBeTrue()
        ->and($wallet->fresh()->available_balance_cents)->toBe(5000);
});

test('does not create alerts or partial movements when the operation rolls back', function () {
    $wallet = fcWallet('alert-rollback');
    fcLimit($wallet, [
        'max_amount_cents' => 1000,
        'action' => 'ALERTAR',
    ]);

    $key = fcKey('alert-rollback');

    expect(function () use ($wallet, $key) {
        DB::connection('sqlsrv')->transaction(function () use ($wallet, $key) {
            app(LedgerService::class)->credit(
                $wallet,
                5000,
                MovementType::RECARGA,
                $key,
                'TEST',
                null
            );

            throw new RuntimeException('Fallo simulado después del movimiento.');
        });
    })->toThrow(RuntimeException::class);

    expect(fcAlertsForWallet($wallet))->toHaveCount(0)
        ->and(FinancialTransaction::where('idempotency_key', $key)->exists())->toBeFalse()
        ->and(LedgerEntry::where('wallet_id', $wallet->public_id)->count())->toBe(0)
        ->and($wallet->fresh()->available_balance_cents)->toBe(0);
});

test('does not duplicate alerts when an operation is replayed with the same idempotency key', function () {
    $wallet = fcWallet('alert-replay');
    fcLimit($wallet, [
        'max_amount_cents' => 1000,
        'action' => 'ALERTAR',
    ]);

    $key = fcKey('alert-replay');
    $ledger = app(LedgerService::class);

    $ledger->credit($wallet, 5000, MovementType::RECARGA, $key, 'TEST', null);
    $ledger->credit($wallet, 5000, MovementType::RECARGA, $key, 'TEST', null);

    expect(fcAlertsForWallet($wallet))->toHaveCount(1)
        ->and($wallet->fresh()->available_balance_cents)->toBe(5000);
});

test('a failure while recording alerts does not affect the financial operation', function () {
    $wallet = fcWallet('alert-failure');

    $detector = $this->createMock(UnusualActivityDetector::class);
    $detector->method('inspectTransaction')
        ->willThrowException(new RuntimeException('Detector caído.'));

    $this->app->instance(UnusualActivityDetector::class, $detector);

    $transaction = app(LedgerService::class)->credit(
        $wallet,
        5000,
        MovementType::RECARGA,
        fcKey('alert-failure'),
        'TEST',
        null
    );

    expect($transaction->status)->toBe(TransactionStatus::COMPLETADA)
        ->and($wallet->fresh()->available_balance_cents)->toBe(5000)
        ->and(LedgerEntry::where('wallet_id', $wallet->public_id)->count())->toBe(1);
});

test('alerts on transfers that exceed a limit of the source wallet', function () {
    $source = fcWallet('alert-transfer-source', 20000);
    $destination = fcWallet('alert-transfer-destination');

    fcLimit($source, [
        'operation' => 'TRANSFERENCIA_SALIDA',
        'max_amount_cents' => 5000,
        'action' => 'ALERTAR',
    ]);

    $transaction = app(LedgerService::class)->transfer(
        $source,
        $destination,
        8000,
        fcKey('alert-transfer')
    );

    $alert = fcAlertsForWallet($source)->first();

    expect($alert)->not->toBeNull()
        ->and($alert->operation)->toBe(MovementType::TRANSFERENCIA_SALIDA)
        ->and(fcSameId($alert->transaction_id, $transaction->public_id))->toBeTrue()
        ->and(fcAlertsForWallet($destination))->toHaveCount(0)
        ->and($source->fresh()->available_balance_cents)->toBe(12000)
        ->and($destination->fresh()->available_balance_cents)->toBe(8000);
});

test('alerts when an operation without prior control exceeds a blocking limit', function () {
    $wallet = fcWallet('alert-unguarded', 20000);

    fcLimit($wallet, [
        'operation' => 'PAGO',
        'max_amount_cents' => 5000,
        'action' => 'BLOQUEAR',
    ]);

    app(LedgerService::class)->debit(
        $wallet,
        8000,
        MovementType::PAGO,
        fcKey('alert-unguarded'),
        'TEST',
        null
    );

    $alert = fcAlertsForWallet($wallet)->first();

    expect($alert)->not->toBeNull()
        ->and($alert->alert_type)->toBe(AlertType::LIMITE_EXCEDIDO)
        ->and($alert->limit_action)->toBe(LimitAction::BLOQUEAR)
        ->and($alert->amount_cents)->toBe(8000)
        // REQ-E2-2.10-01: el excedente de un límite de bloqueo se marca explícitamente.
        ->and($alert->outcome)->toBe(AlertOutcome::EXCEDENTE_NO_PREVENIDO);
});

test('does not alert operations that respect the limits', function () {
    $wallet = fcWallet('alert-none');

    fcLimit($wallet, [
        'max_amount_cents' => 10000,
        'action' => 'ALERTAR',
    ]);

    app(LedgerService::class)->credit(
        $wallet,
        10000,
        MovementType::RECARGA,
        fcKey('alert-none'),
        'TEST',
        null
    );

    expect(fcAlertsForWallet($wallet))->toHaveCount(0)
        ->and(TransactionAlert::where('wallet_id', $wallet->public_id)->exists())->toBeFalse();
});
