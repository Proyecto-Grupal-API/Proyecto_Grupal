<?php

namespace App\Services\InventoryModule;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;

/**
 * Outbox simple: guarda eventos de integracion para entrega posterior.
 * NO incluye worker HTTP — solo persistencia. El reenvio puede hacerse
 * por consulta directa a la coleccion o por un job futuro.
 */
class OutboxService
{
    public function publish(string $topic, array $payload): string
    {
        $id = new ObjectId();
        try {
            DB::connection('inventory')->getCollection('integration_outbox')->insertOne([
                '_id'           => $id,
                'topic'         => $topic,
                'payload'       => $payload,
                'status'        => 'PENDING',
                'attempts'      => 0,
                'last_error'    => null,
                'created_at'    => new \MongoDB\BSON\UTCDateTime(now()),
                'updated_at'    => new \MongoDB\BSON\UTCDateTime(now()),
                'delivered_at'  => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('OutboxService::publish failed', ['topic' => $topic, 'error' => $e->getMessage()]);
        }
        return (string) $id;
    }
}