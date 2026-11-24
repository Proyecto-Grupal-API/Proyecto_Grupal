<?php

use App\Domains\Financial\Enums\CashReconciliationStatus;
use App\Domains\Financial\Enums\DifferenceStatus;
use App\Domains\Financial\Enums\ReconciliationCheck;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\CashOperationConfirmationService;
use App\Domains\Financial\Services\CashSettlementService;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\ReconciliationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.connections.sqlsrv.database'))->toBe('campus_digital_financial_testing');
    DB::connection('sqlsrv')->beginTransaction();
});

afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});

test('cash shift reconciles the confirmed top-up and withdrawal and reports the counted variance', function () {
    $student = User::factory()->create();
    $wallet = Wallet::create([
        'public_id' => (string) Str::uuid(), 'owner_id' => (string) $student->getKey(), 'owner_type' => 'USER',
        'type' => 'WALLET_USUARIO', 'currency' => 'MXN', 'status' => 'ACTIVA',
        'available_balance_cents' => 0, 'held_balance_cents' => 0,
    ]);
    $cash = app(CashShiftService::class);
    $agent = 'test-cash-agent';
    $register = $cash->createRegister('test-association', 'Caja de prueba', 'MXN', $agent);
    $shift = $cash->open($register->public_id, $agent, 10000, (string) Str::uuid());
    $proofs = app(CashOperationConfirmationService::class);
    $settlements = app(CashSettlementService::class);

    foreach (['TOPUP' => 3000, 'WITHDRAWAL' => 1000] as $operation => $amount) {
        $reason = 'Operación de prueba '.$operation;
        $proof = $proofs->request($shift->public_id, $wallet, $operation, $amount, $agent, $reason, (string) Str::uuid());
        $key = (string) Str::uuid();
        $incoming = $operation === 'TOPUP';
        expect(fn () => $settlements->settleConfirmed($shift->public_id, $wallet, $amount, $key, $agent, $reason,
            $incoming, $proof->public_id))->toThrow(InvalidArgumentException::class);

        $proofs->decide($proof->public_id, $student, true, '127.0.0.1');
        $settled = $settlements->settleConfirmed($shift->public_id, $wallet, $amount, $key, $agent, $reason, $incoming, $proof->public_id);
        $replayed = $settlements->settleConfirmed($shift->public_id, $wallet, $amount, $key, $agent, $reason, $incoming, $proof->public_id);
        expect($replayed->folio)->toBe($settled->folio)
            ->and(CashReceipt::where('folio', $settled->folio)->count())->toBe(1)
            ->and($proof->fresh()->status)->toBe('CONSUMED');
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(2000);
    $close = $cash->close($shift->public_id, 11900, (string) Str::uuid(), $agent, 'Arqueo de prueba.');
    expect($close['expected_amount_cents'])->toBe(12000)->and($close['difference_cents'])->toBe(-100);

    config(['financial.cash.reconciliation_enabled' => true]);
    $report = app(ReconciliationService::class)->run(now(config('financial.business_timezone'))->toDateString(), $agent);
    $difference = $report->observedDifferences()->where('check_code', ReconciliationCheck::CAJA_DIFERENCIA->value)
        ->where('entity_id', $shift->public_id)->sole();
    expect($report->cash_status)->toBe(CashReconciliationStatus::COMPARADA)
        ->and($difference->expected_cents)->toBe(12000)
        ->and($difference->actual_cents)->toBe(11900)
        ->and($difference->difference_cents)->toBe(-100);

    $transactionId = strtolower(FinancialTransaction::where('idempotency_key', $key)->sole()->public_id);
    $params = ['source' => 'transaction', 'operationId' => $transactionId, 'folio' => $settled->folio, 'reference' => $transactionId];
    $this->actingAs($student)->get(route('financial.receipts.show', $params))->assertOk();
    $pdf = $this->get(route('financial.receipts.pdf', $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF-');
    $params['folio'] = 'INCORRECTO';
    $this->getJson(route('financial.receipts.show', $params))->assertNotFound();
});

// Requisito 2.10-d (reportes de diferencias). El faltante de un arqueo aparece en la conciliación del día sólo si la conciliación
// de caja está encendida y la conciliación es la global; se resuelve una sola vez, con actor y nota, y resolverlo deja constancia:
// no mueve dinero ni corrige el arqueo. El recorrido de E2 lo hace por la pantalla de controles financieros.
test('a cash shortfall is reported by the global reconciliation and resolved once with a note, without touching the shift', function () {
    $cash = app(CashShiftService::class);
    $service = app(ReconciliationService::class);
    $agent = 'test-cash-agent';
    $register = $cash->createRegister('test-association', 'Caja con faltante', 'MXN', $agent);
    $shift = $cash->open($register->public_id, $agent, 10000, (string) Str::uuid());
    $close = $cash->close($shift->public_id, 9500, (string) Str::uuid(), $agent, 'Arqueo con faltante de prueba.');
    $today = now(config('financial.business_timezone'))->toDateString();
    $shortfall = fn ($report) => $report->observedDifferences()
        ->where('check_code', ReconciliationCheck::CAJA_DIFERENCIA->value)->where('entity_id', $shift->public_id);
    expect($close['expected_amount_cents'])->toBe(10000)->and($close['difference_cents'])->toBe(-500);

    // Como llega el producto: la conciliación no compara la caja y no reporta el faltante.
    config(['financial.cash.reconciliation_enabled' => false]);
    $off = $service->run($today, $agent);
    expect($off->cash_status)->toBe(CashReconciliationStatus::NO_INTEGRADA)->and($shortfall($off)->count())->toBe(0);

    config(['financial.cash.reconciliation_enabled' => true]);
    $report = $service->run($today, $agent);
    $difference = $shortfall($report)->sole();
    expect($report->cash_status)->toBe(CashReconciliationStatus::COMPARADA)
        ->and($difference->status)->toBe(DifferenceStatus::ABIERTA)
        ->and($difference->expected_cents)->toBe(10000)
        ->and($difference->actual_cents)->toBe(9500)
        ->and($difference->difference_cents)->toBe(-500);

    // Resolver exige actor y nota, y ocurre una sola vez.
    expect(fn () => $service->resolveDifference($difference, 'test-controls', '   '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->resolveDifference($difference, '', 'Sin actor.'))->toThrow(InvalidArgumentException::class);
    $resolved = $service->resolveDifference($difference, 'test-controls', 'Faltante aclarado con el operador.');
    expect($resolved->status)->toBe(DifferenceStatus::RESUELTA)
        ->and($resolved->resolved_by)->toBe('test-controls')
        ->and($resolved->resolution_note)->toBe('Faltante aclarado con el operador.')
        ->and($resolved->resolved_at)->not->toBeNull()
        ->and(fn () => $service->resolveDifference($difference, 'test-controls', 'Otra nota.'))
        ->toThrow(InvalidArgumentException::class, 'La diferencia ya fue resuelta.');

    // Deja constancia y nada más: el arqueo conserva lo contado y su diferencia.
    $after = $shift->fresh();
    expect((int) $after->counted_amount_cents)->toBe(9500)->and((int) $after->difference_cents)->toBe(-500)
        ->and($difference->fresh()->difference_cents)->toBe(-500);
});
