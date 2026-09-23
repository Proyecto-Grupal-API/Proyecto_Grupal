<?php

use App\Http\Controllers\BandejaController;
use App\Http\Controllers\BecaController;
use App\Http\Controllers\ComunicacionController;
use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventoController;
use App\Http\Controllers\GestionOrganizacionesController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\OrganizacionController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\StaffEventoController;
use App\Http\Controllers\TransparenciaController;
use Illuminate\Support\Facades\Route;

Route::get('/organizaciones', [OrganizacionController::class, 'index']);
Route::post('/organizaciones/seleccionar', [OrganizacionController::class, 'seleccionar']);
Route::put('/organizaciones/perfil', [OrganizacionController::class, 'updatePerfil']);
Route::post('/organizaciones/miembros', [OrganizacionController::class, 'addMiembro']);
Route::delete('/organizaciones/miembros/{id}', [OrganizacionController::class, 'removeMiembro'])->where('id', '[a-fA-F0-9]{24}');
Route::post('/organizaciones/roles', [OrganizacionController::class, 'assignRol']);
Route::put('/organizaciones/roles/{id}', [OrganizacionController::class, 'assignRol'])->where('id', '[a-fA-F0-9]{24}');
Route::delete('/organizaciones/roles/{id}', [OrganizacionController::class, 'removeRol'])->where('id', '[a-fA-F0-9]{24}');
Route::get('/dashboard', [DashboardController::class, 'index']);
Route::get('/eventos', [EventoController::class, 'index']);
Route::post('/eventos', [EventoController::class, 'store']);
Route::post('/eventos/checkin', [EventoController::class, 'checkin'])->middleware('throttle:60,1');
Route::prefix('eventos/{id}')->where(['id' => '[a-fA-F0-9]{24}'])->group(function () {
    Route::put('/', [EventoController::class, 'update']);
    Route::post('/publicar', [EventoController::class, 'publicar']);
    Route::post('/cancelar', [EventoController::class, 'cancelarEvento']);
    Route::post('/inscripcion', [EventoController::class, 'inscribir'])->middleware('throttle:30,1');
    Route::delete('/inscripcion', [EventoController::class, 'cancelarInscripcion']);
    Route::get('/inscritos', [EventoController::class, 'inscritos']);
    Route::get('/boleto', [EventoController::class, 'miBoleto']);
});
Route::get('/estudiante/boletos', [EventoController::class, 'boletos']);
Route::get('/estudiante/mi-boleto', [EventoController::class, 'miBoleto']);
Route::get('/becas', [BecaController::class, 'index']);
Route::post('/becas', [BecaController::class, 'store']);
Route::get('/becas/mis-solicitudes', [BecaController::class, 'misSolicitudes']);
Route::prefix('becas/solicitudes/{id}')->where(['id' => '[a-fA-F0-9]{24}'])->group(function () {
    Route::get('/', [BecaController::class, 'showSolicitud']);
    Route::put('/', [BecaController::class, 'guardarSolicitud']);
    Route::post('/retirar', [BecaController::class, 'retirar']);
    Route::post('/documentos', [BecaController::class, 'subirDocumento'])->middleware('throttle:30,1');
    Route::get('/documentos/{documento}', [BecaController::class, 'descargarDocumento'])->whereUuid('documento');
    Route::delete('/documentos/{documento}', [BecaController::class, 'eliminarDocumento'])->whereUuid('documento');
    Route::post('/dictamen', [BecaController::class, 'dictaminar']);
    Route::get('/contrato', [BecaController::class, 'contrato']);
});
Route::prefix('becas/{id}')->where(['id' => '[a-fA-F0-9]{24}'])->group(function () {
    Route::put('/', [BecaController::class, 'update']);
    Route::post('/estado', [BecaController::class, 'cambiarEstado']);
    Route::get('/solicitudes', [BecaController::class, 'solicitudes']);
    Route::post('/solicitud', [BecaController::class, 'crearSolicitud'])->middleware('throttle:30,1');
});
Route::get('/comunicacion', [ComunicacionController::class, 'index']);
Route::post('/comunicacion', [ComunicacionController::class, 'store']);
Route::prefix('comunicacion/{id}')->where(['id' => '[a-fA-F0-9]{24}'])->group(function () {
    Route::put('/', [ComunicacionController::class, 'update']);
    Route::post('/preview', [ComunicacionController::class, 'preview']);
    Route::post('/enviar', [ComunicacionController::class, 'enviar'])->middleware('throttle:10,1');
    Route::post('/reintentar', [ComunicacionController::class, 'reintentar'])->middleware('throttle:10,1');
    Route::post('/cancelar', [ComunicacionController::class, 'cancelar']);
});
Route::get('/bandeja', [BandejaController::class, 'index']);
Route::get('/bandeja/preferencias', [BandejaController::class, 'preferencias']);
Route::put('/bandeja/preferencias/{id}', [BandejaController::class, 'preferencia'])->where('id', '[a-fA-F0-9]{24}');
Route::get('/bandeja/{id}', [BandejaController::class, 'show'])->where('id', '[a-fA-F0-9]{24}');
Route::put('/bandeja/{id}', [BandejaController::class, 'update'])->where('id', '[a-fA-F0-9]{24}');
Route::get('/transparencia', [TransparenciaController::class, 'index']);
Route::get('/notificaciones', [NotificacionController::class, 'index']);
Route::put('/notificaciones/leer', [NotificacionController::class, 'marcarLeidas']);
Route::delete('/notificaciones/leidas', [NotificacionController::class, 'eliminarLeidas']);

