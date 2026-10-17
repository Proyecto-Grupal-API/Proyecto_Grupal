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

class InventoryTransferController extends Controller
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

    public function receive(Request $request)
    {
        $v = $request->validate([
            'from_location_id'    => 'required|string',
            'to_location_id'      => 'required|string|different:from_location_id',
            'reason'              => 'nullable|string|max:200',
            'idempotency_key'     => 'required|string|min:8|max:128',
            'items'               => 'required|array|min:1|max:100',
            'items.*.product_id'  => 'required|string',
            'items.*.variant_id'  => 'nullable|string',
            'items.*.quantity'    => 'required|integer|min:1|max:100000',
        ]);

        $idem = DB::connection('mongodb')->getCollection('integration_idempotency');
        $service = 'team4.transfers';

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
        $applied = [];

        try {
            foreach ($v['items'] as $it) {
                $pid = (string) $it['product_id'];
                $vid = $it['variant_id'] ?? null;
                $qty = (int) $it['quantity'];
                $corr = (string) Str::uuid();

                // Verificar stock en origen y decrementar
                $upd = $inv->findOneAndUpdate(
                    ['business_id'=>$this->businessId, 'product_id'=>$pid, 'variant_id'=>$vid,
                     'location_id'=>(string)$v['from_location_id'],
                     '$expr'=>['$gte'=>['$available', $qty]]],
                    ['$inc'=>['on_hand'=>-$qty, 'available'=>-$qty], '$set'=>['updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]],
                    ['returnDocument'=>\MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
                );
                if ($upd === null) throw new \DomainException("Stock insuficiente en origen para producto {$pid}.");

                // Incrementar destino (upsert)
                $inv->updateOne(
                    ['business_id'=>$this->businessId, 'product_id'=>$pid, 'variant_id'=>$vid,
                     'location_id'=>(string)$v['to_location_id']],
                    ['$inc'=>['on_hand'=>$qty, 'available'=>$qty], '$set'=>['updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]],
                    ['upsert'=>true]
                );

                // Dos movimientos
                $mov->insertMany([
                    ['_id'=>new ObjectId(), 'business_id'=>$this->businessId, 'product_id'=>$pid,
                     'variant_id'=>$vid, 'location_id'=>(string)$v['from_location_id'],
                     'type'=>'TRANSFER_OUT', 'quantity'=>-$qty, 'reason'=>$v['reason'] ?? 'INTERNAL_TRANSFER',
                     'correlation_id'=>$corr, 'created_at'=>new \MongoDB\BSON\UTCDateTime(now())],
                    ['_id'=>new ObjectId(), 'business_id'=>$this->businessId, 'product_id'=>$pid,
                     'variant_id'=>$vid, 'location_id'=>(string)$v['to_location_id'],
                     'type'=>'TRANSFER_IN', 'quantity'=>$qty, 'reason'=>$v['reason'] ?? 'INTERNAL_TRANSFER',
                     'correlation_id'=>$corr, 'created_at'=>new \MongoDB\BSON\UTCDateTime(now())],
                ]);
                $applied[] = ['product_id'=>$pid, 'variant_id'=>$vid, 'quantity'=>$qty];
            }
        } catch (\Throwable $e) {
            Log::error('Team4 transfer failed', ['error'=>$e->getMessage()]);
            $idem->updateOne(['service'=>$service,'idempotency_key'=>$v['idempotency_key']],
                ['$set'=>['status'=>'FAILED','unexpired_processing_at'=>null,'updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]]);
            return response()->json(['status'=>'REJECTED','reason'=>$e->getMessage()], 409);
        }

        $res = ['from_location_id'=>$v['from_location_id'], 'to_location_id'=>$v['to_location_id'],
                'status'=>'TRANSFERRED', 'items'=>$applied, 'processed_at'=>now()->toIso8601String()];
        $idem->updateOne(['service'=>$service,'idempotency_key'=>$v['idempotency_key']],
            ['$set'=>['status'=>'COMPLETED','result'=>$res,'unexpired_processing_at'=>null,'updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]]);
        return response()->json($res, 201);
    }
}