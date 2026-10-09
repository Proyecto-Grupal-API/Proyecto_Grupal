<?php

namespace Tests\Feature\Financial;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;

class FinancialAdjustmentServiceTest extends TestCase
{
    private ?Wallet $wallet = null;

    protected function tearDown(): void
{
    if ($this->wallet) {

        $transactionIds = LedgerEntry::where(
            'wallet_id',
            $this->wallet->public_id
        )->pluck('transaction_id');


        FinancialRefundRequest::where(
            'wallet_id',
            $this->wallet->public_id
        )->delete();


        LedgerEntry::whereIn(
            'transaction_id',
            $transactionIds
        )->delete();


        FinancialTransaction::whereIn(
            'original_transaction_id',
            $transactionIds
        )->delete();


        FinancialTransaction::whereIn(
            'public_id',
            $transactionIds
        )->delete();


        $this->wallet->delete();
    }

    parent::tearDown();
}

    public function test_it_creates_a_pending_refund_request_without_moving_money(): void
    {
        $walletService = app(WalletService::class);
        $ledgerService = app(LedgerService::class);
        $adjustmentService = app(
            FinancialAdjustmentService::class
        );

        $ownerId = 'refund-test-' . Str::uuid();

        $this->wallet = $walletService->create(
            ownerType: 'STUDENT',
            ownerId: $ownerId,
            walletType: WalletType::USUARIO
        );

        $ledgerService->credit(
            wallet: $this->wallet,
            amountCents: 100000,
            movementType: MovementType::RECARGA,
            idempotencyKey: 'refund-credit-' . Str::uuid()
        );

        $payment = $ledgerService->debit(
            wallet: $this->wallet,
            amountCents: 30000,
            movementType: MovementType::PAGO,
            idempotencyKey: 'refund-payment-' . Str::uuid()
        );

        $this->wallet->refresh();

        $balanceBeforeRefund =
            $this->wallet->available_balance_cents;

        $refund = $adjustmentService->refund(
            originalTransaction: $payment,
            amountCents: 10000,
            idempotencyKey: 'refund-request-' . Str::uuid(),
            requestedBy: $ownerId,
            reason: 'Prueba de devolución'
        );

        $this->assertDatabaseHas(
            'financial_refund_requests',
            [
                'request_transaction_id' => $refund->public_id,
                'original_transaction_id' => $payment->public_id,
                'wallet_id' => $this->wallet->public_id,
                'amount_cents' => 10000,
                'status' => 'PENDIENTE',
                'requested_by' => $ownerId,
            ],
            'sqlsrv'
         );

        $this->wallet->refresh();

        $this->assertSame(
            TransactionStatus::PENDIENTE,
            $refund->status
        );

        $this->assertSame(
            'REFUND_REQUEST',
            $refund->reference_type
        );

       $this->assertSame(
           strtolower($payment->public_id),
           strtolower($refund->original_transaction_id)
        );

        $this->assertSame(
            10000,
            $refund->metadata['amount_cents']
        );

        $this->assertSame(
            $balanceBeforeRefund,
            $this->wallet->available_balance_cents
        );

        $this->assertDatabaseMissing(
            'ledger_entries',
            [
                'transaction_id' => $refund->public_id,
            ],
            'sqlsrv'
        );
    }

public function test_it_does_not_duplicate_refund_requests_with_same_idempotency_key(): void
{
    $walletService = app(WalletService::class);
    $ledgerService = app(LedgerService::class);
    $adjustmentService = app(
        FinancialAdjustmentService::class
    );

    $ownerId = 'refund-idempotency-' . Str::uuid();

    $this->wallet = $walletService->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    $ledgerService->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    $payment = $ledgerService->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    $idempotencyKey = 'refund-request-' . Str::uuid();

    $firstRefund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: $idempotencyKey,
        requestedBy: $ownerId,
        reason: 'Primera solicitud'
    );

