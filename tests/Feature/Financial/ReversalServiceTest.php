<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Domains\Financial\Services\LedgerService;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';
afterEach(fn () => fcCleanup());

function reversalPayment($wallet, int $amount = 3000) {
    return app(LedgerService::class)->debit($wallet, $amount, MovementType::PAGO,
        fcKey('reverse-payment'), 'TEST_PAYMENT', fcKey('order'));
}

test('reverses a simple payment once with accountable actor and ledger deltas', function () {
    $wallet = fcWallet('reverse-payment', 10000);
    $payment = reversalPayment($wallet);
    $service = app(FinancialAdjustmentService::class);
    $key = fcKey('reverse');
    $first = $service->reverse($payment, $key, 'Corrección autorizada', FC_ACTOR);
    $retry = $service->reverse($payment, $key, 'Corrección autorizada', FC_ACTOR);
    expect(strtolower($retry->public_id))->toBe(strtolower($first->public_id))
        ->and($wallet->fresh()->available_balance_cents)->toBe(10000)
        ->and($payment->fresh()->status)->toBe(TransactionStatus::REVERTIDA)
        ->and($first->metadata['executed_by'])->toBe(FC_ACTOR);
    expect(app(\App\Domains\Financial\Services\ReconciliationService::class)
        ->run(now(config('financial.business_timezone'))->format('Y-m-d'), FC_ACTOR, [$wallet->public_id])
        ->differences_count)->toBe(0);
    $entry = LedgerEntry::where('transaction_id', $first->public_id)->sole();
    expect($entry->amount_cents)->toBe(3000)->and($entry->available_delta_cents)->toBe(3000)
        ->and($entry->held_delta_cents)->toBe(0);
    expect(fn () => $service->reverse($payment, fcKey('second'), 'Corrección autorizada', FC_ACTOR))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects changed reversal actor reason and original for the same key', function () {
    $wallet = fcWallet('reverse-conflict', 10000);
    $payment = reversalPayment($wallet);
    $other = reversalPayment($wallet, 1000);
    $service = app(FinancialAdjustmentService::class);
    $key = fcKey('reverse-conflict');
    $service->reverse($payment, $key, 'Motivo', FC_ACTOR);
    foreach ([[$payment, 'Otro motivo', FC_ACTOR], [$payment, 'Motivo', 'another-actor'], [$other, 'Motivo', FC_ACTOR]] as [$original, $reason, $actor]) {
        expect(fn () => $service->reverse($original, $key, $reason, $actor))->toThrow(InvalidArgumentException::class);
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(9000);
});

test('requires a key responsible actor and reason before reversing a payment', function () {
    $wallet = fcWallet('reverse-input', 10000);
    $payment = reversalPayment($wallet);
    $service = app(FinancialAdjustmentService::class);
    foreach ([['', 'Motivo', FC_ACTOR], [fcKey('input'), '', FC_ACTOR], [fcKey('input'), 'Motivo', null]] as [$key, $reason, $actor]) {
        expect(fn () => $service->reverse($payment, $key, $reason, $actor))->toThrow(InvalidArgumentException::class);
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(7000)
        ->and($payment->fresh()->status)->toBe(TransactionStatus::COMPLETADA);
});

test('reverses a transfer without creating money and retries after insufficient balance', function () {
    $sender = fcWallet('reverse-sender', 10000);
    $receiver = fcWallet('reverse-receiver');
    $ledger = app(LedgerService::class);
    $original = $ledger->transfer($sender, $receiver, 4000, fcKey('reverse-transfer'), 'TEST_TRANSFER', fcKey('transfer'));
    $spent = reversalPayment($receiver, 1000);
    $service = app(FinancialAdjustmentService::class);
    $key = fcKey('reverse-transfer-complete');
    expect(fn () => $service->reverse($original, $key, 'Corrección transferencia', FC_ACTOR))->toThrow(InvalidArgumentException::class);
    expect(FinancialTransaction::where('idempotency_key', $key)->exists())->toBeFalse()
        ->and($original->fresh()->status)->toBe(TransactionStatus::COMPLETADA);
    $service->reverse($spent, fcKey('reverse-spent'), 'Corregir pago', FC_ACTOR);
    $service->reverse($original, $key, 'Corrección transferencia', FC_ACTOR);
    $service->reverse($original, $key, 'Corrección transferencia', FC_ACTOR);
    expect($sender->fresh()->available_balance_cents)->toBe(10000)
        ->and($receiver->fresh()->available_balance_cents)->toBe(0);
});

test('does not reverse external withdrawals topups refunds or previous reversals', function () {
    $wallet = fcWallet('reverse-unsupported', 10000);
    $ledger = app(LedgerService::class);
    $service = app(FinancialAdjustmentService::class);
    $withdrawal = $ledger->debit($wallet, 1000, MovementType::RETIRO, fcKey('withdrawal'), 'WITHDRAWAL', fcKey('withdrawal-id'));
    $topup = $ledger->credit($wallet, 1000, MovementType::RECARGA, fcKey('topup'), 'TOPUP', fcKey('topup-id'));
    $refund = $ledger->credit($wallet, 1000, MovementType::DEVOLUCION, fcKey('refund'), 'DEVOLUCION', fcKey('refund-id'));
    $payment = reversalPayment($wallet);
    $reversal = $service->reverse($payment, fcKey('reverse-once'), 'Motivo', FC_ACTOR);
    foreach ([$withdrawal, $topup, $refund, $reversal] as $transaction) {
        expect(fn () => $service->reverse($transaction, fcKey('unsupported'), 'Motivo', FC_ACTOR))->toThrow(InvalidArgumentException::class);
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(11000);
});

test('rolls back a failed reversal and allows retry with the same key', function () {
    $wallet = fcWallet('reverse-rollback', 10000);
    $payment = reversalPayment($wallet);
    $service = app(FinancialAdjustmentService::class);
    $key = fcKey('reverse-rollback');
    $dispatcher = FinancialTransaction::getEventDispatcher();
    FinancialTransaction::setEventDispatcher(clone $dispatcher);
    FinancialTransaction::updating(function ($transaction) {
        if ($transaction->reference_type === 'REVERSO' && $transaction->status === TransactionStatus::COMPLETADA) {
            throw new RuntimeException('Injected reversal failure');
        }
    });
    try {
        expect(fn () => $service->reverse($payment, $key, 'Motivo', FC_ACTOR))->toThrow(RuntimeException::class);
    } finally {
        FinancialTransaction::setEventDispatcher($dispatcher);
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(7000)
        ->and($payment->fresh()->status)->toBe(TransactionStatus::COMPLETADA)
        ->and(FinancialTransaction::where('idempotency_key', $key)->exists())->toBeFalse();
    $service->reverse($payment, $key, 'Motivo', FC_ACTOR);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000);
});
