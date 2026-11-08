<?php

namespace App\Services;

use App\Models\ReorderRule;
use App\Models\StockAlert;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Location;
use App\Notifications\StockAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use MongoDB\BSON\ObjectId;

class AlertGeneratorService
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

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
            'type' => 'LOW_STOCK',
            'status' => 'ACTIVE',
        ]);

        if (!$rule) {
            if ($existing) {
                $alertsCollection->updateOne(
                    ['_id' => $existing['_id']],
                    ['$set' => [
                        'status' => 'RESOLVED',
                        'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
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
                        'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
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
                    'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]);
                $this->notifyResponsible('LOW_STOCK', $message);
            }
        } else {
            if ($existing) {
                $alertsCollection->updateOne(
                    ['_id' => $existing['_id']],
                    ['$set' => [
                        'status' => 'RESOLVED',
                        'current_qty' => $available,
                        'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
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
                'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]]
        );
    }

    public function generate(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'notifications' => 0];

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
                'type' => 'LOW_STOCK',
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
                            'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
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
                        'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                        'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                    ]);
                    $stats['created']++;
                    if ($this->notifyResponsible('LOW_STOCK', $message)) {
                        $stats['notifications']++;
                    }
                }
            } else {
                if ($existing) {
                    $alertsCollection->updateOne(
                        ['_id' => $existing['_id']],
                        ['$set' => [
                            'status' => 'RESOLVED',
                            'current_qty' => $available,
                            'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                        ]]
                    );
                    $stats['resolved']++;
                }
            }
        }

        $this->generateNoMovementAlerts($stats);

        return $stats;
    }

    private function generateNoMovementAlerts(array &$stats): void
    {
        $products = Product::where('business_id', $this->businessId)
            ->where('active', true)
            ->get()
            ->keyBy(fn ($product) => (string) $product->_id);
        $inventory = Inventory::where('business_id', $this->businessId)
            ->where('on_hand', '>', 0)
            ->get();
        $movements = DB::connection('mongodb')->getCollection('stock_movements');
        $alerts = DB::connection('mongodb')->getCollection('stock_alerts');
        $cutoff = now()->subDays(max(1, (int) config('team4.inactive_product_days', 90)));
        $activeKeys = [];

        foreach ($inventory as $entry) {
            $productId = (string) $entry->product_id;
            $locationId = (string) $entry->location_id;
            $product = $products->get($productId);
            if (!$product) {
                continue;
            }

            $movement = $movements->findOne(
                [
                    'business_id' => $this->businessId,
                    'product_id' => $productId,
                    'location_id' => $locationId,
                ],
                ['sort' => ['created_at' => -1], 'projection' => ['created_at' => 1]]
            );
            $lastMovement = $movement
                ? (($movement->created_at ?? null) instanceof \MongoDB\BSON\UTCDateTime
                    ? $movement->created_at->toDateTime()
                    : (($movement->created_at ?? null) instanceof \DateTimeInterface
                        ? $movement->created_at
                        : null))
                : null;
            if ($lastMovement === null) {
                $createdAt = $product->getAttributes()['created_at'] ?? null;
                $lastMovement = $createdAt instanceof \MongoDB\BSON\UTCDateTime
                    ? $createdAt->toDateTime()
                    : ($createdAt instanceof \DateTimeInterface ? $createdAt : null);
            }
            if ($lastMovement !== null && $lastMovement >= $cutoff) {
                continue;
            }

            $key = $productId . ':' . $locationId;
            $activeKeys[$key] = true;
            $message = sprintf(
                'Sin movimiento durante %d días: %s (%s) en ubicación %s.',
                (int) config('team4.inactive_product_days', 90),
                (string) $product->name,
                (string) $product->sku,
                $locationId
            );
            $existing = $alerts->findOne([
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'location_id' => $locationId,
                'type' => 'NO_MOVEMENT',
                'status' => 'ACTIVE',
            ]);

            if ($existing) {
                $alerts->updateOne(
                    ['_id' => $existing->_id],
                    ['$set' => ['message' => $message, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
                );
                $stats['updated']++;
                continue;
            }

            $alerts->insertOne([
                '_id' => new ObjectId(),
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'location_id' => $locationId,
                'type' => 'NO_MOVEMENT',
                'current_qty' => (int) $entry->available,
                'threshold' => (int) config('team4.inactive_product_days', 90),
                'priority' => 'LOW',
                'status' => 'ACTIVE',
                'message' => $message,
                'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]);
            $stats['created']++;
            if ($this->notifyResponsible('NO_MOVEMENT', $message)) {
                $stats['notifications']++;
            }
        }

        foreach ($alerts->find([
            'business_id' => $this->businessId,
            'type' => 'NO_MOVEMENT',
            'status' => 'ACTIVE',
        ]) as $alert) {
            $document = (array) $alert;
            $key = (string) ($document['product_id'] ?? '') . ':' . (string) ($document['location_id'] ?? '');
            if (isset($activeKeys[$key])) {
                continue;
            }
            $alerts->updateOne(
                ['_id' => $document['_id']],
                ['$set' => ['status' => 'RESOLVED', 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
            );
            $stats['resolved']++;
        }
    }

    private function notifyResponsible(string $type, string $message): bool
    {
        $business = DB::connection('mongodb')->getCollection('businesses')->findOne([
            'business_id' => $this->businessId,
        ]);
        if (!$business) {
            $business = DB::connection('mongodb')->getCollection('businesses')->findOne([
                '_id' => $this->businessId,
            ]);
        }
        $businessData = $business ? (array) $business : [];
        $email = trim((string) (
            $businessData['inventory_responsible_email']
            ?? $businessData['responsible_email']
            ?? config('team4.alert_recipient_email', '')
        ));
        if ($email === '') {
            Log::warning('Alerta de inventario activa sin destinatario configurado.', [
                'business_id' => $this->businessId,
                'type' => $type,
                'message' => $message,
            ]);
            return false;
        }

        try {
            Notification::route('mail', $email)->notify(
                new StockAlertNotification($this->businessId, $type, $message)
            );
            return true;
        } catch (\Throwable $exception) {
            Log::error('No se pudo notificar al responsable de inventario.', [
                'business_id' => $this->businessId,
                'type' => $type,
                'recipient' => $email,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }
}
