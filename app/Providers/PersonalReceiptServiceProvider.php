<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class PersonalReceiptServiceProvider extends ServiceProvider
{
    public function boot(): void { $this->loadRoutesFrom(base_path('routes/financial_receipts.php')); }
}
