<?php

use App\Http\Controllers\StudentServices\Benefits\ApiResponse;
use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
         * Token de servicio servidor a servidor. Mismo alias que el
         * middleware OAuth del Equipo 1: al integrar, este alias debe
         * apuntar a su ValidateServiceToken y las rutas no cambian.
         */
        $middleware->alias([
            'oauth.service' => AuthenticateServiceClient::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Errores no controlados de la API del Equipo 5 en el formato
         * acordado (codigo, mensaje, correlacion_id).
         */
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/v1/servicios/*')) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

            [$code, $message] = match (true) {
                $status === 404 => ['no_encontrado', 'Recurso no encontrado.'],
                $status === 405 => ['metodo_no_permitido', 'Método HTTP no permitido para esta ruta.'],
                $status === 429 => ['demasiadas_solicitudes', 'Demasiadas solicitudes; intenta más tarde.'],
                $status >= 500 => ['error_interno', 'Error interno; consulta el resultado antes de reintentar.'],
                default => ['error', $exception->getMessage() ?: 'Solicitud no válida.'],
            };

            return ApiResponse::error($request, $code, $message, $status);
        });
    })->create();
