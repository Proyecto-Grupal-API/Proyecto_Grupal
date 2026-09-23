<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        if (! Schema::hasTable('organizaciones')) {
            Schema::create('organizaciones');
        }
        Schema::table('organizaciones', function (Blueprint $collection) {
            $collection->unique('slug');
        });
        if (! Schema::hasTable('miembros_organizacion')) {
            Schema::create('miembros_organizacion');
        }
        Schema::table('miembros_organizacion', function (Blueprint $collection) {
            $collection->unique(['organizacion_id', 'usuario_id']);
            $collection->index(['usuario_id', 'estado']);
        });
        if (! Schema::hasTable('asignaciones_roles_organizacion')) {
            Schema::create('asignaciones_roles_organizacion');
        }
        Schema::table('asignaciones_roles_organizacion', function (Blueprint $collection) {
            $collection->unique(['organizacion_id', 'slug_rol'], options: ['partialFilterExpression' => ['eliminado_en' => null]]);
            $collection->index(['organizacion_id', 'usuario_id']);
        });
        if (! Schema::hasTable('auditoria_comunidad')) {
            Schema::create('auditoria_comunidad');
        }
        Schema::table('auditoria_comunidad', function (Blueprint $collection) {
            $collection->index(['organizacion_id', 'creado_en']);
        });
        if (! Schema::hasTable('registros_eventos')) {
            Schema::create('registros_eventos');
        }
        Schema::table('registros_eventos', function (Blueprint $collection) {
            $collection->unique(['evento_id', 'usuario_id']);
            $collection->unique('token_qr');
        });
        if (! Schema::hasTable('eventos')) {
            Schema::create('eventos');
        }
        Schema::table('eventos', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('convocatorias_becas')) {
            Schema::create('convocatorias_becas');
        }
        Schema::table('convocatorias_becas', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('solicitudes_becas')) {
            Schema::create('solicitudes_becas');
        }
        Schema::table('solicitudes_becas', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('asignaciones_beneficios')) {
            Schema::create('asignaciones_beneficios');
        }
        Schema::table('asignaciones_beneficios', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('tipos_beneficio')) {
            Schema::create('tipos_beneficio');
        }
        Schema::table('tipos_beneficio', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('campañas')) {
            Schema::create('campañas');
        }
        Schema::table('campañas', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('mensajes')) {
            Schema::create('mensajes');
        }
        Schema::table('mensajes', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('encuestas')) {
            Schema::create('encuestas');
        }
        Schema::table('encuestas', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('reportes_transparencia')) {
            Schema::create('reportes_transparencia');
        }
        Schema::table('reportes_transparencia', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('elecciones')) {
            Schema::create('elecciones');
        }
        Schema::table('elecciones', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('opciones_elecciones')) {
            Schema::create('opciones_elecciones');
        }
        Schema::table('opciones_elecciones', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
        if (! Schema::hasTable('votos')) {
            Schema::create('votos');
        }
        Schema::table('votos', function (Blueprint $collection) {
            $collection->index('organizacion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votos');
        Schema::dropIfExists('opciones_elecciones');
        Schema::dropIfExists('elecciones');
        Schema::dropIfExists('reportes_transparencia');
        Schema::dropIfExists('encuestas');
        Schema::dropIfExists('mensajes');
        Schema::dropIfExists('campañas');
        Schema::dropIfExists('tipos_beneficio');
        Schema::dropIfExists('asignaciones_beneficios');
        Schema::dropIfExists('solicitudes_becas');
        Schema::dropIfExists('convocatorias_becas');
        Schema::dropIfExists('eventos');
        Schema::dropIfExists('registros_eventos');
        Schema::dropIfExists('auditoria_comunidad');
        Schema::dropIfExists('asignaciones_roles_organizacion');
        Schema::dropIfExists('miembros_organizacion');
        Schema::dropIfExists('organizaciones');
    }
};
