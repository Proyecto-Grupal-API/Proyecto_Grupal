<?php

namespace Tests\Feature\Equipo4;

use App\Models\Product;
use App\Services\ProductVariantService;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use Tests\TestCase;

class ProductVariantServiceTest extends TestCase
{
    private string $businessId;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
        $this->productId = (string) new ObjectId();
    }

    protected function tearDown(): void
    {
        DB::connection('mongodb')->getCollection('product_variants')->deleteMany([
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
        ]);
        parent::tearDown();
    }

    public function test_variant_catalog_is_idempotent_and_deactivates_removed_combinations(): void
    {
        $product = new Product();
        $product->setAttribute('_id', new ObjectId($this->productId));
        $product->setAttribute('business_id', $this->businessId);
        $product->setAttribute('sku', 'VARIANT-TEST');
        $product->setAttribute('active', true);
        $product->setAttribute('sizes', ['S', 'M']);
        $product->setAttribute('colors', ['Azul', 'Rojo']);

        $service = app(ProductVariantService::class);
        $service->sync($product);
        $service->sync($product);

        $variants = DB::connection('mongodb')->getCollection('product_variants');
        $this->assertSame(4, $variants->countDocuments([
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
            'active' => true,
        ]));

        $product->setAttribute('sizes', ['S']);
        $product->setAttribute('colors', ['Azul']);
        $service->sync($product);

        $this->assertSame(1, $variants->countDocuments([
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
            'active' => true,
        ]));
        $this->assertSame(3, $variants->countDocuments([
            'business_id' => $this->businessId,
            'product_id' => $this->productId,
            'active' => false,
        ]));
    }
}
