<?php

use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\CashSettlementService;

// These legacy service tests now complete cash through the real cash orchestrator.
// They do not grant HTTP permissions or bypass the production cash completion gate.
function testCashCompletionCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Solo base financiera de pruebas.');
    $registers = CashRegister::where('association_id', 'like', 'test-cash-gate-fixture-%')->pluck('id');
    $shifts = CashShift::whereIn('cash_register_id', $registers)->pluck('id');
    $movements = CashMovement::whereIn('cash_shift_id', $shifts)->pluck('id');
    CashReceipt::whereIn('cash_movement_id', $movements)->delete();
    CashMovement::whereIn('id', $movements)->delete();
    CashShift::whereIn('id', $shifts)->delete();
    CashRegister::whereIn('id', $registers)->delete();
}
function testCompleteCashTopUp(TopUp $operation, string $key): TopUp
{
    $actor = $operation->agent_id ?? 'test-cash-gate-fixture-actor';
    $cash = app(CashShiftService::class);
    $existing = CashMovement::where('idempotency_key', $key)->first();
    if ($existing) $shift = $existing->shift;
    else {
        $wallet = Wallet::where('public_id', $operation->wallet_id)->firstOrFail();
        $register = $cash->createRegister('test-cash-gate-fixture-'.str()->uuid(), 'Caja de pruebas', $wallet->currency, $actor);
        $shift = $cash->open($register->public_id, $actor, 0, 'test-cash-gate-fixture-open-'.str()->uuid());
    }
    return app(CashSettlementService::class)->completePending($shift->public_id, $operation, $key, $actor, 'Liquidación de prueba');
}
// Only historical-refund fixtures: seed a pre-cash withdrawal, rather than executing a newly forbidden cash completion.
function testHistoricalWithdrawal(Withdrawal $operation, string $key): void
{
    $wallet = Wallet::where('public_id', $operation->wallet_id)->firstOrFail();
    app(\App\Domains\Financial\Services\LedgerService::class)->debit($wallet, $operation->amount_cents,
        \App\Domains\Financial\Enums\MovementType::RETIRO, $key, 'WITHDRAWAL', $operation->public_id,
        ['withdrawal_method' => $operation->method->value]);
    $operation->status = \App\Domains\Financial\Enums\WithdrawalStatus::COMPLETADA;
    $operation->save();
}
