<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('recomendaciones_becas', function (Blueprint $c) {
            $c->unique(['solicitud_id', 'version_comite', 'usuario_id']);
            $c->index(['convocatoria_id', 'version_comite']);
        });
        Schema::table('convocatorias_becas', function (Blueprint $c) {
            $c->index(['organizacion_id', 'comite.responsable_id']);
            $c->index(['organizacion_id', 'comite.revisor_ids']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recomendaciones_becas');
        Schema::table('convocatorias_becas', function (Blueprint $c) {
            $c->dropIndex(['organizacion_id', 'comite.responsable_id']);
            $c->dropIndex(['organizacion_id', 'comite.revisor_ids']);
        });
    }
};
