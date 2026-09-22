<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\Operation\FindOneAndUpdate;

class InventoryService
{
    public function changeStock(array $data): Inventory
    {
        app(BusinessService::class)->validate((string) $data['business_id']);

        $qty = (int) $data['quantity'];

        if ($qty === 0) {
            throw new \InvalidArgumentException('La cantidad no puede ser cero.');
        }

        $allowedTypes = ['RECEIPT', 'SALE', 'RETURN_IN', 'RETURN_OUT',
                         'ADJUSTMENT', 'TRANSFER_IN', 'TRANSFER_OUT',
                         'QUARANTINE', 'SHRINKAGE'];
        if (empty($data['type']) || !in_array($data['type'], $allowedTypes, true)) {
            throw new \InvalidArgumentException(
                'El tipo de movimiento es obligatorio y debe ser uno de: '
                . implode(', ', $allowedTypes)
            );
        }

        $businessId = (string) $data['business_id'];
        $productId = (string) $data['product_id'];
        $variantId = array_key_exists('variant_id', $data) && $data['variant_id'] !== null
            ? (string) $data['variant_id']
            : null;
        $locationId = (string) $data['location_id'];

        $filter = [
            'business_id' => $businessId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $locationId,
        ];

        if ($qty < 0) {
            $requestedOut = abs($qty);

            $filter['$expr'] = [
                '$and' => [
                    ['$gte' => ['$on_hand', $requestedOut]],
                    [
                        '$gte' => [
                            ['$subtract' => ['$on_hand', $requestedOut]],
                            '$reserved',
                        ],
                    ],
                ],
            ];
        }

        $collection = DB::connection('mongodb')->getCollection('inventories');

        $updated = $collection->findOneAndUpdate(
            $filter,
            [
                '$inc' => [
                    'on_hand' => $qty,
                    'available' => $qty,
                ],
                '$set' => [
                    'updated_at' => now()->toDateTime(),
                    'status' => $qty > 0 ? 'AVAILABLE' : 'OUT_OF_STOCK',
                ],
                '$setOnInsert' => [
                    'created_at' => now()->toDateTime(),
                    'reserved' => 0,
                ],
            ],
            [
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'upsert' => $qty > 0,
            ]
        );

        if ($updated === null) {
            throw new \DomainException(
                $qty < 0
                    ? 'Existencia insuficiente para realizar la salida.'
                    : 'No fue posible actualizar el inventario.'
            );
        }

        $updatedArray = (array) $updated;
        $inventoryId = $updatedArray['_id'] ?? null;

        if ($inventoryId === null) {
            throw new \RuntimeException('MongoDB no devolvió el identificador del inventario.');
        }

        $movementData = [
            ...$data,
            'business_id' => $businessId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'quantity' => $qty,
            'correlation_id' => (string) Str::uuid(),
        ];

        try {
            StockMovement::create($movementData);
        } catch (\Throwable $movementException) {
            try {
                $collection->findOneAndUpdate(
                    ['_id' => $inventoryId],
                    [
                        '$inc' => [
                            'on_hand' => -$qty,
                            'available' => -$qty,
                        ],
                        '$set' => [
                            'updated_at' => now()->toDateTime(),
                        ],
                    ]
                );
            } catch (\Throwable $compensationException) {
                Log::critical('Falló la compensación de inventario después de un error de Kardex.', [
                    'inventory_id' => (string) $inventoryId,
                    'correlation_id' => $movementData['correlation_id'],
                    'quantity' => $qty,
                    'movement_error' => $movementException->getMessage(),
                    'compensation_error' => $compensationException->getMessage(),
                ]);

                throw new \RuntimeException(
                    'No fue posible registrar el movimiento ni compensar el inventario.',
                    0,
                    $compensationException
                );
            }

            Log::error('Movimiento de inventario falló y el cambio fue compensado.', [
                'inventory_id' => (string) $inventoryId,
                'correlation_id' => $movementData['correlation_id'],
                'quantity' => $qty,
                'movement_error' => $movementException->getMessage(),
            ]);

            throw new \RuntimeException(
                'No fue posible registrar el movimiento de inventario.',
                0,
                $movementException
            );
        }

        // NUEVO: sincronizar alertas de reorden tras el cambio de stock.
        // Se aísla en try/catch para no romper la operación si algo falla.
        try {
            app(AlertGeneratorService::class)->syncOne($productId, $locationId);
        } catch (\Throwable $alertEx) {
            Log::warning('No se pudo sincronizar alertas tras cambio de stock.', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'error' => $alertEx->getMessage(),
            ]);
        }

        return Inventory::where('_id', $inventoryId)->firstOrFail();
    }
}
