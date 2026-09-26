<?php

namespace Database\Seeders;

use App\Models\Organizacion;
use App\Models\ReporteTransparencia;
use App\Services\MetricasComunidad;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class ReporteSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Los reportes de demostración solo se crean en local o testing.');
        }
        foreach (['sistemas', 'consejo'] as $slug) {
            $org = Organizacion::where('slug', $slug)->firstOrFail();
            $clave = ['organizacion_id' => (string) $org->id, 'clave_solicitud' => '00000000-0000-4000-8000-000000000006'];
            if (ReporteTransparencia::where($clave)->exists()) {
                continue;
            }
            $corte = CarbonImmutable::now();
            $local = $corte->timezone('America/Mexico_City');
            $inicio = $local->startOfMonth()->toDateString();
            $fin = $local->toDateString();
            ReporteTransparencia::firstOrCreate($clave, ['organizacion_nombre' => $org->nombre, 'titulo' => 'Resumen de Comunidad — demostración', 'descripcion' => 'Borrador con cifras reales del mes actual, calculadas al crear esta demostración.', 'version' => 1, 'estado' => 'borrador', 'fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'generado_en' => $corte, 'metricas' => app(MetricasComunidad::class)->calcular((string) $org->id, $inicio, $fin, $corte)]);
        }
    }
}
