<?php

use App\Domains\Financial\Enums\AlertType;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashOperationConfirmation;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\CashOperationConfirmationService;
use App\Domains\Financial\Services\CashReceiptService;
use App\Domains\Financial\Services\CashSettlementService;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Auditoría E2 (19-nov-2026): FIN-02 (el límite se valida después de que el alumno confirma), FIN-04 (la confirmación queda
// CONFIRMED tras el rechazo por límite) y caracterización de INC-01 (una recarga rechazada no deja dinero a medias).
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

/** @return array{0: User, 1: Wallet, 2: string, 3: string} alumno, wallet, turno y operador */
function cclContext(int $balance = 0, int $opening = 10000): array
{
    $student = User::factory()->create();
    $wallet = Wallet::create(['public_id' => (string) Str::uuid(), 'owner_id' => (string) $student->getKey(), 'owner_type' => 'USER',
        'type' => 'WALLET_USUARIO', 'currency' => 'MXN', 'status' => 'ACTIVA', 'available_balance_cents' => $balance, 'held_balance_cents' => 0]);
    $agent = 'test-cash-agent-'.Str::random(6);
    $cash = app(CashShiftService::class);
    $register = $cash->createRegister('test-association', 'Caja límites', 'MXN', $agent);
    $shift = $cash->open($register->public_id, $agent, $opening, (string) Str::uuid());

    return [$student, $wallet, $shift->public_id, $agent];
}

function cclLimit(Wallet $wallet, string $operation, int $max): void
{
    app(FinancialLimitService::class)->create([
        'name' => "Fixture caja {$operation}", 'subject_type' => 'OWNER', 'subject_id' => $wallet->owner_id, 'operation' => $operation,
        'period' => 'OPERACION', 'metric' => 'MONTO', 'max_amount_cents' => $max, 'action' => 'BLOQUEAR',
        'currency' => 'MXN', 'active' => true, 'reason' => 'Fixture configured limit',
    ], 'fixture-admin');
}

function cclBlockedAlerts(Wallet $wallet): int
{
    return TransactionAlert::where('wallet_id', $wallet->public_id)->where('alert_type', AlertType::OPERACION_BLOQUEADA->value)->count();
}

/** Todo lo que una recarga deja escrito si se liquida: nada de esto debe existir tras un rechazo. */
function cclMoneyTrace(Wallet $wallet, string $key, string $shiftId): array
{
    return ['balance' => (int) $wallet->fresh()->available_balance_cents,
        'topups' => TopUp::where('wallet_id', $wallet->public_id)->count(),
        'movements' => CashMovement::where('idempotency_key', $key)->count(),
        'transactions' => FinancialTransaction::where('idempotency_key', $key)->count(),
        'ledger' => LedgerEntry::where('wallet_id', $wallet->public_id)->count(),
        'receipts' => CashReceipt::count(),
        'expected_cash' => app(CashShiftService::class)->getSummary($shiftId)['expected_amount_cents']];
}

test('FIN-02: a cash confirmation above a blocking limit is refused before the student is asked, keeping the alert', function () {
    [, $wallet, $shift, $agent] = cclContext();
    cclLimit($wallet, 'RECARGA', 1000);
    $proofs = app(CashOperationConfirmationService::class);

    // Por debajo y exactamente en el máximo sí se solicita.
    expect($proofs->request($shift, $wallet, 'TOPUP', 999, $agent, 'Recarga bajo el máximo', (string) Str::uuid())->status)->toBe('PENDING')
        ->and($proofs->request($shift, $wallet, 'TOPUP', 1000, $agent, 'Recarga en el máximo', (string) Str::uuid())->status)->toBe('PENDING');

    // Un centavo por encima: no se crea la confirmación y la alerta del intento persiste.
    $key = (string) Str::uuid();
    try {
        $proofs->request($shift, $wallet, 'TOPUP', 1001, $agent, 'Recarga sobre el máximo', $key);
        $this->fail('Se esperaba el rechazo por límite.');
    } catch (FinancialLimitExceededException $e) {
        expect($e->alertId)->not->toBeNull()
            ->and(TransactionAlert::where('public_id', $e->alertId)->exists())->toBeTrue();
    }
    expect(CashOperationConfirmation::where('request_key', $key)->exists())->toBeFalse()
        ->and(cclBlockedAlerts($wallet))->toBe(1);
});

test('FIN-02: withdrawals are prevalidated against their own blocking limit', function () {
    [, $wallet, $shift, $agent] = cclContext(5000);
    cclLimit($wallet, 'RETIRO', 2000);
    $proofs = app(CashOperationConfirmationService::class);

    expect($proofs->request($shift, $wallet, 'WITHDRAWAL', 2000, $agent, 'Retiro en el máximo', (string) Str::uuid())->status)->toBe('PENDING')
        ->and(fn () => $proofs->request($shift, $wallet, 'WITHDRAWAL', 2001, $agent, 'Retiro sobre el máximo', (string) Str::uuid()))
        ->toThrow(FinancialLimitExceededException::class);
});

test('FIN-02: replaying an existing confirmation key returns it even if a limit appeared afterwards', function () {
    [, $wallet, $shift, $agent] = cclContext();
    $proofs = app(CashOperationConfirmationService::class);
    $key = (string) Str::uuid();
    $first = $proofs->request($shift, $wallet, 'TOPUP', 1500, $agent, 'Recarga previa al límite', $key);
    cclLimit($wallet, 'RECARGA', 1000);

    $replayed = $proofs->request($shift, $wallet, 'TOPUP', 1500, $agent, 'Recarga previa al límite', $key);
    expect($replayed->public_id)->toBe($first->public_id)->and(cclBlockedAlerts($wallet))->toBe(0);
});

test('FIN-04 and INC-01: a confirmed top-up blocked at settlement ends REJECTED with its alert and leaves no money behind', function () {
    [$student, $wallet, $shift, $agent] = cclContext(500);
    $proofs = app(CashOperationConfirmationService::class);
    $settlements = app(CashSettlementService::class);
    $reason = 'Recarga con cambio de política';
    $proof = $proofs->request($shift, $wallet, 'TOPUP', 1500, $agent, $reason, (string) Str::uuid());
    $proofs->decide($proof->public_id, $student, true, '127.0.0.1');
    // La política cambia mientras el alumno ya aceptó: la validación final de la liquidación es la que decide.
    cclLimit($wallet, 'RECARGA', 1000);
    $key = (string) Str::uuid();
    $before = cclMoneyTrace($wallet, $key, $shift);

    try {
        $settlements->settleConfirmed($shift, $wallet, 1500, $key, $agent, $reason, true, $proof->public_id);
        $this->fail('Se esperaba el rechazo por límite.');
    } catch (FinancialLimitExceededException $e) {
        $alertId = $e->alertId;
    }

    $rejected = $proof->fresh();
    expect($rejected->status)->toBe('REJECTED')
        ->and($rejected->rejected_at)->not->toBeNull()
        ->and($rejected->rejected_by)->toBe('system:financial-limits')
        ->and($rejected->rejection_alert_id)->toBe($alertId)
        ->and($rejected->rejection_reason)->toContain('límite')
        ->and($rejected->settlement_key)->toBeNull()
        ->and($rejected->cash_movement_id)->toBeNull()
        ->and(cclBlockedAlerts($wallet))->toBe(1)
        // INC-01: ni wallet, ni ledger, ni recarga, ni movimiento de caja, ni transacción, ni comprobante; el efectivo esperado no cambia.
        ->and(cclMoneyTrace($wallet, $key, $shift))->toBe($before);

    // Reintentar con la misma clave no liquida, no duplica alertas y explica el rechazo.
    expect(fn () => $settlements->settleConfirmed($shift, $wallet, 1500, $key, $agent, $reason, true, $proof->public_id))
        ->toThrow(InvalidArgumentException::class, 'rechazada por un límite financiero');
    expect(cclBlockedAlerts($wallet))->toBe(1)->and(cclMoneyTrace($wallet, $key, $shift))->toBe($before);

    // Estado terminal: el alumno ya no puede aceptarla y el cajero ya no puede cancelarla; rechazarla de nuevo es idempotente.
    expect(fn () => $proofs->decide($proof->public_id, $student, true, '127.0.0.1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $proofs->cancel($proof->public_id, $agent))->toThrow(InvalidArgumentException::class)
        ->and($proofs->decide($proof->public_id, $student, false, '127.0.0.1')->status)->toBe('REJECTED')
        ->and($proof->fresh()->rejected_by)->toBe('system:financial-limits');
});

test('a student rejection records who rejected it', function () {
    [$student, $wallet, $shift, $agent] = cclContext();
    $proofs = app(CashOperationConfirmationService::class);
    $proof = $proofs->request($shift, $wallet, 'TOPUP', 700, $agent, 'Recarga que el alumno rechaza', (string) Str::uuid());

    $rejected = $proofs->decide($proof->public_id, $student, false, '127.0.0.1');
    expect($rejected->status)->toBe('REJECTED')->and($rejected->rejected_by)->toBe('user:'.$student->getKey())
        ->and($rejected->rejection_alert_id)->toBeNull();
});

test('a top-up exactly at the limit settles once and a second key cannot reuse the confirmation', function () {
    [$student, $wallet, $shift, $agent] = cclContext();
    cclLimit($wallet, 'RECARGA', 1000);
    $proofs = app(CashOperationConfirmationService::class);
    $settlements = app(CashSettlementService::class);
    $proof = $proofs->request($shift, $wallet, 'TOPUP', 1000, $agent, 'Recarga en el máximo', (string) Str::uuid());
    $proofs->decide($proof->public_id, $student, true, '127.0.0.1');
    $key = (string) Str::uuid();

    $topUp = $settlements->settleConfirmed($shift, $wallet, 1000, $key, $agent, 'Recarga en el máximo', true, $proof->public_id);
    expect($proof->fresh()->status)->toBe('CONSUMED')->and((int) $wallet->fresh()->available_balance_cents)->toBe(1000)
        ->and($settlements->settleConfirmed($shift, $wallet, 1000, $key, $agent, 'Recarga en el máximo', true, $proof->public_id)->folio)->toBe($topUp->folio)
        ->and(fn () => $settlements->settleConfirmed($shift, $wallet, 1000, (string) Str::uuid(), $agent, 'Recarga en el máximo', true, $proof->public_id))
        ->toThrow(InvalidArgumentException::class, 'otra clave');
    expect(CashMovement::where('wallet_id', $wallet->public_id)->count())->toBe(1)
        ->and((int) $wallet->fresh()->available_balance_cents)->toBe(1000);
});

test('a failure while issuing the receipt rolls back everything and keeps the confirmation retryable', function () {
    [$student, $wallet, $shift, $agent] = cclContext();
    $proofs = app(CashOperationConfirmationService::class);
    $settlements = app(CashSettlementService::class);
    $proof = $proofs->request($shift, $wallet, 'TOPUP', 800, $agent, 'Recarga con comprobante fallido', (string) Str::uuid());
    $proofs->decide($proof->public_id, $student, true, '127.0.0.1');
    $key = (string) Str::uuid();
    $before = cclMoneyTrace($wallet, $key, $shift);
    $real = app(CashReceiptService::class);
    app()->instance(CashReceiptService::class, new class extends CashReceiptService
    {
        public function issue(CashMovement $movement): CashReceipt
        {
            throw new RuntimeException('Fallo provocado del comprobante.');
        }
    });

    expect(fn () => $settlements->settleConfirmed($shift, $wallet, 800, $key, $agent, 'Recarga con comprobante fallido', true, $proof->public_id))
        ->toThrow(RuntimeException::class, 'Fallo provocado');
    // Un fallo técnico no es un rechazo definitivo: la confirmación sigue aceptada y se puede reintentar.
    expect(cclMoneyTrace($wallet, $key, $shift))->toBe($before)->and($proof->fresh()->status)->toBe('CONFIRMED')
        ->and($proof->fresh()->settlement_key)->toBeNull();

    app()->instance(CashReceiptService::class, $real);
    $settlements->settleConfirmed($shift, $wallet, 800, $key, $agent, 'Recarga con comprobante fallido', true, $proof->public_id);
    expect($proof->fresh()->status)->toBe('CONSUMED')->and((int) $wallet->fresh()->available_balance_cents)->toBe(800);
});

test('expired and cancelled confirmations keep their behaviour', function () {
    [$student, $wallet, $shift, $agent] = cclContext();
    $proofs = app(CashOperationConfirmationService::class);
    $settlements = app(CashSettlementService::class);
    $expiring = $proofs->request($shift, $wallet, 'TOPUP', 600, $agent, 'Recarga que vence', (string) Str::uuid());
    $proofs->decide($expiring->public_id, $student, true, '127.0.0.1');
    $this->travel((int) config('financial.cash.confirmation_ttl_seconds', 300) + 5)->seconds();
    expect(fn () => $settlements->settleConfirmed($shift, $wallet, 600, (string) Str::uuid(), $agent, 'Recarga que vence', true, $expiring->public_id))
        ->toThrow(InvalidArgumentException::class, 'vigente');
    expect($expiring->fresh()->status)->toBe('CONFIRMED')->and($expiring->fresh()->rejected_by)->toBeNull();
    $this->travelBack();

    $cancelled = $proofs->request($shift, $wallet, 'TOPUP', 650, $agent, 'Recarga cancelada', (string) Str::uuid());
    $proofs->decide($cancelled->public_id, $student, true, '127.0.0.1');
    expect($proofs->cancel($cancelled->public_id, $agent)->status)->toBe('CANCELLED')
        ->and(fn () => $settlements->settleConfirmed($shift, $wallet, 650, (string) Str::uuid(), $agent, 'Recarga cancelada', true, $cancelled->public_id))
        ->toThrow(InvalidArgumentException::class);
    expect((int) $wallet->fresh()->available_balance_cents)->toBe(0);
});
