<?php

namespace App\Observers;

use App\Models\Marketplace\Order;
use App\Services\Marketplace\MarketplaceRewardsEvents;

/** Avisa a Recompensas de las ventas confirmadas y pagadas y de las devoluciones aprobadas (diseño §15). */
final class MarketplaceOrderRewardsObserver
{
    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }
        $events = app(MarketplaceRewardsEvents::class);
        if ($order->status === 'confirmed' && $order->payment_status === 'paid') {
            $events->queue($order, MarketplaceRewardsEvents::CONFIRMED);
        } elseif ($order->status === 'return_approved') {
            $events->queue($order, MarketplaceRewardsEvents::REFUNDED);
        }
    }
}
