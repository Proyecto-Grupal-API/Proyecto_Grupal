<?php

namespace App\Services;

use App\Models\StockReservation;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

class ReservationService
{
    protected string $businessId = 'BUS-CD-SOUV-001';

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
                '$set' => ['updated_at' => now()->toDateTime()],
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
                'expires_at' => \Illuminate\Support\Carbon::parse($expiresAt)->toDateTime(),
                'created_by' => 'USR-ADMIN-001',
                'created_at' => now()->toDateTime(),
                'updated_at' => now()->toDateTime(),
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
                        '$set' => ['updated_at' => now()->toDateTime()],
                    ]
                );
            } catch (\Throwable $compEx) {
                Log::critical('Fallo compensacion de reserva.', ['error' => $compEx->getMessage()]);
            }

            throw $e;
        }

        try {
            app(AlertGeneratorService::class)->syncOne($productId, $locationId);
        } catch (\Throwable $alertEx) {
            // no-op
        }

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

        // 3) Devolver stock: reserved - qty, available + qty
        DB::connection('mongodb')->getCollection('inventories')->findOneAndUpdate(
            [
                'business_id' => $this->businessId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
                '$expr' => ['$gte' => ['$reserved', $quantity]],
            ],
            [
                '$inc' => ['reserved' => -$quantity, 'available' => $quantity],
                '$set' => ['updated_at' => now()->toDateTime()],
            ]
        );

        // 4) Marcar la reserva como RELEASED
        $collection->updateOne(
            ['_id' => $docId],
            ['$set' => [
                'status' => 'RELEASED',
                'released_at' => now()->toDateTime(),
                'release_reason' => $reason,
                'updated_at' => now()->toDateTime(),
            ]]
        );

        // 5) Sincronizar alertas
        try {
            app(AlertGeneratorService::class)->syncOne($productId, $locationId);
        } catch (\Throwable $e) {
            // no-op
        }
    }

    public function expireOverdue(): int
    {
        $collection = DB::connection('mongodb')->getCollection('stock_reservations');

        // Buscar todas las RESERVED vencidas (find directo, sin Eloquent)
        $cursor = $collection->find([
            'business_id' => $this->businessId,
            'status' => 'RESERVED',
            'expires_at' => ['$lt' => now()->toDateTime()],
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
