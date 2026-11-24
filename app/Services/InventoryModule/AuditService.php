<?php

namespace App\Services\InventoryModule;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

class AuditService
{
    /** Operaciones que no pasan por un modelo observado (colección directa). */
    public function record(string $event, string $entityType, string $entityId, array $after = [], array $before = [], ?string $label = null): void
    {
        $this->log($event, [
            'actor_id' => ActorResolver::resolve(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_label' => $label,
            'action' => \Illuminate\Support\Str::afterLast($event, '.'),
            'before' => $before === [] ? null : $before,
            'after' => $after === [] ? null : $after,
        ]);
    }

    public function log(string $event, array $payload = []): void
    {
        try {
            DB::connection('inventory')->getCollection('audit_events')->insertOne([
                '_id'            => new ObjectId(),
                'event'          => $event,
                'actor_id'       => $payload['actor_id']       ?? null,
                'actor_name'     => $payload['actor_name']     ?? (app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->user()?->name),
                'entity_label'   => $payload['entity_label']   ?? null,
                'business_id'    => $payload['business_id']    ?? app(\App\Services\InventoryModule\BusinessContext::class)->id(),
                'entity_type'    => $payload['entity_type']    ?? null,
                'entity_id'      => $payload['entity_id']      ?? null,
                'action'         => $payload['action']         ?? null,
                'before'         => $payload['before']         ?? null,
                'after'          => $payload['after']          ?? null,
                'correlation_id' => $payload['correlation_id'] ?? (string) Str::uuid(),
                'ip'             => $payload['ip']             ?? (app()->runningInConsole() ? null : request()->ip()),
                'created_at'     => new \MongoDB\BSON\UTCDateTime(now()),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditService::log failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
