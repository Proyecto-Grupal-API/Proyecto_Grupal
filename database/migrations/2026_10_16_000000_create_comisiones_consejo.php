<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('comisiones_consejo', function (Blueprint $c) {
            $c->unique(['organizacion_id', 'clave_solicitud']);
            $c->unique(['organizacion_id', 'nombre_clave'], options: ['partialFilterExpression' => ['estado' => 'activa']]);
            $c->index(['organizacion_id', 'estado', 'creado_en']);
        });
        Schema::create('integrantes_comision', function (Blueprint $c) {
            $c->unique(['comision_id', 'clave_solicitud']);
            $c->unique(['comision_id', 'usuario_id'], options: ['partialFilterExpression' => ['retirado_en' => null]]);
            $c->unique('comision_id', name: 'coordinacion_unica', options: ['partialFilterExpression' => ['retirado_en' => null, 'funcion' => 'coordinacion']]);
            $c->index(['organizacion_id', 'usuario_id', 'retirado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrantes_comision');
        Schema::dropIfExists('comisiones_consejo');
    }
};
