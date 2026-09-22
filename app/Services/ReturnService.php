<?php

namespace App\Services;

use App\Models\CustomerReturn;
use App\Models\SupplierReturn;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\Operation\FindOneAndUpdate;

class ReturnService
{
    public function supplier(array $data)
    {
        app(BusinessService::class)->validate((string) $data['business_id']);

        $folio = 'DEV-PROV-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4));

        $businessId = (string) $data['business_id'];
        $productId = (string) $data['product_id'];
        $variantId = array_key_exists('variant_id', $data) && $data['variant_id'] !== null
            ? (string) $data['variant_id']
            : null;
        $locationId = (string) $data['location_id'];
        $quantity = (int) $data['quantity'];
        $correlationId = (string) Str::uuid();

        $inventory = $this->applyInventoryDelta(
            $businessId,
            $productId,
            $variantId,
            $locationId,
            -$quantity
        );

        $movementCreated = false;

        try {
            $movement = new StockMovement();
            $movement->_id = new ObjectId();
            $movement->fill([
                'business_id' => $businessId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'type' => 'RETURN_OUT',
                'quantity' => -$quantity,
                'reason' => $data['reason'],
                'external_reference' => $folio,
                'actor_id' => $data['actor_id'] ?? 'SYSTEM',
                'correlation_id' => $correlationId,
            ]);
            $movement->save();
            $movementCreated = true;

            $supplierReturn = new SupplierReturn();
            $supplierReturn->_id = new ObjectId();
            $supplierReturn->fill([
                'business_id' => $businessId,
                'folio' => $folio,
                'supplier_id' => isset($data['supplier_id']) && $data['supplier_id'] ? (string) $data['supplier_id'] : '',
                'status' => 'RECEIVED',
                'reason' => (string) $data['reason'],
                'source_type' => 'MANUAL',
                'source_reference' => (string) ($data['reference'] ?? ''),
                'created_by' => (string) ($data['actor_id'] ?? 'SYSTEM'),
            ]);
            $supplierReturn->save();

            return $supplierReturn;
        } catch (\Throwable $exception) {
            if ($movementCreated) {
                try {
                    StockMovement::where('correlation_id', $correlationId)->delete();
                } catch (\Throwable $cleanupException) {
                    Log::critical('No fue posible eliminar el movimiento durante la compensación de devolución a proveedor.', [
                        'correlation_id' => $correlationId,
                        'cleanup_error' => $cleanupException->getMessage(),
                    ]);
                }
            }

            $this->compensateInventory(
                $inventory['_id'] ?? null,
                $quantity,
                $correlationId,
                $exception,
                'supplier'
            );

            throw $exception;
        }
    }

    public function customer(array $data)
    {
        app(BusinessService::class)->validate((string) $data['business_id']);

        $folio = 'DEV-CLI-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4));

        $businessId = (string) $data['business_id'];
        $productId = (string) $data['product_id'];
        $variantId = array_key_exists('variant_id', $data) && $data['variant_id'] !== null
            ? (string) $data['variant_id']
            : null;
        $locationId = (string) $data['location_id'];
        $quantity = (int) $data['quantity'];
        $resolution = (string) $data['resolution'];
        $correlationId = (string) Str::uuid();

        $inventory = null;

        if ($resolution === 'RESTOCK') {
            $inventory = $this->applyInventoryDelta(
                $businessId,
                $productId,
                $variantId,
                $locationId,
                $quantity
            );
        } elseif ($resolution === 'QUARANTINE') {
            $inventory = $this->applyQuarantineDelta(
                $businessId,
                $productId,
                $variantId,
                $locationId,
                $quantity
            );
        }

        $movementCreated = false;

        try {
            if (in_array($resolution, ['RESTOCK', 'QUARANTINE'], true)) {
                $movement = new StockMovement();
                $movement->_id = new ObjectId();
                $movement->fill([
                    'business_id' => $businessId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'location_id' => $locationId,
                    'type' => $resolution === 'RESTOCK' ? 'RETURN_IN' : 'QUARANTINE',
                    'quantity' => $quantity,
                    'reason' => $data['reason'],
                    'external_reference' => $folio,
                    'actor_id' => $data['actor_id'] ?? 'SYSTEM',
                    'correlation_id' => $correlationId,
                ]);
                $movement->save();
                $movementCreated = true;
            }

            $customerReturn = new CustomerReturn();
            $customerReturn->_id = new ObjectId();
            $customerReturn->fill([
                'business_id' => $businessId,
                'folio' => $folio,
                'customer_id' => (string) ($data['customer_id'] ?? ''),
                'customer_type' => (string) ($data['customer_type'] ?? 'ALUMNO'),
                'status' => 'RECEIVED',
                'reason' => (string) $data['reason'],
                'sale_reference' => (string) ($data['reference'] ?? ''),
                'resolution' => $resolution,
                'created_by' => (string) ($data['actor_id'] ?? 'SYSTEM'),
            ]);
            $customerReturn->save();

            return $customerReturn;
        } catch (\Throwable $exception) {
            if ($movementCreated) {
                try {
                    StockMovement::where('correlation_id', $correlationId)->delete();
                } catch (\Throwable $cleanupException) {
                    Log::critical('No fue posible eliminar el movimiento durante la compensación de devolución de cliente.', [
                        'correlation_id' => $correlationId,
                        'cleanup_error' => $cleanupException->getMessage(),
                    ]);
                }
            }

            if ($inventory !== null) {
                $this->compensateReturnInventory(
                    $inventory['_id'] ?? null,
                    $resolution,
                    $quantity,
                    $correlationId,
                    $exception
                );
            }

            throw $exception;
        }
    }

