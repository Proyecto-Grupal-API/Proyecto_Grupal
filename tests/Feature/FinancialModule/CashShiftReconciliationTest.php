<?php

use App\Domains\Financial\Enums\CashReconciliationStatus;
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
