<?php

use App\Http\Controllers\StudentServicesController;
use App\Http\Controllers\OAuthTokenController;
use App\Http\Controllers\Financial\WalletController;
use App\Http\Controllers\Financial\TopUpController;
use App\Http\Controllers\Financial\WithdrawalController;
use App\Http\Controllers\Financial\BonusController;
use App\Http\Controllers\Financial\RefundController;
use App\Http\Controllers\Financial\PurchaseRefundController;

use App\Http\Controllers\Financial\FinancialLimitController;
use App\Http\Controllers\Financial\TransactionAlertController;
use App\Http\Controllers\Financial\ReconciliationController;

use App\Http\Controllers\Financial\WalletHoldController;
use App\Http\Controllers\Financial\ReversalController;
use App\Http\Controllers\Financial\WalletHoldPolicyController;
use Illuminate\Support\Facades\Route;

Route::post('/oauth/token', OAuthTokenController::class)
    ->middleware('throttle:60,1');

Route::prefix('v1')->middleware('oauth.service')->group(function () {
    Route::get(
        '/students/{studentId}/status',
        [StudentServicesController::class, 'status']
    );

    Route::get(
        '/students/{studentId}/status/history',
        [StudentServicesController::class, 'statusHistory']
    );

    Route::get(
        '/students/{studentId}/consents',
        [StudentServicesController::class, 'consents']
    );

    Route::post(
        '/students/{studentId}/consents',
        [StudentServicesController::class, 'acceptConsent']
    );

    Route::delete(
        '/students/{studentId}/consents/{consentId}',
        [StudentServicesController::class, 'revokeConsent']
    );

    Route::get(
        '/students/{studentId}/preferences',
        [StudentServicesController::class, 'preferences']
    );

    Route::patch(
        '/students/{studentId}/preferences',
        [StudentServicesController::class, 'updatePreferences']
    );

    Route::prefix('financial')->middleware('financial.correlation')->group(function () {
        // Asociación autorizada además del scope OAuth. Nunca hereda financial:read/write.
        Route::prefix('cash/associations/{associationId}')->group(function () {
            $administrative = \App\Http\Controllers\Financial\CashAdministrativeRequestController::class;
            $adminScope = \App\Http\Middleware\AuthorizeCashAdministrativeScope::class;
            Route::post('/shifts/{shiftId}/administrative-requests', [$administrative, 'storeAdministrative'])->whereUuid('shiftId')->middleware($adminScope);
            Route::get('/shifts/{shiftId}/administrative-requests/{administrativeId}', [$administrative, 'showAdministrative'])->whereUuid(['shiftId', 'administrativeId'])->middleware($adminScope);
            Route::post('/shifts/{shiftId}/administrative-requests/{administrativeId}/cancel', [$administrative, 'cancelAdministrative'])->whereUuid(['shiftId', 'administrativeId'])->middleware($adminScope);
            $approvals = \App\Http\Controllers\Financial\CashApprovalController::class;
            Route::get('/approval-policies', [$approvals, 'policies'])->middleware('oauth.service:financial:cash:approval:read');
            Route::post('/approval-policies', [$approvals, 'configurePolicy'])->middleware('oauth.service:financial:cash:approval:manage');
            Route::get('/approval-policies/history', [$approvals, 'policyHistory'])->middleware('oauth.service:financial:cash:approval:read');
            Route::get('/administrative-approval-requests', [$approvals, 'administrativeQueue'])->middleware('oauth.service:financial:cash:approval:read');
            Route::get('/approval-requests', [$approvals, 'reviewQueue'])->middleware('oauth.service:financial:cash:approval:read');
            $confirmations = \App\Http\Controllers\Financial\CashOperationConfirmationController::class;
            Route::post('/shifts/{shiftId}/confirmations', [$confirmations, 'store'])->whereUuid('shiftId')->middleware('oauth.service:financial:cash:operate');
            Route::get('/shifts/{shiftId}/confirmations/{confirmationId}', [$confirmations, 'showConfirmation'])->whereUuid(['shiftId', 'confirmationId'])->middleware('oauth.service:financial:cash:operate');
            Route::post('/shifts/{shiftId}/confirmations/{confirmationId}/cancel', [$confirmations, 'cancel'])->whereUuid(['shiftId', 'confirmationId'])->middleware('oauth.service:financial:cash:operate');
            $recoveries = \App\Http\Controllers\Financial\CashWithdrawalRecoveryController::class;
            Route::get('/withdrawal-refunds', [$recoveries, 'index'])->middleware('oauth.service:financial:cash:read');
            Route::post('/shifts/{shiftId}/withdrawal-refunds/{refundId}/recover', [$recoveries, 'recover'])
                ->whereUuid(['shiftId', 'refundId'])->middleware('oauth.service:financial:cash:recover');
            Route::get('/receipts/{receiptId}', [\App\Http\Controllers\Financial\CashController::class, 'receipt'])
                ->whereUuid('receiptId')->middleware('oauth.service:financial:cash:read');
            $admin = \App\Http\Controllers\Financial\CashRegisterAdministrationController::class;
            Route::post('/registers', [$admin, 'storeRegister'])->middleware('oauth.service:financial:cash:manage');
            Route::patch('/registers/{registerId}', [$admin, 'updateRegister'])->whereUuid('registerId')->middleware('oauth.service:financial:cash:manage');
            Route::get('/registers/{registerId}/history', [$admin, 'registerHistory'])->whereUuid('registerId')->middleware('oauth.service:financial:cash:read');
            Route::get('/registers', [\App\Http\Controllers\Financial\CashController::class, 'registers'])
                ->middleware('oauth.service:financial:cash:read');
            Route::get('/registers/{registerId}/shifts', [\App\Http\Controllers\Financial\CashController::class, 'shifts'])
                ->middleware('oauth.service:financial:cash:read')->whereUuid('registerId');
            Route::get('/shifts/{shiftId}', [\App\Http\Controllers\Financial\CashController::class, 'show'])
                ->middleware('oauth.service:financial:cash:read')->whereUuid('shiftId');
            Route::get('/shifts/{shiftId}/movements', [\App\Http\Controllers\Financial\CashController::class, 'movements'])
                ->middleware('oauth.service:financial:cash:read')->whereUuid('shiftId');
            Route::post('/registers/{registerId}/shifts', [\App\Http\Controllers\Financial\CashController::class, 'open'])
                ->middleware('oauth.service:financial:cash:operate')->whereUuid('registerId');
            Route::post('/shifts/{shiftId}/movements', [\App\Http\Controllers\Financial\CashController::class, 'move'])
                ->middleware('oauth.service:financial:cash:operate')->whereUuid('shiftId');
            Route::post('/shifts/{shiftId}/adjustments', [\App\Http\Controllers\Financial\CashController::class, 'adjust'])
                ->middleware('oauth.service:financial:cash:adjust')->whereUuid('shiftId');
            Route::post('/shifts/{shiftId}/topups', [\App\Http\Controllers\Financial\CashController::class, 'topUp'])
                ->middleware('oauth.service:financial:cash:operate')->whereUuid('shiftId');
            Route::post('/shifts/{shiftId}/withdrawals', [\App\Http\Controllers\Financial\CashController::class, 'withdraw'])
                ->middleware('oauth.service:financial:cash:operate')->whereUuid('shiftId');
            Route::post('/shifts/{shiftId}/close', [\App\Http\Controllers\Financial\CashController::class, 'close'])
                ->middleware('oauth.service:financial:cash:close')->whereUuid('shiftId');
        });

        Route::get('/hold-policies', [WalletHoldPolicyController::class, 'index'])
            ->middleware('oauth.service:financial:read');
        Route::get('/hold-policies/{policyId}', [WalletHoldPolicyController::class, 'show'])
            ->whereUuid('policyId')->middleware('oauth.service:financial:read');
        Route::get('/hold-policies/{policyId}/history', [WalletHoldPolicyController::class, 'history'])
            ->whereUuid('policyId')->middleware('oauth.service:financial:read');
        Route::post('/hold-policies', [WalletHoldPolicyController::class, 'store'])
            ->middleware('oauth.service:financial:hold-policy:manage');
        Route::patch('/hold-policies/{policyId}', [WalletHoldPolicyController::class, 'update'])
            ->whereUuid('policyId')->middleware('oauth.service:financial:hold-policy:manage');
        Route::post('/transactions/{transactionId}/reverse', [ReversalController::class, 'store'])
            ->whereUuid('transactionId')->middleware('oauth.service:financial:reverse');
        Route::get('/reversals/{reversalId}', [ReversalController::class, 'show'])
            ->whereUuid('reversalId')->middleware('oauth.service:financial:read');
        Route::get('/holds/{holdId}', [WalletHoldController::class, 'show'])
            ->whereUuid('holdId')->middleware('oauth.service:financial:read');
        Route::post('/holds', [WalletHoldController::class, 'store'])
            ->middleware('oauth.service:financial:write');
        Route::post('/holds/{holdId}/release', [WalletHoldController::class, 'release'])
            ->whereUuid('holdId')->middleware('oauth.service:financial:hold:release');
        Route::post('/holds/{holdId}/capture', [WalletHoldController::class, 'capture'])
            ->whereUuid('holdId')->middleware('oauth.service:financial:hold:capture');

        // 2.10 Límites, alertas y conciliación
        Route::middleware(
            'oauth.service:financial:read'
        )->group(function () {
            Route::get('/limits', [FinancialLimitController::class, 'index']);
            Route::get('/limits/{limitId}', [FinancialLimitController::class, 'show']);
            Route::get('/limits/{limitId}/history', [FinancialLimitController::class, 'history']);
            Route::post('/limits/evaluate', [FinancialLimitController::class, 'evaluate']);

            Route::get('/alerts', [TransactionAlertController::class, 'index']);
            Route::get('/alerts/{alertId}', [TransactionAlertController::class, 'show']);

            Route::get('/reconciliations', [ReconciliationController::class, 'index']);
            Route::get('/reconciliations/{reconciliationId}', [ReconciliationController::class, 'show']);
            Route::get('/reconciliations/{reconciliationId}/differences', [ReconciliationController::class, 'differences']);
        });

        Route::middleware(
            'oauth.service:financial:control'
        )->group(function () {
            Route::post('/limits', [FinancialLimitController::class, 'store']);
            Route::patch('/limits/{limitId}', [FinancialLimitController::class, 'update']);

            Route::post('/alerts/{alertId}/status', [TransactionAlertController::class, 'updateStatus']);

            Route::post('/reconciliations', [ReconciliationController::class, 'store']);
            Route::post(
                '/reconciliations/{reconciliationId}/differences/{differenceId}/resolve',
                [ReconciliationController::class, 'resolveDifference']
            );
        });

        Route::middleware(
            'oauth.service:financial:read'
        )->group(function () {
            Route::get(
                '/wallets/{walletId}',
                [WalletController::class, 'show']
            );
            Route::get(
                '/withdrawals/{withdrawalId}',
                 [WithdrawalController::class, 'show']
            );
            Route::get(
                '/wallets/{walletId}/ledger',
                [WalletController::class, 'ledger']
            );

            Route::get(
                '/topups/{topUpId}',
                [TopUpController::class, 'show']
            );

            Route::get(
                '/bonuses/{bonusId}',
                [BonusController::class, 'show']
            );

            Route::get(
                '/refunds/{refundId}',
                [RefundController::class, 'show']
            );

            Route::get(
                '/purchase-refunds/{refundId}',
                [PurchaseRefundController::class, 'show']
             );

             });

             Route::middleware(
                 'oauth.service:financial:refund:recover'
             )->group(function () {
                 Route::post(
                     '/refunds/{refundId}/recover',
                     [RefundController::class, 'recoverWithdrawal']
            );
        });

            Route::middleware(
                'oauth.service:financial:refund:review'
            )->group(function () {
                Route::post(
                    '/refunds/{refundId}/approve',
                    [RefundController::class, 'approve']
                );

                Route::post(
                    '/refunds/{refundId}/reject',
                    [RefundController::class, 'reject']
                );

                Route::post(
                    '/purchase-refunds/{refundId}/approve',
                    [PurchaseRefundController::class, 'approve']
                );

                Route::post(
                    '/purchase-refunds/{refundId}/reject',
                    [PurchaseRefundController::class, 'reject']
                );
            });

            Route::middleware(
                'oauth.service:financial:refund:execute'
            )->group(function () {
                Route::post(
                    '/refunds/{refundId}/complete',
                    [RefundController::class, 'complete']
                );

                Route::post(
                    '/purchase-refunds/{refundId}/complete',
                    [PurchaseRefundController::class, 'complete']
                );
            });


        Route::middleware(
            'oauth.service:financial:write'
        )->group(function () {
            Route::post(
                '/topups',
                [TopUpController::class, 'store']
            );

            Route::post(
                '/topups/{topUpId}/complete',
                [TopUpController::class, 'complete']
            );

            Route::post(
                '/withdrawals',
                [WithdrawalController::class, 'store']
            );

            Route::post(
                '/refunds',
                [RefundController::class, 'store']
            );

            Route::post(
                '/purchase-refunds',
                [PurchaseRefundController::class, 'store']
             );

        });
    });
});
