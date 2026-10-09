<?php

use App\Domains\Financial\Enums\CashReconciliationStatus;
use App\Domains\Financial\Enums\DifferenceStatus;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\ReconciliationCheck;
use App\Domains\Financial\Enums\ReconciliationScope;
use App\Domains\Financial\Enums\ReconciliationStatus;
use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Enums\TopUpStatus;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Reconciliation;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\ReconciliationService;
use App\Domains\Financial\Services\TopUpService;
use Illuminate\Support\Str;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

afterEach(function () {
    fcCleanup();
});

function fcToday(): string
{
    return now(config('financial.business_timezone'))->format('Y-m-d');
}

function fcReconcile(array $wallets): Reconciliation
{
    return app(ReconciliationService::class)->run(
        fcToday(),
        'test-fc-reconciler',
        array_map(fn (Wallet $w) => $w->public_id, $wallets)
    );
}

function fcDifferenceCodes(Reconciliation $reconciliation): array
{
    return $reconciliation->differences()
        ->get()
        ->map(fn ($d) => $d->check_code)
        ->all();
}

test('a consistent day reconciles without differences and without changing balances', function () {
    $wallet = fcWallet('rec-ok', 30000);
    $other = fcWallet('rec-ok-other');

    $ledger = app(LedgerService::class);
    $ledger->debit($wallet, 5000, MovementType::RETIRO, fcKey('rec-ok-debit'), 'TEST', null);
    $ledger->transfer($wallet, $other, 7000, fcKey('rec-ok-transfer'));

    $topUps = app(TopUpService::class);
    $topUp = $topUps->create($wallet, 2000, TopUpMethod::EFECTIVO);
    $topUps->complete($topUp, fcKey('rec-ok-topup'));

    $balanceBefore = $wallet->fresh()->available_balance_cents;
    $entriesBefore = LedgerEntry::whereIn('wallet_id', [$wallet->public_id, $other->public_id])->count();

    $reconciliation = fcReconcile([$wallet, $other]);

    expect($reconciliation->status)->toBe(ReconciliationStatus::CUADRADA)
        ->and($reconciliation->scope)->toBe(ReconciliationScope::WALLETS)
        ->and($reconciliation->differences_count)->toBe(0)
        // 2.8 (caja) no está integrado: se ejecutan solo las comprobaciones internas.
        ->and($reconciliation->checks_executed)->toBe(count(ReconciliationCheck::internal()))
        ->and($reconciliation->cash_status)->toBe(CashReconciliationStatus::NO_INTEGRADA)
        ->and($reconciliation->executed_by)->toBe('test-fc-reconciler')
        ->and($reconciliation->finished_at)->not->toBeNull();

    expect($wallet->fresh()->available_balance_cents)->toBe($balanceBefore)
        ->and($balanceBefore)->toBe(20000)
        ->and(LedgerEntry::whereIn('wallet_id', [$wallet->public_id, $other->public_id])->count())
        ->toBe($entriesBefore);
});

test('detects a wallet balance that does not match its ledger and does not correct it', function () {
    $wallet = fcWallet('rec-balance', 10000);

    Wallet::where('public_id', $wallet->public_id)->update([
        'available_balance_cents' => 15000,
    ]);

    $reconciliation = fcReconcile([$wallet]);

    expect($reconciliation->status)->toBe(ReconciliationStatus::CON_DIFERENCIAS)
        ->and(fcDifferenceCodes($reconciliation))
        ->toContain(ReconciliationCheck::SALDO_WALLET_VS_LEDGER)
        ->toContain(ReconciliationCheck::SALDO_WALLET_VS_ULTIMO_MOVIMIENTO);

    $difference = $reconciliation->differences()
        ->where('check_code', ReconciliationCheck::SALDO_WALLET_VS_LEDGER->value)
        ->first();

    expect($difference->expected_cents)->toBe(10000)
        ->and($difference->actual_cents)->toBe(15000)
        ->and($difference->difference_cents)->toBe(5000)
        ->and($difference->status)->toBe(DifferenceStatus::ABIERTA)
        ->and(fcSameId($difference->wallet_id, $wallet->public_id))->toBeTrue();

    // La conciliación solo informa: el saldo no se modifica.
    expect($wallet->fresh()->available_balance_cents)->toBe(15000);
});

test('detects a completed top up without its ledger movement', function () {
    $wallet = fcWallet('rec-topup');

    $topUp = TopUp::create([
        'public_id' => (string) Str::uuid(),
        'folio' => null,
        'wallet_id' => $wallet->public_id,
        'amount_cents' => 4000,
        'currency' => 'MXN',
        'method' => TopUpMethod::EFECTIVO,
        'status' => TopUpStatus::COMPLETADA,
    ]);

    $reconciliation = fcReconcile([$wallet]);

    $difference = $reconciliation->differences()
        ->where('check_code', ReconciliationCheck::RECARGA_SIN_MOVIMIENTO->value)
        ->first();

    expect($difference)->not->toBeNull()
        ->and(fcSameId($difference->entity_id, $topUp->public_id))->toBeTrue()
        ->and($difference->expected_cents)->toBe(4000)
        ->and($difference->actual_cents)->toBe(0);
});

