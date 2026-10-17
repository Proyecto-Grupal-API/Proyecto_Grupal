<?php
namespace App\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class PaymentWebhookController extends Controller
{
    protected string $businessId;

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }

    public function receive(Request $request)
    {
        $v = $request->validate([
            'event_id'          => 'required|string|max:100',
            'event_version'     => 'required|integer|min:1',
            'payment_intent_id' => 'required|string|max:100',
            'refund_id'         => 'nullable|string|max:100',
            'order_id'          => 'required|string|max:100',
            'business_id'       => 'required|string|max:100',
            'status'            => 'required|in:PENDING,PAID,FAILED,REFUNDED,PARTIALLY_REFUNDED',
            'amount'            => 'required|numeric|min:0',
            'currency'          => 'required|string|size:3',
            'occurred_at'       => 'required|date',
            'items'             => 'nullable|array',
            'items.*.product_id'  => 'required_with:items|string',
            'items.*.variant_id'  => 'nullable|string',
            'items.*.location_id' => 'required_with:items|string',
            'items.*.quantity'    => 'required_with:items|integer|min:1',
        ]);

        if ($v['business_id'] !== $this->businessId) {
            return response()->json(['message' => 'business_id no autorizado.'], 403);
        }

        $coll = DB::connection('mongodb')->getCollection('payment_events');

        try {
            $doc = [
                '_id'               => new ObjectId(),
                'event_id'          => $v['event_id'],
                'event_version'     => $v['event_version'],
                'payment_intent_id' => $v['payment_intent_id'],
                'refund_id'         => $v['refund_id'] ?? null,
                'order_id'          => $v['order_id'],
                'business_id'       => $v['business_id'],
                'status'            => $v['status'],
                'amount'            => $v['amount'],
                'currency'          => $v['currency'],
                'items'             => $v['items'] ?? [],
                'occurred_at'       => $v['occurred_at'],
                'received_at'       => now()->toIso8601String(),
                'source'            => 'team2-webhook',
                'return_status'     => 'PENDING',
            ];
            $coll->insertOne($doc);
        } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
            if ($e->getCode() === 11000) {
                $existing = $coll->findOne(['event_id' => $v['event_id']]);
                if ($existing['payment_intent_id'] !== $v['payment_intent_id'] || $existing['status'] !== $v['status'] || $existing['amount'] != $v['amount']) {
                    return response()->json(['error' => 'event_id_conflict', 'message' => 'Mismo ID con datos distintos.'], 409);
                }
                return response()->json(['received' => true, 'payment_event_id' => (string)$existing['_id'], 'idempotent' => true], 202);
            }
            throw $e;
        }

        $latest = $coll->findOne(['payment_intent_id' => $v['payment_intent_id']], ['sort' => ['event_version' => -1]]);
        if ($latest && $latest['event_id'] !== $v['event_id'] && $v['event_version'] <= $latest['event_version']) {
            Log::warning('Webhook E2 fuera de orden', ['recibido' => $v['event_version'], 'actual' => $latest['event_version']]);
            return response()->json(['error' => 'outdated_event', 'message' => 'Evento ignorado por versión menor.'], 409);
        }

        if (in_array($v['status'], ['REFUNDED','PARTIALLY_REFUNDED'], true) && !empty($v['items'])) {
            $this->processReturn($v['event_id'], $v['items'], $v['order_id'], $v['payment_intent_id']);
        } else {
            $coll->updateOne(['event_id' => $v['event_id']], ['$set' => ['return_status' => 'NOT_APPLICABLE']]);
        }

        return response()->json(['received' => true, 'payment_event_id' => (string)$doc['_id']], 202);
    }

    private function processReturn(string $eventId, array $items, string $orderId, string $piId): void
    {
        $coll = DB::connection('mongodb')->getCollection('payment_events');
        $inv = DB::connection('mongodb')->getCollection('inventories');
        $mov = DB::connection('mongodb')->getCollection('stock_movements');
        
        $failed = false;
        foreach ($items as $it) {
            try {
                $exists = $inv->findOne([
                    'business_id' => $this->businessId, 
                    'product_id' => (string)$it['product_id'],
                    'location_id' => (string)$it['location_id']
                ]);

                if (!$exists) {
                    Log::error('Auto-return: Producto o ubicación no existe', ['item' => $it]);
                    $failed = true;
                    continue;
                }

                $inv->updateOne(
                    ['_id' => $exists['_id']],
                    ['$inc' => ['on_hand' => (int)$it['quantity'], 'available' => (int)$it['quantity']],
                     '$set' => ['updated_at' => new UTCDateTime(now())]]
                );
                $mov->insertOne([
                    'business_id' => $this->businessId, 
                    'product_id' => (string)$it['product_id'],
                    'variant_id' => $it['variant_id'] ?? null, 
                    'location_id' => (string)$it['location_id'], 
                    'type' => 'RETURN',
                    'quantity' => (int)$it['quantity'], 
                    'reason' => 'REFUND',
                    'external_reference' => $orderId,
                    'refund_reference' => $piId,
                    'correlation_id' => (string) Str::uuid(), 
                    'created_at' => new UTCDateTime(now()),
                ]);
            } catch (\Throwable $e) {
                Log::error('Auto-return failed', ['item' => $it, 'error' => $e->getMessage()]);
                $failed = true;
            }
        }

        $status = $failed ? 'FAILED' : 'PROCESSED';
        $coll->updateOne(['event_id' => $eventId], ['$set' => ['return_status' => $status]]);
    }
}