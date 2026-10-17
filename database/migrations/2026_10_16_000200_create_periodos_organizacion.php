<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('periodos_organizacion', function (Blueprint $c) {
            $c->unique(['organizacion_id', 'clave_solicitud']);
            $c->unique(['organizacion_id', 'nombre_clave'], options: ['partialFilterExpression' => ['estado' => 'registrado']]);
            $c->index(['organizacion_id', 'estado', 'fecha_inicio', 'fecha_fin']);
        });
        Schema::table('asignaciones_roles_organizacion', fn (Blueprint $c) => $c->index(['organizacion_id', 'periodo_id'], 'comunidad_cargos_por_periodo'));
        Schema::table('auditoria_comunidad', function (Blueprint $c) {
            $c->index(['organizacion_id', 'antes.periodo_id', 'creado_en'], 'comunidad_periodo_cargo_antes');
            $c->index(['organizacion_id', 'despues.periodo_id', 'creado_en'], 'comunidad_periodo_cargo_despues');
        });
    }

    public function down(): void
    {
        Schema::table('auditoria_comunidad', function (Blueprint $c) {
            $c->dropIndex('comunidad_periodo_cargo_antes');
            $c->dropIndex('comunidad_periodo_cargo_despues');
        });
        Schema::table('asignaciones_roles_organizacion', fn (Blueprint $c) => $c->dropIndex('comunidad_cargos_por_periodo'));
        Schema::dropIfExists('periodos_organizacion');
    }
};