    private function applyInventoryDelta(
        string $businessId,
        string $productId,
        ?string $variantId,
        string $locationId,
        int $delta
    ): array {
        $collection = DB::connection('mongodb')->getCollection('inventories');

        $filter = [
            'business_id' => $businessId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $locationId,
        ];

        if ($delta < 0) {
            $filter['$expr'] = [
                '$and' => [
                    ['$gte' => ['$on_hand', abs($delta)]],
                    [
                        '$gte' => [
                            ['$subtract' => ['$on_hand', abs($delta)]],
                            '$reserved',
                        ],
                    ],
                ],
            ];
        }

        $updated = $collection->findOneAndUpdate(
            $filter,
            [
                '$inc' => [
                    'on_hand' => $delta,
                    'available' => $delta,
                ],
                '$set' => [
                    'updated_at' => now()->toDateTime(),
                ],
                '$setOnInsert' => [
                    'reserved' => 0,
                    'created_at' => now()->toDateTime(),
                ],
            ],
            [
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'upsert' => $delta > 0,
            ]
        );

        if ($updated === null) {
            throw new \DomainException(
                $delta < 0
                    ? 'Existencia insuficiente para devolver a proveedor.'
                    : 'No fue posible reingresar el producto.'
            );
        }

        return (array) $updated;
    }

    private function applyQuarantineDelta(
        string $businessId,
        string $productId,
        ?string $variantId,
        string $locationId,
        int $quantity
    ): array {
        $collection = DB::connection('mongodb')->getCollection('inventories');

        $updated = $collection->findOneAndUpdate(
            [
                'business_id' => $businessId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
            ],
            [
                '$inc' => [
                    'on_hand' => $quantity,
                ],
                '$set' => [
                    'updated_at' => now()->toDateTime(),
                ],
                '$setOnInsert' => [
                    'reserved' => 0,
                    'available' => 0,
                    'created_at' => now()->toDateTime(),
                ],
            ],
            [
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'upsert' => true,
            ]
        );

        return (array) $updated;
    }

    private function compensateInventory(
        $inventoryId,
        int $quantity,
        string $correlationId,
        \Throwable $originalException,
        string $kind
    ): void {
        if ($inventoryId === null) {
            throw new \RuntimeException(
                'No fue posible identificar el inventario para compensar.',
                0,
                $originalException
            );
        }

        try {
            DB::connection('mongodb')->getCollection('inventories')->findOneAndUpdate(
                ['_id' => $inventoryId],
                [
                    '$inc' => [
                        'on_hand' => $quantity,
                        'available' => $quantity,
                    ],
                    '$set' => [
                        'updated_at' => now()->toDateTime(),
                    ],
                ]
            );
        } catch (\Throwable $compensationException) {
            Log::critical('Falló la compensación de devolución.', [
                'kind' => $kind,
                'inventory_id' => (string) $inventoryId,
                'correlation_id' => $correlationId,
                'original_error' => $originalException->getMessage(),
                'compensation_error' => $compensationException->getMessage(),
            ]);

            throw new \RuntimeException(
                'Falló la devolución y también su compensación.',
                0,
                $compensationException
            );
        }
    }

    private function compensateReturnInventory(
        $inventoryId,
        string $resolution,
        int $quantity,
        string $correlationId,
        \Throwable $originalException
    ): void {
        if ($inventoryId === null) {
            return;
        }

        try {
            $inc = ['on_hand' => -$quantity];

            if ($resolution === 'RESTOCK') {
                $inc['available'] = -$quantity;
            }

            DB::connection('mongodb')->getCollection('inventories')->findOneAndUpdate(
                ['_id' => $inventoryId],
                [
                    '$inc' => $inc,
                    '$set' => [
                        'updated_at' => now()->toDateTime(),
                    ],
                ]
            );
        } catch (\Throwable $compensationException) {
            Log::critical('Falló compensación de devolución de cliente.', [
                'inventory_id' => (string) $inventoryId,
                'resolution' => $resolution,
                'correlation_id' => $correlationId,
                'original_error' => $originalException->getMessage(),
                'compensation_error' => $compensationException->getMessage(),
            ]);

            throw new \RuntimeException(
                'Falló la devolución de cliente y también su compensación.',
                0,
                $compensationException
            );
        }
    }
}
