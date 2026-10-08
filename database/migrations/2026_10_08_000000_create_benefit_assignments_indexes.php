<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de benefit_assignments (API de beneficios del Equipo 5).
 * El índice único por cliente + clave de idempotencia es lo que impide
 * crear dos asignaciones con la misma clave, aun con reintentos
 * simultáneos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'mongodb') {
            return;
        }

        $collection = DB::connection('mongodb')->getCollection('benefit_assignments');

        $collection->createIndex(['client_id' => 1, 'idempotency_key' => 1], ['unique' => true, 'name' => 'client_idempotency_unique']);
        $collection->createIndex(['client_id' => 1, 'application_id' => 1], ['name' => 'client_application']);
        $collection->createIndex(['student_id' => 1, 'benefit' => 1, 'status' => 1], ['name' => 'student_benefit_status']);
    }

    public function down(): void
    {
        if (config('database.default') !== 'mongodb') {
            return;
        }

        $collection = DB::connection('mongodb')->getCollection('benefit_assignments');

        foreach (['client_idempotency_unique', 'client_application', 'student_benefit_status'] as $index) {
            $collection->dropIndex($index);
        }
    }
};
