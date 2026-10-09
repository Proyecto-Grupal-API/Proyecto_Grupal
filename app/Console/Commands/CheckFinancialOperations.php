<?php

namespace App\Console\Commands;

use App\Domains\Financial\Support\FinancialOperationsConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class CheckFinancialOperations extends Command
{
    protected $signature = 'financial:operations-check {--require-schedule : Exige programación habilitada y completa}';
    protected $description = 'Comprueba configuración, tablas, acceso SQL y mutexes de operación financiera.';

    public function handle(FinancialOperationsConfiguration $configuration): int
    {
        $errors = $configuration->errors((bool) $this->option('require-schedule'));
        try {
            foreach (['financial_queue_jobs', 'financial_queue_failed_jobs', 'financial_scheduler_cache',
                'financial_scheduler_cache_locks', 'financial_job_locks'] as $table) {
                if (!Schema::connection('sqlsrv')->hasTable($table)) {
                    $errors[] = "Falta la tabla {$table}. Ejecuta las migraciones financieras.";
                }
            }
            if ($errors === []) {
                $store = Cache::store('financial_scheduler');
                $key = 'financial-operations-probe:' . Str::uuid();
                $lock = $store->lock($key, 10);
                $acquired = $lock->get();
                try {
                    $store->put($key . ':cache', 'probe', 10);
                    if ($store->get($key . ':cache') !== 'probe') {
                        $errors[] = 'No se pudo leer/escribir la caché compartida del scheduler.';
                    }
                    if (!$acquired || $store->lock($key, 10)->get()) {
                        $errors[] = 'El mutex compartido no pudo obtenerse de forma exclusiva.';
                    }
                } finally {
                    if ($acquired) { $lock->release(); }
                    $store->forget($key . ':cache');
                }
                $this->line('Pendientes en cola: ' . DB::connection('sqlsrv')->table('financial_queue_jobs')
                    ->where('queue', 'financial-reconciliation')->count());
                $this->line('Trabajos fallidos: ' . DB::connection('sqlsrv')->table('financial_queue_failed_jobs')->count());
            }
        } catch (Throwable $exception) {
            report($exception);
            $errors[] = 'No se pudo comprobar SQL Server o sus mutexes; consulta el log de Laravel.';
        }
        if (!extension_loaded('pcntl')) {
            $this->warn('PCNTL no está disponible: en Windows usa un solo worker para desarrollo. Producción requiere timeout efectivo.');
            if (app()->environment('production')) {
                $errors[] = 'En producción, el worker requiere PCNTL para aplicar su timeout.';
            }
        }
        foreach ($errors as $error) { $this->error($error); }
        if ($errors !== []) { return self::FAILURE; }
        $this->line('Zona: ' . config('financial.business_timezone'));
        $this->line('Horario: ' . (config('financial.reconciliation.schedule_enabled')
            ? config('financial.reconciliation.daily_at') . ' / ' . config('financial.reconciliation.calendar') : 'desactivado'));
        $this->info('Configuración comprobada. Esto no confirma que scheduler y worker estén ejecutándose.');
        return self::SUCCESS;
    }
}
