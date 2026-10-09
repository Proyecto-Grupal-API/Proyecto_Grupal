<?php

namespace App\Console\Commands;

use App\Domains\Financial\Support\FinancialOperationsConfiguration;
use Illuminate\Console\Command;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;

class WorkFinancialQueue extends Command
{
    protected $signature = 'financial:queue-work {--once : Procesa como máximo un trabajo}
        {--stop-when-empty : Termina cuando la cola queda vacía}';
    protected $description = 'Ejecuta exclusivamente la cola de conciliación con fallos registrados en SQL Server.';

    public function handle(FinancialOperationsConfiguration $configuration): int
    {
        $errors = $configuration->errors();
        if (app()->environment('production') && !extension_loaded('pcntl')) {
            $errors[] = 'Producción requiere PCNTL para aplicar el timeout del worker.';
        }
        foreach ($errors as $error) { $this->error($error); }
        if ($errors !== []) { return self::INVALID; }

        // Scoped to this CLI process: other modules keep their default failed-job provider.
        $bound = app()->bound('queue.failer');
        $original = $bound ? app('queue.failer') : null;
        app()->instance('queue.failer', new DatabaseUuidFailedJobProvider(
            app('db'), 'sqlsrv', 'financial_queue_failed_jobs'
        ));
        try {
            return $this->call('queue:work', [
                'connection' => 'financial',
                '--queue' => 'financial-reconciliation',
                '--sleep' => 3,
                '--tries' => (int) config('financial.reconciliation.job_tries'),
                '--timeout' => (int) config('financial.reconciliation.job_timeout_seconds'),
                '--once' => (bool) $this->option('once'),
                '--stop-when-empty' => (bool) $this->option('stop-when-empty'),
            ]);
        } finally {
            if ($bound) { app()->instance('queue.failer', $original); }
            else { app()->forgetInstance('queue.failer'); }
        }
    }
}