    $secondRefund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: $idempotencyKey,
        requestedBy: $ownerId,
        reason: 'Primera solicitud'
    );

    $this->assertSame(
        strtolower($firstRefund->public_id),
        strtolower($secondRefund->public_id)
    );

    $this->assertSame(
        1,
        FinancialTransaction::where(
            'idempotency_key',
            $idempotencyKey
        )->count()
    );

    $this->assertSame(
        1,
        FinancialRefundRequest::where(
            'request_transaction_id',
            $firstRefund->public_id
         )->count()
    );

    $this->assertSame(
        TransactionStatus::PENDIENTE,
        $secondRefund->status
    );
}
public function test_it_rejects_refund_with_same_key_and_different_amount(): void
{
    $walletService = app(WalletService::class);
    $ledgerService = app(LedgerService::class);
    $adjustmentService = app(
        FinancialAdjustmentService::class
    );

    $ownerId = 'refund-different-amount-' . Str::uuid();

    $this->wallet = $walletService->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    $ledgerService->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    $payment = $ledgerService->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    $idempotencyKey = 'refund-request-' . Str::uuid();

    $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: $idempotencyKey,
        requestedBy: $ownerId,
        reason: 'Primera solicitud'
    );

    try {
        $adjustmentService->refund(
            originalTransaction: $payment,
            amountCents: 20000,
            requestedBy: $ownerId,
            idempotencyKey: $idempotencyKey,
            reason: 'Segunda solicitud'
        );

        $this->fail(
            'Se esperaba rechazar una clave reutilizada con otro monto.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'La clave de idempotencia ya fue utilizada con un monto diferente.',
            $exception->getMessage()
        );
    }

    $this->assertSame(
        1,
        FinancialTransaction::where(
            'idempotency_key',
            $idempotencyKey
        )->count()
    );

    $this->wallet->refresh();

    $this->assertSame(
        70000,
        $this->wallet->available_balance_cents
    );
}
public function test_it_rejects_refund_greater_than_original_payment(): void
{
    $walletService = app(WalletService::class);
    $ledgerService = app(LedgerService::class);
    $adjustmentService = app(
        FinancialAdjustmentService::class
    );

    $ownerId = 'refund-limit-' . Str::uuid();

    $this->wallet = $walletService->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    $ledgerService->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    $payment = $ledgerService->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    $idempotencyKey = 'refund-limit-' . Str::uuid();

    try {
        $adjustmentService->refund(
            originalTransaction: $payment,
            amountCents: 40000,
            requestedBy: $ownerId,
            idempotencyKey: $idempotencyKey,
            reason: 'Solicitud superior al pago original'
        );

        $this->fail(
            'Se esperaba rechazar una devolución superior al pago.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El monto solicitado supera el saldo disponible para devolución.',
            $exception->getMessage()
        );
    }

    $this->assertSame(
        0,
        FinancialTransaction::where(
            'idempotency_key',
            $idempotencyKey
        )->count()
    );

    $this->wallet->refresh();

    $this->assertSame(
        70000,
        $this->wallet->available_balance_cents
    );
}
public function test_it_rejects_refunds_exceeding_accumulated_limit(): void
{
    $walletService = app(WalletService::class);
    $ledgerService = app(LedgerService::class);
    $adjustmentService = app(
        FinancialAdjustmentService::class
    );

    $ownerId = 'refund-accumulated-' . Str::uuid();

    $this->wallet = $walletService->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    $ledgerService->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    $payment = $ledgerService->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    $firstRefund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 15000,
        idempotencyKey: 'refund-first-' . Str::uuid(),
        requestedBy: $ownerId
    );

    $secondRefund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: 'refund-second-' . Str::uuid(),
        requestedBy: $ownerId
    );

    $rejectedKey = 'refund-third-' . Str::uuid();

    try {
        $adjustmentService->refund(
            originalTransaction: $payment,
            amountCents: 10000,
            requestedBy: $ownerId,
            idempotencyKey: $rejectedKey
        );

        $this->fail(
            'Se esperaba rechazar la devolución acumulada.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El monto solicitado supera el saldo disponible para devolución.',
            $exception->getMessage()
        );
    }

    $this->assertSame(
        TransactionStatus::PENDIENTE,
        $firstRefund->status
    );

    $this->assertSame(
        TransactionStatus::PENDIENTE,
        $secondRefund->status
    );

    $this->assertSame(
        0,
        FinancialTransaction::where(
            'idempotency_key',
            $rejectedKey
        )->count()
    );

    $this->wallet->refresh();

    $this->assertSame(
        70000,
        $this->wallet->available_balance_cents
    );
}
public function test_it_rejects_refund_with_same_key_and_different_requester(): void
{
    $walletService = app(WalletService::class);
    $ledgerService = app(LedgerService::class);

    $adjustmentService = app(
        FinancialAdjustmentService::class
    );

    $ownerId = 'refund-requester-' . Str::uuid();

    $this->wallet = $walletService->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    $ledgerService->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    $payment = $ledgerService->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    $idempotencyKey = 'refund-request-' . Str::uuid();

    // Primera solicitud: estudiante propietario.

    $firstRefund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: $idempotencyKey,
        requestedBy: $ownerId,
        reason: 'Solicitud original'
    );

    // Segunda solicitud: otro estudiante intenta
    // reutilizar la misma clave de idempotencia.

    $differentRequester = 'other-student-' . Str::uuid();

    try {
        $adjustmentService->refund(
            originalTransaction: $payment,
            amountCents: 10000,
            idempotencyKey: $idempotencyKey,
            requestedBy: $differentRequester,
            reason: 'Solicitud de otro estudiante'
        );

        $this->fail(
            'Se esperaba rechazar una clave utilizada por otro solicitante.'
        );

    } catch (\InvalidArgumentException $exception) {

        $this->assertSame(
            'La clave de idempotencia ya pertenece a otro solicitante.',
            $exception->getMessage()
        );
    }

    // Verificar que no se crearon duplicados.

    $this->assertSame(
        1,
        FinancialTransaction::where(
            'idempotency_key',
            $idempotencyKey
        )->count()
    );

    $this->assertSame(
        1,
        FinancialRefundRequest::where(
            'request_transaction_id',
            $firstRefund->public_id
        )->count()
    );

    // Confirmar que el saldo no cambió.

    $this->wallet->refresh();

    $this->assertSame(
        70000,
        $this->wallet->available_balance_cents
    );
}
}