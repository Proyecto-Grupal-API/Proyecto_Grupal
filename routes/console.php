<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| 2.10 Conciliación diaria programada.
|
| Hora de ejecución y calendario deben configurarse explícitamente. El scheduler solo se
| registra si se habilitó Y se configuraron explícitamente hora y
| calendario; no hay valores supuestos. Mientras tanto, la conciliación
| se ejecuta con `php artisan financial:reconcile`.
*/
$reconciliation = config('financial.reconciliation');

// Applies to scheduler mutexes (including hold expiration), not the app cache.
if ($store = ($reconciliation['scheduler_cache_store'] ?? null)) {
    Schedule::useCache($store);
}

if ($reconciliation['schedule_enabled'] ?? false) {
    $dailyAt = $reconciliation['daily_at'] ?? null;
    $calendar = $reconciliation['calendar'] ?? null;
    $validTime = is_string($dailyAt) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dailyAt);
    $validCalendar = $calendar === 'DIARIO';
    $operationalErrors = app(\App\Domains\Financial\Support\FinancialOperationsConfiguration::class)->errors(true);

    if ($validTime && $validCalendar && $operationalErrors === []) {
        $event = Schedule::command('financial:reconcile --dispatch')
            ->dailyAt($dailyAt)
            ->timezone(config('financial.business_timezone'))
            ->withoutOverlapping()
            ->onOneServer();
    } else {
        Log::warning(
            'Conciliación programada con configuración inválida; no se programó.',
            ['daily_at' => $dailyAt, 'calendar' => $calendar, 'errors' => $operationalErrors]
        );
    }
}

if (config('financial.holds.schedule_expiration', false)) {
    Schedule::command('financial:expire-holds')->everyMinute()->withoutOverlapping()->onOneServer();
}
