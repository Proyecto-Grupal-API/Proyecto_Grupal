<?php

namespace Tests\Feature\Equipo4;

use App\Services\BusinessService;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use Tests\TestCase;

class BusinessServiceTest extends TestCase
{
    private string $businessId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->businessId = 'BUS-SVC-TEST-' . strtoupper(bin2hex(random_bytes(4)));
        DB::connection('mongodb')->getCollection('businesses')->insertOne([
            '_id' => new ObjectId(),
            'business_id' => $this->businessId,
            'name' => 'Negocio de prueba',
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('mongodb')->getCollection('businesses')->deleteOne([
            'business_id' => $this->businessId,
        ]);
        parent::tearDown();
    }

    public function test_only_registered_active_businesses_are_valid(): void
    {
        $service = app(BusinessService::class);
        $this->assertTrue($service->validate($this->businessId));

        $this->expectException(\DomainException::class);
        $service->validate('BUS-NOT-REGISTERED-001');
    }

    public function test_inactive_business_is_rejected(): void
    {
        DB::connection('mongodb')->getCollection('businesses')->updateOne(
            ['business_id' => $this->businessId],
            ['$set' => ['active' => false]]
        );

        $this->expectException(\DomainException::class);
        app(BusinessService::class)->validate($this->businessId);
    }
}
