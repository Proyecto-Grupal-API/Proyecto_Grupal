<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        if (! Schema::hasTable('preferencias_comunicacion')) {
            Schema::create('preferencias_comunicacion');
        }
        Schema::table('preferencias_comunicacion', fn (Blueprint $c) => $c->unique(['usuario_id', 'organizacion_id'], 'preferencia_destinatario'));
        Schema::table('mensajes', function (Blueprint $c) {
            $c->unique(['campaña_id', 'usuario_id'], 'entrega_campana_unica', options: ['partialFilterExpression' => ['campaña_id' => ['$type' => 'string']]]);
            $c->index(['usuario_id', 'archivado_en', 'creado_en'], 'bandeja_usuario');
        });
        Schema::table('campañas', fn (Blueprint $c) => $c->index(['organizacion_id', 'estado', 'creado_en'], 'campana_historial'));
        if (! Schema::hasTable('jobs_comunidad')) {
            Schema::create('jobs_comunidad');
        }
        Schema::table('jobs_comunidad', fn (Blueprint $c) => $c->index(['queue', 'reserved_at'], 'cola_comunidad'));
    }

    public function down(): void
    {
        Schema::table('preferencias_comunicacion', fn (Blueprint $c) => $c->dropIndex('preferencia_destinatario'));
        Schema::table('mensajes', function (Blueprint $c) {
            $c->dropIndex('entrega_campana_unica');
            $c->dropIndex('bandeja_usuario');
        });
        Schema::table('campañas', fn (Blueprint $c) => $c->dropIndex('campana_historial'));
        Schema::table('jobs_comunidad', fn (Blueprint $c) => $c->dropIndex('cola_comunidad'));
    }
};
