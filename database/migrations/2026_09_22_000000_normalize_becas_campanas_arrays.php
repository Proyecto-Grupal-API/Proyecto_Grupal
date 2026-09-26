<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $campos = ['convocatorias_becas' => ['beneficio', 'requisitos_documentos'], 'solicitudes_becas' => ['documentos', 'dictamen'], 'asignaciones_beneficios' => ['contrato'], 'campañas' => ['destinatarios']];
        // Validar todo antes de escribir. El segundo recorrido limita memoria y hace la migración repetible.
        foreach ([false, true] as $escribir) {
            foreach ($campos as $tabla => $atributos) {
                $coleccion = DB::connection('mongodb')->getDatabase()->selectCollection($tabla);
                foreach ($atributos as $campo) {
                    foreach ($coleccion->find(['$expr' => ['$eq' => [['$type' => '$'.$campo], 'string']]]) as $doc) {
                        try {
                            $valor = json_decode($doc[$campo], true, 512, JSON_THROW_ON_ERROR);
                            if (! is_array($valor)) {
                                throw new RuntimeException('Se esperaba un arreglo u objeto.');
                            }
                        } catch (Throwable $e) {
                            throw new RuntimeException('Revisar JSON inválido en '.$tabla.'.'.$campo.' del documento '.$doc['_id'].'. No se descarta su contenido.', 0, $e);
                        }
                        if ($escribir) {
                            $coleccion->updateOne(['_id' => $doc['_id'], $campo => $doc[$campo]], ['$set' => [$campo => $valor]]);
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Transformación sin pérdida: no reintroducir cadenas JSON al revertir código.
    }
};
