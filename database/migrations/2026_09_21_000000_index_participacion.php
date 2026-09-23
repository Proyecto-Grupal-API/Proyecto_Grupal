<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        foreach (['respuestas_encuestas', 'votos'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                Schema::create($tabla);
            }
            Schema::table($tabla, function (Blueprint $c) {
                $c->unique(['consulta_id', 'usuario_id'], options: ['partialFilterExpression' => ['consulta_id' => ['$type' => 'string']]]);
                $c->index(['organizacion_id', 'consulta_id']);
            });
        }
        foreach (['encuestas', 'elecciones'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $c) => $c->index(['organizacion_id', 'estado', 'fecha_fin']));
        }
    }

    public function down(): void
    {
        foreach (['respuestas_encuestas', 'votos'] as $tabla) {
            Schema::table($tabla, function (Blueprint $c) {
                $c->dropIndex(['consulta_id', 'usuario_id']);
                $c->dropIndex(['organizacion_id', 'consulta_id']);
            });
        }
        foreach (['encuestas', 'elecciones'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $c) => $c->dropIndex(['organizacion_id', 'estado', 'fecha_fin']));
        }
    }
};
