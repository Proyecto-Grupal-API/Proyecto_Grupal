<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrganizacionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventoController;
use App\Http\Controllers\BecaController;
use App\Http\Controllers\ComunicacionController;
use App\Http\Controllers\TransparenciaController;
use App\Http\Controllers\NotificacionController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/organizaciones', [OrganizacionController::class, 'index']);
Route::put('/organizaciones/perfil', [OrganizacionController::class, 'updatePerfil']);
Route::post('/organizaciones/miembros', [OrganizacionController::class, 'addMiembro']);
Route::post('/organizaciones/roles', [OrganizacionController::class, 'assignRol']);
Route::delete('/organizaciones/miembros/{id}', [OrganizacionController::class, 'removeMiembro']);
Route::delete('/organizaciones/roles/{id}', [OrganizacionController::class, 'removeRol']);

Route::get('/dashboard', [DashboardController::class, 'index']);
Route::get('/eventos', [EventoController::class, 'index']);
Route::get('/becas', [BecaController::class, 'index']);
Route::get('/comunicacion', [ComunicacionController::class, 'index']);
Route::get('/transparencia', [TransparenciaController::class, 'index']);

Route::get('/notificaciones', [NotificacionController::class, 'index']);
Route::put('/notificaciones/leer', [NotificacionController::class, 'marcarLeidas']);
Route::delete('/notificaciones/leidas', [NotificacionController::class, 'eliminarLeidas']);
Route::get('/eventos', [EventoController::class, 'index']);
Route::post('/eventos', [EventoController::class, 'store']);
Route::get('/eventos', [EventoController::class, 'index']);
Route::post('/eventos', [EventoController::class, 'store']);
Route::post('/eventos/checkin', [EventoController::class, 'checkin']); // <-- RUTA NUEVA