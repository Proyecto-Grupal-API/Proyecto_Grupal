<?php

namespace Database\Seeders;

use App\Models\TipoBeneficio;
use Illuminate\Database\Seeder;

class TipoBeneficioSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['dinero', 'Apoyo monetario', 2], ['bono', 'Bono restringido', 2], ['comidas', 'Comidas', 5], ['impresiones', 'Impresiones', 5], ['transporte', 'Transporte', 5], ['locker', 'Locker', 5]] as [$slug,$nombre,$equipo]) {
            TipoBeneficio::firstOrCreate(['slug' => $slug], ['nombre' => $nombre, 'equipo' => $equipo, 'es_monetario' => $equipo === 2, 'es_servicio' => $equipo === 5]);
        }
    }
}
