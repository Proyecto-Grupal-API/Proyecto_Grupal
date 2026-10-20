<?php

/*
| Laravel Sanctum — modo token Bearer (API tokens).
| Se usa token (no cookie de sesión) porque el frontend Vue es una SPA servida por
| Vite en otro puerto y el backend es una API pura sobre MongoDB. `guard` vacío
| desactiva la autenticación por sesión para que solo valga el token.
*/

return [
    'stateful' => [],

    'guard' => [],

    // Minutos de vida de un token. null = no expira (NO recomendado).
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 480) !== null
        ? (int) env('SANCTUM_TOKEN_EXPIRATION', 480)
        : null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
