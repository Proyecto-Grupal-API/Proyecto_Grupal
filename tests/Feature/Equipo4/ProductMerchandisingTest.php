<?php

namespace Tests\Feature\Equipo4;

use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use MongoDB\BSON\ObjectId;
use Tests\TestCase;

class ProductMerchandisingTest extends TestCase
{
    private string $businessId;
    private string $sku;
    private ?string $categoryId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $this->sku = 'SOUV-TEST-' . $suffix;

        $categories = DB::connection('mongodb')->getCollection('categories');
        $category = $categories->findOne([
            'business_id' => $this->businessId,
            'slug' => 'souvenirs',
            'active' => true,
        ]);
        if ($category === null) {
            $inserted = $categories->insertOne([
                '_id' => new ObjectId(),
                'business_id' => $this->businessId,
                'slug' => 'souvenirs',
                'name' => 'Souvenirs',
                'active' => true,
            ]);
            $this->categoryId = (string) $inserted->getInsertedId();
        }
    }

    protected function tearDown(): void
    {
        DB::connection('mongodb')->getCollection('products')->deleteMany([
            'business_id' => $this->businessId,
            'sku' => $this->sku,
        ]);
        if ($this->categoryId !== null) {
            DB::connection('mongodb')->getCollection('categories')->deleteOne([
                '_id' => new ObjectId($this->categoryId),
            ]);
        }
        parent::tearDown();
    }

    public function test_official_souvenir_persists_merchandising_and_preorder_metadata(): void
    {
        $product = Product::create([
            'business_id' => $this->businessId,
            'sku' => $this->sku,
            'name' => 'Sudadera oficial de prueba',
            'description' => 'Fixture de preventa',
            'category' => 'souvenirs',
            'sizes' => ['M', 'G'],
            'colors' => ['Negro', 'Azul'],
            'seasons' => ['Otono 2026'],
            'collections' => ['Generacion 2026'],
            'packages' => ['Kit de bienvenida'],
            'preorder' => [
                'enabled' => true,
                'available_at' => '2026-12-01',
                'limit' => 24,
            ],
            'official' => true,
            'stock_min' => 2,
            'stock_max' => 50,
            'active' => true,
        ]);

        $stored = Product::where('sku', $this->sku)
            ->where('business_id', $this->businessId)
            ->firstOrFail();

        $this->assertSame(['M', 'G'], $stored->sizes);
        $this->assertSame(['Negro', 'Azul'], $stored->colors);
        $this->assertSame(['Generacion 2026'], $stored->collections);
        $this->assertSame(['Kit de bienvenida'], $stored->packages);
        $this->assertTrue((bool) $stored->official);
        $this->assertTrue((bool) $stored->preorder['enabled']);
        $this->assertSame(24, (int) $stored->preorder['limit']);
    }

    public function test_preorder_requires_release_date_and_quantity_limit(): void
    {
        $input = [
            'sku' => $this->sku,
            'name' => 'Producto de preventa',
            'category' => 'souvenirs',
            'stock_min' => 1,
            'stock_max' => 10,
            'active' => true,
            'preorder' => ['enabled' => true],
        ];
        $validator = Validator::make($input, (new StoreProductRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('preorder.available_at'));
        $this->assertTrue($validator->errors()->has('preorder.limit'));

        $input['preorder'] = [
            'enabled' => true,
            'available_at' => '2026-12-01',
            'limit' => 24,
        ];
        $validValidator = Validator::make($input, (new StoreProductRequest())->rules());
        $this->assertFalse($validValidator->fails());
    }
}
