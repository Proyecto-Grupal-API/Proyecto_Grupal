<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Adapters\{CampusCashAuthorizationProvider,PendingCashAuthorizationProvider};
class CashCampusIntegrationServiceProvider extends ServiceProvider {
    public function register():void {
        $this->app->bind(CashAuthorizationProvider::class,fn($app)=>match(config('financial_cash_authorization.provider','pending')){
            'campus'=>$app->make(CampusCashAuthorizationProvider::class),
            'pending'=>$app->make(PendingCashAuthorizationProvider::class),
            default=>throw new \Symfony\Component\HttpKernel\Exception\HttpException(503,'Proveedor de autorización de caja inválido.'),
        });
    }
    public function boot():void {$this->commands([\App\Console\Commands\CreateCashDevelopmentContext::class]);}
}
