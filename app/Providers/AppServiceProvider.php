<?php

namespace App\Providers;

use App\Events\CredentialChanged;
use App\Events\StudentConsentChanged;
use App\Events\StudentProfileChanged;
use App\Listeners\RevokeSessionOnLogout;
use App\Listeners\StoreDomainEvent;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        Event::listen([
            StudentProfileChanged::class,
            StudentConsentChanged::class,
            CredentialChanged::class,
        ], StoreDomainEvent::class);

        // Modulo 1.7 - al cerrar sesion, marca revocada la sesion Mongo
        // correspondiente para reflejarlo en "Dispositivos y sesiones".
        Event::listen(Logout::class, RevokeSessionOnLogout::class);
    }
}
