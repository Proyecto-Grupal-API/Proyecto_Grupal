<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Registrar los alias de los middlewares
        $middleware->alias([
            'session.active' => \App\Http\Middleware\EnsureSessionIsActive::class,
            'device.track' => \App\Http\Middleware\TrackDeviceSession::class,
            'role.context' => \App\Http\Middleware\EnsureHasContextualRole::class,
            'oauth.service' => \App\Http\Middleware\ValidateServiceToken::class,
            'reauth' => \App\Http\Middleware\EnsureRecentlyReauthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();