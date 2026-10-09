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
public function test_it_rejects_pending_refund_without_moving_money(): void
{
    $walletService = app(WalletService::class);
    $ledgerService = app(LedgerService::class);
    $adjustmentService = app(FinancialAdjustmentService::class);

    $ownerId = 'refund-rejection-' . Str::uuid();

    // Crear la Wallet del estudiante.

    $this->wallet = $walletService->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    // Agregar $1,000.00 a la Wallet.

    $ledgerService->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    // Realizar un pago de $300.00.

    $payment = $ledgerService->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    // Solicitar la devolución de $100.00.

    $refund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: 'refund-request-' . Str::uuid(),
        requestedBy: $ownerId,
        reason: 'Solicitud de prueba'
    );

    $refundRequest = FinancialRefundRequest::where(
        'request_transaction_id',
        $refund->public_id
    )->firstOrFail();

    // Registrar los movimientos contables existentes.

    $entriesBefore = LedgerEntry::where(
        'wallet_id',
        $this->wallet->public_id
    )->count();

    // Un administrador rechaza la solicitud.

    $rejectedRequest = $adjustmentService->rejectRefund(
        refundRequest: $refundRequest,
        reviewedBy: 'admin-financiero-001',
        reviewReason: 'El pago original es correcto.'
    );

    // Verificar el estado administrativo.

    $this->assertSame(
        \App\Domains\Financial\Enums\RefundRequestStatus::RECHAZADA,
        $rejectedRequest->status
    );

    $this->assertSame(
        'admin-financiero-001',
        $rejectedRequest->reviewed_by
    );

    $this->assertSame(
        'El pago original es correcto.',
        $rejectedRequest->review_reason
    );

    $this->assertNotNull(
        $rejectedRequest->reviewed_at
    );

    // Verificar el estado de la transacción financiera.

    $refund->refresh();

    $this->assertSame(
        TransactionStatus::FALLIDA,
        $refund->status
    );

    // Verificar que el saldo no cambió.

    $this->wallet->refresh();

    $this->assertSame(
        70000,
        $this->wallet->available_balance_cents
    );

    // Verificar que no se crearon movimientos contables.

    $entriesAfter = LedgerEntry::where(
        'wallet_id',
        $this->wallet->public_id
    )->count();

    $this->assertSame(
        $entriesBefore,
        $entriesAfter
    );
}
public function test_it_prevents_rejecting_the_same_refund_twice(): void
{
    $walletService = app(WalletService::class);
    $ledgerService = app(LedgerService::class);
    $adjustmentService = app(FinancialAdjustmentService::class);

    $ownerId = 'refund-double-rejection-' . Str::uuid();

    // Crear la Wallet del estudiante.

    $this->wallet = $walletService->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    // Agregar $1,000.00.

    $ledgerService->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    // Realizar un pago de $300.00.

    $payment = $ledgerService->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    // Solicitar una devolución de $100.00.

    $refund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: 'refund-request-' . Str::uuid(),
        requestedBy: $ownerId,
        reason: 'Solicitud de prueba'
    );

    $refundRequest = FinancialRefundRequest::where(
        'request_transaction_id',
        $refund->public_id
    )->firstOrFail();

    // Primer rechazo: debe funcionar.

    $adjustmentService->rejectRefund(
        refundRequest: $refundRequest,
        reviewedBy: 'admin-financiero-001',
        reviewReason: 'Solicitud improcedente'
    );

    // Segundo rechazo: debe ser rechazado por el sistema.

    try {

        $adjustmentService->rejectRefund(
            refundRequest: $refundRequest,
            reviewedBy: 'admin-financiero-002',
            reviewReason: 'Segundo intento de rechazo'
        );

        $this->fail(
            'No debe permitirse rechazar dos veces la misma solicitud.'
        );

    } catch (\InvalidArgumentException $exception) {

        $this->assertSame(
            'Solo se pueden rechazar solicitudes pendientes.',
            $exception->getMessage()
        );
    }

    // Verificar que se conserva el primer responsable.

    $refundRequest->refresh();

    $this->assertSame(
        'admin-financiero-001',
        $refundRequest->reviewed_by
    );

    $this->assertSame(
        'Solicitud improcedente',
        $refundRequest->review_reason
    );

    // Verificar que la solicitud sigue rechazada.

    $this->assertSame(
        \App\Domains\Financial\Enums\RefundRequestStatus::RECHAZADA,
        $refundRequest->status
    );

    // Verificar que la transacción continúa fallida.

    $refund->refresh();

    $this->assertSame(
        TransactionStatus::FALLIDA,
        $refund->status
    );

    // Verificar que el saldo no cambió.

    $this->wallet->refresh();

    $this->assertSame(
        70000,
        $this->wallet->available_balance_cents
    );
}

