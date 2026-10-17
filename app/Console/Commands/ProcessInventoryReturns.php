<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class ProcessInventoryReturns extends Command
{
    protected $signature = 'inventario:procesar-devoluciones';

    protected $description = 'Procesa devoluciones físicas pendientes de eventos financieros E4 v2.';

    public function handle(): int
    {
        if (! config('team4.integration.payment_events_enabled')) {
            $this->info('Recepción financiera deshabilitada; no se procesa inventario.');

            return self::SUCCESS;
        }
        $db = DB::connection('inventory');
        $coll = $db->getCollection('payment_events');
        $rows = $coll->find(['return_status' => ['$in' => ['PENDING', 'FAILED']], 'event_version' => ['$exists' => true],
            '$or' => [['next_attempt_at' => null], ['next_attempt_at' => ['$lte' => new UTCDateTime(now())]]]], ['limit' => 100, 'sort' => ['_id' => 1]]);
        $failed = 0;
        foreach ($rows as $row) {
            $lock = 'inventory-payment:'.hash('sha256', $row['business_id'].'|'.$row['payment_intent_id']);
            try {
                Cache::lock($lock, 60)->block(5, fn () => $db->transaction(function () use ($db, $coll, $row) {
                    $opts = ['session' => $db->getSession()];
                    $event = $coll->findOne(['_id' => $row['_id']], $opts);
                    if (! in_array($event['return_status'] ?? '', ['PENDING', 'FAILED'], true)) {
                        return;
                    }
                    if (! in_array($event['status'], ['REFUNDED', 'PARTIALLY_REFUNDED'], true) || empty($event['refund_id']) || empty($event['items'])) {
                        throw new \DomainException('Evento sin devolución física válida.');
                    }
                    $inventory = $db->getCollection('inventories');
                    foreach ($event['items'] as $item) {
                        $filter = ['business_id' => $event['business_id'], 'product_id' => $item['product_id'],
                            'variant_id' => $item['variant_id'] ?? null, 'location_id' => $item['location_id']];
                        $validProduct = $db->getCollection('products')->findOne(['_id' => new ObjectId($item['product_id']), 'business_id' => $event['business_id']], $opts);
                        $validLocation = $db->getCollection('locations')->findOne(['_id' => new ObjectId($item['location_id']), 'business_id' => $event['business_id']], $opts);
                        if (! $validProduct || ! $validLocation || ! $inventory->findOne($filter, $opts)) {
                            throw new \DomainException('Producto, variante o ubicación inexistente en el negocio.');
                        }
                        $inventory->updateOne($filter, ['$inc' => ['on_hand' => (int) $item['quantity'], 'available' => (int) $item['quantity']],
                            '$set' => ['updated_at' => new UTCDateTime(now())]], $opts);
                        $db->getCollection('stock_movements')->insertOne([
                            '_id' => new ObjectId, ...$filter, 'type' => 'RETURN', 'quantity' => (int) $item['quantity'],
                            'reason' => 'REFUND', 'external_reference' => $event['order_id'], 'refund_reference' => $event['refund_id'],
                            'correlation_id' => $event['event_id'], 'actor_id' => 'EQUIPO2', 'created_at' => new UTCDateTime(now()),
                        ], $opts);
                    }
                    $coll->updateOne(['_id' => $event['_id']], ['$set' => ['return_status' => 'PROCESSED',
                        'return_processed_at' => new UTCDateTime(now()), 'return_last_error' => null], '$inc' => ['return_attempts' => 1]], $opts);
                }));
            } catch (\Throwable $e) {
                $failed++;
                $coll->updateOne(['_id' => $row['_id'], 'return_status' => ['$ne' => 'PROCESSED']], ['$set' => [
                    'return_status' => 'FAILED', 'return_last_error' => class_basename($e),
                    'next_attempt_at' => new UTCDateTime(now()->addMinutes(5)),
                ], '$inc' => ['return_attempts' => 1]]);
                Log::warning('Inventario: devolución pendiente de recuperación.', ['event_id' => $row['event_id'], 'error' => class_basename($e)]);
            }
        }
        $this->info('Devoluciones revisadas; fallos: '.$failed.'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
