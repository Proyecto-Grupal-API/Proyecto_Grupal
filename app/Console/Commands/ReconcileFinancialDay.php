<?php

namespace App\Console\Commands;

use App\Domains\Financial\Enums\ReconciliationStatus;
use App\Domains\Financial\Enums\ReconciliationTrigger;
use App\Domains\Financial\Exceptions\ReconciliationInProgressException;
use App\Domains\Financial\Jobs\RunFinancialReconciliation;
use App\Domains\Financial\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 2.10 Conciliación diaria.
 *
 *   php artisan financial:reconcile                      (día anterior, en línea)
 *   php artisan financial:reconcile 2026-10-06
 *   php artisan financial:reconcile 2026-10-06 --idempotency-key=cierre-2026-10-06
 *   php artisan financial:reconcile --dispatch           (encola el job con reintentos)
 *
 * --dispatch es lo que usa el scheduler. Sin él, el comando ejecuta en
 * línea (útil en desarrollo).
 */
class ReconcileFinancialDay extends Command
{
    protected $signature = 'financial:reconcile
        {date? : Fecha de negocio AAAA-MM-DD (por defecto, ayer)}
        {--executed-by= : Actor que ejecuta la conciliación}
        {--idempotency-key= : Repetir con la misma llave devuelve la corrida ya registrada}
        {--dispatch : Encola la conciliación como job programado (con reintentos)}';

    protected $description = 'Concilia la información financiera de un día de negocio y registra las diferencias.';

    public function handle(ReconciliationService $service): int
    {
        $timezone = config('financial.business_timezone');
        if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
            $this->error('La zona horaria financiera no es válida.');
            return self::INVALID;
        }

        $date = $this->argument('date')
            ?? CarbonImmutable::now(config('financial.business_timezone'))
                ->subDay()
                ->format('Y-m-d');

        // Reject malformed and impossible dates before persisting a queue job.
        if (!is_string($date) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $this->error('La fecha debe ser un día válido con formato AAAA-MM-DD.');
            return self::INVALID;
        }

        if ($date > CarbonImmutable::now($timezone)->format('Y-m-d')) {
            $this->error('No se puede conciliar una fecha futura.');
            return self::INVALID;
        }

        $actor = $this->option('executed-by')
            ?: config('financial.reconciliation.system_actor');

        if (trim((string) $actor) === '') {
            $this->error('El actor de conciliación es obligatorio.');
            return self::INVALID;
        }

        if ($this->option('dispatch')) {
            $errors = app(\App\Domains\Financial\Support\FinancialOperationsConfiguration::class)->errors();
            if ($errors !== []) {
                foreach ($errors as $error) { $this->error($error); }
                return self::INVALID;
            }
            RunFinancialReconciliation::dispatch($date, $actor);

            $this->line("Conciliación de {$date} encolada.");

            return self::SUCCESS;
        }

        try {
            $reconciliation = $service->run(
                $date,
                $actor,
                null,
                ReconciliationTrigger::COMANDO,
                $this->option('idempotency-key') ?: null
            );
        } catch (ReconciliationInProgressException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->line("Conciliación: {$reconciliation->public_id}");
        $this->line("Fecha: {$date} ({$reconciliation->timezone})");
        $this->line("Estado: {$reconciliation->status->value}");
        $this->line("Diferencias: {$reconciliation->differences_count} (nuevas: {$reconciliation->new_differences_count}, ya conocidas: {$reconciliation->recurring_differences_count})");
        $this->line('Caja (2.8): ' . ($reconciliation->cash_status?->value ?? 'N/D'));

        if ($reconciliation->status === ReconciliationStatus::FALLIDA) {
            $this->error((string) $reconciliation->error_message);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
