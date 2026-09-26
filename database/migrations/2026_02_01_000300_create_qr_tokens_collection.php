<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Modulo 1.6 - Identidad QR.
 */
return new class extends Migration
{
    /**
     * La conexion por defecto de la app es "sqlite"; esta migracion
     * debe correr explicitamente contra la conexion "mongodb".
     */
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('qr_tokens', function (Blueprint $collection) {
            $collection->unique('code');
            $collection->index('user_id');
            $collection->index(['user_id', 'type']);
            $collection->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('qr_tokens');
    }
};
