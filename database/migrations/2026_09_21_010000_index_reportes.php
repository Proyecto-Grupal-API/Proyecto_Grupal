<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::table('reportes_transparencia', function (Blueprint $c) {
            $c->unique(['organizacion_id', 'clave_solicitud'], options: ['partialFilterExpression' => ['clave_solicitud' => ['$type' => 'string']]]);
            $c->index(['organizacion_id', 'estado', 'generado_en']);
        });
    }

    public function down(): void
    {
        Schema::table('reportes_transparencia', function (Blueprint $c) {
            $c->dropIndex(['organizacion_id', 'clave_solicitud']);
            $c->dropIndex(['organizacion_id', 'estado', 'generado_en']);
        });
    }
};
