<?php

namespace App\Services;

use App\Models\ReorderRule;
use App\Models\Product;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

class ReorderRuleService
{
    protected string $businessId = 'BUS-CD-SOUV-001';

    /**
     * Calcula el punto de reorden como el punto medio entre min y max.
     * Formula: min + ceil((max - min) / 2)
     */
    public function calculateReorderPoint(int $minQty, int $maxQty): int
    {
        if ($maxQty <= $minQty) {
            return $minQty;
        }
        $mid = (int) ceil(($maxQty - $minQty) / 2);
        return $minQty + $mid;
    }

    /**
     * Sincroniza las reglas de reorden de un producto en TODAS las
     * ubicaciones del negocio.
     *   - Si la regla no existe -> la crea.
     *   - Si ya existe -> actualiza min_qty y max_qty (del producto),
     *     pero NO toca reorder_point (para respetar el ajuste manual).
     */
    public function syncForProduct(string $productId): void
    {
        $product = Product::where('_id', $productId)
            ->where('business_id', $this->businessId)
            ->first();

        if (!$product) {
            return;
        }

        $minQty = (int) ($product->stock_min ?? 0);
        $maxQty = (int) ($product->stock_max ?? 0);
        $defaultReorder = $this->calculateReorderPoint($minQty, $maxQty);

        $locations = Location::where('business_id', $this->businessId)->get();

        $rulesCollection = DB::connection('mongodb')->getCollection('reorder_rules');

        foreach ($locations as $loc) {
            $locationId = (string) $loc->_id;

            $existing = $rulesCollection->findOne([
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'location_id' => $locationId,
            ]);

            if ($existing) {
                $rulesCollection->updateOne(
                    ['_id' => $existing['_id']],
                    ['$set' => [
                        'min_qty' => $minQty,
                        'max_qty' => $maxQty,
                        'updated_at' => now()->toDateTime(),
                    ]]
                );
            } else {
                $rulesCollection->insertOne([
                    '_id' => new ObjectId(),
                    'business_id' => $this->businessId,
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'min_qty' => $minQty,
                    'max_qty' => $maxQty,
                    'reorder_point' => $defaultReorder,
                    'active' => true,
                    'created_at' => now()->toDateTime(),
                    'updated_at' => now()->toDateTime(),
                ]);
            }
        }

        try {
            $alerts = app(AlertGeneratorService::class);
            foreach ($locations as $loc) {
                $alerts->syncOne($productId, (string) $loc->_id);
            }
        } catch (\Throwable $e) {
            // no-op
        }
    }

    /**
     * Recalcula el reorder_point de las reglas de un producto en base
     * a los min/max actuales del producto.
     */
    public function recalculateForProduct(string $productId): void
    {
        $product = Product::where('_id', $productId)
            ->where('business_id', $this->businessId)
            ->first();

        if (!$product) {
            return;
        }

        $minQty = (int) ($product->stock_min ?? 0);
        $maxQty = (int) ($product->stock_max ?? 0);
        $newReorder = $this->calculateReorderPoint($minQty, $maxQty);

        DB::connection('mongodb')->getCollection('reorder_rules')->updateMany(
            [
                'business_id' => $this->businessId,
                'product_id' => $productId,
            ],
            ['$set' => [
                'min_qty' => $minQty,
                'max_qty' => $maxQty,
                'reorder_point' => $newReorder,
                'updated_at' => now()->toDateTime(),
            ]]
        );
    }
}
