<?php

namespace Database\Seeders;

use App\Models\ConvocatoriaBeca;
use App\Models\Organizacion;
use App\Models\TipoBeneficio;
use App\Models\User;
use Illuminate\Database\Seeder;

class BecaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Las becas de demostración solo se crean en local o testing.');
        }
        $this->call(TipoBeneficioSeeder::class);
        foreach ([['consejo', 'bono', 'Bono de alimentación — demostración', 'consejo@campus.test', 80000, 1, ['Constancia de estudios']], ['sistemas', 'impresiones', 'Apoyo de impresiones — demostración', 'presidencia@campus.test', 0, 100, []]] as [$slug,$tipoSlug,$titulo,$email,$monto,$cantidad,$docs]) {
            $org = Organizacion::where('slug', $slug)->firstOrFail();
            $tipo = TipoBeneficio::where('slug', $tipoSlug)->firstOrFail();
            $user = User::where('email', $email)->firstOrFail();
            $hoy = now()->timezone('America/Mexico_City')->startOfDay();
            ConvocatoriaBeca::firstOrCreate(['referencia_demo' => 'beca-'.$slug.'-'.$tipoSlug], [
                'organizacion_id' => (string) $org->id, 'creado_por' => (string) $user->id, 'titulo' => $titulo,
                'descripcion' => 'Convocatoria local para probar solicitudes y dictámenes. La entrega externa permanece pendiente.',
                'requisitos' => 'Ser estudiante y explicar el motivo de la solicitud. Usar exclusivamente documentos de prueba en esta demostración.',
                'tipo_beneficio_id' => (string) $tipo->id, 'beneficio' => ['slug' => $tipo->slug, 'nombre' => $tipo->nombre, 'equipo' => $tipo->equipo, 'es_monetario' => $tipo->es_monetario, 'es_servicio' => $tipo->es_servicio],
                'monto_centavos' => $monto, 'cantidad' => $cantidad, 'total_espacios' => 2, 'fecha_inicio' => $hoy->copy()->utc(), 'fecha_fin' => $hoy->copy()->addDays(8)->utc(),
                'vigencia_inicio' => $hoy->copy()->addDays(8)->utc(), 'vigencia_fin' => $hoy->copy()->addDays(39)->utc(), 'requisitos_documentos' => $docs, 'requiere_documentos' => count($docs) > 0, 'estado' => 'publicada',
            ]);
        }
    }
}
