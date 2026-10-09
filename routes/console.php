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
| DECISIÓN PENDIENTE: hora de corte y calendario. El scheduler solo se
| registra si se habilitó Y se configuraron explícitamente hora y
| calendario; no hay valores supuestos. Mientras tanto, la conciliación
| se ejecuta con `php artisan financial:reconcile`.
*/
$reconciliation = config('financial.reconciliation');

if ($reconciliation['schedule_enabled'] ?? false) {
    $dailyAt = $reconciliation['daily_at'] ?? null;
    $calendar = $reconciliation['calendar'] ?? null;
    $validTime = is_string($dailyAt) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dailyAt);
    $validCalendar = in_array($calendar, ['DIARIO', 'LUNES_A_VIERNES'], true);

    if ($validTime && $validCalendar) {
        $event = Schedule::command('financial:reconcile --dispatch')
            ->dailyAt($dailyAt)
            ->timezone(config('financial.business_timezone'))
            ->withoutOverlapping()
            ->onOneServer();

        if ($calendar === 'LUNES_A_VIERNES') {
            $event->weekdays();
        }
    } else {
        Log::warning(
            'Conciliación programada habilitada pero sin hora de corte o calendario válidos; no se programó.',
            ['daily_at' => $dailyAt, 'calendar' => $calendar]
        );
    }
}

if (config('financial.holds.schedule_expiration', false)) {
    Schedule::command('financial:expire-holds')->everyMinute()->withoutOverlapping()->onOneServer();
}
