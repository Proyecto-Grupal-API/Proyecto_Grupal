<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('delegaciones_organizacion', function (Blueprint $collection) {
            $collection->unique(['organizacion_id', 'clave_idempotencia']);
            $collection->index(['organizacion_id', 'destinatario_id', 'revocada_en', 'fin']);
            $collection->index('cargo_origen_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegaciones_organizacion');
    }
};
