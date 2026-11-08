<?php

namespace App\Console\Commands;

use App\Services\AlertGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateStockAlerts extends Command
{
    protected $signature = 'team4:generate-alerts';
    protected $description = 'Genera alertas de stock bajo y productos sin movimiento para cada negocio.';

    public function handle(): int
    {
        $defaultBusinessId = (string) config('team4.default_business_id', 'BUS-CD-SOUV-001');
        $businessIds = DB::connection('mongodb')->getCollection('products')->distinct('business_id');
        $businessIds = array_values(array_unique(array_filter(array_map('strval', $businessIds))));
        if ($businessIds === []) {
            $businessIds = [$defaultBusinessId];
        }

        $total = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'notifications' => 0];
        $processingBusinessId = $defaultBusinessId;
        try {
            foreach ($businessIds as $businessId) {
                $processingBusinessId = $businessId;
                config(['team4.business_id' => $businessId]);
                $stats = app(AlertGeneratorService::class)->generate();
                foreach ($total as $key => $value) {
                    $total[$key] += (int) ($stats[$key] ?? 0);
                }
            }
        } catch (\Throwable $exception) {
            config(['team4.business_id' => $defaultBusinessId]);
            Log::error('No se pudieron generar todas las alertas de inventario.', [
                'business_id' => $processingBusinessId,
                'error' => $exception->getMessage(),
            ]);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        config(['team4.business_id' => $defaultBusinessId]);
        $this->info(sprintf(
            'Alertas procesadas. Creadas: %d; actualizadas: %d; resueltas: %d; notificadas: %d.',
            $total['created'],
            $total['updated'],
            $total['resolved'],
            $total['notifications']
        ));
        return self::SUCCESS;
    }
}
