<?php

use App\Exceptions\AvisoApiException;
use App\Exceptions\InventoryModule\IdentityUnavailable;
use App\Http\Controllers\Api\Identity\BusinessAuthorizationController;
use App\Http\Controllers\StudentServices\Benefits\ApiResponse;
use App\Http\Middleware\AssignFinancialCorrelation;
use App\Http\Middleware\EnsureHasContextualRole;
use App\Http\Middleware\EnsureInitialPasswordChanged;
use App\Http\Middleware\EnsureRecentlyReauthenticated;
use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InventoryModule\EnsureTeam4Permission;
use App\Http\Middleware\TrackDeviceSession;
use App\Http\Middleware\ValidateServiceToken;
use App\Services\InventoryModule\BusinessContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureSessionIsActive::class,
            EnsureInitialPasswordChanged::class,
            EnsureRequiredTwoFactorAuthentication::class,
        ]);

        // Registrar los alias de los middlewares
        $middleware->alias([
            'financial.correlation' => AssignFinancialCorrelation::class,
            'team4.permission' => EnsureTeam4Permission::class,
            'session.active' => EnsureSessionIsActive::class,
            'device.track' => TrackDeviceSession::class,
            'role.context' => EnsureHasContextualRole::class,
            'oauth.service' => ValidateServiceToken::class,
            'reauth' => EnsureRecentlyReauthenticated::class,
            'initial.password' => EnsureInitialPasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('finanzas*', 'api/v1/financial*', 'api/v1/recompensas*', 'api/v1/plataforma*', 'recompensas*')) {
                return null;
            }
            if ($error instanceof AuthenticationException || $error instanceof ValidationException) {
                return null;
            }
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => match ($status) {
                    401 => 'Se requiere autenticación válida.',403 => 'Sin permiso para esta operación.',404 => 'Recurso no encontrado.',409 => 'El identificador ya existe con otro contenido.',422 => 'Revisa los datos enviados.',default => 'Servicio temporalmente no disponible.'
                }], $status)->header('Cache-Control', 'private, no-store');
            }
            if ($request->is('finanzas*') && in_array($status, [403, 503], true)) {
                return Inertia::render('Financial/Unavailable', ['message' => 'Esta operación requiere acuerdos de autorización con Identidad y Finanzas que todavía están pendientes. Tu cartera y tus comprobantes siguen disponibles.'])->toResponse($request)->setStatusCode($status);
            }

            return null;
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('tienda*', 'api/tienda/*', 'api/v1/marketplace/*', 'api/v1/comunidad/organizaciones/comercio')) {
                return null;
            }
            if ($error instanceof AuthenticationException) {
                return null;
            }
            $status = $error instanceof ValidationException ? 422 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500);
            $message = $error instanceof HttpExceptionInterface && in_array($status, [403, 409, 422, 503], true) ? $error->getMessage() : 'No fue posible completar la operación de Marketplace.';
            if ($request->is('api/*') || $request->expectsJson()) {
                $body = ['message' => $message];
                if ($error instanceof ValidationException) {
                    $body['errors'] = $error->errors();
                }

                return response()->json($body, $status)->header('Cache-Control', 'private, no-store');
            }
            if ($error instanceof ValidationException) {
                return null;
            }
            if (! $request->isMethod('GET') && in_array($status, [409, 422, 503], true)) {
                return redirect()->back(303)->withErrors(['marketplace' => $message]);
            }

            return Inertia::render('Marketplace/Unavailable', ['message' => $message])->toResponse($request)->setStatusCode($status);
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! ($request->is('equipo4*') || $request->is('api/equipo4*') || $request->is('api/v1/inventory*'))) {
                return null;
            }
            $status = $error instanceof IdentityUnavailable ? 503
                : ($error instanceof ValidationException ? 422 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500));
            if (in_array($status, [403, 503], true) && ! $request->is('api/*') && ! $request->expectsJson()) {
                $request->attributes->remove('inventory_user');

                return Inertia::render('Equipo4/NotAuthorized', ['reason' => $status === 503 ? 'identity_unavailable' : 'permission_denied',
                    'businesses' => app(BusinessContext::class)->options()])->toResponse($request)->setStatusCode($status);
            }
            if ($request->is('api/*') || $request->expectsJson()) {
                $message = match ($status) {
                    401 => 'Se requiere una firma válida.', 403 => 'Sin permiso en el negocio seleccionado.',
                    404 => 'Recurso no encontrado.', 422 => 'Revisa los datos enviados.', 503 => 'Identidad o integración no disponible.', default => 'No fue posible completar la operación.'
                };
                $body = ['message' => $message];
                if ($error instanceof ValidationException) {
                    $body['errors'] = $error->errors();
                }

                return response()->json($body, $status);
            }

            return null;
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/v1/servicios/*')) {
                return null;
            }
            $status = $error instanceof ValidationException ? 422 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500);
            [$codigo, $mensaje] = match ($status) {
                401 => ['no_autenticado', 'Se requiere un token de servicio válido.'],
                403 => ['sin_permiso', 'El cliente no tiene el scope requerido.'],
                404 => ['no_encontrado', 'Recurso no encontrado.'],
                422 => ['datos_invalidos', 'Revisa los datos enviados.'],
                429 => ['demasiadas_solicitudes', 'Intenta nuevamente más tarde.'],
                default => ['error_interno', 'No fue posible completar la operación.'],
            };

            return ApiResponse::error($request, $codigo, $mensaje, $status);
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/v1/comunidad/notificaciones*')) {
                return null;
            }
            $http = $error instanceof HttpExceptionInterface;
            $status = $error instanceof ValidationException ? 422 : ($http ? $error->getStatusCode() : 500);
            $codigo = $error instanceof AvisoApiException ? $error->codigo : match ($status) {
                401 => 'no_autenticado', 403 => 'sin_permiso', 404 => 'no_encontrada', 409 => 'conflicto',
                422 => 'datos_invalidos', 429 => 'limite_excedido', default => 'error_servicio',
            };
            $mensaje = $error instanceof AvisoApiException ? $error->getMessage() : match ($status) {
                401 => 'Se requiere un token de servicio válido.', 403 => 'El cliente no tiene permiso para esta operación.',
                404 => 'No se encontró el recurso.', 422 => 'Revisa los datos enviados.', 429 => 'Se alcanzó el límite de 120 solicitudes por minuto.',
                default => 'No fue posible completar la operación.',
            };
            $correlacion = $request->attributes->get('aviso_correlacion', (string) Str::uuid());
            $body = ['codigo' => $codigo, 'mensaje' => $mensaje, 'correlacion_id' => $correlacion];
            if ($error instanceof ValidationException) {
                $body['errores'] = $error->errors();
            }

            return response()->json($body, $status, $http ? $error->getHeaders() : [])
                ->header('X-Correlation-Id', $correlacion)->header('Cache-Control', 'private, no-store');
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception) => $request->is('api/v1/organizations/*/summary') || $request->expectsJson()
        );
        // Only the new business contracts: stable errors even when a local deployment has debug enabled.
        $exceptions->render(function (Throwable $error, Request $request) {
            if ($request->route()?->getControllerClass() !== BusinessAuthorizationController::class
                && ! in_array($request->decodedPath(), ['api/v1/identity/authorization/check',
                    'api/v1/identity/assignments', 'api/v1/identity/business-owner/provision'], true)) {
                return null;
            }
            if ($error instanceof ValidationException) {
                return response()->json(['message' => 'Invalid request.', 'errors' => $error->errors()], 422);
            }
            $http = $error instanceof HttpExceptionInterface;
            $status = $http ? $error->getStatusCode() : 500;
            $message = match ($status) {
                401 => 'A valid service token is required.',
                403 => 'Service access denied.',
                405 => 'Method not allowed.',
                409 => 'Initial owner provisioning conflicts with existing state.',
                422 => 'The requested subject is not eligible for provisioning.',
                429 => 'Too many requests.',
                503 => 'Owner provisioning schema is not ready.',
                default => 'Internal service error.',
            };

            return response()->json(['message' => $message], $status, $http ? $error->getHeaders() : []);
        });
    })->create();
