<?php

use App\Http\Controllers\GestionOrganizacionesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StaffEventoController;
use App\Http\Middleware\SeleccionarOrganizacion;
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

Route::get('/dashboard', function () {
    return redirect()->route('modulo6.asociacion');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ==========================================
// RUTAS DEL MÓDULO 6 (Organizaciones y Becas)
// ==========================================
Route::middleware(['auth', SeleccionarOrganizacion::class])->prefix('modulo6')->name('modulo6.')->group(function () {

    // Ya no hacemos la petición aquí, solo devolvemos la vista
    Route::get('/', function () {
        return Inertia::render('Modulo6/Dashboard');
    })->name('dashboard');

    Route::get('/gestion-organizaciones', [GestionOrganizacionesController::class, 'vista'])->name('gestion-organizaciones');

    Route::get('/asociacion', function () {
        return Inertia::render('Modulo6/Asociacion');
    })->name('asociacion');

    Route::get('/eventos', function () {
        return Inertia::render('Modulo6/Eventos');
    })->name('eventos');

    Route::get('/staff', [StaffEventoController::class, 'vista'])->name('staff');

    Route::get('/mis-boletos', fn () => Inertia::render('Modulo6/Estudiante/MiBoleto'))->name('boletos');
    Route::get('/eventos/{id}/boleto', fn (string $id) => Inertia::render('Modulo6/Estudiante/MiBoleto', ['eventoId' => $id]))->where('id', '[a-fA-F0-9]{24}')->name('boleto');

    Route::get('/becas', function () {
        return Inertia::render('Modulo6/Becas');
    })->name('becas');

    Route::get('/becas/solicitudes/{id}', fn (string $id) => Inertia::render('Modulo6/Estudiante/SolicitudBeca', ['solicitudId' => $id]))->where('id', '[a-fA-F0-9]{24}')->name('solicitud-beca');

    Route::get('/bandeja', fn () => Inertia::render('Modulo6/Bandeja'))->name('bandeja');

    Route::get('/comunicacion', function () {
        return Inertia::render('Modulo6/Comunicacion');
    })->name('comunicacion');

    Route::get('/encuestas', fn () => Inertia::render('Modulo6/Participacion', ['tipo' => 'encuestas']))->name('encuestas');
    Route::get('/votaciones', fn () => Inertia::render('Modulo6/Participacion', ['tipo' => 'votaciones']))->name('votaciones');

    Route::get('/transparencia', function () {
        return Inertia::render('Modulo6/Transparencia');
    })->name('transparencia');

});

Route::middleware(['auth', SeleccionarOrganizacion::class])->prefix('api')->group(__DIR__.'/comunidad.php');

require __DIR__.'/auth.php';
