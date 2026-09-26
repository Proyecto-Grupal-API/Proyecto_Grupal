<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::table('tipos_beneficio', fn (Blueprint $c) => $c->unique('slug', 'beneficios_slug', options: ['partialFilterExpression' => ['slug' => ['$type' => 'string']]]));
        Schema::table('solicitudes_becas', function (Blueprint $c) {
            $c->unique(['convocatoria_id', 'usuario_id'], 'beca_solicitud_unica');
            $c->index(['usuario_id', 'creado_en'], 'beca_solicitudes_usuario');
            $c->index(['convocatoria_id', 'estado'], 'beca_dictamen');
        });
        Schema::table('asignaciones_beneficios', fn (Blueprint $c) => $c->unique('solicitud_id', 'beca_asignacion_unica'));
        Schema::table('convocatorias_becas', fn (Blueprint $c) => $c->index(['estado', 'fecha_fin'], 'beca_catalogo'));
    }

    public function down(): void
    {
        Schema::table('tipos_beneficio', fn (Blueprint $c) => $c->dropIndex('beneficios_slug'));
        Schema::table('solicitudes_becas', function (Blueprint $c) {
            foreach (['beca_solicitud_unica', 'beca_solicitudes_usuario', 'beca_dictamen'] as $i) {
                $c->dropIndex($i);
            }
        });
        Schema::table('asignaciones_beneficios', fn (Blueprint $c) => $c->dropIndex('beca_asignacion_unica'));
        Schema::table('convocatorias_becas', fn (Blueprint $c) => $c->dropIndex('beca_catalogo'));
    }
};
