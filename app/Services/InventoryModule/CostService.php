<?php

namespace App\Services\InventoryModule;

use App\Models\InventoryModule\CostHistory;
use App\Models\InventoryModule\PricingReference;
use App\Models\InventoryModule\Product;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class CostService
{
    public function __construct() {}

    use UsesBusinessContext;

    /**
     * Registra un costo para un producto y actualiza su PricingReference.
     * $data requiere: product_id, cost (float), source (MANUAL|PURCHASE_ORDER|RECEIPT),
     *                 reference (nullable), notes (nullable), suggested_price (nullable),
     *                 actor_id (nullable)
     */
    public function register(array $data): CostHistory
    {
        $productId = (string) $data['product_id'];
        $cost = (float) $data['cost'];
        $source = (string) ($data['source'] ?? 'MANUAL');
        $reference = isset($data['reference']) ? (string) $data['reference'] : '';
        $notes = isset($data['notes']) ? (string) $data['notes'] : '';
        $actorId = (string) ($data['actor_id'] ?? ActorResolver::resolve());
        $suggestedPrice = isset($data['suggested_price']) && $data['suggested_price'] !== null
            ? (float) $data['suggested_price']
            : null;

        // Validar producto
        $product = Product::where('_id', $productId)
            ->where('business_id', $this->businessId())
            ->first();

        if (! $product) {
            throw new \InvalidArgumentException('Producto no encontrado.');
        }

        // Insertar CostHistory
        $costHistoryObjectId = new ObjectId;
        DB::connection('inventory')->getCollection('cost_histories')->insertOne([
            '_id' => $costHistoryObjectId,
            'business_id' => $this->businessId(),
            'product_id' => $productId,
            'sku' => (string) $product->sku, 'product_name' => (string) $product->name,
            'cost' => $cost,
            'source' => $source,
            'reference' => $reference,
            'notes' => $notes,
            'actor_id' => $actorId,
            'effective_at' => new UTCDateTime(now()),
            'created_at' => new UTCDateTime(now()),
            'updated_at' => new UTCDateTime(now()),
        ]);

        // Recalcular PricingReference
        $this->recalculateReference($productId, $suggestedPrice);

        return CostHistory::where('_id', (string) $costHistoryObjectId)->firstOrFail();
    }

    /**
     * Recalcula la PricingReference de un producto:
     *   - last_cost: el costo más reciente
     *   - average_cost: promedio de los últimos 5 costos
     *   - suggested_margin: (precio - last_cost) / precio si hay suggested_price
     */
    public function recalculateReference(string $productId, ?float $suggestedPrice = null): void
    {
        $recent = CostHistory::where('business_id', $this->businessId())
            ->where('product_id', $productId)
            ->orderBy('effective_at', 'desc')->orderBy('_id', 'desc')
            ->limit(5)
            ->get();

        if ($recent->isEmpty()) {
            return;
        }

        $lastCost = (float) $recent->first()->cost;
        $averageCost = (float) ($recent->avg('cost') ?? $lastCost);

        $suggestedMargin = 0.0;
        if ($suggestedPrice !== null && $suggestedPrice > 0) {
            $suggestedMargin = round((($suggestedPrice - $lastCost) / $suggestedPrice) * 100, 2);
        }

        // Upsert
        $refCollection = DB::connection('inventory')->getCollection('pricing_references');

        $existing = $refCollection->findOne([
            'business_id' => $this->businessId(),
            'product_id' => $productId,
        ]);

        $payload = [
            'business_id' => $this->businessId(),
            'product_id' => $productId,
            'last_cost' => $lastCost,
            'average_cost' => round($averageCost, 2),
            'suggested_margin' => $suggestedMargin,
            'suggested_price' => $suggestedPrice,
            'updated_at' => new UTCDateTime(now()),
        ];

        if ($existing) {
            $refCollection->updateOne(['_id' => $existing['_id']], ['$set' => $payload]);
        } else {
            $payload['_id'] = new ObjectId;
            $payload['created_at'] = new UTCDateTime(now());
            $refCollection->insertOne($payload);
        }
    }
}
