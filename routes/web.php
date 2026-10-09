<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\NfcCardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SecurityDeviceController;
use App\Http\Controllers\StudentServicesController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentImportController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'verified', 'session.active', 'device.track'])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::prefix('finanzas/bonos/administracion')->name('financial.bonuses.admin.')->middleware('financial.correlation')->group(function () {
        $admin = \App\Http\Controllers\Financial\BonusAdministrationWebController::class;
        Route::get('/', [$admin, 'index'])->name('index');
        Route::get('/records', [$admin, 'listing'])->name('records');
        Route::get('/beneficiaries/{userId}', [$admin, 'beneficiary'])->where('userId', '[0-9a-fA-F]{24}')->name('beneficiary');
        Route::post('/issue', [$admin, 'store'])->name('issue');
        Route::post('/{bonusId}/cancel', [$admin, 'cancel'])->whereUuid('bonusId')->name('cancel');
        Route::get('/{bonusId}/history', [$admin, 'history'])->whereUuid('bonusId')->name('history');
    });

    Route::prefix('finanzas/bonos')->name('financial.bonuses.')->group(function () {
        $bonuses = \App\Http\Controllers\Financial\BonusWebController::class;
        Route::get('/', [$bonuses, 'index'])->name('index');
        Route::get('/{bonusId}', [$bonuses, 'show'])->whereUuid('bonusId')->name('show');
        Route::get('/{bonusId}/history', [$bonuses, 'history'])->whereUuid('bonusId')->name('history');
    });

    Route::prefix('finanzas/ajustes')->name('financial.adjustments.')->middleware('financial.correlation')->group(function () {
        $controller = \App\Http\Controllers\Financial\FinancialAdjustmentWebController::class;
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
        Route::get('/', [\App\Http\Controllers\Financial\FinancialControlWebController::class, 'index'])->name('index');
        $gate = \App\Http\Middleware\AuthorizeFinancialControlWeb::class;
        $limits = \App\Http\Controllers\Financial\FinancialLimitWebController::class;
        $alerts = \App\Http\Controllers\Financial\TransactionAlertWebController::class;
        $runs = \App\Http\Controllers\Financial\ReconciliationWebController::class;
        Route::get('/limits', [$limits, 'index'])->middleware($gate . ':limits.view')->name('limits.index');
        Route::post('/limits', [$limits, 'store'])->middleware($gate . ':limits.manage')->name('limits.store');
        Route::post('/limits/evaluate', [$limits, 'evaluate'])->middleware($gate . ':limits.evaluate')->name('limits.evaluate');
        Route::patch('/limits/{limitId}', [$limits, 'update'])->whereUuid('limitId')->middleware($gate . ':limits.manage')->name('limits.update');
        Route::get('/limits/{limitId}/history', [$limits, 'history'])->whereUuid('limitId')->middleware($gate . ':limits.view')->name('limits.history');
        Route::get('/alerts', [$alerts, 'index'])->middleware($gate . ':alerts.view')->name('alerts.index');
        Route::get('/alerts/{alertId}', [$alerts, 'show'])->whereUuid('alertId')->middleware($gate . ':alerts.view')->name('alerts.show');
        Route::post('/alerts/{alertId}/status', [$alerts, 'updateStatus'])->whereUuid('alertId')->middleware($gate . ':alerts.review')->name('alerts.status');
        Route::get('/reconciliations', [$runs, 'index'])->middleware($gate . ':reconciliation.view')->name('reconciliations.index');
        Route::post('/reconciliations', [$runs, 'store'])->middleware($gate . ':reconciliation.run')->name('reconciliations.store');
        Route::get('/reconciliations/{reconciliationId}', [$runs, 'show'])->whereUuid('reconciliationId')->middleware($gate . ':reconciliation.view')->name('reconciliations.show');
        Route::get('/reconciliations/{reconciliationId}/differences', [$runs, 'differences'])->whereUuid('reconciliationId')->middleware($gate . ':reconciliation.view')->name('reconciliations.differences');
        Route::post('/reconciliations/{reconciliationId}/differences/{differenceId}/resolve', [$runs, 'resolveDifference'])
            ->whereUuid(['reconciliationId', 'differenceId'])->middleware($gate . ':reconciliation.resolve')->name('reconciliations.resolve');
    });

    Route::prefix('finanzas/confirmaciones-caja')->name('financial.cash.confirmations.student.')->group(function () {
        $student = \App\Http\Controllers\Financial\StudentCashConfirmationController::class;
        Route::get('/', [$student, 'index'])->name('index');
        Route::get('/{confirmationId}', [$student, 'show'])->whereUuid('confirmationId')->name('show');
        Route::post('/{confirmationId}/{action}', [$student, 'decide'])->whereUuid('confirmationId')->whereIn('action', ['approve', 'reject'])->name('decide');
    });

    Route::prefix('finanzas/caja')->name('financial.cash.')->middleware('financial.correlation')->group(function () {
        $cash = \App\Http\Controllers\Financial\CashWebController::class;
        Route::get('/', [$cash, 'index'])->name('index');
        Route::get('/context', [$cash, 'context'])->name('context');
        Route::get('/autorizaciones', [\App\Http\Controllers\Financial\CashApprovalWebController::class, 'index'])->name('approvals');
        Route::get('/administracion', [\App\Http\Controllers\Financial\CashRegisterAdministrationWebController::class, 'index'])->name('administration');
        Route::prefix('asociaciones/{associationId}')->group(function () use ($cash) {
            $administrative = \App\Http\Controllers\Financial\CashAdministrativeRequestWebController::class;
            Route::post('/shifts/{shiftId}/administrative-requests', [$administrative, 'storeAdministrative'])->whereUuid('shiftId')->name('administrative-requests.store');
            Route::get('/shifts/{shiftId}/administrative-requests/{administrativeId}', [$administrative, 'showAdministrative'])->whereUuid(['shiftId', 'administrativeId'])->name('administrative-requests.show');
            Route::post('/shifts/{shiftId}/administrative-requests/{administrativeId}/cancel', [$administrative, 'cancelAdministrative'])->whereUuid(['shiftId', 'administrativeId'])->name('administrative-requests.cancel');
            $approvals = \App\Http\Controllers\Financial\CashApprovalWebController::class;
            Route::get('/approval-policies', [$approvals, 'policies'])->name('approval-policies.index');
            Route::post('/approval-policies', [$approvals, 'configurePolicy'])->name('approval-policies.configure');
            Route::get('/approval-policies/history', [$approvals, 'policyHistory'])->name('approval-policies.history');
            Route::get('/administrative-approval-requests', [$approvals, 'administrativeQueue'])->name('administrative-approvals.index');
            Route::post('/administrative-approval-requests/{administrativeId}/{decision}', [$approvals, 'reviewAdministrative'])
                ->whereUuid('administrativeId')->whereIn('decision', ['approve', 'reject'])->name('administrative-approvals.review');
            Route::get('/approval-requests', [$approvals, 'reviewQueue'])->name('approval-requests.index');
            Route::post('/approval-requests/{confirmationId}/{decision}', [$approvals, 'reviewOperation'])
                ->whereUuid('confirmationId')->whereIn('decision', ['approve', 'reject'])->name('approval-requests.review');
            $confirmations = \App\Http\Controllers\Financial\CashOperationConfirmationWebController::class;
            Route::post('/shifts/{shiftId}/confirmations', [$confirmations, 'store'])->whereUuid('shiftId')->name('confirmations.store');
            Route::get('/shifts/{shiftId}/confirmations/{confirmationId}', [$confirmations, 'showConfirmation'])->whereUuid(['shiftId', 'confirmationId'])->name('confirmations.show');
            Route::post('/shifts/{shiftId}/confirmations/{confirmationId}/cancel', [$confirmations, 'cancel'])->whereUuid(['shiftId', 'confirmationId'])->name('confirmations.cancel');
            $recoveries = \App\Http\Controllers\Financial\CashWithdrawalRecoveryWebController::class;
            Route::get('/withdrawal-refunds', [$recoveries, 'index'])->name('withdrawal-refunds.index');
            Route::post('/shifts/{shiftId}/withdrawal-refunds/{refundId}/recover', [$recoveries, 'recover'])
                ->whereUuid(['shiftId', 'refundId'])->name('withdrawal-refunds.recover');
            Route::get('/receipts/{receiptId}', [$cash, 'receipt'])->whereUuid('receiptId')->name('receipts.show');
            Route::get('/receipts/{receiptId}/imprimir', [$cash, 'printReceipt'])->whereUuid('receiptId')->name('receipts.print');
            $admin = \App\Http\Controllers\Financial\CashRegisterAdministrationWebController::class;
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

    Route::get('/student-services', [StudentServicesController::class, 'index'])
        ->name('student-services.index');

    Route::get('/students', [StudentController::class, 'index'])->name('students.index');
    Route::get('/students/create', [StudentController::class, 'create'])->name('students.create');
    Route::post('/students', [StudentController::class, 'store'])->name('students.store');
    Route::post('/students/import', [StudentImportController::class, 'store'])->name('students.import');
    Route::get('/students/{student}/edit', [StudentController::class, 'edit'])->name('students.edit');
    Route::patch('/students/{student}', [StudentController::class, 'update'])->name('students.update');

    // --------------------------------------------------------------
    // Perfil
    // --------------------------------------------------------------
    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');

    // --------------------------------------------------------------
    // Modulo 1.6 - Identidad QR
    // --------------------------------------------------------------
    Route::prefix('identidad/qr')->name('identity.qr.')->group(function () {
        Route::get('/', [QrController::class, 'index'])
            ->name('index');

        Route::post('/generar', [QrController::class, 'generate'])
            ->name('generate');

        Route::get('/historial', [QrController::class, 'history'])
            ->name('history');

        Route::post('/simular-validacion', [QrController::class, 'simulateValidation'])
            ->name('simulate');
    });

    // --------------------------------------------------------------
    // Modulo 1.7 - Dispositivos y sesiones confiables
    // --------------------------------------------------------------
    Route::prefix('seguridad')->name('security.')->group(function () {

        Route::get('/dispositivos', [SecurityDeviceController::class, 'index'])
            ->name('devices.index');

        Route::post('/reautenticar', [AuthController::class, 'reauthenticate'])
            ->name('reauth');

        Route::middleware('reauth')->group(function () {

            Route::post('/sesiones/{session}/revocar', [SecurityDeviceController::class, 'revoke'])
                ->name('sessions.revoke');

            Route::post('/sesiones/revocar-otras', [SecurityDeviceController::class, 'revokeOthers'])
                ->name('sessions.revoke-others');

            Route::post('/dispositivos/{device}/confianza', [SecurityDeviceController::class, 'trust'])
                ->name('devices.trust');
        });
    });

    // --------------------------------------------------------------
    // Modulo 1.4 - Registro de tarjetas NFC
    // --------------------------------------------------------------
    Route::get('/nfc-cards', [NfcCardController::class, 'index'])
        ->name('nfc-cards.index');

    Route::get('/nfc-cards/create', [NfcCardController::class, 'create'])
        ->name('nfc-cards.create');

    Route::post('/nfc-cards', [NfcCardController::class, 'store'])
        ->name('nfc-cards.store');

    // --------------------------------------------------------------
    // Modulo 1.5 - Ciclo de vida de credenciales NFC
    // --------------------------------------------------------------
    Route::patch('/nfc-cards/{nfcCard}/status', [NfcCardController::class, 'updateStatus'])
        ->name('nfc-cards.update-status');

    Route::get('/nfc-cards/{nfcCard}/history', [NfcCardController::class, 'history'])
        ->name('nfc-cards.history');

    // Módulos 1.2 y 1.3
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('/roles/assign', [RoleController::class, 'assign'])->name('roles.assign');

    // Módulos 1.6 y 1.7 (Equipo)
    Route::get('/security/devices', [SecurityDeviceController::class, 'index'])->name('security.devices.index');
    Route::delete('/security/devices/{device}', [SecurityDeviceController::class, 'destroy'])->name('security.devices.destroy');
    Route::post('/security/devices/logout-others', [SecurityDeviceController::class, 'logoutOthers'])->name('security.devices.logout-others');

    Route::get('/identity/qr', [QrController::class, 'index'])->name('identity.qr');
    Route::get('/identity/qr/view', [QrController::class, 'index'])->name('identity.qr.view');
    Route::post('/identity/qr/refresh', [QrController::class, 'generate'])->name('identity.qr.refresh');
    Route::post('/identity/qr/validate', [QrController::class, 'simulateValidation'])->name('identity.qr.validate');
});

require __DIR__.'/auth.php';