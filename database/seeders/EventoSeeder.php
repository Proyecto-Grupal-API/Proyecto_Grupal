<?php

namespace Database\Seeders;

use App\Models\Evento;
use App\Models\Organizacion;
use App\Models\User;
use Illuminate\Database\Seeder;

class EventoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Los eventos de demostración solo se crean en local o testing.');
        }
        $org = Organizacion::where('slug', 'sistemas')->firstOrFail();
        $user = User::where('email', 'presidencia@campus.test')->firstOrFail();
        foreach ([['taller-mongodb-demo', 'Taller de MongoDB', 0], ['encuentro-comunidad-demo', 'Encuentro de Comunidad', 75]] as [$slug, $titulo, $costo]) {
            Evento::firstOrCreate(['slug' => $slug], [
                'organizacion_id' => (string) $org->id, 'creado_por' => (string) $user->id,
                'titulo' => $titulo, 'descripcion' => 'Evento de demostración para probar reservas y lista de espera.',
                'ubicacion' => 'Laboratorio de Sistemas', 'estado' => 'publicado', 'capacidad' => 2,
                'costo' => $costo, 'lista_espera' => true,
                'fecha_inicio_registro' => now()->startOfMinute()->subDay(),
                'fecha_fin_registro' => now()->startOfMinute()->addDays(2),
                'fecha_hora_inicio' => now()->startOfMinute()->addDays(2),
                'fecha_hora_fin' => now()->startOfMinute()->addDays(2)->addHours(2),
            ]);
        }
    }
}
