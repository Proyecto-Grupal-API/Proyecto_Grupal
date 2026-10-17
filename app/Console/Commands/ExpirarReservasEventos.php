<?php

namespace App\Console\Commands;

use App\Models\RegistroEvento;
use App\Services\InscripcionesEvento;
use Illuminate\Console\Command;

class ExpirarReservasEventos extends Command
{
    protected $signature = 'comunidad:expirar-reservas-eventos';

    protected $description = 'Libera reservas pendientes vencidas y promueve la espera sin confirmar pagos.';

    public function handle(InscripcionesEvento $servicio): int
    {
        $revisados = 0;
        RegistroEvento::pendientesDeVencer()->chunkById(100, function ($registros) use ($servicio, &$revisados) {
            foreach ($registros->pluck('evento_id')->unique() as $id) {
                $servicio->ejecutar($id, fn ($evento) => $servicio->sincronizar($evento));
                $revisados++;
            }
        });
        $this->info('Eventos conciliados: '.$revisados.'. No se procesaron pagos.');

        return self::SUCCESS;
    }
}
