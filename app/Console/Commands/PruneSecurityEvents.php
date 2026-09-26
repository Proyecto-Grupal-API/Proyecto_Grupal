<?php

namespace App\Console\Commands;

use App\Models\SecurityEvent;
use Illuminate\Console\Command;

/**
 * Modulo 1.7 - Retencion de la bitacora de seguridad.
 *
 * security_events es una coleccion de solo insercion (append-only) y
 * crecera indefinidamente si nadie la limpia. Este comando aplica una
 * politica de retencion simple: borra eventos mas antiguos que N dias
 * (configurable via SECURITY_EVENTS_RETENTION_DAYS, por defecto 90).
 *
 * Se agenda a diario en routes/console.php.
 */
class PruneSecurityEvents extends Command
{
    protected $signature = 'security-events:prune {--days= : Dias de retencion a aplicar, sobreescribe el .env}';

    protected $description = 'Elimina la bitacora de seguridad (security_events) mas antigua que el periodo de retencion configurado';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? env('SECURITY_EVENTS_RETENTION_DAYS', 90));

        if ($days <= 0) {
            $this->warn('Retencion invalida (<= 0 dias); no se elimino nada.');

            return self::INVALID;
        }

        $cutoff = now()->subDays($days);

        $deleted = SecurityEvent::where('occurred_at', '<', $cutoff)->delete();

        $this->info("Eliminados {$deleted} eventos de seguridad anteriores a {$cutoff->toDateString()} ({$days} dias de retencion).");

        return self::SUCCESS;
    }
}
