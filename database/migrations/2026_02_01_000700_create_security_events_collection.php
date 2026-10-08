<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Modulo 1.7 - Alertas de acceso / bitacora de seguridad.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('security_events', function (Blueprint $collection) {
            $collection->index('user_id');
            $collection->index('occurred_at');
            $collection->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('security_events');
    }
};
