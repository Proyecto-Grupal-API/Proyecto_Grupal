<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        if (! Schema::hasTable('permisos_gestion_organizaciones')) {
            Schema::create('permisos_gestion_organizaciones');
        }
        Schema::table('permisos_gestion_organizaciones', fn (Blueprint $c) => $c->unique('usuario_id', 'gestor_usuario'));
        Schema::table('organizaciones', function (Blueprint $c) {
            $c->unique('clave_alta', 'organizacion_alta', options: ['partialFilterExpression' => ['clave_alta' => ['$type' => 'string']]]);
            $c->index(['estado', 'nombre'], 'organizaciones_estado_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('organizaciones', function (Blueprint $c) {
            $c->dropIndex('organizacion_alta');
            $c->dropIndex('organizaciones_estado_nombre');
        });
        Schema::dropIfExists('permisos_gestion_organizaciones');
    }
};
