<?php

namespace App\Services;

use App\Models\ReorderRule;
use App\Models\StockAlert;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;

class AlertGeneratorService
{
    protected string $businessId = 'BUS-CD-SOUV-001';

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

    public function syncOne(string $productId, string $locationId): void
    {
        $rule = ReorderRule::where('business_id', $this->businessId)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('active', true)
            ->first();

        $alertsCollection = DB::connection('mongodb')->getCollection('stock_alerts');

        $existing = $alertsCollection->findOne([
            'business_id' => $this->businessId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'status' => 'ACTIVE',
        ]);

        if (!$rule) {
            if ($existing) {
                $alertsCollection->updateOne(
                    ['_id' => $existing['_id']],
                    ['$set' => [
                        'status' => 'RESOLVED',
                        'updated_at' => now()->toDateTime(),
                    ]]
                );
            }
            return;
        }

        $product = Product::where('_id', $productId)
            ->where('business_id', $this->businessId)
            ->first();

        $productActive = $product ? (bool) $product->active : true;

        $inventory = Inventory::where('business_id', $this->businessId)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        $available = $inventory ? (int) $inventory->available : 0;
        $reorderPoint = (int) $rule->reorder_point;
        $minQty = (int) $rule->min_qty;

        if ($available < $reorderPoint) {
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
                        'updated_at' => now()->toDateTime(),
                    ]]
                );
            } else {
                $alertsCollection->insertOne([
                    '_id' => new ObjectId(),
                    'business_id' => $this->businessId,
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'type' => 'LOW_STOCK',
                    'current_qty' => $available,
                    'threshold' => $reorderPoint,
                    'priority' => $priority,
                    'status' => 'ACTIVE',
                    'message' => $message,
                    'created_at' => now()->toDateTime(),
                    'updated_at' => now()->toDateTime(),
                ]);
            }
        } else {
            if ($existing) {
                $alertsCollection->updateOne(
                    ['_id' => $existing['_id']],
                    ['$set' => [
                        'status' => 'RESOLVED',
                        'current_qty' => $available,
                        'updated_at' => now()->toDateTime(),
                    ]]
                );
            }
        }
    }

    public function resolveFor(string $productId, string $locationId): void
    {
        DB::connection('mongodb')->getCollection('stock_alerts')->updateMany(
            [
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'location_id' => $locationId,
                'status' => 'ACTIVE',
            ],
            ['$set' => [
                'status' => 'RESOLVED',
                'updated_at' => now()->toDateTime(),
            ]]
        );
    }

    public function generate(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'resolved' => 0];

        $rules = ReorderRule::where('business_id', $this->businessId)
            ->where('active', true)
            ->get();

        $productsMap = Product::where('business_id', $this->businessId)
            ->get()
            ->keyBy(fn($p) => (string) $p->_id);

        $inventoryMap = [];
        $allInventory = Inventory::where('business_id', $this->businessId)->get();
        foreach ($allInventory as $inv) {
            $pid = (string) $inv->product_id;
            $lid = (string) $inv->location_id;
            if (!isset($inventoryMap[$pid])) {
                $inventoryMap[$pid] = [];
            }
            $inventoryMap[$pid][$lid] = (int) $inv->available;
        }

        $alertsCollection = DB::connection('mongodb')->getCollection('stock_alerts');

        foreach ($rules as $rule) {
            $productId = (string) $rule->product_id;
            $locationId = (string) $rule->location_id;
            $reorderPoint = (int) $rule->reorder_point;
            $minQty = (int) $rule->min_qty;

            if (!$productsMap->has($productId)) {
                continue;
            }

            $product = $productsMap->get($productId);
            $productActive = (bool) $product->active;

            $available = $inventoryMap[$productId][$locationId] ?? 0;

            $existing = $alertsCollection->findOne([
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'location_id' => $locationId,
                'status' => 'ACTIVE',
            ]);

            if ($available < $reorderPoint) {
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
                            'updated_at' => now()->toDateTime(),
                        ]]
                    );
                    $stats['updated']++;
                } else {
                    $alertsCollection->insertOne([
                        '_id' => new ObjectId(),
                        'business_id' => $this->businessId,
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'type' => 'LOW_STOCK',
                        'current_qty' => $available,
                        'threshold' => $reorderPoint,
                        'priority' => $priority,
                        'status' => 'ACTIVE',
                        'message' => $message,
                        'created_at' => now()->toDateTime(),
                        'updated_at' => now()->toDateTime(),
                    ]);
                    $stats['created']++;
                }
            } else {
                if ($existing) {
                    $alertsCollection->updateOne(
                        ['_id' => $existing['_id']],
                        ['$set' => [
                            'status' => 'RESOLVED',
                            'current_qty' => $available,
                            'updated_at' => now()->toDateTime(),
                        ]]
                    );
                    $stats['resolved']++;
                }
            }
        }

        return $stats;
    }
}
