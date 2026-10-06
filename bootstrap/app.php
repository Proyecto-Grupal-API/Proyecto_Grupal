<?php

use App\Http\Controllers\Api\Identity\BusinessAuthorizationController;
use App\Http\Middleware\EnsureHasContextualRole;
use App\Http\Middleware\EnsureInitialPasswordChanged;
use App\Http\Middleware\EnsureRecentlyReauthenticated;
use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrackDeviceSession;
use App\Http\Middleware\ValidateServiceToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            'session.active' => EnsureSessionIsActive::class,
            'device.track' => TrackDeviceSession::class,
            'role.context' => EnsureHasContextualRole::class,
            'oauth.service' => ValidateServiceToken::class,
            'reauth' => EnsureRecentlyReauthenticated::class,
            'initial.password' => EnsureInitialPasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
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
