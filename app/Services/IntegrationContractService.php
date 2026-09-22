<?php

namespace App\Services;

use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Operation\FindOneAndUpdate;

class IntegrationContractService
{
    public function reserveStock(array $data): StockReservation
    {
        // Corrección D: se valida el negocio antes de crear cualquier reserva,
        // evitando reservas asociadas a un business_id inválido o inexistente.
        app(BusinessService::class)->validate((string) $data['business_id']);

        $key = (string) $data['idempotency_key'];
        $service = 'team4.reserve_stock';

        $idempotency = DB::connection('mongodb')->getCollection('integration_idempotency');
        $reservations = DB::connection('mongodb')->getCollection('stock_reservations');
        $inventories = DB::connection('mongodb')->getCollection('inventories');

        $operationId = (string) Str::uuid();

        try {
            $idempotency->insertOne([
                'service' => $service,
                'idempotency_key' => $key,
                'operation_id' => $operationId,
                'status' => 'PROCESSING',
                // Corrección C: si el proceso muere antes de alcanzar un estado
                // terminal (COMPLETED/FAILED/REJECTED), este campo permite que
                // el índice TTL borre el registro huérfano después de 15
                // minutos, liberando la idempotency_key. Mongo TTL ignora
                // valores null, así que en cuanto el registro llega a un
                // estado terminal este campo se pone en null (ver más abajo)
                // y el TTL deja de aplicarle.
                'unexpired_processing_at' => now()->addMinutes(15)->toDateTime(),
                'created_at' => now()->toDateTime(),
                'updated_at' => now()->toDateTime(),
                'expires_at' => now()->addHours(24)->toDateTime(),
            ]);
        } catch (BulkWriteException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }

            $existing = $idempotency->findOne([
                'service' => $service,
                'idempotency_key' => $key,
            ]);

            if ($existing === null) {
                throw new \RuntimeException('No fue posible recuperar el estado de idempotencia.');
            }

            $existing = (array) $existing;

            if (!empty($existing['reservation_id'])) {
                $reservation = StockReservation::where(
                    'reservation_id',
                    (string) $existing['reservation_id']
                )->first();

                if ($reservation !== null) {
                    return $reservation;
                }
            }

            throw new \RuntimeException(
                'La misma idempotency_key está siendo procesada por otra solicitud. Reintente.'
            );
        }

        $quantity = (int) $data['quantity'];
        $businessId = (string) $data['business_id'];
        $productId = (string) $data['product_id'];
        $variantId = array_key_exists('variant_id', $data) && $data['variant_id'] !== null
            ? (string) $data['variant_id']
            : null;
        $locationId = (string) $data['location_id'];

        $inventoryFilter = [
            'business_id' => $businessId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $locationId,
            '$expr' => [
                '$gte' => ['$available', $quantity],
            ],
        ];

        $inventory = $inventories->findOneAndUpdate(
            $inventoryFilter,
            [
                '$inc' => [
                    'reserved' => $quantity,
                    'available' => -$quantity,
                ],
                '$set' => [
                    'updated_at' => now()->toDateTime(),
                ],
            ],
            [
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'upsert' => false,
            ]
        );

        if ($inventory === null) {
            $idempotency->updateOne(
                ['service' => $service, 'idempotency_key' => $key],
                [
                    '$set' => [
                        'status' => 'REJECTED',
                        // Corrección C: estado terminal alcanzado; se anula el
                        // campo TTL para que el índice no borre este registro.
                        'unexpired_processing_at' => null,
                        'result' => [
                            'status' => 'REJECTED',
                            'reason' => 'Existencia insuficiente.',
                        ],
                        'updated_at' => now()->toDateTime(),
                    ],
                ]
            );

            throw new \DomainException('Existencia insuficiente para reservar stock.');
        }

        $reservationId = (string) Str::ulid();

        try {
            $reservations->insertOne([
                'reservation_id' => $reservationId,
                'business_id' => $businessId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'status' => 'RESERVED',
                'source' => $data['source'],
                'idempotency_key' => $key,
                'external_reference' => $data['external_reference'] ?? (string) Str::uuid(),
                'expires_at' => \Illuminate\Support\Carbon::parse($data['expires_at'])->toDateTime(),
                'created_at' => now()->toDateTime(),
                'updated_at' => now()->toDateTime(),
            ]);

            $idempotency->updateOne(
                ['service' => $service, 'idempotency_key' => $key],
                [
                    '$set' => [
                        'status' => 'COMPLETED',
                        // Corrección C: estado terminal alcanzado; se anula el
                        // campo TTL para que el índice no borre este registro.
                        'unexpired_processing_at' => null,
                        'reservation_id' => $reservationId,
                        'result' => [
                            'reservation_id' => $reservationId,
                            'status' => 'RESERVED',
                        ],
                        'updated_at' => now()->toDateTime(),
                    ],
                ]
            );
        } catch (\Throwable $reservationException) {
            try {
                $inventories->findOneAndUpdate(
                    [
                        'business_id' => $businessId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'location_id' => $locationId,
                        '$expr' => [
                            '$gte' => ['$reserved', $quantity],
                        ],
                    ],
                    [
                        '$inc' => [
                            'reserved' => -$quantity,
                            'available' => $quantity,
                        ],
                        '$set' => [
                            'updated_at' => now()->toDateTime(),
                        ],
                    ]
                );
            } catch (\Throwable $compensationException) {
                Log::critical('Falló la compensación de una reserva de stock.', [
                    'idempotency_key' => $key,
                    'operation_id' => $operationId,
                    'quantity' => $quantity,
                    'reservation_error' => $reservationException->getMessage(),
                    'compensation_error' => $compensationException->getMessage(),
                ]);

                throw new \RuntimeException(
                    'No fue posible crear la reserva ni compensar el inventario.',
                    0,
                    $compensationException
                );
            }

            $idempotency->updateOne(
                ['service' => $service, 'idempotency_key' => $key],
                [
                    '$set' => [
                        'status' => 'FAILED',
                        // Corrección C: estado terminal alcanzado; se anula el
                        // campo TTL para que el índice no borre este registro.
                        'unexpired_processing_at' => null,
                        'result' => [
                            'status' => 'FAILED',
                            'reason' => 'No fue posible persistir la reserva.',
                        ],
                        'updated_at' => now()->toDateTime(),
                    ],
                ]
            );

            throw new \RuntimeException(
                'No fue posible persistir la reserva.',
                0,
                $reservationException
            );
        }

        return StockReservation::where('reservation_id', $reservationId)->firstOrFail();
    }

    private function isDuplicateKey(BulkWriteException $exception): bool
    {
        foreach ($exception->getWriteResult()->getWriteErrors() as $error) {
            if ($error->getCode() === 11000) {
                return true;
            }
        }

        return false;
    }
}
