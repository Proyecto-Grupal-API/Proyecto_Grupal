<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Modulo 1.7 - Dispositivos y sesiones confiables.
 *
 * Coleccion "sessions": tal como documenta el README, en MongoDB este
 * proyecto crea "users" y "sessions" como colecciones (no como tabla
 * nativa de sesiones de Laravel, que aqui no se usa: SESSION_DRIVER=file).
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('sessions', function (Blueprint $collection) {
            $collection->index('user_id');
            $collection->index('device_id');
            $collection->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('sessions');
    }
};
