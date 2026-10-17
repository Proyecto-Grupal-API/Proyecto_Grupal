<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $coll = DB::connection('inventory')->getCollection('payment_events');
        $coll->createIndex(['event_id' => 1], ['unique' => true, 'name' => 'inventory_payment_event_id_v2',
            'partialFilterExpression' => ['event_id' => ['$type' => 'string']]]);
        $coll->createIndex(['business_id' => 1, 'payment_intent_id' => 1, 'event_version' => 1],
            ['unique' => true, 'name' => 'inventory_payment_version_v2', 'partialFilterExpression' => ['event_version' => ['$type' => 'number']]]);
        $coll->createIndex(['business_id' => 1, 'payment_intent_id' => 1, 'refund_id' => 1],
            ['unique' => true, 'name' => 'inventory_refund_identity_v2', 'partialFilterExpression' => ['refund_id' => ['$type' => 'string']]]);
        $coll->createIndex(['return_status' => 1, 'next_attempt_at' => 1], ['name' => 'inventory_return_pending_v2']);
    }

    public function down(): void
    {
        $coll = DB::connection('inventory')->getCollection('payment_events');
        foreach (['inventory_payment_event_id_v2', 'inventory_payment_version_v2', 'inventory_refund_identity_v2', 'inventory_return_pending_v2'] as $name) {
            $coll->dropIndex($name);
        }
    }
};
