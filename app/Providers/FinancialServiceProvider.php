<?php

namespace App\Providers;

use App\Domains\Financial\Adapters\CashShiftReconciliationSource;
use App\Domains\Financial\Adapters\Module1IdentityAdapter;
use App\Domains\Financial\Adapters\Module1TransferAccountAdapter;
use App\Domains\Financial\Adapters\Module1TransferRecipientAdapter;
use App\Domains\Financial\Adapters\Module1TransferSessionContextAdapter;
use App\Domains\Financial\Adapters\PendingBonusAuthorizationProvider;
use App\Domains\Financial\Adapters\PendingCashAuthorizationProvider;
use App\Domains\Financial\Adapters\PendingFinancialRoleProvider;
use App\Domains\Financial\Adapters\PendingFinancialWebAuthorizer;
use App\Domains\Financial\Adapters\PendingTransferAuthorizationProvider;
use App\Domains\Financial\Adapters\PendingTransferDelegationProvider;
use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Contracts\CashReconciliationSource;
use App\Domains\Financial\Contracts\FinancialRoleProvider;
use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Contracts\IdentityProvider;
use App\Domains\Financial\Contracts\TransferAccountProvider;
use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Contracts\TransferDelegationProvider;
use App\Domains\Financial\Contracts\TransferRecipientProvider;
use App\Domains\Financial\Contracts\TransferSessionContextProvider;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Observers\FinancialTransactionObserver;
use App\Domains\Financial\Support\FinancialCorrelation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class FinancialServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TransferAccountProvider::class,
            Module1TransferAccountAdapter::class);
        $this->app->bind(TransferSessionContextProvider::class,
            Module1TransferSessionContextAdapter::class);
        $this->app->bind(TransferDelegationProvider::class,
            PendingTransferDelegationProvider::class);
        $this->app->bind(TransferRecipientProvider::class,
            Module1TransferRecipientAdapter::class);
        $this->app->bind(TransferAuthorizationProvider::class,
            PendingTransferAuthorizationProvider::class);
        $this->app->bind(BonusAuthorizationProvider::class,
            PendingBonusAuthorizationProvider::class);
        $this->app->bind(FinancialWebAuthorizer::class,
            PendingFinancialWebAuthorizer::class);
        $this->app->bind(CashAuthorizationProvider::class,
            PendingCashAuthorizationProvider::class);
        $this->app->bind(FinancialRoleProvider::class, PendingFinancialRoleProvider::class);
        $this->app->bind(CashReconciliationSource::class, CashShiftReconciliationSource::class);
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
        RateLimiter::for('student-transfer-api', function (Request $request) {
            return Limit::perMinute(30)->by(
                (string) $request->attributes->get('oauth_client_id').'|'.$request->route()->getActionMethod()
            );
        });

        FinancialTransaction::observe(FinancialTransactionObserver::class);
        $this->loadRoutesFrom(base_path('routes/financial.php'));
        $this->loadRoutesFrom(base_path('routes/financial_api.php'));

    }
}
