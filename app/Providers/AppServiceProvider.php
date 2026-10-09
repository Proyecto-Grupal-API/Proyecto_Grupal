<?php

namespace App\Providers;

use App\Services\StudentServices\Benefits\LocalStudentDirectory;
use App\Services\StudentServices\Benefits\StudentDirectory;
use App\Services\StudentServices\ServiceAccess\LocalStudentCredentialResolver;
use App\Services\StudentServices\ServiceAccess\StudentCredentialResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Modulo 5.11: resolución de credenciales NFC/QR. Cuando el
         * Equipo 1 publique su servicio de identidad, cambiar aquí la
         * implementación por la suya.
         */
        $this->app->bind(
            StudentCredentialResolver::class,
            LocalStudentCredentialResolver::class
        );

        /*
         * Beneficios becados (REQ-M6-E5-001): estatus del beneficiario.
         * Al integrar, cambiar por un cliente de la API del Equipo 1
         * (GET /api/v1/students/{id}/status, scope students:read).
         */
        $this->app->bind(
            StudentDirectory::class,
            LocalStudentDirectory::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
