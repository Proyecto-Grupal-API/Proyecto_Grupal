<?php

namespace App\Providers;

use App\Domains\Financial\Adapters\Module1IdentityAdapter;
use App\Domains\Financial\Contracts\IdentityProvider;
use App\Domains\Financial\Adapters\PendingFinancialRoleProvider;
use App\Domains\Financial\Adapters\PendingCashReconciliationSource;
use App\Domains\Financial\Contracts\FinancialRoleProvider;
use App\Domains\Financial\Contracts\CashReconciliationSource;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Observers\FinancialTransactionObserver;
use App\Domains\Financial\Support\FinancialCorrelation;
use App\Events\CredentialChanged;
use App\Events\StudentConsentChanged;
use App\Events\StudentProfileChanged;
use App\Listeners\StoreDomainEvent;
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
        $this->app->bind(\App\Domains\Financial\Contracts\FinancialWebAuthorizer::class,
            \App\Domains\Financial\Adapters\PendingFinancialWebAuthorizer::class);
        $this->app->bind(FinancialRoleProvider::class, PendingFinancialRoleProvider::class);
        $this->app->bind(CashReconciliationSource::class, PendingCashReconciliationSource::class);
        $this->app->scoped(FinancialCorrelation::class);
        $this->app->bind(
            IdentityProvider::class,
            Module1IdentityAdapter::class
        );

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FinancialTransaction::observe(FinancialTransactionObserver::class);

        Vite::prefetch(concurrency: 3);

        Event::listen([
            StudentProfileChanged::class,
            StudentConsentChanged::class,
            CredentialChanged::class,
        ], StoreDomainEvent::class);
    }
}
