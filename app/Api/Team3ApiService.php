<?php

namespace App\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Services\AuditService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Operation\FindOneAndUpdate;

class Team3ApiService
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;
    protected string $reservationsCollection = 'team3_api_reservations';

    public function availability(array $items): array
    {
        $invColl = DB::connection('mongodb')->getCollection('inventories');
        $result = [];
        $allAvailable = true;

        foreach ($items as $item) {
            $productId = (string) ($item['product_id'] ?? '');
            $variantId = isset($item['variant_id']) && $item['variant_id'] !== null ? (string) $item['variant_id'] : null;
            $qty = (int) ($item['quantity'] ?? 0);

            $filter = ['business_id' => $this->businessId, 'product_id' => $productId, 'variant_id' => $variantId];
            if (!empty($item['location_id'])) $filter['location_id'] = (string) $item['location_id'];

            $agg = $invColl->aggregate([
                ['$match' => $filter],
                ['$group' => ['_id' => null, 'total' => ['$sum' => '$available']]],
            ])->toArray();

            $available = 0;
            if (!empty($agg)) $available = (int) (((array) $agg[0])['total'] ?? 0);

            $hasEnough = $available >= $qty;
            if (!$hasEnough) $allAvailable = false;

            $result[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'requested' => $qty,
                'available' => $available,
                'has_enough' => $hasEnough,
            ];
        }

        return ['available' => $allAvailable, 'items' => $result];
    }

    public function reserve(array $data): array
    {
        $idempotencyKey = (string) ($data['idempotency_key'] ?? '');
        if ($idempotencyKey === '') throw new \InvalidArgumentException('idempotency_key es requerido.');

        $items = $data['items'] ?? [];
        if (empty($items) || !is_array($items)) throw new \InvalidArgumentException('items es requerido.');

        $service = 'team3.reserve';
        $idemColl = DB::connection('mongodb')->getCollection('integration_idempotency');

        try {
            $idemColl->insertOne([
                '_id' => new ObjectId(),
                'service' => $service,
                'idempotency_key' => $idempotencyKey,
                'status' => 'PROCESSING',
                'unexpired_processing_at' => new UTCDateTime((time() + 900) * 1000),
                'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]);
        } catch (BulkWriteException $e) {
            if (!$this->isDuplicateKey($e)) throw $e;
            $existing = $idemColl->findOne(['service' => $service, 'idempotency_key' => $idempotencyKey]);
            if ($existing === null) throw new \RuntimeException('No se pudo recuperar el estado de idempotencia.');
            $existing = (array) $existing;
            if (($existing['status'] ?? '') === 'COMPLETED' && !empty($existing['result'])) return (array) $existing['result'];
            throw new \RuntimeException('La misma idempotency_key esta siendo procesada.');
        }

        $invColl = DB::connection('mongodb')->getCollection('inventories');
        $applied = [];

        try {
            foreach ($items as $item) {
                $productId = (string) ($item['product_id'] ?? '');
                $variantId = isset($item['variant_id']) && $item['variant_id'] !== null ? (string) $item['variant_id'] : null;
                $quantity = (int) ($item['quantity'] ?? 0);
                $locationId = $this->resolveLocationForProduct($productId, $variantId, $item['location_id'] ?? null);

                if ($quantity <= 0) throw new \InvalidArgumentException('Cada item debe tener quantity > 0.');
                if ($locationId === null) throw new \DomainException("Sin ubicacion disponible para producto {$productId}.");

                $updated = $invColl->findOneAndUpdate(
                    ['business_id' => $this->businessId, 'product_id' => $productId, 'variant_id' => $variantId,
                     'location_id' => $locationId, '$expr' => ['$gte' => ['$available', $quantity]]],
                    ['$inc' => ['reserved' => $quantity, 'available' => -$quantity],
                     '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]],
                    ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER, 'upsert' => false]
                );

                if ($updated === null) throw new \DomainException("Existencia insuficiente para producto {$productId}.");
                $applied[] = ['product_id' => $productId, 'variant_id' => $variantId, 'location_id' => $locationId, 'quantity' => $quantity];
                app(\App\Services\InventoryStatusService::class)->sync($this->businessId, $productId, $variantId, $locationId);
            }
        } catch (\Throwable $e) {
            foreach ($applied as $a) {
                try {
                    $invColl->findOneAndUpdate(
                        ['business_id' => $this->businessId, 'product_id' => $a['product_id'],
                         'variant_id' => $a['variant_id'], 'location_id' => $a['location_id'],
                         '$expr' => ['$gte' => ['$reserved', $a['quantity']]]],
                        ['$inc' => ['reserved' => -$a['quantity'], 'available' => $a['quantity']],
                         '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
                    );
                    app(\App\Services\InventoryStatusService::class)->sync(
                        $this->businessId,
                        $a['product_id'],
                        $a['variant_id'],
                        $a['location_id']
                    );
                } catch (\Throwable $compEx) { Log::critical('Team3 reserve compensacion fallo', ['item' => $a, 'error' => $compEx->getMessage()]); }
            }
            $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
                ['$set' => ['status' => 'REJECTED', 'result' => ['error' => $e->getMessage()],
                            'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);
            throw $e;
        }

        $reservationId = (string) Str::ulid();
        $expiresAt = isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : now()->addHours(2);

        try {
            DB::connection('mongodb')->getCollection($this->reservationsCollection)->insertOne([
                '_id' => new ObjectId(),
                'reservation_id' => $reservationId,
                'order_id' => (string) ($data['order_id'] ?? ''),
                'business_id' => $this->businessId,
                'origin_system' => (string) ($data['origin_system'] ?? 'TEAM3'),
                'status' => 'RESERVED',
                'source' => (string) ($data['source'] ?? 'CHECKOUT'),
                'idempotency_key' => $idempotencyKey,
                'items' => $applied,
                'expires_at' => new \MongoDB\BSON\UTCDateTime($expiresAt->getTimestamp() * 1000),
                'confirmed_at' => null,
                'released_at' => null,
                'rejection_reason' => null,
                'external_reference' => (string) ($data['external_reference'] ?? ''),
                'created_by' => (string) ($data['actor_id'] ?? 'TEAM3'),
                'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]);
        } catch (\Throwable $e) {
            foreach ($applied as $a) {
                try {
                    $invColl->findOneAndUpdate(
                        ['business_id' => $this->businessId, 'product_id' => $a['product_id'],
                         'variant_id' => $a['variant_id'], 'location_id' => $a['location_id'],
                         '$expr' => ['$gte' => ['$reserved', $a['quantity']]]],
                        ['$inc' => ['reserved' => -$a['quantity'], 'available' => $a['quantity']],
                         '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
                    );
                    app(\App\Services\InventoryStatusService::class)->sync(
                        $this->businessId,
                        $a['product_id'],
                        $a['variant_id'],
                        $a['location_id']
                    );
                } catch (\Throwable $compEx) {
                    Log::critical('Team3 reserve persistencia compensacion fallo', [
                        'item' => $a,
                        'error' => $compEx->getMessage(),
                    ]);
                }
            }
            throw new \RuntimeException('No se pudo persistir la reserva: ' . $e->getMessage());
        }

        $result = [
            'reservation_id' => $reservationId,
            'status' => 'RESERVED',
            'items' => $applied,
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
        ];

        $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
            ['$set' => ['status' => 'COMPLETED', 'result' => $result,
                        'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);

        app(AuditService::class)->log('stock_reservation.created', [
            'actor_id' => (string) ($data['actor_id'] ?? 'TEAM3'),
            'business_id' => $this->businessId,
            'entity_type' => 'StockReservation',
            'entity_id' => $reservationId,
            'action' => 'created',
            'after' => $result,
        ]);

        return $result;
    }

    public function confirm(string $reservationId, array $data): array
    {
        $idempotencyKey = (string) ($data['idempotency_key'] ?? '');
        if ($idempotencyKey === '') throw new \InvalidArgumentException('idempotency_key es requerido.');

        $service = 'team3.confirm';
        $idemColl = DB::connection('mongodb')->getCollection('integration_idempotency');

        try {
            $idemColl->insertOne([
                '_id' => new ObjectId(), 'service' => $service, 'idempotency_key' => $idempotencyKey,
                'status' => 'PROCESSING',
                'unexpired_processing_at' => new UTCDateTime((time() + 900) * 1000),
                'created_at' => new \MongoDB\BSON\UTCDateTime(now()), 'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]);
        } catch (BulkWriteException $e) {
            if (!$this->isDuplicateKey($e)) throw $e;
            $existing = (array) ($idemColl->findOne(['service' => $service, 'idempotency_key' => $idempotencyKey]) ?? []);
            if (($existing['status'] ?? '') === 'COMPLETED' && !empty($existing['result'])) return (array) $existing['result'];
            throw new \RuntimeException('Idempotency_key en proceso.');
        }

        $resColl = DB::connection('mongodb')->getCollection($this->reservationsCollection);
        $doc = $resColl->findOne(['reservation_id' => $reservationId, 'business_id' => $this->businessId]);

        if (!$doc) {
            $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
                ['$set' => ['status' => 'REJECTED', 'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);
            throw new \InvalidArgumentException('Reserva no encontrada.');
        }
        $reservation = (array) $doc;

        $expiresAt = $reservation['expires_at'] ?? null;
        if ($expiresAt instanceof UTCDateTime && $expiresAt->toDateTime() <= now()->toDateTime()) {
            try {
                $this->release($reservationId, [
                    'idempotency_key' => 'team3-expire-' . $reservationId . '-' . Str::ulid(),
                    'reason' => 'EXPIRED',
                ]);
            } catch (\Throwable $exception) {
                $idemColl->updateOne(
                    ['service' => $service, 'idempotency_key' => $idempotencyKey],
                    ['$set' => [
                        'status' => 'FAILED',
                        'result' => ['error' => 'No se pudo liberar la reserva vencida.'],
                        'unexpired_processing_at' => null,
                        'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                    ]]
                );
                throw $exception;
            }
            $idemColl->updateOne(
                ['service' => $service, 'idempotency_key' => $idempotencyKey],
                ['$set' => [
                    'status' => 'REJECTED',
                    'result' => ['error' => 'La reserva venció antes de confirmarse.'],
                    'unexpired_processing_at' => null,
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]]
            );
            throw new \DomainException('La reserva venció antes de confirmarse.');
        }

        if (($reservation['status'] ?? '') !== 'RESERVED') {
            $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
                ['$set' => ['status' => 'REJECTED', 'result' => ['error' => 'Estado ' . $reservation['status']],
                            'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);
            throw new \DomainException('Solo se pueden confirmar reservas RESERVED.');
        }

        $claim = $resColl->updateOne(
            ['reservation_id' => $reservationId, 'business_id' => $this->businessId, 'status' => 'RESERVED'],
            ['$set' => ['status' => 'CONFIRMING', 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
        );
        if ($claim->getMatchedCount() !== 1) {
            $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
                ['$set' => ['status' => 'REJECTED', 'result' => ['error' => 'La reserva ya está siendo procesada o cambió de estado.'],
                            'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);
            throw new \DomainException('La reserva ya está siendo procesada o cambió de estado.');
        }

        $items = $reservation['items'] ?? [];
        $invColl = DB::connection('mongodb')->getCollection('inventories');
        $movColl = DB::connection('mongodb')->getCollection('stock_movements');
        $applied = [];
        $movements = [];
        $movementIds = [];

        try {
            foreach ($items as $item) {
                $productId = (string) $item['product_id'];
                $variantId = $item['variant_id'] ?? null;
                $locationId = (string) $item['location_id'];
                $quantity = (int) $item['quantity'];

                $updated = $invColl->findOneAndUpdate(
                    ['business_id' => $this->businessId, 'product_id' => $productId,
                     'variant_id' => $variantId, 'location_id' => $locationId,
                     '$expr' => ['$and' => [['$gte' => ['$on_hand', $quantity]], ['$gte' => ['$reserved', $quantity]]]]],
                    ['$inc' => ['on_hand' => -$quantity, 'reserved' => -$quantity],
                     '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]],
                    ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
                );

                if ($updated === null) throw new \DomainException("Inconsistencia al confirmar producto {$productId}.");

                $movementId = new ObjectId();
                $movementIds[] = $movementId;
                $applied[] = $item;
                $movements[] = [
                    '_id' => $movementId,
                    'business_id' => $this->businessId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'location_id' => $locationId,
                    'type' => 'SALE',
                    'quantity' => -$quantity,
                    'reason' => 'Venta Team3 - Orden ' . ($reservation['order_id'] ?? ''),
                    'external_reference' => (string) ($reservation['order_id'] ?? ''),
                    'actor_id' => (string) ($data['actor_id'] ?? 'TEAM3'),
                    'correlation_id' => (string) Str::uuid(),
                    'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ];
                $movColl->insertOne($movements[count($movements) - 1]);

                $movements[count($movements) - 1] = ['product_id' => $productId, 'quantity' => -$quantity, 'type' => 'SALE'];
            }
            $confirmed = $resColl->updateOne(
                ['reservation_id' => $reservationId, 'business_id' => $this->businessId, 'status' => 'CONFIRMING'],
                ['$set' => ['status' => 'CONFIRMED', 'confirmed_at' => new \MongoDB\BSON\UTCDateTime(now()),
                            'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
            );
            if ($confirmed->getMatchedCount() !== 1) {
                throw new \DomainException('No fue posible confirmar el estado de la reserva.');
            }
        } catch (\Throwable $e) {
            $compensated = true;
            foreach ($applied as $a) {
                try {
                    $released = $invColl->findOneAndUpdate(
                        ['business_id' => $this->businessId, 'product_id' => $a['product_id'],
                         'variant_id' => $a['variant_id'] ?? null, 'location_id' => $a['location_id']],
                        ['$inc' => ['on_hand' => (int) $a['quantity'], 'reserved' => (int) $a['quantity']],
                         '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
                    );
                    if ($released === null) {
                        throw new \DomainException("No fue posible compensar stock para producto {$a['product_id']}.");
                    }
                    app(\App\Services\InventoryStatusService::class)->sync(
                        $this->businessId,
                        $a['product_id'],
                        $a['variant_id'] ?? null,
                        $a['location_id']
                    );
                } catch (\Throwable $ex) {
                    $compensated = false;
                    Log::critical('Team3 confirm compensation failed', [
                        'reservation_id' => $reservationId,
                        'item' => $a,
                        'error' => $ex->getMessage(),
                    ]);
                }
            }
            foreach ($movementIds as $movementId) {
                $movColl->deleteOne(['_id' => $movementId]);
            }
            $resColl->updateOne(
                ['reservation_id' => $reservationId, 'business_id' => $this->businessId, 'status' => 'CONFIRMING'],
                ['$set' => [
                    'status' => $compensated ? 'RESERVED' : 'CONFIRMATION_FAILED',
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]]
            );
            $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
                ['$set' => ['status' => 'FAILED', 'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);
            throw $e;
        }

        $result = ['reservation_id' => $reservationId, 'status' => 'CONFIRMED', 'movements' => $movements];
        $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
            ['$set' => ['status' => 'COMPLETED', 'result' => $result,
                        'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);

        app(AuditService::class)->log('stock_reservation.confirmed', [
            'actor_id' => (string) ($data['actor_id'] ?? 'TEAM3'),
            'business_id' => $this->businessId,
            'entity_type' => 'StockReservation',
            'entity_id' => $reservationId,
            'action' => 'confirmed',
            'before' => ['status' => 'RESERVED'],
            'after' => $result,
        ]);

        return $result;
    }

    public function release(string $reservationId, array $data): array
    {
        $idempotencyKey = (string) ($data['idempotency_key'] ?? '');
        if ($idempotencyKey === '') throw new \InvalidArgumentException('idempotency_key es requerido.');

        $service = 'team3.release';
        $idemColl = DB::connection('mongodb')->getCollection('integration_idempotency');

        try {
            $idemColl->insertOne([
                '_id' => new ObjectId(), 'service' => $service, 'idempotency_key' => $idempotencyKey,
                'status' => 'PROCESSING',
                'unexpired_processing_at' => new UTCDateTime((time() + 900) * 1000),
                'created_at' => new \MongoDB\BSON\UTCDateTime(now()), 'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]);
        } catch (BulkWriteException $e) {
            if (!$this->isDuplicateKey($e)) throw $e;
            $existing = (array) ($idemColl->findOne(['service' => $service, 'idempotency_key' => $idempotencyKey]) ?? []);
            if (($existing['status'] ?? '') === 'COMPLETED' && !empty($existing['result'])) return (array) $existing['result'];
            throw new \RuntimeException('Idempotency_key en proceso.');
        }

        $resColl = DB::connection('mongodb')->getCollection($this->reservationsCollection);
        $doc = $resColl->findOne(['reservation_id' => $reservationId, 'business_id' => $this->businessId]);

        if (!$doc) {
            $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
                ['$set' => ['status' => 'REJECTED', 'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);
            throw new \InvalidArgumentException('Reserva no encontrada.');
        }
        $reservation = (array) $doc;

        if (($reservation['status'] ?? '') !== 'RESERVED') {
            $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
                ['$set' => ['status' => 'REJECTED', 'result' => ['error' => 'Estado ' . $reservation['status']],
                            'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);
            throw new \DomainException('Solo se pueden liberar reservas RESERVED.');
        }

        $items = $reservation['items'] ?? [];
        $invColl = DB::connection('mongodb')->getCollection('inventories');
        $itemsReleased = [];

        $claim = $resColl->updateOne(
            ['reservation_id' => $reservationId, 'business_id' => $this->businessId, 'status' => 'RESERVED'],
            ['$set' => ['status' => 'RELEASING', 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
        );
        if ($claim->getMatchedCount() !== 1) {
            $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
                ['$set' => ['status' => 'REJECTED', 'result' => ['error' => 'La reserva ya está siendo procesada o cambió de estado.'],
                            'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);
            throw new \DomainException('La reserva ya está siendo procesada o cambió de estado.');
        }

        try {
            foreach ($items as $item) {
                $productId = (string) $item['product_id'];
                $variantId = $item['variant_id'] ?? null;
                $locationId = (string) $item['location_id'];
                $quantity = (int) $item['quantity'];

                $updated = $invColl->findOneAndUpdate(
                    ['business_id' => $this->businessId, 'product_id' => $productId,
                     'variant_id' => $variantId, 'location_id' => $locationId,
                     '$expr' => ['$gte' => ['$reserved', $quantity]]],
                    ['$inc' => ['reserved' => -$quantity, 'available' => $quantity],
                     '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
                );
                if ($updated === null) {
                    $inventoryExists = $invColl->findOne([
                        'business_id' => $this->businessId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'location_id' => $locationId,
                    ]);
                    if ($inventoryExists !== null) {
                        throw new \DomainException("No fue posible liberar stock para producto {$productId}.");
                    }

                    Log::warning('La reserva vencida no tiene un documento de inventario asociado.', [
                        'reservation_id' => $reservationId,
                        'product_id' => $productId,
                        'location_id' => $locationId,
                    ]);
                } else {
                    app(\App\Services\InventoryStatusService::class)->sync($this->businessId, $productId, $variantId, $locationId);
                }
                $itemsReleased[] = ['product_id' => $productId, 'variant_id' => $variantId, 'location_id' => $locationId, 'quantity' => $quantity];
            }

            $released = $resColl->updateOne(
                ['reservation_id' => $reservationId, 'business_id' => $this->businessId, 'status' => 'RELEASING'],
                ['$set' => ['status' => 'RELEASED', 'released_at' => new \MongoDB\BSON\UTCDateTime(now()),
                            'release_reason' => (string) ($data['reason'] ?? 'RELEASED'),
                            'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
            );
            if ($released->getMatchedCount() !== 1) {
                throw new \DomainException('No fue posible confirmar la liberación de la reserva.');
            }
        } catch (\Throwable $e) {
            $compensated = true;
            foreach ($itemsReleased as $item) {
                try {
                    $invColl->findOneAndUpdate(
                        ['business_id' => $this->businessId, 'product_id' => $item['product_id'],
                         'variant_id' => $item['variant_id'], 'location_id' => $item['location_id']],
                        ['$inc' => ['reserved' => $item['quantity'], 'available' => -$item['quantity']],
                         '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
                    );
                    app(\App\Services\InventoryStatusService::class)->sync(
                        $this->businessId,
                        $item['product_id'],
                        $item['variant_id'],
                        $item['location_id']
                    );
                } catch (\Throwable $compensationException) {
                    $compensated = false;
                    Log::critical('Team3 release compensation failed', [
                        'reservation_id' => $reservationId,
                        'item' => $item,
                        'error' => $compensationException->getMessage(),
                    ]);
                }
            }
            $resColl->updateOne(
                ['reservation_id' => $reservationId, 'business_id' => $this->businessId, 'status' => 'RELEASING'],
                ['$set' => ['status' => $compensated ? 'RESERVED' : 'RELEASE_FAILED',
                            'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
            );
            $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
                ['$set' => ['status' => 'FAILED', 'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);
            throw $e;
        }

        $reason = (string) ($data['reason'] ?? 'RELEASED');

        $result = ['reservation_id' => $reservationId, 'status' => 'RELEASED', 'items_released' => $itemsReleased];
        $idemColl->updateOne(['service' => $service, 'idempotency_key' => $idempotencyKey],
            ['$set' => ['status' => 'COMPLETED', 'result' => $result,
                        'unexpired_processing_at' => null, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]);

        app(AuditService::class)->log('stock_reservation.released', [
            'actor_id' => (string) ($data['actor_id'] ?? 'TEAM3'),
            'business_id' => $this->businessId,
            'entity_type' => 'StockReservation',
            'entity_id' => $reservationId,
            'action' => 'released',
            'before' => ['status' => 'RESERVED'],
            'after' => [...$result, 'release_reason' => $reason],
        ]);

        return $result;
    }

    private function resolveLocationForProduct(string $productId, ?string $variantId, ?string $explicit): ?string
    {
        if (!empty($explicit)) return (string) $explicit;

        $invColl = DB::connection('mongodb')->getCollection('inventories');
        $best = $invColl->findOne(
            ['business_id' => $this->businessId, 'product_id' => $productId, 'variant_id' => $variantId],
            ['sort' => ['available' => -1]]
        );

        return $best ? (string) ((array) $best)['location_id'] : null;
    }

    private function isDuplicateKey(BulkWriteException $e): bool
    {
        foreach ($e->getWriteResult()->getWriteErrors() as $err) {
            if ($err->getCode() === 11000) return true;
        }
        return false;
    }
}
