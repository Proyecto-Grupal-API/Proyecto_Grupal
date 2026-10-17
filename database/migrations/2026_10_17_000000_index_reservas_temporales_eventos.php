<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::table('registros_eventos', fn (Blueprint $c) => $c->index(['estado', 'estado_pago', 'reserva_hasta', 'evento_id'], 'eventos_reservas_vencimiento'));
    }

    public function down(): void
    {
        Schema::table('registros_eventos', fn (Blueprint $c) => $c->dropIndex('eventos_reservas_vencimiento'));
    }
};
