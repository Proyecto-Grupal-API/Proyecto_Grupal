<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Modulo 1.7 - Retencion de bitacora de seguridad (security_events).
Schedule::command('security-events:prune')->daily();
// Scheduler lock complements the per-event claim; expire it after 30 minutes if the process dies.
Schedule::command('events:publish')->everyMinute()->withoutOverlapping(30);
