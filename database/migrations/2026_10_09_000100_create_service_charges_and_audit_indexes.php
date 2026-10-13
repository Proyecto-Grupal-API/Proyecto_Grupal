<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Connection;

/**
 * Índices de service_charges (movimientos de dinero del Equipo 5) y de
 * service_audit_logs (bitácora de operaciones sensibles). La llave única de
 * idempotencia impide registrar dos veces la misma retención o cobro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'mongodb') {
            return;
        }

        $database = $this->mongo();

        $charges = $database->getCollection('service_charges');
        $charges->createIndex(['idempotency_key' => 1], ['unique' => true, 'name' => 'idempotency_unique']);
        $charges->createIndex(['reference' => 1], ['unique' => true, 'name' => 'reference_unique']);
        $charges->createIndex(['hold_reference' => 1, 'operation' => 1], ['name' => 'hold_operation']);

        $audit = $database->getCollection('service_audit_logs');
        $audit->createIndex(['entity_type' => 1, 'entity_id' => 1], ['name' => 'entity']);
        $audit->createIndex(['occurred_at' => -1], ['name' => 'occurred_at']);
        $audit->createIndex(['published_at' => 1], ['name' => 'published_at']);
    }

    public function down(): void
    {
        if (config('database.default') !== 'mongodb') {
            return;
        }

        $database = $this->mongo();

        foreach (['idempotency_unique', 'reference_unique', 'hold_operation'] as $index) {
            $database->getCollection('service_charges')->dropIndex($index);
        }

        foreach (['entity', 'occurred_at', 'published_at'] as $index) {
            $database->getCollection('service_audit_logs')->dropIndex($index);
        }
    }

    private function mongo(): Connection
    {
        $connection = DB::connection('mongodb');

        if (! $connection instanceof Connection) {
            throw new RuntimeException('La conexión [mongodb] no usa el driver de MongoDB.');
        }

        return $connection;
    }
};
