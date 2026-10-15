<?php

namespace App\Console\Commands\InventoryModule;

use App\Services\InventoryModule\OutboxService;
use App\Api\InventoryModule\Team3ApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class E7ExpireCommand extends Command
{
    protected $signature = 'e7:expire';
    protected $description = 'Expira reservas E7 vencidas y publica inventory.reservation.expired';

    public function handle(Team3ApiService $engine, OutboxService $outbox): int
    {
        $bid = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
        $coll = DB::connection('inventory')->getCollection('team3_api_reservations');

        $cursor = $coll->find([
            'business_id'       => $bid,
            'origin_system'     => 'E7',
            'status'            => 'RESERVED',
            'expires_at'        => ['$lt' => new \MongoDB\BSON\UTCDateTime()],
        ]);

        $count = 0;
        foreach ($cursor as $doc) {
            $d = (array) $doc;
            $rid = (string) ($d['reservation_id'] ?? '');
            if ($rid === '') continue;

            try {
                // Reutiliza el motor comun (E3) con reason EXPIRED para que
                // el mapeo de estado sea "expired" segun el adapter E7
                $engine->release($rid, [
                    'idempotency_key' => 'e7-expire-' . $rid,
                    'reason'          => 'EXPIRED',
                ]);

                $outbox->publish('inventory.reservation.expired', [
                    'reservation_id'   => $rid,
                    'origin_reference' => $d['origin_reference'] ?? ($d['external_reference'] ?? ''),
                    'origin_type'      => $d['origin_type'] ?? 'REWARD',
                    'expired_at'       => now()->toIso8601String(),
                ]);

                $count++;
                $this->line("  EXPIRED: $rid");
            } catch (\Throwable $e) {
                $this->error("  FALLO: $rid -> " . $e->getMessage());
            }
        }

        $this->info("Reservas E7 expiradas: $count");
        return self::SUCCESS;
    }
}