test('detects pending transactions and completed transactions without movements', function () {
    $wallet = fcWallet('rec-transactions');

    $topUp = app(TopUpService::class)->create($wallet, 1000, TopUpMethod::EFECTIVO);

    $pending = FinancialTransaction::create([
        'public_id' => (string) Str::uuid(),
        'idempotency_key' => fcKey('rec-pending'),
        'status' => TransactionStatus::PENDIENTE,
        'reference_type' => 'TOPUP',
        'reference_id' => $topUp->public_id,
    ]);

    $empty = FinancialTransaction::create([
        'public_id' => (string) Str::uuid(),
        'idempotency_key' => fcKey('rec-empty'),
        'status' => TransactionStatus::COMPLETADA,
        'reference_type' => 'TOPUP',
        'reference_id' => $topUp->public_id,
    ]);

    $reconciliation = fcReconcile([$wallet]);

    $differences = $reconciliation->differences()->get();

    expect($differences->contains(fn ($d) =>
        $d->check_code === ReconciliationCheck::TRANSACCION_PENDIENTE
        && fcSameId($d->entity_id, $pending->public_id)
    ))->toBeTrue()
        ->and($differences->contains(fn ($d) =>
            $d->check_code === ReconciliationCheck::TRANSACCION_SIN_MOVIMIENTOS
            && fcSameId($d->entity_id, $empty->public_id)
        ))->toBeTrue();
});

test('detects a transfer whose movements do not add up to zero', function () {
    $wallet = fcWallet('rec-transfer', 10000);

    $transaction = FinancialTransaction::create([
        'public_id' => (string) Str::uuid(),
        'idempotency_key' => fcKey('rec-broken-transfer'),
        'status' => TransactionStatus::COMPLETADA,
        'reference_type' => 'TEST',
    ]);

    LedgerEntry::create([
        'public_id' => (string) Str::uuid(),
        'transaction_id' => $transaction->public_id,
        'wallet_id' => $wallet->public_id,
        'movement_type' => MovementType::TRANSFERENCIA_SALIDA,
        'amount_cents' => -500,
        'balance_after_cents' => 9500,
        'available_balance_after_cents' => 9500,
        'held_balance_after_cents' => 0,
    ]);

    $reconciliation = fcReconcile([$wallet]);

    $difference = $reconciliation->differences()
        ->where('check_code', ReconciliationCheck::TRANSFERENCIA_DESCUADRADA->value)
        ->first();

    expect($difference)->not->toBeNull()
        ->and(fcSameId($difference->entity_id, $transaction->public_id))->toBeTrue()
        ->and($difference->expected_cents)->toBe(0)
        ->and($difference->actual_cents)->toBe(-500);
});

test('differences can be queried and resolved once, and every run is kept', function () {
    $wallet = fcWallet('rec-resolve', 1000);

    Wallet::where('public_id', $wallet->public_id)->update([
        'available_balance_cents' => 900,
    ]);

    $service = app(ReconciliationService::class);

    $first = fcReconcile([$wallet]);
    $second = fcReconcile([$wallet]);

    expect(Reconciliation::where('executed_by', 'test-fc-reconciler')->count())->toBe(2)
        ->and($first->public_id)->not->toBe($second->public_id);

    $difference = $first->differences()->first();

    $resolved = $service->resolveDifference($difference, 'test-fc-auditor', 'Ajuste pendiente en 2.7.');

    expect($resolved->status)->toBe(DifferenceStatus::RESUELTA)
        ->and($resolved->resolved_by)->toBe('test-fc-auditor')
        ->and($resolved->resolved_at)->not->toBeNull();

    expect(fn () => $service->resolveDifference($resolved, 'test-fc-auditor', 'Otra vez'))
        ->toThrow(InvalidArgumentException::class, 'ya fue resuelta');
});

test('rejects invalid reconciliation requests', function () {
    $service = app(ReconciliationService::class);
    $tomorrow = now(config('financial.business_timezone'))->addDay()->format('Y-m-d');

    expect(fn () => $service->run($tomorrow, 'test-fc-reconciler'))
        ->toThrow(InvalidArgumentException::class, 'fecha futura')
        ->and(fn () => $service->run('2026-13-45', 'test-fc-reconciler'))
        ->toThrow(InvalidArgumentException::class, 'AAAA-MM-DD')
        ->and(fn () => $service->run(fcToday(), 'test-fc-reconciler', []))
        ->toThrow(InvalidArgumentException::class, 'no puede estar vacía')
        ->and(fn () => $service->run(fcToday(), ''))
        ->toThrow(InvalidArgumentException::class);
});

test('the reconcile command runs for a given date', function () {
    $this->artisan('financial:reconcile', [
        'date' => fcToday(),
        '--executed-by' => 'test-fc-command',
    ])->assertExitCode(0);

    $reconciliation = Reconciliation::where('executed_by', 'test-fc-command')->first();

    expect($reconciliation)->not->toBeNull()
        ->and($reconciliation->scope)->toBe(ReconciliationScope::GLOBAL)
        ->and($reconciliation->status)->not->toBe(ReconciliationStatus::FALLIDA);
});
