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
        \Illuminate\Support\Facades\RateLimiter::for('avisos-servicio', fn (\Illuminate\Http\Request $r) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by('avisos:'.$r->attributes->get('oauth_client_id')));
        // Consultas de autorización de negocio (E3/E4 → E1). `throttle:30,1` se contaba por IP del llamador, que entre
        // módulos del mismo despliegue es la propia aplicación: un único contador para todos los usuarios y rutas de
        // servicio. Laravel ejecuta ThrottleRequests antes que `oauth.service` (prioridad de middleware), así que el
        // atributo oauth_client_id todavía no existe aquí: el cliente se lee del propio token firmado.
        $serviceClient = static function (\Illuminate\Http\Request $r): string {
            try {
                return 'client:'.(string) (app(\App\Services\OAuthTokenService::class)->decode((string) $r->bearerToken())->sub ?? '');
            } catch (\Throwable) {
                return 'ip:'.$r->ip(); // sin token válido la petición se rechazará; no consume el presupuesto de ningún cliente
            }
        };
        // Cada pantalla de Inventarios hace 4 consultas: el presupuesto es por cliente y por sujeto consultado
        // (120/min ≈ 30 pantallas por usuario y módulo).
        \Illuminate\Support\Facades\RateLimiter::for('identity-business-read', fn (\Illuminate\Http\Request $r) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by('identity-business-read:'.$serviceClient($r)
                .'|'.(is_string($r->input('subject_id')) ? $r->input('subject_id') : '')));
        // El alta de propietario es una escritura: conserva 30/min, ahora por cliente de servicio.
        \Illuminate\Support\Facades\RateLimiter::for('identity-business-provision', fn (\Illuminate\Http\Request $r) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('identity-business-provision:'.$serviceClient($r)));
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
