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

Schedule::command('comunidad:entregar-beneficios')->everyMinute()->withoutOverlapping(10);
Schedule::command('servicios:avisos')->everyMinute()->withoutOverlapping(10);

Schedule::command('inventario:expirar-reservas')->everyFiveMinutes()->withoutOverlapping(10);

Schedule::command('marketplace:reconciliar')->everyMinute()->withoutOverlapping(10);

Schedule::command('comunidad:expirar-reservas-eventos')->everyMinute()->withoutOverlapping(10);

Schedule::command('inventario:procesar-devoluciones')->everyMinute()->withoutOverlapping(10);
Schedule::command('team4:publish-outbox')->everyMinute()->withoutOverlapping(10);
