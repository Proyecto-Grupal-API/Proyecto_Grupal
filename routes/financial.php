<?php

use App\Http\Controllers\Financial\BonusAdministrationWebController;
use App\Http\Controllers\Financial\BonusWebController;
use App\Http\Controllers\Financial\CashAdministrativeRequestWebController;
use App\Http\Controllers\Financial\CashApprovalWebController;
use App\Http\Controllers\Financial\CashOperationConfirmationWebController;
use App\Http\Controllers\Financial\CashRegisterAdministrationWebController;
use App\Http\Controllers\Financial\CashWebController;
use App\Http\Controllers\Financial\CashWithdrawalRecoveryWebController;
use App\Http\Controllers\Financial\FinancialAdjustmentWebController;
use App\Http\Controllers\Financial\FinancialControlWebController;
use App\Http\Controllers\Financial\FinancialLimitWebController;
use App\Http\Controllers\Financial\ReconciliationWebController;
use App\Http\Controllers\Financial\StudentCashConfirmationController;
use App\Http\Controllers\Financial\StudentTransferWebController;
use App\Http\Controllers\Financial\TransactionAlertWebController;
use App\Http\Controllers\FinancialController;
use App\Http\Middleware\AuthorizeFinancialControlWeb;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'session.active', 'device.track'])->group(function () {
    Route::prefix('finanzas/transferencias')->name('financial.transfers.')->middleware('financial.correlation')->group(function () {
        $transfers = StudentTransferWebController::class;
        Route::get('/', [$transfers, 'index'])->name('index');
        Route::get('/records', [$transfers, 'listing'])->name('records');
        Route::get('/confirmations', [$transfers, 'pendingConfirmations'])->name('pending');
        Route::post('/confirmations', [$transfers, 'prepare'])->middleware('throttle:30,1')->name('prepare');
        Route::get('/confirmations/{confirmationId}', [$transfers, 'showConfirmation'])->whereUuid('confirmationId')->name('confirmation');
        Route::post('/confirmations/{confirmationId}/confirm', [$transfers, 'confirm'])->whereUuid('confirmationId')->middleware('throttle:30,1')->name('confirm');
        Route::post('/confirmations/{confirmationId}/cancel', [$transfers, 'cancel'])->whereUuid('confirmationId')->name('cancel');
        Route::patch('/policy', [$transfers, 'updatePolicy'])->name('policy.update');
        Route::get('/policy/history', [$transfers, 'policyHistory'])->name('policy.history');
        Route::get('/{transferId}', [$transfers, 'show'])->whereUuid('transferId')->name('show');
    });

    Route::prefix('finanzas/bonos/administracion')->name('financial.bonuses.admin.')->middleware(['financial.correlation'])->group(function () {
        $admin = BonusAdministrationWebController::class;
        Route::get('/', [$admin, 'index'])->name('index');
        Route::get('/records', [$admin, 'listing'])->name('records');
        Route::get('/beneficiaries/{userId}', [$admin, 'beneficiary'])->where('userId', '[0-9a-fA-F]{24}')->name('beneficiary');
        Route::post('/issue', [$admin, 'store'])->name('issue');
        Route::post('/{bonusId}/cancel', [$admin, 'cancel'])->whereUuid('bonusId')->name('cancel');
        Route::get('/{bonusId}/history', [$admin, 'history'])->whereUuid('bonusId')->name('history');
    });

    Route::prefix('finanzas/bonos')->name('financial.bonuses.')->group(function () {
        $bonuses = BonusWebController::class;
        Route::get('/', [$bonuses, 'index'])->name('index');
        Route::get('/{bonusId}', [$bonuses, 'show'])->whereUuid('bonusId')->name('show');
        Route::get('/{bonusId}/history', [$bonuses, 'history'])->whereUuid('bonusId')->name('history');
    });

    Route::prefix('finanzas/ajustes')->name('financial.adjustments.')->middleware('financial.correlation')->group(function () {
        $controller = FinancialAdjustmentWebController::class;
        Route::get('/', [$controller, 'index'])->name('index');
        Route::post('/refunds', [$controller, 'requestRefund'])->name('refunds.store');
        Route::post('/purchase-refunds', [$controller, 'requestPurchaseRefund'])->name('purchase-refunds.store');
        Route::post('/refunds/{refundId}/{action}', [$controller, 'refundAction'])
            ->whereUuid('refundId')->whereIn('action', ['approve', 'reject', 'complete', 'recover'])->name('refunds.action');
        Route::post('/purchase-refunds/{refundId}/{action}', [$controller, 'purchaseRefundAction'])
            ->whereUuid('refundId')->whereIn('action', ['approve', 'reject', 'complete'])->name('purchase-refunds.action');
        Route::post('/holds/{holdId}/{action}', [$controller, 'holdAction'])
            ->whereUuid('holdId')->whereIn('action', ['release', 'capture'])->name('holds.action');
        Route::post('/transactions/{transactionId}/reverse', [$controller, 'reverse'])
            ->whereUuid('transactionId')->name('reverse');
        Route::post('/policies', [$controller, 'createPolicy'])->name('policies.store');
        Route::patch('/policies/{policyId}', [$controller, 'updatePolicy'])
            ->whereUuid('policyId')->name('policies.update');
        Route::get('/policies/{policyId}/history', [$controller, 'policyHistory'])
            ->whereUuid('policyId')->name('policies.history');
    });

    Route::prefix('finanzas/controles')->name('financial.controls.')->middleware('financial.correlation')->group(function () {
        Route::get('/', [FinancialControlWebController::class, 'index'])->name('index');
        $gate = AuthorizeFinancialControlWeb::class;
        $limits = FinancialLimitWebController::class;
        $alerts = TransactionAlertWebController::class;
        $runs = ReconciliationWebController::class;
        Route::get('/limits', [$limits, 'index'])->middleware($gate.':limits.view')->name('limits.index');
        Route::post('/limits', [$limits, 'store'])->middleware($gate.':limits.manage')->name('limits.store');
        Route::post('/limits/evaluate', [$limits, 'evaluate'])->middleware($gate.':limits.evaluate')->name('limits.evaluate');
        Route::patch('/limits/{limitId}', [$limits, 'update'])->whereUuid('limitId')->middleware($gate.':limits.manage')->name('limits.update');
        Route::get('/limits/{limitId}/history', [$limits, 'history'])->whereUuid('limitId')->middleware($gate.':limits.view')->name('limits.history');
        Route::get('/alerts', [$alerts, 'index'])->middleware($gate.':alerts.view')->name('alerts.index');
        Route::get('/alerts/{alertId}', [$alerts, 'show'])->whereUuid('alertId')->middleware($gate.':alerts.view')->name('alerts.show');
        Route::post('/alerts/{alertId}/status', [$alerts, 'updateStatus'])->whereUuid('alertId')->middleware($gate.':alerts.review')->name('alerts.status');
        Route::get('/reconciliations', [$runs, 'index'])->middleware($gate.':reconciliation.view')->name('reconciliations.index');
        Route::post('/reconciliations', [$runs, 'store'])->middleware($gate.':reconciliation.run')->name('reconciliations.store');
        Route::get('/reconciliations/{reconciliationId}', [$runs, 'show'])->whereUuid('reconciliationId')->middleware($gate.':reconciliation.view')->name('reconciliations.show');
        Route::get('/reconciliations/{reconciliationId}/differences', [$runs, 'differences'])->whereUuid('reconciliationId')->middleware($gate.':reconciliation.view')->name('reconciliations.differences');
        Route::post('/reconciliations/{reconciliationId}/differences/{differenceId}/resolve', [$runs, 'resolveDifference'])
            ->whereUuid(['reconciliationId', 'differenceId'])->middleware($gate.':reconciliation.resolve')->name('reconciliations.resolve');
    });

    Route::prefix('finanzas/confirmaciones-caja')->name('financial.cash.confirmations.student.')->group(function () {
        $student = StudentCashConfirmationController::class;
        Route::get('/', [$student, 'index'])->name('index');
        Route::get('/{confirmationId}', [$student, 'show'])->whereUuid('confirmationId')->name('show');
        Route::post('/{confirmationId}/{action}', [$student, 'decide'])->whereUuid('confirmationId')->whereIn('action', ['approve', 'reject'])->name('decide');
    });

    Route::prefix('finanzas/caja')->name('financial.cash.')->middleware('financial.correlation')->group(function () {
        $cash = CashWebController::class;
        Route::get('/', [$cash, 'index'])->name('index');
        Route::get('/context', [$cash, 'context'])->name('context');
        Route::get('/autorizaciones', [CashApprovalWebController::class, 'index'])->name('approvals');
        Route::get('/administracion', [CashRegisterAdministrationWebController::class, 'index'])->name('administration');
        Route::prefix('asociaciones/{associationId}')->group(function () use ($cash) {
            $administrative = CashAdministrativeRequestWebController::class;
            Route::post('/shifts/{shiftId}/administrative-requests', [$administrative, 'storeAdministrative'])->whereUuid('shiftId')->name('administrative-requests.store');
            Route::get('/shifts/{shiftId}/administrative-requests/{administrativeId}', [$administrative, 'showAdministrative'])->whereUuid(['shiftId', 'administrativeId'])->name('administrative-requests.show');
            Route::post('/shifts/{shiftId}/administrative-requests/{administrativeId}/cancel', [$administrative, 'cancelAdministrative'])->whereUuid(['shiftId', 'administrativeId'])->name('administrative-requests.cancel');
            $approvals = CashApprovalWebController::class;
            Route::get('/approval-policies', [$approvals, 'policies'])->name('approval-policies.index');
            Route::post('/approval-policies', [$approvals, 'configurePolicy'])->name('approval-policies.configure');
            Route::get('/approval-policies/history', [$approvals, 'policyHistory'])->name('approval-policies.history');
            Route::get('/administrative-approval-requests', [$approvals, 'administrativeQueue'])->name('administrative-approvals.index');
            Route::post('/administrative-approval-requests/{administrativeId}/{decision}', [$approvals, 'reviewAdministrative'])
                ->whereUuid('administrativeId')->whereIn('decision', ['approve', 'reject'])->name('administrative-approvals.review');
            Route::get('/approval-requests', [$approvals, 'reviewQueue'])->name('approval-requests.index');
            Route::post('/approval-requests/{confirmationId}/{decision}', [$approvals, 'reviewOperation'])
                ->whereUuid('confirmationId')->whereIn('decision', ['approve', 'reject'])->name('approval-requests.review');
            $confirmations = CashOperationConfirmationWebController::class;
            Route::post('/shifts/{shiftId}/confirmations', [$confirmations, 'store'])->whereUuid('shiftId')->name('confirmations.store');
            Route::get('/shifts/{shiftId}/confirmations/{confirmationId}', [$confirmations, 'showConfirmation'])->whereUuid(['shiftId', 'confirmationId'])->name('confirmations.show');
            Route::post('/shifts/{shiftId}/confirmations/{confirmationId}/cancel', [$confirmations, 'cancel'])->whereUuid(['shiftId', 'confirmationId'])->name('confirmations.cancel');
            $recoveries = CashWithdrawalRecoveryWebController::class;
            Route::get('/withdrawal-refunds', [$recoveries, 'index'])->name('withdrawal-refunds.index');
            Route::post('/shifts/{shiftId}/withdrawal-refunds/{refundId}/recover', [$recoveries, 'recover'])
                ->whereUuid(['shiftId', 'refundId'])->name('withdrawal-refunds.recover');
            Route::get('/receipts/{receiptId}', [$cash, 'receipt'])->whereUuid('receiptId')->name('receipts.show');
            Route::get('/receipts/{receiptId}/pdf', [$cash, 'downloadReceiptPdf'])->whereUuid('receiptId')->middleware('throttle:20,1')->name('receipts.pdf');
            Route::get('/receipts/{receiptId}/imprimir', [$cash, 'printReceipt'])->whereUuid('receiptId')->name('receipts.print');
            $admin = CashRegisterAdministrationWebController::class;
            Route::post('/registers', [$admin, 'storeRegister'])->name('registers.store');
            Route::patch('/registers/{registerId}', [$admin, 'updateRegister'])->whereUuid('registerId')->name('registers.update');
            Route::get('/registers/{registerId}/history', [$admin, 'registerHistory'])->whereUuid('registerId')->name('registers.history');
            Route::get('/registers', [$cash, 'registers'])->name('registers');
            Route::get('/registers/{registerId}/shifts', [$cash, 'shifts'])->name('shifts')->whereUuid('registerId');
            Route::get('/shifts/{shiftId}', [$cash, 'show'])->name('show')->whereUuid('shiftId');
            Route::get('/shifts/{shiftId}/movements', [$cash, 'movements'])->name('movements')->whereUuid('shiftId');
            Route::post('/registers/{registerId}/shifts', [$cash, 'open'])->name('open')->whereUuid('registerId');
            Route::post('/shifts/{shiftId}/movements', [$cash, 'move'])->name('move')->whereUuid('shiftId');
            Route::post('/shifts/{shiftId}/adjustments', [$cash, 'adjust'])->name('adjust')->whereUuid('shiftId');
            Route::post('/shifts/{shiftId}/topups', [$cash, 'topUp'])->name('topups')->whereUuid('shiftId');
            Route::post('/shifts/{shiftId}/withdrawals', [$cash, 'withdraw'])->name('withdrawals')->whereUuid('shiftId');
            Route::post('/shifts/{shiftId}/close', [$cash, 'close'])->name('close')->whereUuid('shiftId');
        });
    });

    Route::get('/finanzas', [FinancialController::class, 'index'])
        ->name('financial.dashboard');

});
