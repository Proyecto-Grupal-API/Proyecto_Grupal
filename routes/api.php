<?php

use App\Http\Controllers\StudentServices\Benefits\BenefitApiController;
use App\Http\Middleware\AssignCorrelationId;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de Servicios al Estudiante (Equipo 5)
|--------------------------------------------------------------------------
|
| Beneficios de servicio becados para Comunidad (Equipo 6),
| REQ-M6-E5-001. Servidor a servidor con token de servicio:
|   services:benefits:read  -> consultas
|   services:benefits:write -> crear y cancelar asignaciones
|
*/

Route::prefix('v1/servicios')
    ->middleware([AssignCorrelationId::class, 'throttle:120,1'])
    ->name('api.student-services.')
    ->group(function () {
        Route::middleware('oauth.service:services:benefits:read')->group(function () {
            Route::get('/beneficios', [BenefitApiController::class, 'catalog'])
                ->name('benefits.catalog');

            Route::get('/disponibilidad', [BenefitApiController::class, 'availability'])
                ->name('benefits.availability');

            Route::get('/asignaciones', [BenefitApiController::class, 'index'])
                ->name('benefits.assignments.index');

            Route::get('/asignaciones/{assignmentId}', [BenefitApiController::class, 'show'])
                ->name('benefits.assignments.show');
        });

        Route::middleware('oauth.service:services:benefits:write')->group(function () {
            Route::post('/asignaciones', [BenefitApiController::class, 'store'])
                ->name('benefits.assignments.store');

            Route::post('/asignaciones/{assignmentId}/cancelacion', [BenefitApiController::class, 'cancel'])
                ->name('benefits.assignments.cancel');
        });
    });
