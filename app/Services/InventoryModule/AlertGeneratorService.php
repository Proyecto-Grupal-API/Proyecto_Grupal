<?php

namespace App\Services\InventoryModule;

use App\Models\InventoryModule\ReorderRule;
use App\Models\InventoryModule\StockAlert;
use App\Models\InventoryModule\Inventory;
use App\Models\InventoryModule\Product;
use App\Models\InventoryModule\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;

class AlertGeneratorService
{

    public function __construct()
    {
    }
    use \App\Services\InventoryModule\UsesBusinessContext;

    /**
     * Calcula la prioridad de una alerta basada en:
     *   - Producto inactivo -> LOW (no urge reabastecer)
     *   - Producto activo + available < min_qty -> CRITICAL
     *   - Producto activo + available < reorder_point -> HIGH
     */
    private function calculatePriority(bool $productActive, int $available, int $minQty, int $reorderPoint): string
    {
        if (!$productActive) {
            return 'LOW';
        }

        if ($available < $minQty) {
            return 'CRITICAL';
        }

        return 'HIGH';
    }

    private const PRIORITY_RANK = ['LOW' => 0, 'HIGH' => 1, 'CRITICAL' => 2];

    /**
     * Un descarte silencia la condición de stock bajo de ese producto y ubicación mientras
     * siga siendo la misma: no se vuelve a avisar hasta que la existencia se recupere por
     * encima del punto de reorden (episodio nuevo) o la situación empeore de prioridad.
     */
    private function silencedBy(\MongoDB\Collection $alerts, string $productId, string $locationId, string $priority): bool
    {
        $dismissed = $alerts->findOne([
            'business_id' => $this->businessId(),
            'product_id' => $productId,
            'location_id' => $locationId,
            'status' => 'DISMISSED',
            'condition_cleared_at' => null,
        ], ['sort' => ['dismissed_at' => -1, '_id' => -1]]);

        if ($dismissed === null) {
            return false;
        }

        $dismissedRank = self::PRIORITY_RANK[(string) ($dismissed['priority'] ?? '')] ?? 0;

        return (self::PRIORITY_RANK[$priority] ?? 0) <= $dismissedRank;
    }

    /** La existencia volvió a estar por encima del punto de reorden: los descartes previos dejan de aplicar. */
    private function clearSilences(\MongoDB\Collection $alerts, string $productId, string $locationId): void
    {
        $alerts->updateMany([
            'business_id' => $this->businessId(),
            'product_id' => $productId,
            'location_id' => $locationId,
            'status' => 'DISMISSED',
            'condition_cleared_at' => null,
        ], ['$set' => ['condition_cleared_at' => new \MongoDB\BSON\UTCDateTime(now())]]);
    }

    /** @return 'created'|'updated'|'resolved'|'silenced'|null */
    private function evaluate(string $productId, string $locationId, bool $productActive, int $available, int $reorderPoint, int $minQty): ?string
    {
        $alertsCollection = DB::connection('inventory')->getCollection('stock_alerts');

        $existing = $alertsCollection->findOne([
            'business_id' => $this->businessId(),
            'product_id' => $productId,
            'location_id' => $locationId,
            'status' => 'ACTIVE',
        ]);

        if ($available >= $reorderPoint) {
            $this->clearSilences($alertsCollection, $productId, $locationId);
            if (! $existing) {
                return null;
            }
            $alertsCollection->updateOne(
                ['_id' => $existing['_id']],
                ['$set' => [
                    'status' => 'RESOLVED',
                    'current_qty' => $available,
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]]
            );

            return 'resolved';
        }

        $priority = $this->calculatePriority($productActive, $available, $minQty, $reorderPoint);

        $message = sprintf(
            '%s: %d disponibles (reorden en %d, mínimo %d)',
            $productActive ? 'Stock bajo' : 'Producto inactivo con stock bajo',
            $available,
            $reorderPoint,
            $minQty
        );

        if ($existing) {
            $alertsCollection->updateOne(
                ['_id' => $existing['_id']],
                ['$set' => [
                    'current_qty' => $available,
                    'threshold' => $reorderPoint,
                    'priority' => $priority,
                    'message' => $message,
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]]
            );

            return 'updated';
        }

        if ($this->silencedBy($alertsCollection, $productId, $locationId, $priority)) {
            return 'silenced';
        }

        $alertsCollection->insertOne([
            '_id' => new ObjectId(),
            'business_id' => $this->businessId(),
            'product_id' => $productId,
            'location_id' => $locationId,
            'type' => 'LOW_STOCK',
            'current_qty' => $available,
            'threshold' => $reorderPoint,
            'priority' => $priority,
            'status' => 'ACTIVE',
            'message' => $message,
            'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
            'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
        ]);

        return 'created';
    }

    public function syncOne(string $productId, string $locationId): void
    {
        $rule = ReorderRule::where('business_id', $this->businessId())
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('active', true)
            ->first();

        if (!$rule) {
            $this->resolveFor($productId, $locationId);
            return;
        }

        $product = Product::where('_id', $productId)
            ->where('business_id', $this->businessId())
            ->first();

        $inventory = Inventory::where('business_id', $this->businessId())
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        $this->evaluate(
            $productId,
            $locationId,
            $product ? (bool) $product->active : true,
            $inventory ? (int) $inventory->available : 0,
            (int) $rule->reorder_point,
            (int) $rule->min_qty
        );
    }

    public function resolveFor(string $productId, string $locationId): void
    {
        DB::connection('inventory')->getCollection('stock_alerts')->updateMany(
            [
                'business_id' => $this->businessId(),
                'product_id' => $productId,
                'location_id' => $locationId,
                'status' => 'ACTIVE',
            ],
            ['$set' => [
                'status' => 'RESOLVED',
                'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]]
        );
    }

    public function generate(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'silenced' => 0];

        $rules = ReorderRule::where('business_id', $this->businessId())
            ->where('active', true)
            ->get();

        $productsMap = Product::where('business_id', $this->businessId())
            ->get()
            ->keyBy(fn($p) => (string) $p->_id);

        $inventoryMap = [];
        foreach (Inventory::where('business_id', $this->businessId())->get() as $inv) {
            $inventoryMap[(string) $inv->product_id][(string) $inv->location_id] = (int) $inv->available;
        }

        foreach ($rules as $rule) {
            $productId = (string) $rule->product_id;
            $locationId = (string) $rule->location_id;

            if (!$productsMap->has($productId)) {
                continue;
            }

            $result = $this->evaluate(
                $productId,
                $locationId,
                (bool) $productsMap->get($productId)->active,
                $inventoryMap[$productId][$locationId] ?? 0,
                (int) $rule->reorder_point,
                (int) $rule->min_qty
            );

            if ($result !== null) {
                $stats[$result]++;
            }
        }

        return $stats;
    }
}
