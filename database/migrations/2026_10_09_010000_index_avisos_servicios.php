<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';
    public function up(): void
    {
        Schema::table('mensajes', fn (Blueprint $c) => $c->unique(['cliente_id', 'clave_idempotencia'], 'aviso_cliente_clave',
            options: ['partialFilterExpression' => ['cliente_id' => ['$type' => 'string']]]));
        foreach (['preferencias_bandeja', 'retiros_avisos'] as $table) {
            if (! Schema::hasTable($table)) { Schema::create($table); }
        }
        Schema::table('preferencias_bandeja', fn (Blueprint $c) => $c->unique(['usuario_id', 'categoria'], 'preferencia_categoria'));
        Schema::table('retiros_avisos', fn (Blueprint $c) => $c->unique(['cliente_id', 'clave_idempotencia'], 'retiro_cliente_clave'));
    }
    public function down(): void
    {
        Schema::table('mensajes', fn (Blueprint $c) => $c->dropIndex('aviso_cliente_clave'));
        Schema::table('preferencias_bandeja', fn (Blueprint $c) => $c->dropIndex('preferencia_categoria'));
        Schema::table('retiros_avisos', fn (Blueprint $c) => $c->dropIndex('retiro_cliente_clave'));
    }
};
