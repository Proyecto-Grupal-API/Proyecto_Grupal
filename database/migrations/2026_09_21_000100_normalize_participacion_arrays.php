<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        // Convierte solo documentos del formato nuevo; conserva los registros históricos.
        foreach (['encuestas' => ['revision', ['preguntas', 'padron']], 'elecciones' => ['revision', ['preguntas', 'padron']], 'respuestas_encuestas' => ['consulta_id', ['respuestas']], 'votos' => ['consulta_id', ['respuestas']]] as $tabla => [$marca, $campos]) {
            foreach (DB::connection('mongodb')->table($tabla)->whereNotNull($marca)->get() as $documento) {
                foreach ($campos as $campo) {
                    $valor = $documento->{$campo} ?? null;
                    if (! is_string($valor)) {
                        continue;
                    }
                    $array = json_decode($valor, true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($array) || ! array_is_list($array)) {
                        throw new RuntimeException('Se esperaba una lista JSON en '.$tabla.'.'.$campo);
                    }
                    DB::connection('mongodb')->table($tabla)->where('_id', $documento->id)->where($campo, $valor)->update([$campo => $array]);
                }
            }
        }
    }

    public function down(): void
    {
        // No volver a serializar arreglos nativos: no se elimina información.
    }
};
