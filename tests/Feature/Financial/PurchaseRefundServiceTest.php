<?php

namespace Tests\Feature\Financial;

use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusLedgerEntry;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\PurchasePaymentBonus;
use App\Domains\Financial\Models\PurchaseRefundBonus;
use App\Domains\Financial\Models\PurchaseRefundRequest;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\BonusService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\PurchasePaymentService;
use App\Domains\Financial\Services\PurchaseRefundService;
use App\Domains\Financial\Services\WalletService;
use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;

class PurchaseRefundServiceTest extends TestCase
{
    private ?Wallet $wallet = null;
    private ?Bonus $bonus = null;
    private ?Bonus $secondBonus = null;
    private ?PurchasePayment $payment = null;
    private ?ServiceClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        $authorization = $this->createMock(
            BonusAuthorizationProvider::class
        );

        $authorization
            ->method('canIssueBonus')
            ->willReturn(true);

        $this->app->instance(
            BonusAuthorizationProvider::class,
            $authorization
        );
    }

    protected function tearDown(): void
    {
        try {
            if ($this->wallet) {
                $refunds = PurchaseRefundRequest::where(
                    'wallet_id',
                    $this->wallet->public_id
                )->get();

                $transactionIds = LedgerEntry::where(
                    'wallet_id',
                    $this->wallet->public_id
                )
                    ->pluck('transaction_id')
                    ->merge($refunds->pluck('request_transaction_id'))
                    ->merge($refunds->pluck('financial_transaction_id'))
                    ->filter()
                    ->unique();

                PurchaseRefundBonus::whereIn(
                    'refund_request_id',
                    $refunds->pluck('public_id')
                )->delete();

                PurchaseRefundRequest::where(
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
            }

            if ($this->payment) {
                PurchasePaymentBonus::where(
                    'purchase_payment_id',
                    $this->payment->public_id
                )->delete();

                $this->payment->delete();
            }

            if ($this->bonus) {
                BonusLedgerEntry::where(
                    'bonus_id',
                    $this->bonus->public_id
                )->delete();

                $this->bonus->restrictions()->delete();
                $this->bonus->delete();
            }

            if ($this->secondBonus) {
                BonusLedgerEntry::where(
                    'bonus_id',
                    $this->secondBonus->public_id
            )->delete();

            $this->secondBonus->restrictions()->delete();
            $this->secondBonus->delete();
        } 

            $this->client?->delete();
            $this->wallet?->delete();
        } finally {
            parent::tearDown();
        }
    }

    private function createPurchase(int $totalAmountCents): void
    {
        $ownerId = 'purchase-refund-test-' . Str::uuid();

        $this->wallet = app(WalletService::class)->create(
            ownerType: 'STUDENT',
            ownerId: $ownerId,
            walletType: WalletType::USUARIO
        );

        app(LedgerService::class)->credit(
            wallet: $this->wallet,
            amountCents: 100000,
            movementType: MovementType::RECARGA,
            idempotencyKey: 'purchase-refund-credit-' . Str::uuid()
        );

        $this->bonus = app(BonusService::class)->issue(
            beneficiaryType: 'STUDENT',
            beneficiaryId: $ownerId,
            issuerType: 'SYSTEM',
            issuerId: 'system-test',
            type: BonusType::BENEFICIO,
            amountCents: 50000,
            validFrom: now()->subDay(),
            expiresAt: now()->addDay(),
            combinable: true,
            allowsPartialUse: true,
            externalReference: 'purchase-refund-bonus-' . Str::uuid()
        );

        $key = 'purchase-refund-payment-' . Str::uuid();

        app(PurchasePaymentService::class)->pay(
            wallet: $this->wallet,
            totalAmountCents: $totalAmountCents,
            bonusPublicId: $this->bonus->public_id,
            idempotencyKey: $key
        );

        $this->payment = PurchasePayment::where(
            'idempotency_key',
            $key
        )->firstOrFail();
    }

    public function test_it_requests_a_mixed_refund_without_changing_balances(): void
    {
        // Compra: $500 de bono y $200 de wallet.
        $this->createPurchase(70000);

        $service = app(PurchaseRefundService::class);
        $key = 'mixed-refund-request-' . Str::uuid();

        $bonusRefunds = [
            [
                'bonus_id' => $this->bonus->public_id,
                'amount_cents' => 10000,
            ],
        ];

        $refund = $service->request(
            payment: $this->payment,
            walletAmountCents: 5000,
            bonusRefunds: $bonusRefunds,
            idempotencyKey: $key,
            requestedBy: 'service:purchase-refund-test'
        );

        $retry = $service->request(
            payment: $this->payment,
            walletAmountCents: 5000,
            bonusRefunds: $bonusRefunds,
            idempotencyKey: $key,
            requestedBy: 'service:purchase-refund-test'
        );

        $this->wallet->refresh();
        $this->bonus->refresh();

        $this->assertSame(80000, $this->wallet->available_balance_cents);
        $this->assertSame(0, $this->bonus->remaining_amount_cents);

        $this->assertSame('PENDIENTE', $refund->status->value);
        $this->assertSame(15000, $refund->total_amount_cents);
        $this->assertSame(5000, $refund->wallet_amount_cents);
        $this->assertSame(10000, $refund->bonus_amount_cents);

        $this->assertSame(
            strtolower($refund->public_id),
            strtolower($retry->public_id)
        );

        $this->assertSame(
            1,
            PurchaseRefundRequest::where(
                'purchase_payment_id',
                $this->payment->public_id
            )->count()
        );

        $this->assertDatabaseHas('purchase_refund_bonuses', [
            'refund_request_id' => $refund->public_id,
            'bonus_id' => $this->bonus->public_id,
            'amount_cents' => 10000,
        ], 'sqlsrv');

        $this->assertSame(
            0,
            LedgerEntry::where(
                'transaction_id',
                $refund->request_transaction_id
            )->count()
        );

        $this->assertNull($refund->financial_transaction_id);
    }

    public function test_it_requests_a_bonus_only_refund_without_a_wallet_charge(): void
    {
        // Compra de $300 cubierta completamente por el bono.
        $this->createPurchase(30000);

        $this->assertFalse(
            FinancialTransaction::where(
                'idempotency_key',
                $this->payment->idempotency_key
            )->exists()
        );

        $refund = app(PurchaseRefundService::class)->request(
            payment: $this->payment,
            walletAmountCents: 0,
            bonusRefunds: [
                [
                    'bonus_id' => $this->bonus->public_id,
                    'amount_cents' => 10000,
                ],
            ],
            idempotencyKey: 'bonus-only-refund-' . Str::uuid(),
            requestedBy: 'service:purchase-refund-test'
        );

        $this->wallet->refresh();
        $this->bonus->refresh();

        $this->assertSame(100000, $this->wallet->available_balance_cents);
        $this->assertSame(20000, $this->bonus->remaining_amount_cents);

        $this->assertSame('PENDIENTE', $refund->status->value);
        $this->assertSame(10000, $refund->total_amount_cents);
        $this->assertSame(0, $refund->wallet_amount_cents);
        $this->assertSame(10000, $refund->bonus_amount_cents);

        $this->assertSame(
            0,
            LedgerEntry::where(
                'transaction_id',
                $refund->request_transaction_id
            )->count()
        );
    }
public function test_it_approves_a_purchase_refund_without_moving_money(): void
{
    $this->createPurchase(70000);

    $service = app(PurchaseRefundService::class);

    $request = $service->request(
        payment: $this->payment,
        walletAmountCents: 5000,
        bonusRefunds: [
            [
                'bonus_id' => $this->bonus->public_id,
                'amount_cents' => 10000,
            ],
        ],
        idempotencyKey: 'purchase-approval-' . Str::uuid(),
        requestedBy: 'service:purchase-requester'
    );

    $approved = $service->approve(
        refundRequest: $request,
        reviewedBy: 'service:purchase-reviewer',
        reviewReason: 'Devolución autorizada'
    );

    $this->assertSame('APROBADA', $approved->status->value);
    $this->assertSame(
        'service:purchase-reviewer',
        $approved->reviewed_by
    );
    $this->assertNotNull($approved->reviewed_at);
    $this->assertSame(
        'PENDIENTE',
        $approved->requestTransaction->status->value
    );
    $this->assertNull($approved->financial_transaction_id);

    $this->wallet->refresh();
    $this->bonus->refresh();

    $this->assertSame(80000, $this->wallet->available_balance_cents);
    $this->assertSame(0, $this->bonus->remaining_amount_cents);

    // Una solicitud aprobada no puede rechazarse después.
    try {
        $service->reject(
            refundRequest: $request,
            reviewedBy: 'service:another-reviewer',
            reviewReason: 'Segundo intento de revisión'
        );

        $this->fail('No debe revisarse nuevamente una solicitud aprobada.');
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'Solo se pueden revisar solicitudes pendientes.',
            $exception->getMessage()
        );
    }
}

