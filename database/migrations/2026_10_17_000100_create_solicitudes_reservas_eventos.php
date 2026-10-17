<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('solicitudes_reservas_eventos', function (Blueprint $c) {
            $c->unique(['usuario_id', 'clave_solicitud']);
            $c->index(['evento_id', 'usuario_id', 'generacion_resultado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_reservas_eventos');
    }
};
