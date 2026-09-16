<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
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
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ==========================================
// RUTAS DEL MÓDULO 6 (Organizaciones y Becas)
// ==========================================
Route::prefix('modulo6')->name('modulo6.')->group(function () {
    
    // Ya no hacemos la petición aquí, solo devolvemos la vista
    Route::get('/', function () { 
        return Inertia::render('Modulo6/Dashboard'); 
    })->name('dashboard');

    Route::get('/asociacion', function () { 
        return Inertia::render('Modulo6/Asociacion'); 
    })->name('asociacion');

    Route::get('/eventos', function () { 
        return Inertia::render('Modulo6/Eventos'); 
    })->name('eventos');

    Route::get('/becas', function () { 
        return Inertia::render('Modulo6/Becas'); 
    })->name('becas');

    Route::get('/comunicacion', function () { 
        return Inertia::render('Modulo6/Comunicacion'); 
    })->name('comunicacion');

    Route::get('/transparencia', function () { 
        return Inertia::render('Modulo6/Transparencia'); 
    })->name('transparencia');
    
});

require __DIR__.'/auth.php';