<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->partialUnique('analytics_events', 'event_id', 'event_id_1');
        $this->partialUnique('analytics_events', 'idempotency_key');
        $this->partialUnique('webhook_events', 'event_id', 'webhook_id_1');
        $this->partialUnique('audit_logs', 'event_id');
    }

    public function down(): void
    {
        // Los índices parciales no estorban a versiones anteriores; no se revierten.
    }

    private function partialUnique(string $collection, string $field, ?string $drop = null): void
    {
        $col = DB::connection('mongodb')->getCollection($collection);

        if ($drop) {
            try { $col->dropIndex($drop); } catch (\Throwable) {}
        }

        $col->createIndex([$field => 1], [
            'unique' => true,
            'partialFilterExpression' => [$field => ['$type' => 'string']],
        ]);
    }
};