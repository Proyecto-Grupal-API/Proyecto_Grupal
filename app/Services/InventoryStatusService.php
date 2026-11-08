<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryStatusService
{
    public function sync(string $businessId, string $productId, ?string $variantId, string $locationId): void
    {
        DB::connection('mongodb')->getCollection('inventories')->updateOne(
            [
                'business_id' => $businessId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
            ],
            [[
                '$set' => [
                    'status' => [
                        '$cond' => [
                            ['$gt' => [['$ifNull' => ['$available', 0]], 0]],
                            'AVAILABLE',
                            'OUT_OF_STOCK',
                        ],
                    ],
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ],
            ]]
        );
    }
}
