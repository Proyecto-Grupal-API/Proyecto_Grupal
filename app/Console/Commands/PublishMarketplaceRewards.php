<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceRewardsEvents;
use Illuminate\Console\Command;

class PublishMarketplaceRewards extends Command
{
    protected $signature = 'marketplace:publicar-recompensas {--limit=50}';

    protected $description = 'Entrega a Recompensas los avisos de ventas confirmadas y devoluciones aprobadas de Tienda';

    public function handle(MarketplaceRewardsEvents $events): int
    {
        $result = $events->deliver((int) $this->option('limit'));
        if (! $result['enabled']) {
            $this->line('Avisos de Tienda a Recompensas deshabilitados (MARKETPLACE_REWARDS_ENABLED).');

            return self::SUCCESS;
        }
        $this->line("Avisos entregados: {$result['sent']}; con error: {$result['failed']}.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
