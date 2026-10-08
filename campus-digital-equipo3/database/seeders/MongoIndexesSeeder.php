<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MongoIndexesSeeder extends Seeder
{
    public function run(): void
    {
        $indexes = [
            'users' => [['email' => 1]],
            'businesses' => [['slug' => 1], ['status' => 1, 'visibility' => 1], ['owner_id' => 1]],
            'business_members' => [['business_id' => 1, 'user_id' => 1]],
            'business_applications' => [['status' => 1], ['applicant_id' => 1]],
            'storefronts' => [['business_id' => 1]],
            'products' => [['business_id' => 1], ['active' => 1, 'available' => 1], ['category' => 1], ['slug' => 1]],
            'carts' => [['user_id' => 1]],
            'orders' => [['folio' => 1], ['buyer_id' => 1, 'created_at' => -1], ['business_id' => 1, 'status' => 1], ['idempotency_key' => 1]],
            'payment_intents' => [['order_id' => 1], ['idempotency_key' => 1], ['reference' => 1]],
            'return_requests' => [['order_id' => 1], ['status' => 1]],
        ];

        foreach ($indexes as $collection => $collectionIndexes) {
            foreach ($collectionIndexes as $index) {
                try {
                    DB::connection('mongodb')->getCollection($collection)->createIndex($index);
                } catch (\Throwable $e) {
                    // Indexes are best-effort during local bootstrap.
                }
            }
        }
    }
}
