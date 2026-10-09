<?php

namespace App\Domains\Financial\Jobs;

use App\Domains\Financial\Enums\ReconciliationStatus;
use App\Domains\Financial\Enums\ReconciliationTrigger;
use App\Domains\Financial\Exceptions\ReconciliationInProgressException;
use App\Domains\Financial\Services\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 2.10 Conciliación programada de un día de negocio.
 *
 * - La fecha se fija al despachar (no al ejecutar), para que un reintento
 *   después de medianoche concilie el mismo día.
 * - Cada intento queda registrado como una corrida (attempt = n). Una
 *   corrida FALLIDA lanza excepción para que la cola reintente.
 * - Si ya hay otra corrida en curso para la fecha, el job se libera y se
 *   reintenta más tarde en lugar de ejecutar en paralelo.
 * - La llave de idempotencia es por fecha e intento: un mismo intento
 *   reentregado por la cola no ejecuta una segunda corrida.
 */
class RunFinancialReconciliation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    /** @var array<int, int> */
    public array $backoff;

    public function __construct(
        public readonly string $businessDate,
        public readonly ?string $executedBy = null
    ) {
        $this->onConnection((string) config('financial.reconciliation.queue_connection', 'financial'));
        $this->onQueue((string) config('financial.reconciliation.queue_name', 'financial-reconciliation'));
        $this->timeout = (int) config('financial.reconciliation.job_timeout_seconds', 600);
        $this->tries = max(1, (int) config('financial.reconciliation.job_tries', 3));
        $this->backoff = array_map(
            'intval',
            array_filter(explode(',', (string) config('financial.reconciliation.job_backoff_seconds', '60,300')))
        );
    }

    public function handle(ReconciliationService $service): void
    {
        $attempt = max(1, (int) ($this->job?->attempts() ?? 1));
        $actor = $this->executedBy ?: (string) config('financial.reconciliation.system_actor');

        try {
            $reconciliation = $service->run(
                $this->businessDate,
                $actor,
                null,
                ReconciliationTrigger::PROGRAMADA,
                "scheduled:{$this->businessDate}:attempt:{$attempt}",
                $attempt
            );
        } catch (ReconciliationInProgressException $exception) {
            Log::warning('Conciliación programada pospuesta: hay otra en curso.', [
                'business_date' => $this->businessDate,
                'attempt' => $attempt,
            ]);

            if ($this->job) {
                $this->release($this->backoff[0] ?? 60);

                return;
            }

            throw $exception;
        }

        Log::info('Conciliación programada terminada.', [
            'reconciliation_id' => $reconciliation->public_id,
            'business_date' => $this->businessDate,
            'status' => $reconciliation->status->value,
            'differences' => $reconciliation->differences_count,
            'attempt' => $attempt,
        ]);

        if ($reconciliation->status === ReconciliationStatus::FALLIDA) {
            throw new RuntimeException(
                "La conciliación {$reconciliation->public_id} falló: {$reconciliation->error_message}"
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Conciliación programada agotó sus reintentos.', [
            'business_date' => $this->businessDate,
            'error' => $exception->getMessage(),
        ]);
    }
}
