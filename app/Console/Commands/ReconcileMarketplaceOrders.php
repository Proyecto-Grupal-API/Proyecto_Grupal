<?php

namespace App\Console\Commands;

use App\Models\Marketplace\Order;
use App\Services\Marketplace\MarketplaceOrders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileMarketplaceOrders extends Command
{
    protected $signature = 'marketplace:reconciliar';

    protected $description = 'Revisa reservas pendientes y libera pedidos vencidos o cancelados, sin realizar cobros.';

    public function handle(MarketplaceOrders $flow): int
    {
        $orders = Order::where('payment_status', 'pending')->where(function ($q) {
            $q->whereIn('inventory_state', ['new', 'unknown', 'release_pending'])
                ->orWhere(function ($q) {
                    $q->whereIn('status', ['awaiting_payment', 'pending_inventory'])->where('hold_expires_at', '<=', now());
                })
                ->orWhere(function ($q) {
                    $q->whereIn('status', ['cancelled', 'expired'])->where('inventory_state', 'reserved');
                });
        })->orderBy('updated_at')->limit(100)->get();
        foreach ($orders as $order) {
            try {
                $flow->reconcile($order);
            } catch (\Throwable $e) {
                Log::warning('marketplace.reconciliation.deferred', ['order_id' => (string) $order->getKey(), 'exception' => get_class($e)]);
            }
        }
        $this->info('Pedidos revisados: '.$orders->count());

        return self::SUCCESS;
    }
}
