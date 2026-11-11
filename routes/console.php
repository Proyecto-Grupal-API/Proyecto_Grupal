<?php

use App\Domains\Financial\Support\FinancialOperationsConfiguration;
use Illuminate\Console\Scheduling\CacheEventMutex;
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
if (config('becas_finanzas.enabled') === true) {
    Schedule::command('comunidad:conciliar-bonos-beca')->everyMinute()->withoutOverlapping(10);
}
Schedule::command('servicios:avisos')->everyMinute()->withoutOverlapping(10);
Schedule::command('servicios:revisar-sla')->everyFiveMinutes()->withoutOverlapping(10);

Schedule::command('inventario:expirar-reservas')->everyFiveMinutes()->withoutOverlapping(10);

Schedule::command('marketplace:reconciliar')->everyMinute()->withoutOverlapping(10);

if (config('comunidad.eventos_pagos.enabled') === true) {
    Schedule::command('comunidad:conciliar-pagos-eventos')->everyMinute()->withoutOverlapping(10);
}

Schedule::command('comunidad:expirar-reservas-eventos')->everyMinute()->withoutOverlapping(10);

Schedule::command('inventario:procesar-devoluciones')->everyMinute()->withoutOverlapping(10);
Schedule::command('team4:publish-outbox')->everyMinute()->withoutOverlapping(10);

if (config('platform.services_delivery.enabled') === true) {
    Schedule::command('servicios:publicar-auditoria --limit=20')->everyMinute()->withoutOverlapping(10);
}

if (config('platform.delivery.enabled') === true) {
    Schedule::command('comunidad:publicar-plataforma')->everyMinute()->withoutOverlapping();
}

if (config('financial.reconciliation.schedule_enabled')
    && app(FinancialOperationsConfiguration::class)->errors(requireSchedule: true) === []) {
    Schedule::command('financial:reconcile --dispatch')
        ->dailyAt(config('financial.reconciliation.daily_at'))
        ->timezone(config('financial.business_timezone'))
        ->preventOverlapsUsing(app(CacheEventMutex::class)->useStore('financial_scheduler'))
        ->withoutOverlapping();
}

if (config('financial.holds.schedule_expiration')) {
    Schedule::command('financial:expire-holds')
        ->everyMinute()
        ->preventOverlapsUsing(app(CacheEventMutex::class)->useStore('financial_scheduler'))
        ->withoutOverlapping(10);
}

if (config('financial_team4.enabled') === true) {
    Schedule::command('financial:team4-deliver')
        ->everyMinute()
        ->preventOverlapsUsing(app(CacheEventMutex::class)->useStore('financial_scheduler'))
        ->withoutOverlapping(10);
}

if (config('financial_community_notices.enabled') === true) {
    Schedule::command('financial:community-notices --limit=30')
        ->everyMinute()
        ->preventOverlapsUsing(app(CacheEventMutex::class)->useStore('financial_scheduler'))
        ->withoutOverlapping(10);
}
