<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Modelo de token Sanctum adaptado a MongoDB
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Autorización basada en permisos definidos en config/permissions.php
        Gate::before(function (User $user, string $ability) {
            if ($user->role === User::ROLE_PLATFORM_ADMIN) {
                return true;
            }

            if ($user->hasPermission($ability)) {
                return true;
            }

            return null; // Deja que políticas específicas evalúen si las hay
        });
    }
}
