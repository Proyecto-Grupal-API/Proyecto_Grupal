<?php

namespace Tests\Feature\Financial;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
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
            reason: 'Prueba de devolución'
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
        reason: 'Primera solicitud'
    );

    $secondRefund = $adjustmentService->refund(
        originalTransaction: $payment,
        amountCents: 10000,
        idempotencyKey: $idempotencyKey,
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
        reason: 'Primera solicitud'
    );

    try {
        $adjustmentService->refund(
            originalTransaction: $payment,
            amountCents: 20000,
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
}