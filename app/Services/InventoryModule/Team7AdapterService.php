<?php

namespace App\Services\InventoryModule;

use App\Api\InventoryModule\Team3ApiService;
use App\Exceptions\InventoryModule\ProductReferenceNotFound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

/**
 * Adaptador Equipo 7 -> motor comun de reservas (Team3ApiService).
 * Mapea nomenclatura E7 (product_reference, variant_sku, origin_type, etc.)
 * al motor ya existente. NO duplica ReservationService.
 */
class Team7AdapterService
{
    use \App\Services\InventoryModule\UsesBusinessContext;
    protected string $collection = 'team3_api_reservations';

    public function __construct(protected Team3ApiService $inner)
    {
    }

    public function availability(array $items): array
    {
        $mapped = [];
        foreach ($items as $it) {
            $pid = $this->resolveProductId($it['product_reference'] ?? $it['product_id'] ?? null);
            $mapped[] = [
                'product_id'  => $pid,
                'variant_id'  => $this->resolveVariantId($it['variant_sku'] ?? null),
                'location_id' => $it['location_id'] ?? null,
                'quantity'    => (int) ($it['quantity'] ?? 1),
            ];
        }

        $res = $this->inner->availability($mapped);

        $outItems = [];
        foreach ($res['items'] as $idx => $it) {
            $outItems[] = [
                'product_reference' => $it['product_id'],
                'variant_sku'       => $it['variant_id'],
                'requested'         => $it['requested'],
                'available'         => $it['available'],
                'has_enough'        => $it['has_enough'],
                'warehouse_id'      => $this->resolveWarehouseIdForItem($mapped[$idx]),
            ];
        }

        return [
            'available' => $res['available'],
            'items'     => $outItems,
        ];
    }

    public function reserve(array $data): array
    {
        $items = [];
        foreach ($data['items'] as $it) {
            $pid = $this->resolveProductId($it['product_reference'] ?? $it['product_id'] ?? null);
            $items[] = [
                'product_id'  => $pid,
                'variant_id'  => $this->resolveVariantId($it['variant_sku'] ?? null),
                'location_id' => $it['location_id'] ?? null,
                'quantity'    => (int) ($it['quantity'] ?? 1),
            ];
        }

        $inner = $this->inner->reserve([
            'order_id'           => $data['origin_reference'] ?? ('E7-' . Str::ulid()),
            'items'              => $items,
            'idempotency_key'    => $data['idempotency_key'],
            'source'             => $this->mapSource($data['origin_type'] ?? 'REWARD'),
            'external_reference' => $data['origin_reference'] ?? '',
            'expires_at'         => $data['expires_at'] ?? null,
            'actor_id'           => $data['actor_id'] ?? 'TEAM7',
        ]);

        DB::connection('inventory')->getCollection($this->collection)->updateOne(
            ['reservation_id' => $inner['reservation_id']],
            ['$set' => [
                'origin_system'    => 'E7',
                'origin_type'      => $data['origin_type'] ?? 'REWARD',
                'origin_reference' => $data['origin_reference'] ?? '',
            ]]
        );

        return [
            'reservation_id'   => $inner['reservation_id'],
            'status'           => 'reserved',
            'origin_reference' => $data['origin_reference'] ?? '',
            'items'            => $items,
            'expires_at'       => $inner['expires_at'],
        ];
    }

    public function confirm(string $reservationId, array $data): array
    {
        $inner = $this->inner->confirm($reservationId, [
            'idempotency_key' => $data['idempotency_key'],
            'actor_id'        => $data['delivered_by'] ?? 'TEAM7',
        ]);

        $movementId = null;
        $firstProductId = $inner['movements'][0]['product_id'] ?? null;
        if ($firstProductId) {
            $mov = DB::connection('inventory')->getCollection('stock_movements')->findOne(
                ['product_id' => $firstProductId, 'type' => 'SALE', 'business_id' => $this->businessId()],
                ['sort' => ['created_at' => -1]]
            );
            if ($mov) $movementId = (string) $mov['_id'];
        }

        return [
            'reservation_id'    => $reservationId,
            'status'            => 'consumed',
            'stock_movement_id' => $movementId,
            'confirmed_at'      => now()->toIso8601String(),
        ];
    }

