<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $indexes = [
        'marketplace_businesses' => [['slug' => 1]],
        'marketplace_storefronts' => [['business_id' => 1]],
        'marketplace_carts' => [['user_id' => 1]],
        'marketplace_orders' => [['buyer_id' => 1, 'idempotency_key' => 1]],
        'marketplace_payment_intents' => [['order_id' => 1]],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $collection => $indexes) {
            foreach ($indexes as $keys) {
                DB::connection('marketplace')->getCollection($collection)->createIndex($keys, ['unique' => true, 'name' => 'marketplace_unique']);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $collection => $indexes) {
            DB::connection('marketplace')->getCollection($collection)->dropIndex('marketplace_unique');
        }
    }
};
