<?php

namespace App\Services;

use App\Models\StockReservation;
use App\Models\Inventory;
use App\Models\Product;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

class ReservationService
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

    public function reserve(array $data): StockReservation
    {
        app(BusinessService::class)->validate($this->businessId);

        $productId = (string) $data['product_id'];
        $variantId = isset($data['variant_id']) && $data['variant_id'] !== null ? (string) $data['variant_id'] : null;
        $locationId = (string) $data['location_id'];
        $quantity = (int) $data['quantity'];
        $source = (string) ($data['source'] ?? 'MANUAL');
        $externalReference = (string) ($data['external_reference'] ?? '');
        $expiresAt = $data['expires_at'] ?? now()->addHours(24);

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('La cantidad debe ser mayor a cero.');
        }

        $collection = DB::connection('mongodb')->getCollection('inventories');

        $updated = $collection->findOneAndUpdate(
            [
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
                '$expr' => ['$gte' => ['$available', $quantity]],
            ],
            [
                '$inc' => [
                    'reserved' => $quantity,
                    'available' => -$quantity,
                ],
                '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())],
            ],
            [
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'upsert' => false,
            ]
        );

        if ($updated === null) {
            throw new \DomainException('Existencia insuficiente para reservar.');
        }
        $reservationId = (string) Str::ulid();
        $reservationObjectId = new ObjectId();

        try {
            app(InventoryStatusService::class)->sync($this->businessId, $productId, $variantId, $locationId);
            DB::connection('mongodb')->getCollection('stock_reservations')->insertOne([
                '_id' => $reservationObjectId,
                'reservation_id' => $reservationId,
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'status' => 'RESERVED',
                'source' => $source,
                'external_reference' => $externalReference,
                'expires_at' => new \MongoDB\BSON\UTCDateTime(\Illuminate\Support\Carbon::parse($expiresAt)),
                'created_by' => ActorResolver::resolve(),
                'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]);
        } catch (\Throwable $e) {
            try {
                $collection->findOneAndUpdate(
                    [
                        'business_id' => $this->businessId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'location_id' => $locationId,
                        '$expr' => ['$gte' => ['$reserved', $quantity]],
                    ],
                    [
                        '$inc' => ['reserved' => -$quantity, 'available' => $quantity],
                        '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())],
                    ]
                );
                app(InventoryStatusService::class)->sync($this->businessId, $productId, $variantId, $locationId);
            } catch (\Throwable $compEx) {
                Log::critical('Fallo compensacion de reserva.', ['error' => $compEx->getMessage()]);
            }

            throw $e;
        }

        try {
            app(AlertGeneratorService::class)->syncOne($productId, $locationId);
        } catch (\Throwable $alertEx) {
            Log::warning('No se pudieron sincronizar alertas después de reservar existencias.', [
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'location_id' => $locationId,
                'error' => $alertEx->getMessage(),
            ]);
        }

        app(AuditService::class)->log('stock_reservation.created', [
            'actor_id' => ActorResolver::resolve(),
            'business_id' => $this->businessId,
            'entity_type' => 'StockReservation',
            'entity_id' => (string) $reservationObjectId,
            'action' => 'created',
            'after' => [
                'reservation_id' => $reservationId,
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'expires_at' => $expiresAt,
            ],
        ]);

        return StockReservation::where('_id', (string) $reservationObjectId)->firstOrFail();
    }

    /**
     * Libera una reserva DIRECTAMENTE con la coleccion Mongo (sin Eloquent).
     * Acepta _id (24 hex) o reservation_id (ULID).
     */
    public function release(string $identifier, string $reason = 'MANUAL'): void
    {
        $collection = DB::connection('mongodb')->getCollection('stock_reservations');

        // 1) Buscar el documento: por _id o por reservation_id
        $doc = null;

        // Si parece ObjectId hex de 24 chars
        if (preg_match('/^[a-f0-9]{24}$/i', $identifier)) {
            try {
                $doc = $collection->findOne([
                    '_id' => new ObjectId($identifier),
                    'business_id' => $this->businessId,
                ]);
            } catch (\Throwable $e) {
                $doc = null;
            }
        }

        // Fallback: por reservation_id (ULID)
        if (!$doc) {
            $doc = $collection->findOne([
                'reservation_id' => $identifier,
                'business_id' => $this->businessId,
            ]);
        }

        if (!$doc) {
            throw new \InvalidArgumentException('Reserva no encontrada. Identificador: ' . $identifier);
        }

        // 2) Leer propiedades del documento BSON (acceso por array, sin Eloquent)
        $status = (string) ($doc['status'] ?? '');
        $quantity = (int) ($doc['quantity'] ?? 0);
        $productId = (string) ($doc['product_id'] ?? '');
        $variantId = $doc['variant_id'] ?? null;
        $locationId = (string) ($doc['location_id'] ?? '');
        $docId = $doc['_id'];

        if ($status !== 'RESERVED') {
            throw new \DomainException('Solo se pueden liberar reservas activas. Estado actual: ' . $status);
        }

        $claimed = $collection->updateOne(
            ['_id' => $docId, 'business_id' => $this->businessId, 'status' => 'RESERVED'],
            ['$set' => ['status' => 'RELEASING', 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
        );
        if ($claimed->getMatchedCount() !== 1) {
            throw new \DomainException('La reserva ya está siendo procesada o cambió de estado.');
        }

        $inventoryCollection = DB::connection('mongodb')->getCollection('inventories');
        $inventoryUpdated = null;
        try {
            $inventoryUpdated = $inventoryCollection->findOneAndUpdate(
                [
                    'business_id' => $this->businessId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'location_id' => $locationId,
                    '$expr' => ['$gte' => ['$reserved', $quantity]],
                ],
                [
                    '$inc' => ['reserved' => -$quantity, 'available' => $quantity],
                    '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())],
                ]
            );
            if ($inventoryUpdated === null) {
                throw new \DomainException('No fue posible liberar la existencia reservada.');
            }
            app(InventoryStatusService::class)->sync(
                $this->businessId,
                $productId,
                $variantId === null ? null : (string) $variantId,
                $locationId
            );

            $released = $collection->updateOne(
                ['_id' => $docId, 'business_id' => $this->businessId, 'status' => 'RELEASING'],
                ['$set' => [
                    'status' => 'RELEASED',
                    'released_at' => new \MongoDB\BSON\UTCDateTime(now()),
                    'release_reason' => $reason,
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]]
            );
            if ($released->getMatchedCount() !== 1) {
                throw new \RuntimeException('No fue posible confirmar la liberación de la reserva.');
            }
        } catch (\Throwable $exception) {
            $compensated = true;
            if ($inventoryUpdated !== null) {
                try {
                    $inventoryCollection->findOneAndUpdate(
                        [
                            'business_id' => $this->businessId,
                            'product_id' => $productId,
                            'variant_id' => $variantId,
                            'location_id' => $locationId,
                        ],
                        [
                            '$inc' => ['reserved' => $quantity, 'available' => -$quantity],
                            '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())],
                        ]
                    );
                    app(InventoryStatusService::class)->sync(
                        $this->businessId,
                        $productId,
                        $variantId === null ? null : (string) $variantId,
                        $locationId
                    );
                } catch (\Throwable $compensationException) {
                    $compensated = false;
                    Log::critical('Falló la compensación de liberación de reserva.', [
                        'reservation_id' => $identifier,
                        'error' => $compensationException->getMessage(),
                    ]);
                }
            }

            $collection->updateOne(
                ['_id' => $docId, 'business_id' => $this->businessId, 'status' => 'RELEASING'],
                ['$set' => [
                    'status' => $compensated ? 'RESERVED' : 'RELEASE_FAILED',
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]]
            );
            throw $exception;
        }

        // 5) Sincronizar alertas
        try {
            app(AlertGeneratorService::class)->syncOne($productId, $locationId);
        } catch (\Throwable $e) {
            Log::warning('No se pudieron sincronizar alertas después de liberar una reserva.', [
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'location_id' => $locationId,
                'error' => $e->getMessage(),
            ]);
        }

        app(AuditService::class)->log('stock_reservation.released', [
            'actor_id' => ActorResolver::resolve(),
            'business_id' => $this->businessId,
            'entity_type' => 'StockReservation',
            'entity_id' => (string) $docId,
            'action' => 'released',
            'before' => ['status' => 'RESERVED'],
            'after' => ['status' => 'RELEASED', 'release_reason' => $reason, 'quantity' => $quantity],
        ]);
    }

    public function expireOverdue(): int
    {
        $collection = DB::connection('mongodb')->getCollection('stock_reservations');

        // Buscar todas las RESERVED vencidas (find directo, sin Eloquent)
        $cursor = $collection->find([
            'business_id' => $this->businessId,
            'status' => 'RESERVED',
            'expires_at' => ['$lt' => new \MongoDB\BSON\UTCDateTime(now())],
        ]);

        $count = 0;
        foreach ($cursor as $doc) {
            try {
                $attrs = (array) $doc;
                $rawId = $attrs['_id'] ?? null;
                $idString = null;

                if ($rawId instanceof ObjectId) {
                    $idString = (string) $rawId;
                } elseif (is_string($rawId)) {
                    $idString = $rawId;
                } elseif (is_object($rawId) && method_exists($rawId, '__toString')) {
                    $idString = (string) $rawId;
                }

                if (!$idString) {
                    Log::warning('Reserva sin _id, se omite.', ['reservation_id' => $attrs['reservation_id'] ?? 'N/A']);
                    continue;
                }

                $this->release($idString, 'EXPIRED');
                $count++;
            } catch (\Throwable $e) {
                Log::warning('Fallo al expirar reserva.', [
                    'reservation_id' => $attrs['reservation_id'] ?? 'N/A',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
