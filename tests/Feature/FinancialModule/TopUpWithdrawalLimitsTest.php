<?php

use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Enums\TopUpStatus;
use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Enums\WithdrawalStatus;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Domains\Financial\Services\TopUpService;
use App\Domains\Financial\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Diseño E2 2.3 (recargas), 2.4 (retiros) y 2.10 (límites con acción BLOQUEAR/ALERTAR).
beforeEach(function () {
    config(['database.connections.sqlsrv.database' => 'campus_digital_financial_testing']);
    DB::purge('sqlsrv');
    if (! app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') {
        throw new RuntimeException('Solo bases de pruebas.');
    }
    DB::connection('sqlsrv')->beginTransaction();
    Http::preventStrayRequests();
});
afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});
function twlWallet(int $balance = 10000, string $status = 'ACTIVA'): Wallet
{
    return Wallet::create(['public_id' => (string) Str::uuid(), 'owner_type' => 'USER', 'owner_id' => 'fixture-twl-'.Str::uuid(),
        'type' => 'WALLET_USUARIO', 'currency' => 'MXN', 'status' => $status, 'available_balance_cents' => $balance, 'held_balance_cents' => 0]);
}
function twlLimit(Wallet $w, string $operation, int $max, string $action = 'BLOQUEAR'): void
{
    app(FinancialLimitService::class)->create([
        'name' => "Fixture {$operation} {$action}", 'subject_type' => 'OWNER', 'subject_id' => $w->owner_id, 'operation' => $operation,
        'period' => 'OPERACION', 'metric' => 'MONTO', 'max_amount_cents' => $max, 'action' => $action,
        'currency' => 'MXN', 'active' => true, 'reason' => 'Fixture configured limit',
    ], 'fixture-admin');
}

test('a top-up request is validated and stays pending without moving money', function () {
    $w = twlWallet(0);
    $service = app(TopUpService::class);
    expect(fn () => $service->create($w, 0, TopUpMethod::EFECTIVO))->toThrow(InvalidArgumentException::class, 'mayor que cero');
    expect(fn () => $service->create(twlWallet(0, 'BLOQUEADA'), 500, TopUpMethod::EFECTIVO))->toThrow(InvalidArgumentException::class, 'activa');
    $topUp = $service->create($w, 500, TopUpMethod::EFECTIVO, 'agent-1', 'REF-'.Str::uuid());
    expect($topUp->status)->toBe(TopUpStatus::PENDIENTE)->and($topUp->amount_cents)->toBe(500)
        ->and($w->fresh()->available_balance_cents)->toBe(0);
});

test('a cash top-up can only be completed from the cash shift flow', function () {
    $w = twlWallet(0);
    $topUp = app(TopUpService::class)->create($w, 500, TopUpMethod::EFECTIVO);
    expect(fn () => app(TopUpService::class)->complete($topUp, 'twl-'.Str::uuid()))->toThrow(InvalidArgumentException::class, 'Caja y turnos');
    expect($topUp->fresh()->status)->toBe(TopUpStatus::PENDIENTE)->and($w->fresh()->available_balance_cents)->toBe(0);
});

test('a blocking top-up limit rejects the request and records alert evidence', function () {
    $w = twlWallet(0);
    twlLimit($w, 'RECARGA', 1000);
    expect(fn () => app(TopUpService::class)->create($w, 1001, TopUpMethod::EFECTIVO))->toThrow(FinancialLimitExceededException::class);
    expect(TopUp::where('wallet_id', $w->public_id)->exists())->toBeFalse()
        ->and(TransactionAlert::where('wallet_id', $w->public_id)->where('reference_type', 'TOPUP_REQUEST')->exists())->toBeTrue();
    expect(app(TopUpService::class)->create($w, 1000, TopUpMethod::EFECTIVO)->status)->toBe(TopUpStatus::PENDIENTE);
});

test('a transfer withdrawal debits the ledger once and cannot be completed twice', function () {
    $w = twlWallet(5000);
    $service = app(WithdrawalService::class);
    $withdrawal = $service->create($w, 2000, WithdrawalMethod::TRANSFERENCIA, 'agent-1');
    expect($withdrawal->status)->toBe(WithdrawalStatus::PENDIENTE)->and($w->fresh()->available_balance_cents)->toBe(5000);
    $key = 'twl-'.Str::uuid();
    $service->complete($withdrawal, $key);
    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::COMPLETADA)
        ->and($w->fresh()->available_balance_cents)->toBe(3000)
        ->and(FinancialTransaction::where('idempotency_key', $key)->where('reference_type', 'WITHDRAWAL')->count())->toBe(1);
    expect(fn () => $service->complete($withdrawal, 'twl-'.Str::uuid()))->toThrow(InvalidArgumentException::class, 'pendiente');
    expect($w->fresh()->available_balance_cents)->toBe(3000);
});

test('a withdrawal without enough balance fails without changing the wallet or the request', function () {
    $w = twlWallet(1000);
    $withdrawal = app(WithdrawalService::class)->create($w, 1500, WithdrawalMethod::TRANSFERENCIA);
    expect(fn () => app(WithdrawalService::class)->complete($withdrawal, 'twl-'.Str::uuid()))->toThrow(InvalidArgumentException::class, 'Saldo insuficiente');
    expect($w->fresh()->available_balance_cents)->toBe(1000)->and($withdrawal->fresh()->status)->toBe(WithdrawalStatus::PENDIENTE);
});

test('cash withdrawals are rejected outside the cash shift flow', function () {
    $w = twlWallet(5000);
    $withdrawal = app(WithdrawalService::class)->create($w, 500, WithdrawalMethod::EFECTIVO);
    expect(fn () => app(WithdrawalService::class)->complete($withdrawal, 'twl-'.Str::uuid()))->toThrow(InvalidArgumentException::class, 'Caja y turnos');
    expect($w->fresh()->available_balance_cents)->toBe(5000);
});

test('withdrawal limits block above the maximum and alert-only limits do not block the request', function () {
    $w = twlWallet(10000);
    twlLimit($w, 'RETIRO', 3000);
    expect(fn () => app(WithdrawalService::class)->create($w, 3001, WithdrawalMethod::TRANSFERENCIA))->toThrow(FinancialLimitExceededException::class);
    expect(Withdrawal::where('wallet_id', $w->public_id)->exists())->toBeFalse()
        ->and(TransactionAlert::where('wallet_id', $w->public_id)->where('reference_type', 'WITHDRAWAL_REQUEST')->exists())->toBeTrue();

    $alertOnly = twlWallet(10000);
    twlLimit($alertOnly, 'RETIRO', 100, 'ALERTAR');
    expect(app(WithdrawalService::class)->create($alertOnly, 500, WithdrawalMethod::TRANSFERENCIA)->status)->toBe(WithdrawalStatus::PENDIENTE);
});
