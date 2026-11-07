<?php

namespace App\Api\InventoryModule;

use App\Services\InventoryModule\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Operation\FindOneAndUpdate;

class Team3ApiService
{

    public function __construct()
    {
    }
    use \App\Services\InventoryModule\UsesBusinessContext;
    protected string $reservationsCollection = 'team3_api_reservations';

    public function availability(array $items): array
    {
        $invColl = DB::connection('inventory')->getCollection('inventories');
        $result = [];
        $allAvailable = true;

        foreach ($items as $item) {
            $productId = (string) ($item['product_id'] ?? '');
            $variantId = isset($item['variant_id']) && $item['variant_id'] !== null ? (string) $item['variant_id'] : null;
            $qty = (int) ($item['quantity'] ?? 0);

            $filter = ['business_id' => $this->businessId(), 'product_id' => $productId, 'variant_id' => $variantId];
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

        $service = 'team3.reserve:'.$this->businessId();
        $idemColl = DB::connection('inventory')->getCollection('integration_idempotency');

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
            if (($existing['status'] ?? '') === 'COMPLETED' && !empty($existing['result'])) {
                $reservation = DB::connection('inventory')->getCollection($this->reservationsCollection)->findOne([
                    'reservation_id' => $existing['result']['reservation_id'],
                    'business_id' => $this->businessId(),
                ]);
                if (! $reservation || ($reservation['origin_system'] ?? null) !== ($data['origin_system'] ?? null)
                    || $reservation['order_id'] !== ($data['order_id'] ?? '')) {
                    throw new \DomainException('La clave de idempotencia corresponde a otra orden.');
                }

                return (array) $existing['result'];
            }
            throw new \RuntimeException('La misma idempotency_key esta siendo procesada.');
        }

        $invColl = DB::connection('inventory')->getCollection('inventories');
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
                    ['business_id' => $this->businessId(), 'product_id' => $productId, 'variant_id' => $variantId,
                     'location_id' => $locationId, '$expr' => ['$gte' => ['$available', $quantity]]],
                    ['$inc' => ['reserved' => $quantity, 'available' => -$quantity],
                     '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]],
                    ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER, 'upsert' => false]
                );

                if ($updated === null) throw new \DomainException("Existencia insuficiente para producto {$productId}.");

