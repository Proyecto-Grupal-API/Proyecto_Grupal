<?php

namespace App\Services;

use App\Models\CostHistory;
use App\Models\PricingReference;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;

class CostService
{
    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

        public function register(array $data): CostHistory
    {
        $productId = isset($data['product_id']) && $data['product_id'] ? (string) $data['product_id'] : null;
        $sku = $data['sku'] ?? null;
        $productName = $data['product_name'] ?? null;
        $productExists = $data['product_exists'] ?? false;
        $productStatus = $data['product_status'] ?? 'deleted';
        $cost = (float) $data['cost'];
        $source = (string) ($data['source'] ?? 'MANUAL');
        $reference = isset($data['reference']) ? (string) $data['reference'] : '';
        $notes = isset($data['notes']) ? (string) $data['notes'] : '';
        $actorId = (string) ($data['actor_id'] ?? 'USR-ADMIN-001');
        $suggestedPrice = isset($data['suggested_price']) && $data['suggested_price'] !== null
            ? (float) $data['suggested_price']
            : null;

        $costHistoryObjectId = new ObjectId();
        DB::connection('mongodb')->getCollection('cost_histories')->insertOne([
            '_id' => $costHistoryObjectId,
            'business_id' => $this->businessId,
            'product_id' => $productId,
            'sku' => $sku,
            'product_name' => $productName,
            'product_exists' => $productExists,
            'product_status' => $productStatus,
            'cost' => $cost,
            'source' => $source,
            'reference' => $reference,
            'notes' => $notes,
            'actor_id' => $actorId,
            'effective_at' => new \MongoDB\BSON\UTCDateTime(now()),
            'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
            'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
        ]);

        if ($productId) {
            $this->recalculateReference($productId, $suggestedPrice);
        }

        return CostHistory::where('_id', (string) $costHistoryObjectId)->firstOrFail();
    }

    public function recalculateReference(string $productId, ?float $suggestedPrice = null): void
    {
        $recent = CostHistory::where('business_id', $this->businessId)
            ->where('product_id', $productId)
            ->orderBy('effective_at', 'desc')
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

        $refCollection = DB::connection('mongodb')->getCollection('pricing_references');

        $existing = $refCollection->findOne([
            'business_id' => $this->businessId,
            'product_id' => $productId,
        ]);

        $payload = [
            'business_id' => $this->businessId,
            'product_id' => $productId,
            'last_cost' => $lastCost,
            'average_cost' => round($averageCost, 2),
            'suggested_margin' => $suggestedMargin,
            'suggested_price' => $suggestedPrice,
            'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
        ];

        if ($existing) {
            $refCollection->updateOne(['_id' => $existing['_id']], ['$set' => $payload]);
        } else {
            $payload['_id'] = new ObjectId();
            $payload['created_at'] = new \MongoDB\BSON\UTCDateTime(now());
            $refCollection->insertOne($payload);
        }
    }
}