<?php

namespace Tests\Feature\Equipo4;

use App\Http\Controllers\StockCountController;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Tests\TestCase;

class StockCountApprovalTest extends TestCase
{
    private string $businessId;
    private ObjectId $countObjectId;
    private string $countId;
    private string $itemId;
    private ObjectId $inventoryObjectId;
    private string $productId;
    private string $locationId;
    private string $folio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
        $suffix = bin2hex(random_bytes(6));
        $this->countObjectId = new ObjectId();
        $this->countId = (string) $this->countObjectId;
        $this->itemId = (string) new ObjectId();
        $this->inventoryObjectId = new ObjectId();
        $this->productId = 'count-approval-product-' . $suffix;
        $this->locationId = 'count-approval-location-' . $suffix;
        $this->folio = 'COUNT-APPROVAL-' . $suffix;

        DB::connection('mongodb')->getCollection('stock_counts')->insertOne([
            '_id' => $this->countObjectId,
            'business_id' => $this->businessId,
            'folio' => $this->folio,
            'status' => 'DRAFT',
            'started_by' => 'USR-BUY-001',
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime(),
        ]);
        DB::connection('mongodb')->getCollection('stock_count_items')->insertOne([
            '_id' => new ObjectId($this->itemId),
            'business_id' => $this->businessId,
            'stock_count_id' => $this->countId,
            'product_id' => $this->productId,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'product_sku' => 'COUNT-TEST',
            'expected_qty' => 5,
            'counted_qty' => 7,
            'difference' => 2,
            'captured' => true,
        ]);
        DB::connection('mongodb')->getCollection('inventories')->insertOne([
            '_id' => $this->inventoryObjectId,
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'on_hand' => 5,
            'reserved' => 0,
            'available' => 5,
            'status' => 'AVAILABLE',
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('mongodb')->getCollection('stock_movements')->deleteMany([
            'business_id' => $this->businessId,
            'external_reference' => $this->folio,
        ]);
        DB::connection('mongodb')->getCollection('inventories')->deleteOne(['_id' => $this->inventoryObjectId]);
        DB::connection('mongodb')->getCollection('stock_count_items')->deleteOne(['_id' => new ObjectId($this->itemId)]);
        DB::connection('mongodb')->getCollection('stock_counts')->deleteOne(['_id' => $this->countObjectId]);
        DB::connection('mongodb')->getCollection('audit_events')->deleteMany(['entity_id' => $this->countId]);
        parent::tearDown();
    }

    public function test_count_differences_change_stock_only_after_independent_approval(): void
    {
        session()->put('team4_impersonate_user_id', 'USR-ADMIN-001');
        app(StockCountController::class)->close($this->countId);

        $count = (array) DB::connection('mongodb')->getCollection('stock_counts')->findOne([
            '_id' => $this->countObjectId,
        ]);
        $inventory = (array) DB::connection('mongodb')->getCollection('inventories')->findOne([
            '_id' => $this->inventoryObjectId,
        ]);
        $this->assertSame('PENDING_APPROVAL', $count['status']);
        $this->assertSame(5, (int) $inventory['on_hand']);

        session()->put('team4_impersonate_user_id', 'USR-INV-001');
        app(StockCountController::class)->approve($this->countId, app(InventoryService::class));

        $count = (array) DB::connection('mongodb')->getCollection('stock_counts')->findOne([
            '_id' => $this->countObjectId,
        ]);
        $inventory = (array) DB::connection('mongodb')->getCollection('inventories')->findOne([
            '_id' => $this->inventoryObjectId,
        ]);
        $this->assertSame('CLOSED', $count['status']);
        $this->assertSame('USR-INV-001', $count['approved_by']);
        $this->assertSame(7, (int) $inventory['on_hand']);
        $this->assertSame(7, (int) $inventory['available']);
    }
}
