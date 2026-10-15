<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void
    {
        $db=DB::connection('inventory');
        foreach ([
            ['inventory_businesses', ['business_id' => 1]],
            ['products', ['business_id' => 1, 'sku' => 1]],
            ['warehouses', ['business_id' => 1, 'code' => 1]],
            ['locations', ['business_id' => 1, 'warehouse_id' => 1, 'code' => 1]],
            ['categories', ['business_id' => 1, 'slug' => 1]],
            ['suppliers', ['business_id' => 1, 'code' => 1]],
            ['inventories', ['business_id' => 1, 'product_id' => 1, 'variant_id' => 1, 'location_id' => 1]],
        ] as [$name,$keys]) { $db->getCollection($name)->createIndex($keys, ['unique' => true]); }
        $db->getCollection('stock_movements')->createIndex(['business_id' => 1, 'created_at' => -1]);
        $db->getCollection('team3_api_reservations')->createIndex(['business_id' => 1, 'status' => 1, 'expires_at' => 1]);
    }
    public function down(): void {}
};
