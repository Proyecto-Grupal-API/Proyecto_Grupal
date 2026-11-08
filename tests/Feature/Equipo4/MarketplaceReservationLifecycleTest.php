<?php

namespace Tests\Feature\Equipo4;

use App\Api\Team3ApiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Tests\TestCase;

class MarketplaceReservationLifecycleTest extends TestCase
{
    private string $businessId;
    private string $productId;
    private string $locationId;
    private string $orderId;
    private string $reserveKey;
    private string $inventoryId;
    private ?string $reservationId = null;
    private array $idempotencyKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
        $suffix = bin2hex(random_bytes(6));
        $this->productId = 'marketplace-test-product-' . $suffix;
        $this->locationId = 'marketplace-test-location-' . $suffix;
        $this->orderId = 'marketplace-test-order-' . $suffix;
        $this->reserveKey = 'marketplace-reserve-' . $suffix;
        $this->inventoryId = (string) new ObjectId();

        DB::connection('mongodb')->getCollection('inventories')->insertOne([
            '_id' => new ObjectId($this->inventoryId),
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'on_hand' => 10,
            'reserved' => 0,
            'available' => 10,
            'status' => 'AVAILABLE',
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime(),
        ]);
    }

    protected function tearDown(): void
    {
        $reservations = DB::connection('mongodb')->getCollection('team3_api_reservations');
        $movements = DB::connection('mongodb')->getCollection('stock_movements');
        $idempotency = DB::connection('mongodb')->getCollection('integration_idempotency');

        if ($this->reservationId !== null) {
            $reservations->deleteOne(['reservation_id' => $this->reservationId]);
            $movements->deleteMany(['external_reference' => $this->orderId]);
            $idempotency->deleteMany([
                'service' => 'team3.release',
                'idempotency_key' => ['$regex' => '^team3-expire-' . preg_quote($this->reservationId, '/') . '-'],
            ]);
        }

        $idempotency->deleteMany(['idempotency_key' => ['$in' => $this->idempotencyKeys]]);
        DB::connection('mongodb')->getCollection('inventories')->deleteOne([
            '_id' => new ObjectId($this->inventoryId),
        ]);

        parent::tearDown();
    }

    public function test_confirm_is_idempotent_and_deducts_stock_once(): void
    {
        $this->reserve();
        $confirmKey = 'marketplace-confirm-' . bin2hex(random_bytes(6));
        $this->idempotencyKeys[] = $confirmKey;
        $service = app(Team3ApiService::class);

        $first = $service->confirm($this->reservationId, ['idempotency_key' => $confirmKey]);
        $second = $service->confirm($this->reservationId, ['idempotency_key' => $confirmKey]);

        $inventory = (array) DB::connection('mongodb')->getCollection('inventories')->findOne([
            '_id' => new ObjectId($this->inventoryId),
        ]);
        $saleCount = DB::connection('mongodb')->getCollection('stock_movements')->countDocuments([
            'external_reference' => $this->orderId,
            'type' => 'SALE',
        ]);

        $this->assertSame('CONFIRMED', $first['status']);
        $this->assertSame('CONFIRMED', $second['status']);
        $this->assertSame(8, (int) $inventory['on_hand']);
        $this->assertSame(0, (int) $inventory['reserved']);
        $this->assertSame(8, (int) $inventory['available']);
        $this->assertSame(1, $saleCount);
    }

    public function test_release_restores_stock_and_is_idempotent(): void
    {
        $this->reserve();
        $releaseKey = 'marketplace-release-' . bin2hex(random_bytes(6));
        $this->idempotencyKeys[] = $releaseKey;
        $service = app(Team3ApiService::class);

        $first = $service->release($this->reservationId, [
            'idempotency_key' => $releaseKey,
            'reason' => 'CANCELLED',
        ]);
        $second = $service->release($this->reservationId, [
            'idempotency_key' => $releaseKey,
            'reason' => 'CANCELLED',
        ]);

        $inventory = (array) DB::connection('mongodb')->getCollection('inventories')->findOne([
            '_id' => new ObjectId($this->inventoryId),
        ]);
        $this->assertSame('RELEASED', $first['status']);
        $this->assertSame('RELEASED', $second['status']);
        $this->assertSame(10, (int) $inventory['available']);
        $this->assertSame(0, (int) $inventory['reserved']);
    }

    public function test_expired_confirmation_releases_stock_before_rejecting(): void
    {
        $this->reserve(Carbon::now()->subMinute());
        $confirmKey = 'marketplace-expired-confirm-' . bin2hex(random_bytes(6));
        $this->idempotencyKeys[] = $confirmKey;

        try {
            app(Team3ApiService::class)->confirm($this->reservationId, ['idempotency_key' => $confirmKey]);
            $this->fail('La confirmación de una reserva vencida debe rechazarse.');
        } catch (\DomainException $exception) {
            $this->assertSame('La reserva venció antes de confirmarse.', $exception->getMessage());
        }

        $inventory = (array) DB::connection('mongodb')->getCollection('inventories')->findOne([
            '_id' => new ObjectId($this->inventoryId),
        ]);
        $reservation = (array) DB::connection('mongodb')->getCollection('team3_api_reservations')->findOne([
            'reservation_id' => $this->reservationId,
        ]);

        $this->assertSame('RELEASED', $reservation['status']);
        $this->assertSame('EXPIRED', $reservation['release_reason']);
        $this->assertSame(10, (int) $inventory['available']);
        $this->assertSame(0, (int) $inventory['reserved']);
    }

    private function reserve(?Carbon $expiresAt = null): void
    {
        $this->idempotencyKeys[] = $this->reserveKey;
        $result = app(Team3ApiService::class)->reserve([
            'order_id' => $this->orderId,
            'idempotency_key' => $this->reserveKey,
            'expires_at' => $expiresAt,
            'items' => [[
                'product_id' => $this->productId,
                'location_id' => $this->locationId,
                'quantity' => 2,
            ]],
        ]);
        $this->reservationId = $result['reservation_id'];
    }
}
