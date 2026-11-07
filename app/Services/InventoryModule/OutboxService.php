<?php

namespace App\Services\InventoryModule;

use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Session;

/**
 * Outbox simple: guarda eventos de integracion para entrega posterior.
 * El comando team4:publish-outbox entrega eventos cuando exista un receptor E7 acordado.
 */
class OutboxService
{
    public function publish(string $topic, array $payload, ?Session $session = null): string
    {
        $id = new ObjectId();
        DB::connection('inventory')->getCollection('integration_outbox')->insertOne([
            '_id'           => $id,
            'topic'         => $topic,
            'business_id'   => app(BusinessContext::class)->id(),
            'payload'       => $payload,
            'status'        => 'PENDING',
            'attempts'      => 0,
            'last_error'    => null,
            'created_at'    => new \MongoDB\BSON\UTCDateTime(now()),
            'updated_at'    => new \MongoDB\BSON\UTCDateTime(now()),
            'delivered_at'  => null,
        ], $session === null ? [] : ['session' => $session]);

        return (string) $id;
    }
}
