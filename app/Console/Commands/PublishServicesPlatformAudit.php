<?php

namespace App\Console\Commands;

use App\Services\StudentServices\Audit\PlatformAuditDelivery;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PublishServicesPlatformAudit extends Command
{
    protected $signature = 'servicios:publicar-auditoria {--limit=100}';

    protected $description = 'Publica en E7 las auditorías habilitadas del piloto de Servicios al Estudiante.';

    public function handle(PlatformAuditDelivery $delivery): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('El límite debe estar entre 1 y 100.');

            return self::FAILURE;
        }

        try {
            $result = $delivery->deliver($limit);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($result['enabled'] ? 'Entregados: '.$result['sent'].'; pendientes de reintento: '.$result['failed'].'.' : 'Envío de auditorías de Servicios deshabilitado.');

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
