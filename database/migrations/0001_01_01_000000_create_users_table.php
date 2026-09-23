<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * La conexion por defecto de la app es "mongodb"; esta migracion crea
 * las colecciones base de autenticacion.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('users', function (Blueprint $collection) {
            $collection->unique('email');
        });

        Schema::connection('mongodb')->create('password_reset_tokens', function (Blueprint $collection) {
            $collection->unique('email');
        });

        Schema::connection('mongodb')->create('sessions', function (Blueprint $collection) {
            $collection->index('user_id');
            $collection->index('last_activity');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('users');
        Schema::connection('mongodb')->dropIfExists('password_reset_tokens');
        Schema::connection('mongodb')->dropIfExists('sessions');
    }
};