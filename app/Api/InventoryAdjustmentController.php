<?php
namespace App\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;

class InventoryAdjustmentController extends Controller
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

    public function receive(Request $request)
    {
        $v = $request->validate([
            'product_id'        => 'required|string',
            'variant_id'        => 'nullable|string',
            'location_id'       => 'required|string',
            'new_quantity'      => 'nullable|integer|min:0',
            'delta'             => 'nullable|integer',
            'reason'            => 'required|string|max:200',
            'idempotency_key'   => 'required|string|min:8|max:128',
            'actor_id'          => 'nullable|string|max:100',
        ]);

        if (!isset($v['new_quantity']) && !isset($v['delta'])) {
            return response()->json(['message'=>'Debes enviar new_quantity o delta'], 422);
        }

        $idem = DB::connection('mongodb')->getCollection('integration_idempotency');
        $service = 'team4.adjustments';

        try {
            $idem->insertOne([
                '_id'=>new ObjectId(), 'service'=>$service,
                'idempotency_key'=>$v['idempotency_key'], 'status'=>'PROCESSING',
                'unexpired_processing_at'=>new UTCDateTime((time()+900)*1000),
                'created_at'=>new \MongoDB\BSON\UTCDateTime(now()), 'updated_at'=>new \MongoDB\BSON\UTCDateTime(now()),
            ]);
        } catch (BulkWriteException $e) {
            $ex = (array) ($idem->findOne(['service'=>$service,'idempotency_key'=>$v['idempotency_key']]) ?? []);
            if (($ex['status'] ?? '') === 'COMPLETED' && !empty($ex['result'])) {
                return response()->json((array) $ex['result'], 200);
            }
            return response()->json(['message' => 'Idempotency_key en proceso'], 409);
        }

        $inv = DB::connection('mongodb')->getCollection('inventories');
        $mov = DB::connection('mongodb')->getCollection('stock_movements');

        $filter = ['business_id'=>$this->businessId, 'product_id'=>(string)$v['product_id'],
                   'variant_id'=>$v['variant_id'] ?? null, 'location_id'=>(string)$v['location_id']];

        $current = $inv->findOne($filter);
        $currentQty = $current ? (int)((array)$current)['on_hand'] : 0;

        $newQty = isset($v['new_quantity']) ? (int)$v['new_quantity'] : ($currentQty + (int)$v['delta']);
        $actualDelta = $newQty - $currentQty;

        try {
            $inv->updateOne(
                $filter,
                ['$set'=>['on_hand'=>$newQty, 'available'=>$newQty, 'updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]],
                ['upsert'=>true]
            );
            $mov->insertOne([
                '_id'=>new ObjectId(), 'business_id'=>$this->businessId,
                'product_id'=>(string)$v['product_id'], 'variant_id'=>$v['variant_id'] ?? null,
                'location_id'=>(string)$v['location_id'], 'type'=>'ADJUSTMENT',
                'quantity'=>$actualDelta, 'reason'=>$v['reason'],
                'actor_id'=>$v['actor_id'] ?? 'API', 'correlation_id'=>(string) Str::uuid(),
                'created_at'=>new \MongoDB\BSON\UTCDateTime(now()),
            ]);
        } catch (\Throwable $e) {
            Log::error('Team4 adjustment failed', ['error'=>$e->getMessage()]);
            $idem->updateOne(['service'=>$service,'idempotency_key'=>$v['idempotency_key']],
                ['$set'=>['status'=>'FAILED','unexpired_processing_at'=>null,'updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]]);
            return response()->json(['message'=>'Error al ajustar inventario.'], 500);
        }

        $res = ['product_id'=>$v['product_id'], 'location_id'=>$v['location_id'],
                'previous_qty'=>$currentQty, 'new_qty'=>$newQty, 'delta'=>$actualDelta,
                'reason'=>$v['reason'], 'processed_at'=>now()->toIso8601String()];
        $idem->updateOne(['service'=>$service,'idempotency_key'=>$v['idempotency_key']],
            ['$set'=>['status'=>'COMPLETED','result'=>$res,'unexpired_processing_at'=>null,'updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]]);
        return response()->json($res, 201);
    }
}