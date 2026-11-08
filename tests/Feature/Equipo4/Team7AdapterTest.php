<?php

namespace Tests\Feature\Equipo4;

use App\Services\Team7AdapterService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Team7AdapterTest extends TestCase
{
    protected Team7AdapterService $adapter;
    protected string $sku = 'SOUV-TAZA-001';
    protected array $inventoryBefore = [];
    protected array $reservationIds = [];
    protected array $originReferences = [];
    protected array $idempotencyKeys = [];
    protected string $businessId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = app(Team7AdapterService::class);
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');

        $product = DB::connection('mongodb')->getCollection('products')->findOne([
            'business_id' => $this->businessId,
            'sku' => $this->sku,
        ]);
        $this->assertNotNull($product, "Ejecuta database/mongo/team4_seed.js para crear {$this->sku}.");

        $inventory = DB::connection('mongodb')->getCollection('inventories')->findOne([
            'business_id' => $this->businessId,
            'product_id' => (string) $product['_id'],
            'variant_id' => null,
        ], ['sort' => ['available' => -1]]);
        $this->assertNotNull($inventory, "El producto {$this->sku} necesita inventario para ejecutar estas pruebas.");
        $inventory = (array) $inventory;
        $this->inventoryBefore = [
            '_id' => $inventory['_id'],
            'on_hand' => (int) ($inventory['on_hand'] ?? 0),
            'reserved' => (int) ($inventory['reserved'] ?? 0),
            'available' => (int) ($inventory['available'] ?? 0),
            'status' => (string) ($inventory['status'] ?? 'OUT_OF_STOCK'),
        ];
    }

    protected function tearDown(): void
    {
        $db = DB::connection('mongodb');
        if ($this->inventoryBefore !== []) {
            $db->getCollection('inventories')->updateOne(
                ['_id' => $this->inventoryBefore['_id']],
                ['$set' => [
                    'on_hand' => $this->inventoryBefore['on_hand'],
                    'reserved' => $this->inventoryBefore['reserved'],
                    'available' => $this->inventoryBefore['available'],
                    'status' => $this->inventoryBefore['status'],
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]]
            );
        }

        if ($this->reservationIds !== []) {
            $db->getCollection('team3_api_reservations')->deleteMany([
                'reservation_id' => ['$in' => $this->reservationIds],
            ]);
        }
        if ($this->originReferences !== []) {
            $db->getCollection('stock_movements')->deleteMany([
                'external_reference' => ['$in' => $this->originReferences],
            ]);
        }
        foreach ($this->idempotencyKeys as [$service, $key]) {
            $db->getCollection('integration_idempotency')->deleteOne([
                'service' => $service,
                'idempotency_key' => $key,
            ]);
        }

        parent::tearDown();
    }

    /** @test */
    public function test_availability_por_sku_funciona(): void
    {
        $res = $this->adapter->availability([
            ['product_reference' => $this->sku, 'quantity' => 1],
        ]);
        $this->assertIsArray($res);
        $this->assertArrayHasKey('available', $res);
        $this->assertArrayHasKey('items', $res);
    }

    /** @test */
    public function test_reserve_y_confirm_sin_payment_intent(): void
    {
        $originReference = 'TEST-RWD-' . uniqid();
        $reserveKey = 'test-7-' . uniqid();
        $confirmKey = 'test-7-confirm-' . uniqid();
        $this->originReferences[] = $originReference;
        $this->idempotencyKeys[] = ['team3.reserve', $reserveKey];
        $this->idempotencyKeys[] = ['team3.confirm', $confirmKey];

        $reserve = $this->adapter->reserve([
            'origin_reference' => $originReference,
            'origin_type'      => 'REWARD',
            'items'            => [['product_reference' => $this->sku, 'quantity' => 1]],
            'idempotency_key'  => $reserveKey,
        ]);
        $this->reservationIds[] = $reserve['reservation_id'];
        $this->assertSame('reserved', $reserve['status']);
        $confirm = $this->adapter->confirm($reserve['reservation_id'], [
            'delivered_by'    => 'TEST-STAFF',
            'idempotency_key' => $confirmKey,
        ]);
        $this->assertSame('consumed', $confirm['status']);
        $this->assertArrayHasKey('stock_movement_id', $confirm);
    }

    /** @test */
    public function test_release_es_idempotente(): void
    {
        $originReference = 'TEST-RWD-R-' . uniqid();
        $reserveKey = 'test-7-rel-' . uniqid();
        $this->originReferences[] = $originReference;
        $this->idempotencyKeys[] = ['team3.reserve', $reserveKey];

        $reserve = $this->adapter->reserve([
            'origin_reference' => $originReference,
            'origin_type'      => 'REWARD',
            'items'            => [['product_reference' => $this->sku, 'quantity' => 1]],
            'idempotency_key'  => $reserveKey,
        ]);
        $rid = $reserve['reservation_id'];
        $this->reservationIds[] = $rid;
        $releaseKey = 'rel-key-1-' . $rid;
        $this->idempotencyKeys[] = ['team3.release', $releaseKey];
        $r1 = $this->adapter->release($rid, ['idempotency_key' => $releaseKey]);
        $this->assertSame('released', $r1['status']);
    }

    /** @test */
    public function test_status_publico_mapea_correctamente(): void
    {
        $originReference = 'TEST-MAP-' . uniqid();
        $reserveKey = 'test-map-' . uniqid();
        $confirmKey = 'cf-' . uniqid();
        $this->originReferences[] = $originReference;
        $this->idempotencyKeys[] = ['team3.reserve', $reserveKey];
        $this->idempotencyKeys[] = ['team3.confirm', $confirmKey];

        $reserve = $this->adapter->reserve([
            'origin_reference' => $originReference,
            'origin_type'      => 'REWARD',
            'items'            => [['product_reference' => $this->sku, 'quantity' => 1]],
            'idempotency_key'  => $reserveKey,
        ]);
        $rid = $reserve['reservation_id'];
        $this->reservationIds[] = $rid;
        $this->assertSame('reserved', $this->adapter->show($rid)['status']);
        $this->adapter->confirm($rid, ['idempotency_key' => $confirmKey]);
        $this->assertSame('consumed', $this->adapter->show($rid)['status']);
    }
}