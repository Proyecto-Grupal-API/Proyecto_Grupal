<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('documentos_organizacion', function (Blueprint $c) {
            $c->unique(['organizacion_id', 'clave_solicitud']);
            $c->index(['organizacion_id', 'estado', 'visibilidad', 'creado_en']);
        });
        Schema::create('versiones_documentos_organizacion', function (Blueprint $c) {
            $c->unique(['documento_id', 'numero']);
            $c->unique(['documento_id', 'clave_solicitud']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versiones_documentos_organizacion');
        Schema::dropIfExists('documentos_organizacion');
    }
};
