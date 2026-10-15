<?php
namespace App\Console\Commands;

use App\Models\StudentServices\Notifications\NotificationOutbox;
use App\Services\StudentServices\Notifications\{NotificationPlanner, NotificationDelivery};
use Illuminate\Console\Command;

class DeliverServiceNotifications extends Command
{
    protected $signature = 'servicios:avisos {--solo-planificar}';
    protected $description = 'Planifica avisos de Servicios y entrega/retiro por la API HTTP de Comunidad con reintentos.';
    public function handle(NotificationPlanner $planner, NotificationDelivery $delivery): int
    {
        $count = $planner->scan();
        if (! $this->option('solo-planificar')) {
            foreach (NotificationOutbox::where('next_attempt_at', '<=', now())
                ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'delivered')->where('withdraw_pending', true)))
                ->orderBy('created_at')->limit(100)->get() as $entry) { $delivery->deliver($entry); }
        }
        $this->info('Registros revisados: '.$count.'. Pendientes: '.NotificationOutbox::where('status', 'pending')->count().'.');
        return self::SUCCESS;
    }
}
