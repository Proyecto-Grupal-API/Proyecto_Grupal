<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Connection;

/**
 * Salida de eventos del Equipo 5 hacia el Equipo 7 (service_platform_events).
 * El event_id único impide registrar dos veces el mismo evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'mongodb') {
            return;
        }

        $database = $this->mongo();

        $events = $database->getCollection('service_platform_events');
        $events->createIndex(['event_id' => 1], ['unique' => true, 'name' => 'event_id_unique']);
        $events->createIndex(['status' => 1, 'next_attempt_at' => 1], ['name' => 'status_next_attempt']);
        $events->createIndex(['event_name' => 1, 'occurred_at' => 1], ['name' => 'event_name_occurred']);

    }

    public function down(): void
    {
        if (config('database.default') !== 'mongodb') {
            return;
        }

        $database = $this->mongo();

        foreach (['event_id_unique', 'status_next_attempt', 'event_name_occurred'] as $index) {
            $database->getCollection('service_platform_events')->dropIndex($index);
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