public function test_it_releases_purchase_refund_amounts_after_rejection(): void
{
    $this->createPurchase(70000);

    $service = app(PurchaseRefundService::class);

    $bonusRefunds = [
        [
            'bonus_id' => $this->bonus->public_id,
            'amount_cents' => 50000,
        ],
    ];

    // Reservar el importe completo de la compra.
    $first = $service->request(
        payment: $this->payment,
        walletAmountCents: 20000,
        bonusRefunds: $bonusRefunds,
        idempotencyKey: 'purchase-first-' . Str::uuid(),
        requestedBy: 'service:purchase-requester'
    );

    // No debe permitirse reservar dinero adicional.
    try {
        $service->request(
            payment: $this->payment,
            walletAmountCents: 1,
            bonusRefunds: [],
            idempotencyKey: 'purchase-excess-' . Str::uuid(),
            requestedBy: 'service:purchase-requester'
        );

        $this->fail('No debe superarse el importe disponible de wallet.');
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'La devolución de wallet supera lo disponible de esta compra.',
            $exception->getMessage()
        );
    }

    $rejected = $service->reject(
        refundRequest: $first,
        reviewedBy: 'service:purchase-reviewer',
        reviewReason: 'Solicitud improcedente'
    );

    $this->assertSame('RECHAZADA', $rejected->status->value);
    $this->assertSame(
        'FALLIDA',
        $rejected->requestTransaction->status->value
    );
    $this->assertNotNull($rejected->reviewed_at);

    // Al rechazar, se puede solicitar nuevamente el importe completo.
    $second = $service->request(
        payment: $this->payment,
        walletAmountCents: 20000,
        bonusRefunds: $bonusRefunds,
        idempotencyKey: 'purchase-second-' . Str::uuid(),
        requestedBy: 'service:purchase-requester'
    );

    $this->assertSame('PENDIENTE', $second->status->value);
    $this->assertSame(70000, $second->total_amount_cents);

    $this->wallet->refresh();
    $this->bonus->refresh();

    $this->assertSame(80000, $this->wallet->available_balance_cents);
    $this->assertSame(0, $this->bonus->remaining_amount_cents);
}
public function test_it_completes_a_mixed_refund_without_duplicate_movements(): void
{
    $this->createPurchase(70000);

    $service = app(PurchaseRefundService::class);

    $request = $service->request(
        payment: $this->payment,
        walletAmountCents: 5000,
        bonusRefunds: [
            [
                'bonus_id' => $this->bonus->public_id,
                'amount_cents' => 10000,
            ],
        ],
        idempotencyKey: 'mixed-execution-request-' . Str::uuid(),
        requestedBy: 'service:purchase-requester'
    );

    $service->approve(
        refundRequest: $request,
        reviewedBy: 'service:purchase-reviewer'
    );

    $key = 'mixed-execution-' . Str::uuid();
    $originalExpiry = $this->bonus->expires_at->toISOString();

    $completed = $service->complete(
        refundRequest: $request,
        idempotencyKey: $key,
        executedBy: 'service:purchase-executor'
    );

    $retried = $service->complete(
        refundRequest: $request,
        idempotencyKey: $key,
        executedBy: 'service:another-executor'
    );

    $this->wallet->refresh();
    $this->bonus->refresh();
    $request->refresh();

    $this->assertSame(85000, $this->wallet->available_balance_cents);
    $this->assertSame(10000, $this->bonus->remaining_amount_cents);
    $this->assertSame('ACTIVO', $this->bonus->status->value);
    $this->assertSame(
        $originalExpiry,
        $this->bonus->expires_at->toISOString()
    );

    $this->assertSame('COMPLETADA', $request->status->value);
    $this->assertNotNull($request->completed_at);
    $this->assertSame(
        'COMPLETADA',
        $request->requestTransaction->status->value
    );

    $this->assertSame(
        strtolower($completed->public_id),
        strtolower($retried->public_id)
    );

    $this->assertSame(
        'service:purchase-executor',
        $retried->metadata['executed_by']
    );

    $this->assertDatabaseHas('ledger_entries', [
        'transaction_id' => $completed->public_id,
        'wallet_id' => $this->wallet->public_id,
        'movement_type' => 'DEVOLUCION',
        'amount_cents' => 5000,
    ], 'sqlsrv');

    $this->assertDatabaseHas('bonus_ledger_entries', [
        'bonus_id' => $this->bonus->public_id,
        'movement_type' => 'DEVOLUCION',
        'amount_cents' => 10000,
        'remaining_after_cents' => 10000,
        'reference_type' => 'PURCHASE_REFUND',
        'reference_id' => $request->public_id,
    ], 'sqlsrv');

    $this->assertSame(
        1,
        LedgerEntry::where(
            'transaction_id',
            $completed->public_id
        )->count()
    );

    $this->assertSame(
        1,
        BonusLedgerEntry::where(
            'bonus_id',
            $this->bonus->public_id
        )
            ->where('reference_type', 'PURCHASE_REFUND')
            ->where('reference_id', $request->public_id)
            ->count()
    );

    // Una clave diferente tampoco permite devolver otra vez.
    try {
        $service->complete(
            refundRequest: $request,
            idempotencyKey: 'different-execution-' . Str::uuid(),
            executedBy: 'service:purchase-executor'
        );

        $this->fail('No debe ejecutarse dos veces con claves diferentes.');
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'La devolución ya fue completada con otra clave o presenta datos inconsistentes.',
            $exception->getMessage()
        );
    }
}

