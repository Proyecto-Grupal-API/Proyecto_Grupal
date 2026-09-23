<?php

namespace Database\Seeders;

use App\Models\Eleccion;
use App\Models\Encuesta;
use App\Models\Organizacion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ParticipacionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Las consultas de demostración solo se crean en local o testing.');
        }
        foreach (['sistemas', 'consejo'] as $slug) {
            $org = Organizacion::where('slug', $slug)->firstOrFail();
            foreach ([Encuesta::class, Eleccion::class] as $clase) {
                $encuesta = $clase === Encuesta::class;
                $preguntas = [['id' => (string) Str::uuid(), 'titulo' => $encuesta ? '¿Qué actividad te interesa más?' : '¿Qué propuesta prefieres?', 'opciones' => array_map(fn ($texto) => ['id' => (string) Str::uuid(), 'texto' => $texto], $encuesta ? ['Taller de programación', 'Conferencia de tecnología', 'Convivencia estudiantil'] : ['Propuesta A: tutorías entre estudiantes', 'Propuesta B: feria de proyectos'])]];
                if ($encuesta) {
                    $preguntas[] = ['id' => (string) Str::uuid(), 'titulo' => '¿Qué horario prefieres?', 'opciones' => array_map(fn ($texto) => ['id' => (string) Str::uuid(), 'texto' => $texto], ['Por la mañana', 'Por la tarde'])];
                }
                $clase::firstOrCreate(['referencia_demo' => ($encuesta ? 'actividades-' : 'propuestas-').$slug], ['organizacion_id' => (string) $org->id, 'titulo' => $encuesta ? 'Actividades de nuestra comunidad' : 'Propuestas para el próximo periodo', 'descripcion' => 'Borrador académico para revisar opciones, publicar y probar la participación de los integrantes.', 'fecha_inicio' => now()->startOfMinute(), 'fecha_fin' => now()->addWeek()->startOfMinute(), 'preguntas' => $preguntas, 'estado' => 'borrador', 'revision' => 1]);
            }
        }
    }
}
