<?php

namespace App\Console\Commands;

use App\Models\StudentServices\Platform\ServicePlatformEvent;
use App\Services\StudentServices\Platform\ServicePlatformDelivery;
use Illuminate\Console\Command;
use Throwable;

/**
 * Envía al Equipo 7 los eventos pendientes del Equipo 5 (la auditoría va
 * por servicios:publicar-auditoria). Lo ejecuta el scheduler cada minuto
 * cuando PLATFORM_SERVICES_ANALYTICS_ENABLED=true (independiente de
 * PLATFORM_SERVICES_DELIVERY_ENABLED, que solo controla la auditoría).
 */
class PublishServicesPlatform extends Command
{
    protected $signature = 'servicios:publicar-eventos {--limit=100 : Máximo de eventos en esta pasada}';

    protected $description = 'Entrega los eventos de Servicios al receptor del Equipo 7 (firma HMAC, reintentos con el mismo event_id).';

    public function handle(ServicePlatformDelivery $delivery): int
    {
        $limit = max(1, min(300, (int) $this->option('limit')));

        try {
            $result = $delivery->deliver($limit);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $result['enabled']) {
            $this->warn('Envío al Equipo 7 deshabilitado (PLATFORM_SERVICES_ANALYTICS_ENABLED=false). No se envió nada.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Entregados: %d. Por reintentar: %d. Rechazados: %d. Eventos pendientes en total: %d.',
            $result['sent'],
            $result['retry'],
            $result['rejected'],
            ServicePlatformEvent::query()->where('status', ServicePlatformEvent::PENDING)->count(),
        ));

        return self::SUCCESS;
    }
}
