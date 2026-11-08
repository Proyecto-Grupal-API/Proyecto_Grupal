<?php

namespace Tests\Feature\Equipo4;

use App\Notifications\StockAlertNotification;
use App\Services\AlertGeneratorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Tests\TestCase;

class StockAlertNotificationTest extends TestCase
{
    private string $businessId;
    private string $productId;
    private string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
        $suffix = bin2hex(random_bytes(6));
        $this->productId = 'inactive-stock-product-' . $suffix;
        $this->locationId = 'inactive-stock-location-' . $suffix;

        DB::connection('mongodb')->getCollection('products')->insertOne([
            '_id' => $this->productId,
            'business_id' => $this->businessId,
            'sku' => 'INACTIVE-' . $suffix,
            'name' => 'Producto sin movimiento de prueba',
            'active' => true,
            'created_at' => new UTCDateTime((time() - 120 * 86400) * 1000),
        ]);
        DB::connection('mongodb')->getCollection('inventories')->insertOne([
            '_id' => new ObjectId(),
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'on_hand' => 3,
            'available' => 3,
            'reserved' => 0,
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('mongodb')->getCollection('stock_alerts')->deleteMany([
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
        ]);
        DB::connection('mongodb')->getCollection('inventories')->deleteMany([
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
        ]);
        DB::connection('mongodb')->getCollection('products')->deleteOne([
            '_id' => $this->productId,
        ]);
        parent::tearDown();
    }

    public function test_inactive_product_generates_alert_and_notifies_responsible(): void
    {
        Notification::fake();
        config(['team4.alert_recipient_email' => 'inventory@example.test']);

        $stats = app(AlertGeneratorService::class)->generate();

        $alert = DB::connection('mongodb')->getCollection('stock_alerts')->findOne([
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
            'type' => 'NO_MOVEMENT',
            'status' => 'ACTIVE',
        ]);

        $this->assertNotNull($alert);
        $this->assertGreaterThanOrEqual(1, (int) $stats['created']);
        Notification::assertSentOnDemand(StockAlertNotification::class);
    }
}
