<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Modulo 1.6 - Identidad QR.
 *
 * Indice para el codigo corto de respaldo (6 digitos) que el
 * estudiante puede dictar/teclear manualmente cuando no se puede
 * escanear el QR. No es unico a nivel de coleccion porque se puede
 * repetir entre tokens ya consumidos/expirados de distintos alumnos;
 * la unicidad efectiva (solo un token "usable" con ese short_code a
 * la vez) la garantiza la logica de generacion en IdentityService.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->table('qr_tokens', function (Blueprint $collection) {
            $collection->index('short_code');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->table('qr_tokens', function (Blueprint $collection) {
            $collection->dropIndex('short_code');
        });
    }
};
