<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\StudentServices\Benefits\StudentDirectory;
use App\Services\StudentServices\Benefits\IdentityStudentDirectory;
use App\Services\StudentServices\ServiceAccess\StudentCredentialResolver;
use App\Services\StudentServices\ServiceAccess\IdentityCredentialResolver;
use App\Services\StudentServices\Payments\StudentPaymentGateway;
use App\Services\StudentServices\Payments\PendingStudentPaymentGateway;

class StudentServicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StudentDirectory::class, IdentityStudentDirectory::class);
        $this->app->bind(StudentCredentialResolver::class, IdentityCredentialResolver::class);
        $this->app->bind(StudentPaymentGateway::class, PendingStudentPaymentGateway::class);
    }
    public function boot(): void
    {
        foreach (\App\Services\StudentServices\Notifications\NotificationPlanner::MODELS as $model) {
            $model::observe(\App\Services\StudentServices\Notifications\NotificationPlanner::class);
        }
    }
}
