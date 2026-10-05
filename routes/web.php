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

    Route::get('/finanzas', [FinancialController::class, 'index'])
        ->name('financial.dashboard');

    Route::get('/finanzas/ajustes', [FinancialController::class, 'adjustments'])
        ->name('financial.adjustments');

    Route::post('/finanzas/ajustes/{transactionId}/devolucion', [FinancialController::class, 'requestRefund'])
        ->name('financial.refunds.request');

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