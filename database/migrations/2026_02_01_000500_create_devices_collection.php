<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Modulo 1.7 - Dispositivos y sesiones confiables.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('devices', function (Blueprint $collection) {
            $collection->index('user_id');
            $collection->index(['user_id', 'fingerprint']);
            $collection->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('devices');
    }
};