    public function release(string $reservationId, array $data): array
    {
        $this->inner->release($reservationId, [
            'idempotency_key' => $data['idempotency_key'],
            'reason'          => $data['reason'] ?? 'E7_RELEASE',
        ]);

        return [
            'reservation_id' => $reservationId,
            'status'         => 'released',
            'released_at'    => now()->toIso8601String(),
        ];
    }

    public function show(string $reservationId): ?array
    {
        $doc = DB::connection('inventory')->getCollection($this->collection)
            ->findOne(['reservation_id' => $reservationId, 'business_id' => $this->businessId()]);
        if (!$doc) return null;

        $d = (array) $doc;
        $items = $d['items'] ?? [];

        // Spec E7 pide 'quantity' a nivel raiz (suma de quantities)
        $totalQty = 0;
        foreach ($items as $it) {
            $totalQty += (int) ($it['quantity'] ?? 0);
        }

        return [
            'reservation_id'   => $d['reservation_id'],
            'status'           => $this->mapStatusForE7($d['status'] ?? 'RESERVED', $d['release_reason'] ?? null),
            'origin_reference' => $d['origin_reference'] ?? ($d['external_reference'] ?? ''),
            'quantity'         => $totalQty,
            'items'            => $items,
            'expires_at'       => $d['expires_at'] ?? null,
        ];
    }

    private function mapSource(string $e7): string
    {
        return match (strtolower($e7)) {
            'reward_redemption','reward','recompensa' => 'REWARD',
            'canje','redemption'                       => 'CANJE',
            default                                    => 'REWARD',
        };
    }

    private function mapStatusForE7(string $internal, ?string $releaseReason): string
    {
        return match ($internal) {
            'RESERVED'  => 'reserved',
            'CONFIRMED' => 'consumed',
            'RELEASED'  => ($releaseReason === 'EXPIRED' ? 'expired' : 'released'),
            'REJECTED'  => 'rejected',
            default     => strtolower($internal),
        };
    }

    /**
     * Resuelve product_reference (SKU o ObjectId) a product_id.
     * Lanza ProductReferenceNotFound si el SKU no existe -> controller 404.
     */
    private function resolveProductId(?string $ref): ?string
    {
        if (!$ref) {
            throw new ProductReferenceNotFound('product_reference vacio o ausente.');
        }
        if (preg_match('/^[a-f0-9]{24}$/i', $ref)) {
            return strtolower($ref);
        }
        $p = DB::connection('inventory')->getCollection('products')
            ->findOne(['sku' => $ref, 'business_id' => $this->businessId()]);
        if (!$p) {
            throw new ProductReferenceNotFound("product_reference no encontrado: {$ref}");
        }
        return (string) $p['_id'];
    }

    private function resolveVariantId(?string $sku): ?string
    {
        if (!$sku) return null;
        if (preg_match('/^[a-f0-9]{24}$/i', $sku)) return strtolower($sku);

        $v = DB::connection('inventory')->getCollection('product_variants')
            ->findOne(['sku' => $sku, 'business_id' => $this->businessId()]);
        if (!$v) {
            throw new ProductReferenceNotFound("variant_sku no encontrado: {$sku}");
        }
        return (string) $v['_id'];
    }

    /**
     * Devuelve el warehouse_id asociado a la ubicacion principal del item.
     * Si no se puede determinar, devuelve null.
     */
    private function resolveWarehouseIdForItem(array $mapped): ?string
    {
        try {
            $inv = DB::connection('inventory')->getCollection('inventories')->findOne(
                [
                    'business_id' => $this->businessId(),
                    'product_id'  => $mapped['product_id'],
                    'variant_id'  => $mapped['variant_id'] ?? null,
                ],
                ['sort' => ['available' => -1]]
            );
            if (!$inv) return null;

            $invArr     = (array) $inv;
            $locationId = $invArr['location_id'] ?? null;
            if (!$locationId) return null;

            $loc = DB::connection('inventory')->getCollection('locations')->findOne([
                '_id' => new ObjectId((string) $locationId),
            ]);
            if (!$loc) return null;

            $locArr = (array) $loc;
            return isset($locArr['warehouse_id']) ? (string) $locArr['warehouse_id'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}