<?php

namespace App\Console\Commands;

use App\Api\Team3ApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;

class ExpireTeam3Reservations extends Command
{
    protected $signature = 'team3:expire-reservations';
    protected $description = 'Libera reservas vencidas de Marketplace sin afectar las reservas de recompensas.';

    public function handle(Team3ApiService $service): int
    {
        $collection = DB::connection('mongodb')->getCollection('team3_api_reservations');
        $reservations = $collection->find([
            'business_id' => (string) config('team4.business_id', 'BUS-CD-SOUV-001'),
            'origin_system' => ['$ne' => 'E7'],
            'status' => 'RESERVED',
            'expires_at' => ['$lte' => new UTCDateTime()],
        ]);

        $expired = 0;
        $failed = 0;

        foreach ($reservations as $document) {
            $reservation = (array) $document;
            $reservationId = (string) ($reservation['reservation_id'] ?? '');
            if ($reservationId === '') {
                $this->error('Se encontró una reserva vencida sin reservation_id.');
                $failed++;
                continue;
            }

            try {
                $service->release($reservationId, [
                    'idempotency_key' => 'team3-expire-' . $reservationId . '-' . Str::ulid(),
                    'reason' => 'EXPIRED',
                ]);
                $expired++;
                $this->line("  EXPIRED: {$reservationId}");
            } catch (\Throwable $exception) {
                Log::error('Fallo al expirar reserva de Marketplace.', [
                    'reservation_id' => $reservationId,
                    'error' => $exception->getMessage(),
                ]);
                $this->error("  FALLO: {$reservationId} -> {$exception->getMessage()}");
                $failed++;
            }
        }

        $this->info("Reservas de Marketplace vencidas liberadas: {$expired}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
