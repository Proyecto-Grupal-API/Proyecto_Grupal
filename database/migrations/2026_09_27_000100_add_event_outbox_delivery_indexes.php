<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $collection = DB::connection('mongodb')->getCollection('event_outboxes');

        $collection->createIndex(['event_id' => 1], [
            'name' => 'outbox_event_id_unique',
            'unique' => true,
        ]);
        $collection->createIndex(['published_at' => 1, 'next_attempt_at' => 1, 'claim_expires_at' => 1, 'occurred_at' => 1], [
            'name' => 'outbox_delivery_lookup',
        ]);
    }

    public function down(): void
    {
        $collection = DB::connection('mongodb')->getCollection('event_outboxes');
        $collection->dropIndex('outbox_delivery_lookup');
        $collection->dropIndex('outbox_event_id_unique');
    }
};