Route::prefix('consultas/{tipo}')->where(['tipo' => 'encuestas|votaciones'])->group(function () {
    Route::get('/', [ConsultaController::class, 'index']);
    Route::post('/', [ConsultaController::class, 'store']);
    Route::prefix('{id}')->where(['id' => '[a-fA-F0-9]{24}'])->group(function () {
        Route::get('/', [ConsultaController::class, 'show']);
        Route::put('/', [ConsultaController::class, 'update']);
        Route::post('/preview', [ConsultaController::class, 'preview']);
        Route::post('/publicar', [ConsultaController::class, 'publicar']);
        Route::post('/responder', [ConsultaController::class, 'responder'])->middleware('throttle:30,1');
        Route::post('/cerrar', [ConsultaController::class, 'cerrar']);
        Route::post('/cancelar', [ConsultaController::class, 'cancelar']);
    });
});

Route::post('/transparencia/reportes', [ReporteController::class, 'store'])->middleware('throttle:10,1');
Route::prefix('transparencia/reportes/{id}')->where(['id' => '[a-fA-F0-9]{24}'])->group(function () {
    Route::get('/', [ReporteController::class, 'show']);
    Route::post('/publicar', [ReporteController::class, 'publicar']);
    Route::post('/retirar', [ReporteController::class, 'retirar']);
    Route::get('/csv', [ReporteController::class, 'csv']);
    Route::get('/imprimir', [ReporteController::class, 'imprimir']);
});

Route::get('/staff/eventos', [StaffEventoController::class, 'index']);
Route::get('/staff/eventos/{id}', [StaffEventoController::class, 'show'])->where('id', '[a-fA-F0-9]{24}');
Route::get('/eventos/{id}/staff', [StaffEventoController::class, 'asignados'])->where('id', '[a-fA-F0-9]{24}');
Route::post('/eventos/{id}/staff', [StaffEventoController::class, 'asignar'])->where('id', '[a-fA-F0-9]{24}');
Route::delete('/eventos/{id}/staff/{staff}', [StaffEventoController::class, 'retirar'])->where(['id' => '[a-fA-F0-9]{24}', 'staff' => '[a-fA-F0-9]{24}']);

Route::prefix('gestion-organizaciones')->group(function () {
    Route::get('/', [GestionOrganizacionesController::class, 'index']);
    Route::post('/', [GestionOrganizacionesController::class, 'store'])->middleware('throttle:20,1');
    Route::post('/titular', [GestionOrganizacionesController::class, 'titular'])->middleware('throttle:20,1');
    Route::get('/{id}', [GestionOrganizacionesController::class, 'show'])->where('id', '[a-fA-F0-9]{24}');
    Route::post('/{id}/completar', [GestionOrganizacionesController::class, 'completar'])->where('id', '[a-fA-F0-9]{24}');
    Route::post('/{id}/estado', [GestionOrganizacionesController::class, 'estado'])->where('id', '[a-fA-F0-9]{24}');
});
