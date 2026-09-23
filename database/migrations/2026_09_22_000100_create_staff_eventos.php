<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        if (! Schema::hasTable('staff_eventos')) {
            Schema::create('staff_eventos');
        }
        Schema::table('staff_eventos', function (Blueprint $c) {
            $c->unique(['evento_id', 'usuario_id'], 'staff_evento_usuario');
            $c->index(['organizacion_id', 'usuario_id', 'eliminado_en'], 'staff_eventos_usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_eventos');
    }
};
