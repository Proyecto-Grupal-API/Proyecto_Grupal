<?php
namespace App\Api\InventoryModule;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

class PaymentWebhookController extends Controller
{

    public function __construct()
    {
    }
    use \App\Services\InventoryModule\UsesBusinessContext;

    public function receive(Request $request)
    {
        $v = $request->validate([
            'payment_intent_id' => 'required|string|max:100',
            'order_id'          => 'required|string|max:100',
            'business_id'       => 'required|string|max:100',
            'status'            => 'required|in:PENDING,PAID,FAILED,REFUNDED,PARTIALLY_REFUNDED',
            'amount'            => 'nullable|numeric|min:0',
            'currency'          => 'nullable|string|size:3',
            'components'        => 'nullable|array',
            'items'             => 'nullable|array',
            'items.*.product_id'  => 'required_with:items|string',
            'items.*.variant_id'  => 'nullable|string',
            'items.*.location_id' => 'required_with:items|string',
            'items.*.quantity'    => 'required_with:items|integer|min:1',
            'occurred_at'       => 'nullable|date',
        ]);

        // Validar business_id contra el configurado. Si no coincide, se rechaza.
        if ($v['business_id'] !== $this->businessId()) {
            Log::warning('Webhook E2 con business_id no autorizado', [
                'received' => $v['business_id'],
                'expected' => $this->businessId(),
                'pi'       => $v['payment_intent_id'] ?? null,
            ]);
            return response()->json([
                'message'  => 'business_id no corresponde al negocio configurado.',
                'expected' => $this->businessId(),
            ], 403);
        }

        $coll = DB::connection('inventory')->getCollection('payment_events');

        $exists = $coll->findOne([
            'payment_intent_id' => $v['payment_intent_id'],
            'status'            => $v['status'],
            'amount'            => $v['amount'] ?? null,
        ]);

        if ($exists) {
            return response()->json([
                'received'=>true, 'payment_event_id'=>(string)$exists['_id'], 'idempotent'=>true,
            ], 202);
        }

        $doc = [
            '_id'=>new ObjectId(), 'payment_intent_id'=>$v['payment_intent_id'],
            'order_id'=>$v['order_id'], 'business_id'=>$v['business_id'] ?? $this->businessId(),
            'status'=>$v['status'], 'amount'=>$v['amount'] ?? null, 'currency'=>$v['currency'] ?? 'MXN',
            'components'=>$v['components'] ?? [], 'items'=>$v['items'] ?? [],
            'raw_payload'=>$v, 'occurred_at'=>$v['occurred_at'] ?? now()->toIso8601String(),
            'received_at'=>now()->toIso8601String(), 'source'=>'team2-webhook',
        ];
        $coll->insertOne($doc);

        // Si es REFUNDED y trae items, generar entradas de stock automaticamente
        if (in_array($v['status'], ['REFUNDED','PARTIALLY_REFUNDED'], true) && !empty($v['items'])) {
            $inv = DB::connection('inventory')->getCollection('inventories');
            $mov = DB::connection('inventory')->getCollection('stock_movements');
            foreach ($v['items'] as $it) {
                try {
                    $inv->updateOne(
                        ['business_id'=>$this->businessId(), 'product_id'=>(string)$it['product_id'],
                         'variant_id'=>$it['variant_id'] ?? null, 'location_id'=>(string)$it['location_id']],
                        ['$inc'=>['on_hand'=>(int)$it['quantity'], 'available'=>(int)$it['quantity']],
                         '$set'=>['updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]],
                        ['upsert'=>true]
                    );
                    $mov->insertOne([
                        '_id'=>new ObjectId(), 'business_id'=>$this->businessId(),
                        'product_id'=>(string)$it['product_id'], 'variant_id'=>$it['variant_id'] ?? null,
                        'location_id'=>(string)$it['location_id'], 'type'=>'RETURN',
                        'quantity'=>(int)$it['quantity'], 'reason'=>'REFUND',
                        'external_reference'=>$v['order_id'],
                        'refund_reference'=>$v['payment_intent_id'],
                        'correlation_id'=>(string) Str::uuid(), 'created_at'=>new \MongoDB\BSON\UTCDateTime(now()),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Auto-return failed', ['item'=>$it, 'error'=>$e->getMessage()]);
                }
            }
        }

        Log::info('Payment webhook', ['pi'=>$v['payment_intent_id'], 'status'=>$v['status']]);
        return response()->json(['received'=>true, 'payment_event_id'=>(string)$doc['_id']], 202);
    }
}