                $applied[] = ['product_id' => $productId, 'variant_id' => $variantId, 'location_id' => $locationId, 'quantity' => $quantity];
            }
        } catch (\Throwable $e) {
            foreach ($applied as $a) {
                try {
                    $invColl->findOneAndUpdate(
                        ['business_id' => $this->businessId(), 'product_id' => $a['product_id'],
                         'variant_id' => $a['variant_id'], 'location_id' => $a['location_id'],
                         '$expr' => ['$gte' => ['$reserved', $a['quantity']]]],
                        ['$inc' => ['reserved' => -$a['quantity'], 'available' => $a['quantity']],
                         '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
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
            DB::connection('inventory')->getCollection($this->reservationsCollection)->insertOne([
                '_id' => new ObjectId(),
                'reservation_id' => $reservationId,
                'order_id' => (string) ($data['order_id'] ?? ''),
                'business_id' => $this->businessId(),
                'status' => 'RESERVED',
                'origin_system' => $data['origin_system'] ?? null,
                'origin_reference' => $data['origin_reference'] ?? null,
                'origin_type' => $data['origin_type'] ?? null,
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
                        ['business_id' => $this->businessId(), 'product_id' => $a['product_id'],
                         'variant_id' => $a['variant_id'], 'location_id' => $a['location_id'],
                         '$expr' => ['$gte' => ['$reserved', $a['quantity']]]],
                        ['$inc' => ['reserved' => -$a['quantity'], 'available' => $a['quantity']],
                         '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
                    );
                } catch (\Throwable $compEx) {}
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

        return $result;
    }

    public function confirm(string $reservationId, array $data): array
    {
        return $this->transition($reservationId, $data, 'confirm');
    }

    public function release(string $reservationId, array $data): array
    {
        return $this->transition($reservationId, $data, 'release');
    }

    public function expire(string $reservationId): bool
    {
        return $this->transition($reservationId, [], 'release', true) !== [];
    }

    private function transition(string $reservationId, array $data, string $action, bool $expire = false): array
    {
        if (! $expire && empty($data['idempotency_key'])) {
            throw new \InvalidArgumentException('idempotency_key es requerido.');
        }

        $db = DB::connection('inventory');
        $business = $this->businessId();
        $session = $db->getMongoClient()->startSession();
        $options = ['session' => $session];
        $confirm = $action === 'confirm';
        $key = $expire ? null : [
            'service' => "team3.{$action}:{$business}",
            'idempotency_key' => $data['idempotency_key'],
        ];
        $result = [];

        \MongoDB\with_transaction($session, function () use ($db, $options, $business, $reservationId, $data, $key, $confirm, $expire, &$result): void {
            $result = [];
            $resColl = $db->getCollection($this->reservationsCollection);
            $idemColl = $db->getCollection('integration_idempotency');
            $scope = ['reservation_id' => $reservationId, 'business_id' => $business];
            if (! $expire) {
                $scope['origin_system'] = ($data['origin_system'] ?? null) === 'E7' ? 'E7' : ['$ne' => 'E7'];
            }

            $reservation = $resColl->findOne($scope, $options);
            if (! $reservation) {
                throw new \InvalidArgumentException('Reserva no encontrada.');
            }
            if (isset($data['order_id']) && $data['order_id'] !== ($reservation['order_id'] ?? null)) {
                throw new \DomainException('La orden no corresponde a la reserva.');
            }

            $existing = $key === null ? null : $idemColl->findOne($key, $options);
            if (($existing['status'] ?? null) === 'COMPLETED') {
                if (($existing['result']['reservation_id'] ?? null) !== $reservationId
                    || (isset($data['payment']) && ($reservation['payment_intent_id'] ?? null) !== $data['payment']['payment_intent_id'])) {
                    throw new \DomainException('La clave de idempotencia corresponde a otra operación.');
                }
                $result = (array) $existing['result'];

                return;
            }
            if (($existing['status'] ?? null) === 'PROCESSING') {
                throw new \DomainException('La clave de idempotencia está en proceso.');
            }

            $now = new UTCDateTime(now());
            $expired = isset($reservation['expires_at']) && $reservation['expires_at'] < $now;
            if ($reservation['status'] !== 'RESERVED' || ($expire && ! $expired) || ($confirm && $expired)) {
                if ($expire) {
                    return;
                }
                throw new \DomainException('La reserva ya no está disponible para esta operación.');
            }

            $status = $confirm ? 'CONFIRMED' : 'RELEASED';
            $reason = $expire ? 'EXPIRED' : ($data['reason'] ?? 'RELEASED');
            $update = $confirm
                ? ['confirmed_at' => $now] + ($data['payment'] ?? [])
                : ['released_at' => $now, 'release_reason' => $reason];
            $resColl->updateOne($scope, ['$set' => $update + ['status' => $status, 'updated_at' => $now]], $options);

            $invColl = $db->getCollection('inventories');
            $movColl = $db->getCollection('stock_movements');
            $lines = [];
            foreach ($reservation['items'] as $item) {
                $line = [
                    'product_id' => (string) $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'location_id' => (string) $item['location_id'],
                ];
                $quantity = (int) $item['quantity'];
                $filter = $line + ['business_id' => $business, 'reserved' => ['$gte' => $quantity]];
                if ($confirm) {
                    $filter['on_hand'] = ['$gte' => $quantity];
                }

                $change = $confirm ? ['on_hand' => -$quantity] : ['available' => $quantity];
                $updated = $invColl->updateOne($filter, [
                    '$inc' => $change + ['reserved' => -$quantity],
                    '$set' => ['updated_at' => $now],
                ], $options);
                if ($updated->getMatchedCount() !== 1) {
                    throw new \DomainException("Stock reservado inconsistente para el producto {$line['product_id']}.");
                }

                if ($confirm) {
                    $movColl->insertOne($line + [
                        'business_id' => $business,
                        'type' => 'SALE',
                        'quantity' => -$quantity,
                        'reason' => 'Venta Team3 - Orden '.($reservation['order_id'] ?? ''),
                        'external_reference' => (string) ($reservation['order_id'] ?? ''),
                        'actor_id' => (string) ($data['actor_id'] ?? 'TEAM3'),
                        'correlation_id' => (string) Str::uuid(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $options);
                    $lines[] = ['product_id' => $line['product_id'], 'quantity' => -$quantity, 'type' => 'SALE'];
                } else {
                    $lines[] = $line + ['quantity' => $quantity];
                }
            }

            if ($expire && ($reservation['origin_system'] ?? null) === 'E7') {
                app(OutboxService::class)->publish('inventory.reservation.expired', [
                    'reservation_id' => $reservationId,
                    'origin_reference' => $reservation['origin_reference'] ?? ($reservation['external_reference'] ?? ''),
                    'expired_at' => $now->toDateTime()->format(DATE_ATOM),
                ], $options['session']);
            }

            $result = ['reservation_id' => $reservationId, 'status' => $status, ($confirm ? 'movements' : 'items_released') => $lines];
            if ($key !== null) {
                $idemColl->updateOne($key, [
                    '$set' => ['status' => 'COMPLETED', 'result' => $result, 'unexpired_processing_at' => null, 'updated_at' => $now],
                    '$setOnInsert' => ['created_at' => $now],
                ], $options + ['upsert' => true]);
            }
        });

        return $result;
    }

    private function resolveLocationForProduct(string $productId, ?string $variantId, ?string $explicit): ?string
    {
        if (!empty($explicit)) return (string) $explicit;

        $invColl = DB::connection('inventory')->getCollection('inventories');
        $best = $invColl->findOne(
            ['business_id' => $this->businessId(), 'product_id' => $productId, 'variant_id' => $variantId],
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
