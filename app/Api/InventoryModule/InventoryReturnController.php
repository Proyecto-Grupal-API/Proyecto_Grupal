<?php
namespace App\Api\InventoryModule;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;

class InventoryReturnController extends Controller
{

    public function __construct()
    {
    }
    use \App\Services\InventoryModule\UsesBusinessContext;

    public function receive(Request $request)
    {
        $v = $request->validate([
            'order_id'            => 'required|string|max:100',
            'refund_reference'    => 'nullable|string|max:100',
            'reason'              => 'nullable|string|max:200',
            'idempotency_key'     => 'required|string|min:8|max:128',
            'items'               => 'required|array|min:1|max:100',
            'items.*.product_id'  => 'required|string',
            'items.*.variant_id'  => 'nullable|string',
            'items.*.location_id' => 'required|string',
            'items.*.quantity'    => 'required|integer|min:1|max:100000',
        ]);

        $idem = DB::connection('inventory')->getCollection('integration_idempotency');
        $service = 'team4.returns';

        try {
            $idem->insertOne([
                '_id' => new ObjectId(), 'service' => $service,
                'idempotency_key' => $v['idempotency_key'], 'status' => 'PROCESSING',
                'unexpired_processing_at' => new UTCDateTime((time()+900)*1000),
                'created_at' => new \MongoDB\BSON\UTCDateTime(now()), 'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]);
        } catch (BulkWriteException $e) {
            $ex = (array) ($idem->findOne(['service'=>$service,'idempotency_key'=>$v['idempotency_key']]) ?? []);
            if (($ex['status'] ?? '') === 'COMPLETED' && !empty($ex['result'])) {
                return response()->json((array) $ex['result'], 200);
            }
            return response()->json(['message' => 'Idempotency_key en proceso'], 409);
        }

        $inv = DB::connection('inventory')->getCollection('inventories');
        $mov = DB::connection('inventory')->getCollection('stock_movements');
        $applied = [];

        try {
            foreach ($v['items'] as $it) {
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
                    'quantity'=>(int)$it['quantity'], 'reason'=>$v['reason'] ?? 'CUSTOMER_RETURN',
                    'external_reference'=>$v['order_id'], 'refund_reference'=>$v['refund_reference'] ?? null,
                    'correlation_id'=>(string) Str::uuid(), 'created_at'=>new \MongoDB\BSON\UTCDateTime(now()),
                ]);
                $applied[] = $it;
            }
        } catch (\Throwable $e) {
            Log::error('Team4 return failed', ['error'=>$e->getMessage()]);
            $idem->updateOne(['service'=>$service,'idempotency_key'=>$v['idempotency_key']],
                ['$set'=>['status'=>'FAILED','unexpired_processing_at'=>null,'updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]]);
            return response()->json(['message'=>'Error al procesar devolución.'], 500);
        }

        $res = ['order_id'=>$v['order_id'], 'status'=>'RETURNED', 'items'=>$applied, 'processed_at'=>now()->toIso8601String()];
        $idem->updateOne(['service'=>$service,'idempotency_key'=>$v['idempotency_key']],
            ['$set'=>['status'=>'COMPLETED','result'=>$res,'unexpired_processing_at'=>null,'updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]]);
        return response()->json($res, 201);
    }
}