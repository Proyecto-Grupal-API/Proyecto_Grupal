<?php
namespace App\Providers;
use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Adapters\RoleBonusAuthorizationProvider;
use App\Domains\Financial\Adapters\PendingBonusAuthorizationProvider;
use App\Domains\Identity\Contracts\CapabilityAuthorization;
use App\Domains\Identity\Contracts\ScopedCapabilityAuthorization;
use App\Domains\Identity\Services\FinancialCapabilityAuthorization;
use Illuminate\Support\ServiceProvider;
final class BonusRoleIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ScopedCapabilityAuthorization::class, fn ($app) => $app->make(config('financial.bonus_roles.authorizer')));
        $this->app->bind(\App\Domains\Financial\Contracts\BonusBeneficiaryProvider::class, \App\Domains\Identity\Adapters\Module1BonusBeneficiaryProvider::class);
        $this->app->bind(CapabilityAuthorization::class, fn ($app) => $app->make(ScopedCapabilityAuthorization::class));
        $this->app->bind(BonusAuthorizationProvider::class, fn ($app) => $app->make(config('financial.bonus_roles.enabled') ? RoleBonusAuthorizationProvider::class : PendingBonusAuthorizationProvider::class));
    }
    public function boot(): void
    {
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(
            function (\App\Domains\Identity\Exceptions\AuthorizationDependencyUnavailable $exception, \Illuminate\Http\Request $request) {
                return response()->json([
                    'message' => 'La autorización de roles no está disponible. No se ejecutó la operación.',
                    'code' => 'BONUS_AUTHORIZATION_UNAVAILABLE',
                ], 503);
            }
        );
    }
}