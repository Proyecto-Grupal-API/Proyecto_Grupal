<?php

namespace Database\Seeders;

use App\Models\Campana;
use App\Models\Organizacion;
use Illuminate\Database\Seeder;

class ComunicacionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Las campañas de demostración solo se crean en local o testing.');
        }
        foreach (['sistemas', 'consejo'] as $slug) {
            $org = Organizacion::where('slug', $slug)->firstOrFail();
            Campana::firstOrCreate(['referencia_demo' => 'bienvenida-'.$slug], [
                'organizacion_id' => (string) $org->id, 'nombre' => 'Bienvenida — demostración', 'asunto' => 'Participa en las actividades de tu organización',
                'cuerpo' => 'Consulta los eventos disponibles y revisa las convocatorias de apoyo. Este borrador permite probar la vista previa antes de enviar a la bandeja interna.',
                'audiencia' => 'miembros', 'referencia_id' => null, 'accion' => 'eventos', 'texto_accion' => 'Explorar eventos', 'estado' => 'borrador', 'revision' => 1,
            ]);
        }
    }
}
