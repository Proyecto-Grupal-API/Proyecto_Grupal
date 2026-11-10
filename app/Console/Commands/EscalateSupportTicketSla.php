<?php

namespace App\Console\Commands;

use App\Services\StudentServices\Support\SupportTicketService;
use Illuminate\Console\Command;

/**
 * Escala los tickets de soporte cuyo SLA ya venció (módulo 5.9).
 * El scheduler lo ejecuta cada cinco minutos.
 */
class EscalateSupportTicketSla extends Command
{
    protected $signature = 'servicios:revisar-sla';

    protected $description = 'Escala los tickets de soporte con el SLA vencido (sube un nivel de prioridad y lo deja en su historial).';

    public function handle(SupportTicketService $tickets): int
    {
        $this->info('Tickets escalados: '.$tickets->escalateOverdue().'.');

        return self::SUCCESS;
    }
}
