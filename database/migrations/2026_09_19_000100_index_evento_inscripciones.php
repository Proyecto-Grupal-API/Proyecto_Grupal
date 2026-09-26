<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::table('eventos', fn (Blueprint $c) => $c->index(['estado', 'fecha_hora_inicio'], 'eventos_catalogo'));
        Schema::table('registros_eventos', function (Blueprint $c) {
            $c->index(['evento_id', 'estado', 'registrado_en'], 'eventos_cola');
            $c->index(['usuario_id', 'registrado_en'], 'eventos_boletos');
        });
    }

    public function down(): void
    {
        Schema::table('eventos', fn (Blueprint $c) => $c->dropIndex('eventos_catalogo'));
        Schema::table('registros_eventos', function (Blueprint $c) {
            $c->dropIndex('eventos_cola');
            $c->dropIndex('eventos_boletos');
        });
    }
};