public function test_it_restores_a_bonus_without_crediting_the_wallet(): void
{
    $this->createPurchase(30000);

    $service = app(PurchaseRefundService::class);

    $request = $service->request(
        payment: $this->payment,
        walletAmountCents: 0,
        bonusRefunds: [
            [
                'bonus_id' => $this->bonus->public_id,
                'amount_cents' => 10000,
            ],
        ],
        idempotencyKey: 'bonus-restoration-request-' . Str::uuid(),
        requestedBy: 'service:purchase-requester'
    );

    $service->approve(
        refundRequest: $request,
        reviewedBy: 'service:purchase-reviewer'
    );

    $key = 'bonus-restoration-' . Str::uuid();

    $completed = $service->complete(
        refundRequest: $request,
        idempotencyKey: $key,
        executedBy: 'service:purchase-executor'
    );

    $service->complete(
        refundRequest: $request,
        idempotencyKey: $key,
        executedBy: 'service:purchase-executor'
    );

    $this->wallet->refresh();
    $this->bonus->refresh();
    $request->refresh();

    $this->assertSame(100000, $this->wallet->available_balance_cents);
    $this->assertSame(30000, $this->bonus->remaining_amount_cents);
    $this->assertSame('COMPLETADA', $request->status->value);

    $this->assertSame(
        0,
        LedgerEntry::where(
            'transaction_id',
            $completed->public_id
        )->count()
    );

    $this->assertSame(
        1,
        BonusLedgerEntry::where(
            'bonus_id',
            $this->bonus->public_id
        )
            ->where('reference_type', 'PURCHASE_REFUND')
            ->where('reference_id', $request->public_id)
            ->count()
    );

    $this->assertSame(
        1,
        FinancialTransaction::where(
            'idempotency_key',
            $key
        )->count()
    );
}
public function test_it_blocks_the_entire_refund_when_an_exhausted_bonus_has_expired(): void
{
    $this->createPurchase(70000);

    $service = app(PurchaseRefundService::class);

    $request = $service->request(
        payment: $this->payment,
        walletAmountCents: 5000,
        bonusRefunds: [
            [
                'bonus_id' => $this->bonus->public_id,
                'amount_cents' => 10000,
            ],
        ],
        idempotencyKey: 'expired-bonus-request-' . Str::uuid(),
        requestedBy: 'service:purchase-requester'
    );

    $service->approve(
        refundRequest: $request,
        reviewedBy: 'service:purchase-reviewer'
    );

    $this->bonus->refresh();
    $this->assertSame('AGOTADO', $this->bonus->status->value);

    // Mantener AGOTADO y hacer que su fecha de vigencia haya terminado.
    $this->bonus->expires_at = now()->subMinute();
    $this->bonus->save();

    $walletEntriesBefore = LedgerEntry::where(
        'wallet_id',
        $this->wallet->public_id
    )->count();

    $bonusEntriesBefore = BonusLedgerEntry::where(
        'bonus_id',
        $this->bonus->public_id
    )->count();

    $key = 'expired-bonus-execution-' . Str::uuid();

    try {
        $service->complete(
            refundRequest: $request,
            idempotencyKey: $key,
            executedBy: 'service:purchase-executor'
        );

        $this->fail('No debe restaurarse un bono fuera de vigencia.');
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'No se puede restaurar un bono cancelado, expirado o fuera de vigencia.',
            $exception->getMessage()
        );
    }

    $this->wallet->refresh();
    $this->bonus->refresh();
    $request->refresh();

    $this->assertSame(80000, $this->wallet->available_balance_cents);
    $this->assertSame(0, $this->bonus->remaining_amount_cents);
    $this->assertSame('AGOTADO', $this->bonus->status->value);
    $this->assertSame('APROBADA', $request->status->value);
    $this->assertNull($request->completed_at);
    $this->assertNull($request->financial_transaction_id);

    $this->assertSame(
        $walletEntriesBefore,
        LedgerEntry::where(
            'wallet_id',
            $this->wallet->public_id
        )->count()
    );

    $this->assertSame(
        $bonusEntriesBefore,
        BonusLedgerEntry::where(
            'bonus_id',
            $this->bonus->public_id
        )->count()
    );

    $this->assertSame(
        0,
        FinancialTransaction::where(
            'idempotency_key',
            $key
        )->count()
    );
}
public function test_it_rolls_back_a_mixed_refund_and_allows_retry(): void
{
    $this->createPurchase(70000);

    $service = app(PurchaseRefundService::class);

    $request = $service->request(
        payment: $this->payment,
        walletAmountCents: 5000,
        bonusRefunds: [
            [
                'bonus_id' => $this->bonus->public_id,
                'amount_cents' => 10000,
            ],
        ],
        idempotencyKey: 'mixed-rollback-request-' . Str::uuid(),
        requestedBy: 'service:purchase-requester'
    );

    $service->approve(
        refundRequest: $request,
        reviewedBy: 'service:purchase-reviewer'
    );

    $walletEntriesBefore = LedgerEntry::where(
        'wallet_id',
        $this->wallet->public_id
    )->count();

    $bonusEntriesBefore = BonusLedgerEntry::where(
        'bonus_id',
        $this->bonus->public_id
    )->count();

    $key = 'mixed-rollback-execution-' . Str::uuid();

    // Usar una copia del despachador para no dejar el fallo
    // simulado registrado en otras pruebas.
    $originalDispatcher = BonusLedgerEntry::getEventDispatcher();
    BonusLedgerEntry::setEventDispatcher(clone $originalDispatcher);

    try {
        BonusLedgerEntry::creating(function (BonusLedgerEntry $entry) {
            if (
                $entry->movement_type ===
                \App\Domains\Financial\Enums\BonusMovementType::DEVOLUCION
            ) {
                throw new \RuntimeException(
                    'Fallo simulado al registrar la devolución del bono.'
                );
            }
        });

        $service->complete(
            refundRequest: $request,
            idempotencyKey: $key,
            executedBy: 'service:purchase-executor'
        );

        $this->fail('Se esperaba el fallo simulado.');
    } catch (\RuntimeException $exception) {
        $this->assertSame(
            'Fallo simulado al registrar la devolución del bono.',
            $exception->getMessage()
        );
    } finally {
        BonusLedgerEntry::setEventDispatcher($originalDispatcher);
    }

    $this->wallet->refresh();
    $this->bonus->refresh();
    $request->refresh();

    // Deshacer tanto el abono de wallet como la restauración del bono.
    $this->assertSame(80000, $this->wallet->available_balance_cents);
    $this->assertSame(0, $this->bonus->remaining_amount_cents);
    $this->assertSame('AGOTADO', $this->bonus->status->value);

    $this->assertSame('APROBADA', $request->status->value);
    $this->assertNull($request->completed_at);
    $this->assertNull($request->financial_transaction_id);
    $this->assertSame(
        'PENDIENTE',
        $request->requestTransaction->status->value
    );

    $this->assertSame(
        $walletEntriesBefore,
        LedgerEntry::where(
            'wallet_id',
            $this->wallet->public_id
        )->count()
    );

    $this->assertSame(
        $bonusEntriesBefore,
        BonusLedgerEntry::where(
            'bonus_id',
            $this->bonus->public_id
        )->count()
    );

    $this->assertSame(
        0,
        FinancialTransaction::where(
            'idempotency_key',
            $key
        )->count()
    );

    // Reintentar con la misma clave después de retirar el fallo.
    $completed = $service->complete(
        refundRequest: $request,
        idempotencyKey: $key,
        executedBy: 'service:purchase-executor'
    );

    $this->wallet->refresh();
    $this->bonus->refresh();
    $request->refresh();

    $this->assertSame(85000, $this->wallet->available_balance_cents);
    $this->assertSame(10000, $this->bonus->remaining_amount_cents);
    $this->assertSame('ACTIVO', $this->bonus->status->value);
    $this->assertSame('COMPLETADA', $request->status->value);

    $this->assertSame(
        1,
        LedgerEntry::where(
            'transaction_id',
            $completed->public_id
        )->count()
    );

    $this->assertSame(
        1,
        BonusLedgerEntry::where(
            'reference_type',
            'PURCHASE_REFUND'
        )
            ->where('reference_id', $request->public_id)
            ->count()
    );
}
public function test_it_returns_each_part_to_its_original_bonus_and_wallet(): void
{
    $ownerId = 'multiple-bonus-refund-' . Str::uuid();

    $this->wallet = app(WalletService::class)->create(
        ownerType: 'STUDENT',
        ownerId: $ownerId,
        walletType: WalletType::USUARIO
    );

    app(LedgerService::class)->credit(
        wallet: $this->wallet,
        amountCents: 100000,
        movementType: MovementType::RECARGA,
        idempotencyKey: 'multi-refund-credit-' . Str::uuid()
    );

    $bonusService = app(BonusService::class);

    $this->bonus = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: $ownerId,
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 30000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'multi-refund-bonus-a-' . Str::uuid()
    );

    $this->secondBonus = $bonusService->issue(
        beneficiaryType: 'STUDENT',
        beneficiaryId: $ownerId,
        issuerType: 'SYSTEM',
        issuerId: 'system-test',
        type: BonusType::BENEFICIO,
        amountCents: 20000,
        validFrom: now()->subDay(),
        expiresAt: now()->addDay(),
        combinable: true,
        allowsPartialUse: true,
        externalReference: 'multi-refund-bonus-b-' . Str::uuid()
    );

    $paymentKey = 'multi-refund-payment-' . Str::uuid();

    app(PurchasePaymentService::class)->payWithMultipleBonuses(
        wallet: $this->wallet,
        totalAmountCents: 70000,
        bonusPublicIds: [
            $this->bonus->public_id,
            $this->secondBonus->public_id,
        ],
        idempotencyKey: $paymentKey
    );

    $this->payment = PurchasePayment::where(
        'idempotency_key',
        $paymentKey
    )->firstOrFail();

    $service = app(PurchaseRefundService::class);

    $request = $service->request(
        payment: $this->payment,
        walletAmountCents: 5000,
        bonusRefunds: [
            [
                'bonus_id' => $this->bonus->public_id,
                'amount_cents' => 10000,
            ],
            [
                'bonus_id' => $this->secondBonus->public_id,
                'amount_cents' => 5000,
            ],
        ],
        idempotencyKey: 'multi-refund-request-' . Str::uuid(),
        requestedBy: 'service:purchase-requester'
    );

    $service->approve(
        refundRequest: $request,
        reviewedBy: 'service:purchase-reviewer'
    );

    $key = 'multi-refund-complete-' . Str::uuid();

    $completed = $service->complete(
        refundRequest: $request,
        idempotencyKey: $key,
        executedBy: 'service:purchase-executor'
    );

    $service->complete(
        refundRequest: $request,
        idempotencyKey: $key,
        executedBy: 'service:purchase-executor'
    );

    $this->wallet->refresh();
    $this->bonus->refresh();
    $this->secondBonus->refresh();
    $request->refresh();

    $this->assertSame(85000, $this->wallet->available_balance_cents);
    $this->assertSame(10000, $this->bonus->remaining_amount_cents);
    $this->assertSame(5000, $this->secondBonus->remaining_amount_cents);
    $this->assertSame('ACTIVO', $this->bonus->status->value);
    $this->assertSame('ACTIVO', $this->secondBonus->status->value);
    $this->assertSame('COMPLETADA', $request->status->value);

    $this->assertDatabaseHas('bonus_ledger_entries', [
        'bonus_id' => $this->bonus->public_id,
        'movement_type' => 'DEVOLUCION',
        'amount_cents' => 10000,
        'remaining_after_cents' => 10000,
        'reference_id' => $request->public_id,
    ], 'sqlsrv');

    $this->assertDatabaseHas('bonus_ledger_entries', [
        'bonus_id' => $this->secondBonus->public_id,
        'movement_type' => 'DEVOLUCION',
        'amount_cents' => 5000,
        'remaining_after_cents' => 5000,
        'reference_id' => $request->public_id,
    ], 'sqlsrv');

    $this->assertSame(
        2,
        BonusLedgerEntry::where(
            'reference_type',
            'PURCHASE_REFUND'
        )->where('reference_id', $request->public_id)->count()
    );

    $this->assertSame(
        1,
        LedgerEntry::where(
            'transaction_id',
            $completed->public_id
        )->count()
    );
}
public function test_it_completes_a_mixed_purchase_refund_through_the_api(): void
{
    $this->createPurchase(70000);

    $scopes = [
        'financial:read',
        'financial:write',
        'financial:refund:review',
        'financial:refund:execute',
    ];

    $this->client = ServiceClient::create([
        'name' => 'Mixed purchase refund API test',
        'client_id' => 'svc_purchase_refund_' . Str::uuid(),
        'secret_hash' => Hash::make('test-secret'),
        'scopes' => $scopes,
        'active' => true,
    ]);

    $tokens = [];

    foreach ($scopes as $scope) {
        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->client->client_id,
            'client_secret' => 'test-secret',
            'scope' => $scope,
        ]);

        $response->assertOk();
        $tokens[$scope] = $response->json('access_token');
    }

    $baseUrl = '/api/v1/financial/purchase-refunds';

    $payload = [
        'purchase_payment_id' => $this->payment->public_id,
        'wallet_amount_cents' => 5000,
        'bonus_refunds' => [
            [
                'bonus_id' => $this->bonus->public_id,
                'amount_cents' => 10000,
            ],
        ],
        'requested_by' => 'solicitante-falso',
    ];

    $requestHeaders = [
        'Authorization' => 'Bearer ' . $tokens['financial:write'],
        'Idempotency-Key' => 'api-purchase-request-' . Str::uuid(),
    ];

    $created = $this->postJson($baseUrl, $payload, $requestHeaders);

    $created
        ->assertCreated()
        ->assertJsonPath('data.status', 'PENDIENTE')
        ->assertJsonPath('data.total_amount_cents', 15000)
        ->assertJsonPath(
            'data.requested_by',
            'service:' . $this->client->client_id
        );

    $refundId = $created->json('data.id');
    $url = $baseUrl . '/' . $refundId;

    $this->postJson($baseUrl, $payload, $requestHeaders)
        ->assertOk()
        ->assertJsonPath(
            'data.id',
            fn ($id) => strtolower($id) === strtolower($refundId)
        );

    $this->getJson($url, [
        'Authorization' => 'Bearer ' . $tokens['financial:read'],
    ])
        ->assertOk()
        ->assertJsonPath('data.wallet_amount_cents', 5000)
        ->assertJsonPath('data.bonus_amount_cents', 10000);

    $executeHeaders = [
        'Authorization' =>
            'Bearer ' . $tokens['financial:refund:execute'],
        'Idempotency-Key' => 'api-purchase-complete-' . Str::uuid(),
    ];

    // No se puede ejecutar antes de aprobar.
    $this->postJson($url . '/complete', [], $executeHeaders)
        ->assertStatus(409);

    $this->postJson(
        $url . '/approve',
        [
            'review_reason' => 'Devolución autorizada',
            'reviewed_by' => 'revisor-falso',
        ],
        [
            'Authorization' =>
                'Bearer ' . $tokens['financial:refund:review'],
        ]
    )
        ->assertOk()
        ->assertJsonPath('data.status', 'APROBADA')
        ->assertJsonPath(
            'data.reviewed_by',
            'service:' . $this->client->client_id
        );

    $this->wallet->refresh();
    $this->bonus->refresh();

    $this->assertSame(80000, $this->wallet->available_balance_cents);
    $this->assertSame(0, $this->bonus->remaining_amount_cents);

    $completed = $this->postJson(
        $url . '/complete',
        ['executed_by' => 'ejecutor-falso'],
        $executeHeaders
    );

    $completed
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETADA');

    $transactionId = $completed->json('data.financial_transaction_id');

    $this->postJson($url . '/complete', [], $executeHeaders)
        ->assertOk()
        ->assertJsonPath(
            'data.financial_transaction_id',
            fn ($id) =>
                strtolower($id) === strtolower($transactionId)
        );

    $this->wallet->refresh();
    $this->bonus->refresh();

    $this->assertSame(85000, $this->wallet->available_balance_cents);
    $this->assertSame(10000, $this->bonus->remaining_amount_cents);

    $transaction = FinancialTransaction::where(
        'public_id',
        $transactionId
    )->firstOrFail();

    $this->assertSame(
        'service:' . $this->client->client_id,
        $transaction->metadata['executed_by']
    );

    $this->assertSame(
        1,
        LedgerEntry::where('transaction_id', $transactionId)->count()
    );

    $this->assertSame(
        1,
        BonusLedgerEntry::where('reference_type', 'PURCHASE_REFUND')
            ->where('reference_id', $refundId)
            ->count()
    );

    $this->assertSame(
        1,
        PurchaseRefundRequest::where(
            'purchase_payment_id',
            $this->payment->public_id
        )->count()
    );
}
public function test_it_blocks_legacy_refunds_and_reversals_of_mixed_purchases(): void
{
    $this->createPurchase(70000);

    $original = FinancialTransaction::where(
        'idempotency_key',
        $this->payment->idempotency_key
    )->firstOrFail();

    $service = app(
        \App\Domains\Financial\Services\FinancialAdjustmentService::class
    );

    $refundKey = 'legacy-purchase-refund-' . Str::uuid();
    $reverseKey = 'legacy-purchase-reverse-' . Str::uuid();

    $operations = [
        fn () => $service->refund(
            originalTransaction: $original,
            amountCents: 5000,
            idempotencyKey: $refundKey,
            requestedBy: 'service:legacy-requester'
        ),
        fn () => $service->reverse(
            originalTransaction: $original,
            idempotencyKey: $reverseKey
        ),
    ];

    foreach ($operations as $operation) {
        try {
            $operation();
            $this->fail('Debe usarse el flujo de devolución de compra.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'Los pagos con bonos deben devolverse mediante el flujo de devolución de compra.',
                $exception->getMessage()
            );
        }
    }

    $this->wallet->refresh();
    $this->bonus->refresh();
    $original->refresh();

    $this->assertSame(80000, $this->wallet->available_balance_cents);
    $this->assertSame(0, $this->bonus->remaining_amount_cents);
    $this->assertSame('COMPLETADA', $original->status->value);

    $this->assertSame(
        0,
        FinancialTransaction::whereIn(
            'idempotency_key',
            [$refundKey, $reverseKey]
        )->count()
    );
}
}