<?php

namespace App\Providers;

use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;
use App\Domains\Financial\Adapters\PendingInstitutionalBudgetAuthorization;
use Illuminate\Support\ServiceProvider;

final class InstitutionalBudgetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/financial_institutional_read.php'));
        $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
            if (config('financial_institutional_expiration.schedule_enabled') !== true) return;
            $actor = config('financial_institutional_expiration.actor_id');
            if (!is_string($actor) || !preg_match('/\A[0-9a-f]{24}\z/i', $actor)) {
                \Illuminate\Support\Facades\Log::warning('Vencimiento BECA no programado: falta actor valido.');
                return;
            }
            // Local scheduler mutex is only an optimization. SQL domain locks/version checks
            // and the stable per-bonus operation key also fence independent servers.
            $schedule->command('financial:expire-institutional-becas', ['--confirm','--all'])
                ->everyMinute()->withoutOverlapping();
        });
    }

    public function register(): void
    {
        $this->app->scoped(\App\Domains\Financial\Services\InstitutionalCheckoutConsent::class);
        $this->app->bind(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class, \App\Domains\Financial\Adapters\PendingInstitutionalSettlementAuthorization::class);
        $this->app->bind(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class, \App\Domains\Financial\Adapters\CheckoutInstitutionalPurchaseAuthorization::class);
        $this->app->bind(InstitutionalBudgetAuthorization::class, PendingInstitutionalBudgetAuthorization::class);
    }
}
