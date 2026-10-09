<?php

namespace App\Domains\Financial\Support;

/** Configuration checks without reading credentials or changing any state. */
class FinancialOperationsConfiguration
{
    public function errors(bool $requireSchedule = false): array
    {
        $errors = [];
        $timezone = config('financial.business_timezone');
        if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
            $errors[] = 'FINANCIAL_BUSINESS_TIMEZONE no es una zona horaria válida.';
        }
        $timeout = (int) config('financial.reconciliation.job_timeout_seconds');
        $retry = (int) config('queue.connections.financial.retry_after');
        $lock = (int) config('financial.reconciliation.lock_ttl_seconds');
        if ($timeout < 1 || $lock <= $timeout || $retry <= $lock) {
            $errors[] = 'Se requiere 0 < JOB_TIMEOUT < LOCK_TTL < RETRY_AFTER.';
        }
        if ((int) config('financial.reconciliation.job_tries') < 1) {
            $errors[] = 'JOB_TRIES debe ser mayor que cero.';
        }
        $backoff = (string) config('financial.reconciliation.job_backoff_seconds');
        if (!preg_match('/^\d+(,\d+)*$/', $backoff)) {
            $errors[] = 'JOB_BACKOFF debe contener segundos enteros separados por comas.';
        }
        if (trim((string) config('financial.reconciliation.system_actor')) === '') {
            $errors[] = 'El actor del scheduler es obligatorio.';
        }
        if (config('financial.reconciliation.queue_connection') !== 'financial'
            || config('financial.reconciliation.queue_name') !== 'financial-reconciliation'
            || config('queue.connections.financial.driver') !== 'database'
            || config('queue.connections.financial.connection') !== 'sqlsrv') {
            $errors[] = 'La conciliación debe utilizar la cola dedicada financial en sqlsrv.';
        }
        if ($requireSchedule || config('financial.reconciliation.schedule_enabled')) {
            if (!config('financial.reconciliation.schedule_enabled')) {
                $errors[] = 'La programación de conciliación está desactivada.';
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) config('financial.reconciliation.daily_at'))) {
                $errors[] = 'DAILY_AT debe tener formato HH:MM.';
            }
            // Weekday-only execution would leave weekend business dates unreconciled.
            if (config('financial.reconciliation.calendar') !== 'DIARIO') {
                $errors[] = 'Utiliza DIARIO: el comando concilia ayer y no recupera fines de semana omitidos.';
            }
            if (config('financial.reconciliation.scheduler_cache_store') !== 'financial_scheduler') {
                $errors[] = 'El scheduler requiere FINANCIAL_SCHEDULER_CACHE_STORE=financial_scheduler.';
            }
        }
        return $errors;
    }
}