public function test_it_releases_refund_limit_after_rejection(): void
{
    $walletService = app(WalletService::class);
    $ledgerService = app(LedgerService::class);
    $adjustmentService = app(FinancialAdjustmentService::class);

    $ownerId = 'refund-limit-release-' . Str::uuid();

    $this->wallet = $walletService->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    // Agregar $1,000.00.

    $ledgerService->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    // Realizar un pago de $300.00.

    $payment = $ledgerService->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    // Primera solicitud: $200.00.

    $firstRefund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 20000,
        idempotencyKey: 'refund-first-' . Str::uuid(),
        requestedBy: $ownerId,
        reason: 'Primera solicitud'
    );

    $refundRequest = FinancialRefundRequest::where(
        'request_transaction_id',
        $firstRefund->public_id
    )->firstOrFail();

    // Rechazar la primera solicitud.

    $adjustmentService->rejectRefund(
        refundRequest: $refundRequest,
        reviewedBy: 'admin-financiero-001',
        reviewReason: 'Solicitud rechazada'
    );

    // Segunda solicitud: $300.00.
    // Debe permitirse porque la anterior fue rechazada.

    $secondRefund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 30000,
        idempotencyKey: 'refund-second-' . Str::uuid(),
        requestedBy: $ownerId,
        reason: 'Nueva solicitud'
    );

    $this->assertSame(
        TransactionStatus::PENDIENTE,
        $secondRefund->status
    );

    $this->assertSame(
        30000,
        (int) $secondRefund->metadata['amount_cents']
    );

    // Confirmar que no hubo movimientos de dinero.

    $this->wallet->refresh();

    $this->assertSame(
        70000,
        $this->wallet->available_balance_cents
    );
}
public function test_it_approves_pending_refund_without_moving_money(): void
{
    $walletService = app(WalletService::class);
    $ledgerService = app(LedgerService::class);
    $adjustmentService = app(FinancialAdjustmentService::class);

    $ownerId = 'refund-approval-' . Str::uuid();

    // Crear la Wallet del estudiante.

    $this->wallet = $walletService->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    // Agregar $1,000.00 a la Wallet.

    $ledgerService->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    // Realizar un pago de $300.00.

    $payment = $ledgerService->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    // Solicitar una devolución de $100.00.

    $refund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: 'refund-request-' . Str::uuid(),
        requestedBy: $ownerId,
        reason: 'Solicitud de devolución'
    );

    $refundRequest = FinancialRefundRequest::where(
        'request_transaction_id',
        $refund->public_id
    )->firstOrFail();

    // Contar los movimientos contables existentes.

    $entriesBefore = LedgerEntry::where(
        'wallet_id',
        $this->wallet->public_id
    )->count();

    // Aprobar administrativamente la devolución.

    $approvedRequest = $adjustmentService->approveRefund(
        refundRequest: $refundRequest,
        reviewedBy: 'admin-financiero-001',
        reviewReason: 'Devolución autorizada'
    );

    // Verificar el estado administrativo.

    $this->assertSame(
        \App\Domains\Financial\Enums\RefundRequestStatus::APROBADA,
        $approvedRequest->status
    );

    // Verificar quién autorizó la devolución.

    $this->assertSame(
        'admin-financiero-001',
        $approvedRequest->reviewed_by
    );

    // Verificar el motivo de aprobación.

    $this->assertSame(
        'Devolución autorizada',
        $approvedRequest->review_reason
    );

    // Verificar que se registró la fecha.

    $this->assertNotNull(
        $approvedRequest->reviewed_at
    );

    // La transacción financiera sigue pendiente
    // porque todavía no se ha ejecutado el abono.

    $refund->refresh();

    $this->assertSame(
        TransactionStatus::PENDIENTE,
        $refund->status
    );

    // Verificar que el saldo no cambió.

    $this->wallet->refresh();

    $this->assertSame(
        70000,
        $this->wallet->available_balance_cents
    );

    // Verificar que no se generaron movimientos contables.

    $entriesAfter = LedgerEntry::where(
        'wallet_id',
        $this->wallet->public_id
    )->count();

    $this->assertSame(
        $entriesBefore,
        $entriesAfter
    );
}
public function test_it_completes_approved_refund_without_duplicating_money(): void
{
    $ledger = app(LedgerService::class);
    $service = app(FinancialAdjustmentService::class);
    $ownerId = 'refund-completion-' . Str::uuid();

    $this->wallet = app(WalletService::class)->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    $ledger->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    $payment = $ledger->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    $requestTransaction = $service->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: 'refund-request-' . Str::uuid(),
        requestedBy: $ownerId
    );

    $request = FinancialRefundRequest::where(
        'request_transaction_id',
        $requestTransaction->public_id
    )->firstOrFail();

    $completionKey = 'refund-complete-' . Str::uuid();

    // Una solicitud pendiente no puede ejecutar el abono.
    try {
        $service->completeRefund($request, $completionKey);
        $this->fail('No debe ejecutarse una solicitud pendiente.');
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'Solo se pueden ejecutar solicitudes aprobadas.',
            $exception->getMessage()
        );
    }

    $this->wallet->refresh();
    $this->assertSame(70000, $this->wallet->available_balance_cents);

    $service->approveRefund(
        refundRequest: $request,
        reviewedBy: 'admin-financiero-001'
    );

    $completed = $service->completeRefund($request, $completionKey);

    // Repetir con la misma clave no debe abonar otra vez.
    $retried = $service->completeRefund($request, $completionKey);

    $this->wallet->refresh();
    $request->refresh();
    $requestTransaction->refresh();

    $this->assertSame(80000, $this->wallet->available_balance_cents);

    $this->assertSame(
        strtolower($completed->public_id),
        strtolower($retried->public_id)
    );

    $this->assertSame(
        TransactionStatus::COMPLETADA,
        $completed->status
    );

    $this->assertSame(
        \App\Domains\Financial\Enums\RefundRequestStatus::COMPLETADA,
        $request->status
    );

    $this->assertNotNull($request->completed_at);

    $this->assertSame(
        strtolower($completed->public_id),
        strtolower($request->financial_transaction_id)
    );

    $this->assertSame(
        strtolower($payment->public_id),
        strtolower($completed->original_transaction_id)
    );

    $this->assertSame(
        TransactionStatus::COMPLETADA,
        $requestTransaction->status
    );

    $this->assertDatabaseHas(
        'ledger_entries',
        [
            'transaction_id' => $completed->public_id,
            'wallet_id' => $this->wallet->public_id,
            'movement_type' => 'DEVOLUCION',
            'amount_cents' => 10000,
            'available_balance_after_cents' => 80000,
        ],
        'sqlsrv'
    );

    $this->assertSame(
        1,
        LedgerEntry::where(
            'transaction_id',
            $completed->public_id
        )->count()
    );

    // Cambiar la clave tampoco permite una segunda devolución.
    try {
        $service->completeRefund(
            $request,
            'refund-other-key-' . Str::uuid()
        );

        $this->fail('No debe completarse otra vez con una clave diferente.');
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'La devolución ya fue completada con otra clave o presenta datos inconsistentes.',
            $exception->getMessage()
        );
    }

    // El pago ya devuelto parcialmente no puede reversarse completo.
    try {
        $service->reverse(
            originalTransaction: $payment,
            idempotencyKey: 'refund-reverse-' . Str::uuid()
        );

        $this->fail('No debe reversarse un pago con una devolución.');
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'No se puede reversar una operación con devoluciones pendientes, aprobadas o completadas.',
            $exception->getMessage()
        );
    }

    $this->wallet->refresh();
    $this->assertSame(80000, $this->wallet->available_balance_cents);
}
public function test_it_rolls_back_failed_refund_and_allows_retry(): void
{
    $ledger = app(LedgerService::class);
    $service = app(FinancialAdjustmentService::class);
    $ownerId = 'refund-rollback-' . Str::uuid();

    $this->wallet = app(WalletService::class)->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    $ledger->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    $payment = $ledger->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    $requestTransaction = $service->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: 'refund-request-' . Str::uuid(),
        requestedBy: $ownerId
    );

    $request = FinancialRefundRequest::where(
        'request_transaction_id',
        $requestTransaction->public_id
    )->firstOrFail();

    $service->approveRefund(
        refundRequest: $request,
        reviewedBy: 'admin-financiero-001'
    );

    $entriesBefore = LedgerEntry::where(
        'wallet_id',
        $this->wallet->public_id
    )->count();

    $completionKey = 'refund-complete-' . Str::uuid();

    // Ejecutar el abono real y provocar un error inmediatamente después.
    $failingLedger = $this->createMock(LedgerService::class);

    $failingLedger
        ->expects($this->once())
        ->method('credit')
        ->willReturnCallback(
            function (
                Wallet $wallet,
                int $amountCents,
                MovementType $movementType,
                string $idempotencyKey,
                ?string $referenceType = null,
                ?string $referenceId = null,
                array $metadata = []
            ) use ($ledger): FinancialTransaction {
                $ledger->credit(
                    wallet: $wallet,
                    amountCents: $amountCents,
                    movementType: $movementType,
                    idempotencyKey: $idempotencyKey,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    metadata: $metadata
                );

                throw new \RuntimeException(
                    'Fallo simulado después del abono.'
                );
            }
        );

    $this->app->instance(LedgerService::class, $failingLedger);

    try {
        $service->completeRefund($request, $completionKey);
        $this->fail('Se esperaba el fallo simulado.');
    } catch (\RuntimeException $exception) {
        $this->assertSame(
            'Fallo simulado después del abono.',
            $exception->getMessage()
        );
    } finally {
        // Restaurar el Ledger real para el siguiente intento.
        $this->app->instance(LedgerService::class, $ledger);
    }

    $this->wallet->refresh();
    $request->refresh();
    $requestTransaction->refresh();

    // El abono fallido no debe conservarse.
    $this->assertSame(70000, $this->wallet->available_balance_cents);

    $this->assertSame(
        \App\Domains\Financial\Enums\RefundRequestStatus::APROBADA,
        $request->status
    );

    $this->assertNull($request->completed_at);
    $this->assertNull($request->financial_transaction_id);

    $this->assertSame(
        TransactionStatus::PENDIENTE,
        $requestTransaction->status
    );

    $this->assertSame(
        0,
        FinancialTransaction::where(
            'idempotency_key',
            $completionKey
        )->count()
    );

    $this->assertSame(
        $entriesBefore,
        LedgerEntry::where(
            'wallet_id',
            $this->wallet->public_id
        )->count()
    );

    // Reintentar con la misma clave debe funcionar.
    $completed = $service->completeRefund($request, $completionKey);

    $this->wallet->refresh();
    $request->refresh();

    $this->assertSame(80000, $this->wallet->available_balance_cents);

    $this->assertSame(
        \App\Domains\Financial\Enums\RefundRequestStatus::COMPLETADA,
        $request->status
    );

    $this->assertSame(
        strtolower($completed->public_id),
        strtolower($request->financial_transaction_id)
    );

    $this->assertSame(
        $entriesBefore + 1,
        LedgerEntry::where(
            'wallet_id',
            $this->wallet->public_id
        )->count()
    );
}
public function test_it_cannot_complete_a_rejected_refund(): void
{
    $ledger = app(LedgerService::class);
    $service = app(FinancialAdjustmentService::class);
    $ownerId = 'refund-rejected-execution-' . Str::uuid();

    $this->wallet = app(WalletService::class)->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    $ledger->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'refund-credit-' . Str::uuid()
    );

    $payment = $ledger->debit(
        wallet: $this->wallet,
        amountCents: 30000,
        movementType: MovementType::PAGO,
        idempotencyKey: 'refund-payment-' . Str::uuid()
    );

    $requestTransaction = $service->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: 'refund-request-' . Str::uuid(),
        requestedBy: $ownerId
    );

    $request = FinancialRefundRequest::where(
        'request_transaction_id',
        $requestTransaction->public_id
    )->firstOrFail();

    $service->rejectRefund(
        refundRequest: $request,
        reviewedBy: 'admin-financiero-001',
        reviewReason: 'Solicitud improcedente'
    );

    $completionKey = 'refund-complete-' . Str::uuid();

    $entriesBefore = LedgerEntry::where(
        'wallet_id',
        $this->wallet->public_id
    )->count();

    try {
        $service->completeRefund($request, $completionKey);
        $this->fail('No debe ejecutarse una devolución rechazada.');
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'Solo se pueden ejecutar solicitudes aprobadas.',
            $exception->getMessage()
        );
    }

    $this->wallet->refresh();
    $request->refresh();
    $requestTransaction->refresh();

    $this->assertSame(70000, $this->wallet->available_balance_cents);

    $this->assertSame(
        \App\Domains\Financial\Enums\RefundRequestStatus::RECHAZADA,
        $request->status
    );

    $this->assertSame(
        TransactionStatus::FALLIDA,
        $requestTransaction->status
    );

    $this->assertNull($request->completed_at);
    $this->assertNull($request->financial_transaction_id);

    $this->assertSame(
        0,
        FinancialTransaction::where(
            'idempotency_key',
            $completionKey
        )->count()
    );

    $this->assertSame(
        $entriesBefore,
        LedgerEntry::where(
            'wallet_id',
            $this->wallet->public_id
        )->count()
    );
}
}