<?php

namespace App\Services\InventoryModule;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

class AuditService
{
    public function log(string $event, array $payload = []): void
    {
        try {
            DB::connection('inventory')->getCollection('audit_events')->insertOne([
                '_id'            => new ObjectId(),
                'event'          => $event,
                'actor_id'       => $payload['actor_id']       ?? null,
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
