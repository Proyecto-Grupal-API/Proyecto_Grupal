<?php

namespace App\Console\Commands;

use App\Models\PagoReservaEvento;
use App\Services\PagosEvento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConciliarPagosEventos extends Command
{
    protected $signature = 'comunidad:conciliar-pagos-eventos';

    protected $description = 'Recupera resultados de pago y cierre de reservas de Comunidad.';

    public function handle(PagosEvento $pagos): int
    {
        if (config('comunidad.eventos_pagos.enabled') !== true) {
            return self::SUCCESS;
        }

        $pendientes = PagoReservaEvento::where('estado', 'pendiente')->orderBy('revisado_en')->orderBy('_id')->limit(20)->get();
        foreach ($pendientes as $pago) {
            $pago->update(['revisado_en' => now()]);
            try {
                $pagos->recuperar($pago);
            } catch (Throwable $e) {
                Log::warning('comunidad.pago_evento_pendiente', ['pago_id' => (string) $pago->id, 'error' => $e::class]);
            }
        }
        $this->info('Intentos de pago revisados: '.$pendientes->count());

        return self::SUCCESS;
    }
}
