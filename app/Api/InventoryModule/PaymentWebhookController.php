<?php

namespace App\Api\InventoryModule;

use App\Http\Controllers\Controller;
use App\Services\InventoryModule\UsesBusinessContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;

class PaymentWebhookController extends Controller
{
    use UsesBusinessContext;

    public function receive(Request $request)
    {
        $v = $request->validate([
            'event_id' => 'required|string|max:100',
            'event_version' => 'required|integer|min:1|max:2147483647',
            'payment_intent_id' => 'required|string|max:100',
            'refund_id' => [Rule::requiredIf(fn () => in_array($request->input('status'), ['REFUNDED', 'PARTIALLY_REFUNDED'], true)), 'nullable', 'string', 'max:100'],
            'order_id' => 'required|string|max:100',
            'business_id' => 'required|string|max:100',
            'status' => 'required|in:PENDING,PAID,FAILED,REFUNDED,PARTIALLY_REFUNDED',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|regex:/^[A-Z]{3}$/',
            'occurred_at' => 'required|date',
            'items' => 'nullable|array|max:100',
            'items.*.product_id' => 'required_with:items|string',
            'items.*.variant_id' => 'nullable|string',
            'items.*.location_id' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|integer|min:1|max:100000',
        ]);
        abort_unless($v['business_id'] === $this->businessId(), 403);
        $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
        $db = DB::connection('inventory');
        $lock = 'inventory-payment:'.hash('sha256', $v['business_id'].'|'.$v['payment_intent_id']);

        try {
            return Cache::lock($lock, 60)->block(5, fn () => $db->transaction(function () use ($db, $v, $hash) {
                $coll = $db->getCollection('payment_events');
                $opts = ['session' => $db->getSession()];
                $old = $coll->findOne(['event_id' => $v['event_id']], $opts);
                if ($old) {
                    abort_unless(($old['request_hash'] ?? '') === $hash, 409, 'event_id_conflict');
                    return response()->json(['received' => true, 'payment_event_id' => (string) $old['_id'], 'idempotent' => true], 202);
                }
                $head = $coll->findOne(['business_id' => $v['business_id'], 'payment_intent_id' => $v['payment_intent_id']],
                    $opts + ['sort' => ['event_version' => -1, 'received_at' => -1]]);
                if ($head) {
                    abort_if($v['event_version'] <= ($head['event_version'] ?? 0), 409, 'outdated_event');
                    abort_unless($head['order_id'] === $v['order_id'] && $head['currency'] === $v['currency'], 409, 'payment_context_conflict');
                }
                $refund = in_array($v['status'], ['REFUNDED', 'PARTIALLY_REFUNDED'], true);
                $id = new ObjectId;
                $coll->insertOne($v + [
                    '_id' => $id, 'request_hash' => $hash, 'source' => 'team2-webhook',
                    'received_at' => new UTCDateTime(now()),
                    'return_status' => $refund && ! empty($v['items']) ? 'PENDING' : 'NOT_APPLICABLE',
                    'return_attempts' => 0,
                ], $opts);
                return response()->json(['received' => true, 'payment_event_id' => (string) $id], 202);
            }));
        } catch (BulkWriteException $e) {
            if ($e->getCode() !== 11000) { throw $e; }
            $old = $db->getCollection('payment_events')->findOne(['event_id' => $v['event_id']]);
            if ($old && ($old['request_hash'] ?? '') === $hash) {
                return response()->json(['received' => true, 'payment_event_id' => (string) $old['_id'], 'idempotent' => true], 202);
            }
            return response()->json(['message' => 'Conflicto de evento, versión o refund_id.'], 409);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Operación en curso; reintente el mismo evento.'], 503);
        }
    }
}
