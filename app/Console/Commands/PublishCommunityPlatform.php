<?php

namespace App\Console\Commands;

use App\Services\CommunityPlatformDelivery;
use Illuminate\Console\Command;

final class PublishCommunityPlatform extends Command
{
    protected $signature = 'comunidad:publicar-plataforma {--limit=100}';

    protected $description = 'Entrega a E7 proyecciones mínimas de auditoría de Comunidad por API firmada.';

    public function handle(CommunityPlatformDelivery $delivery): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('El límite debe estar entre 1 y 100.');

            return self::FAILURE;
        }
        $result = $delivery->deliver($limit);
        $this->info($result['enabled'] ? 'Entregados: '.$result['sent'].'; pendientes de reintento: '.$result['failed'].'.' : 'Envío E7 deshabilitado.');

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
