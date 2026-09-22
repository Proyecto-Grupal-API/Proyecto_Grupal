<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';
    protected $description = 'Libera reservas vencidas (status=RESERVED con expires_at < ahora).';

    public function handle(ReservationService $service): int
    {
        $count = $service->expireOverdue();
        $this->info("Reservas vencidas liberadas: {$count}");
        return self::SUCCESS;
    }
}
