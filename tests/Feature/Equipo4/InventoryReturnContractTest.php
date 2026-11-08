<?php

namespace Tests\Feature\Equipo4;

use App\Api\InventoryReturnController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use Tests\TestCase;

class InventoryReturnContractTest extends TestCase
{
    private string $businessId;
    private string $productId;
    private string $locationId;
    private string $orderId;
    private string $idempotencyKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
        $suffix = bin2hex(random_bytes(6));
        $this->productId = 'flat-return-product-' . $suffix;
        $this->locationId = 'flat-return-location-' . $suffix;
        $this->orderId = 'flat-return-order-' . $suffix;
        $this->idempotencyKey = 'flat-return-key-' . $suffix;
    }

    protected function tearDown(): void
    {
        DB::connection('mongodb')->getCollection('stock_movements')->deleteMany([
            'business_id' => $this->businessId,
            'external_reference' => $this->orderId,
        ]);
        DB::connection('mongodb')->getCollection('inventories')->deleteMany([
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
        ]);
        DB::connection('mongodb')->getCollection('integration_idempotency')->deleteMany([
            'service' => 'team4.returns',
            'idempotency_key' => $this->idempotencyKey,
        ]);
        parent::tearDown();
    }

    public function test_return_api_accepts_the_documented_flat_single_item_payload(): void
    {
        $response = app(InventoryReturnController::class)->receive(Request::create('/api/equipo4/returns', 'POST', [
            'order_id' => $this->orderId,
            'product_id' => $this->productId,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'quantity' => 1,
            'reason' => 'DEFECT',
            'idempotency_key' => $this->idempotencyKey,
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('RETURNED', $response->getData(true)['status']);
        $inventory = (array) DB::connection('mongodb')->getCollection('inventories')->findOne([
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
        ]);
        $this->assertSame(1, (int) $inventory['on_hand']);
        $this->assertSame(1, (int) $inventory['available']);
    }
}
