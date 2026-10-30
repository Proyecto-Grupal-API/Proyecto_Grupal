<?php

namespace App\Services;

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
            DB::connection('mongodb')->getCollection('integration_outbox')->insertOne([
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

    /**
     * Despacha los eventos pendientes al Equipo 7.
     */
    public function dispatchPending(): int
    {
        $coll = DB::connection('mongodb')->getCollection('integration_outbox');
        $pending = $coll->find(['status' => 'PENDING'])->limit(50);
        $count = 0;

        foreach ($pending as $doc) {
            $d = (array) $doc;
            $topic = $d['topic'] ?? '';
            $payload = $d['payload'] ?? [];
            $id = $d['_id'];

            $ok = $this->dispatcher->dispatch($topic, $payload);

            if ($ok) {
                $coll->updateOne(
                    ['_id' => $id],
                    ['$set' => ['status' => 'DISPATCHED', 'delivered_at' => new \MongoDB\BSON\UTCDateTime(now())]]
                );
                $count++;
            } else {
                $coll->updateOne(
                    ['_id' => $id],
                    ['$inc' => ['attempts' => 1], '$set' => ['last_error' => 'Fallo en dispatch']]
                );
            }
        }
        return $count;
    }

}