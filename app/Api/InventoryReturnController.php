<?php
namespace App\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\AuditService;
use App\Services\InventoryStatusService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;

class InventoryReturnController extends Controller
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

    public function receive(Request $request)
    {
        $v = $request->validate([
            'order_id'            => 'required|string|max:100',
            'refund_reference'    => 'nullable|string|max:100',
            'reason'              => 'nullable|string|max:200',
            'idempotency_key'     => 'required|string|min:8|max:128',
            'items'               => 'sometimes|array|min:1|max:100',
            'items.*.product_id'  => 'required_with:items|string',
            'items.*.variant_id'  => 'nullable|string',
            'items.*.location_id' => 'required_with:items|string',
            'items.*.quantity'    => 'required_with:items|integer|min:1|max:100000',
            'product_id'          => 'required_without:items|string',
            'variant_id'          => 'nullable|string',
            'location_id'         => 'required_without:items|string',
            'quantity'            => 'required_without:items|integer|min:1|max:100000',
        ]);
        $v['items'] ??= [[
            'product_id' => $v['product_id'],
            'variant_id' => $v['variant_id'] ?? null,
            'location_id' => $v['location_id'],
            'quantity' => $v['quantity'],
        ]];

        $idem = DB::connection('mongodb')->getCollection('integration_idempotency');
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

        $inv = DB::connection('mongodb')->getCollection('inventories');
        $mov = DB::connection('mongodb')->getCollection('stock_movements');
        $applied = [];

        try {
            foreach ($v['items'] as $it) {
                $inv->updateOne(
                    ['business_id'=>$this->businessId, 'product_id'=>(string)$it['product_id'],
                     'variant_id'=>$it['variant_id'] ?? null, 'location_id'=>(string)$it['location_id']],
                    ['$inc'=>['on_hand'=>(int)$it['quantity'], 'available'=>(int)$it['quantity']],
                     '$set'=>['updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]],
                    ['upsert'=>true]
                );
                $movementId = new ObjectId();
                $applied[] = [...$it, '_movement_id' => $movementId];
                $mov->insertOne([
                    '_id'=>$movementId, 'business_id'=>$this->businessId,
                    'product_id'=>(string)$it['product_id'], 'variant_id'=>$it['variant_id'] ?? null,
                    'location_id'=>(string)$it['location_id'], 'type'=>'RETURN_IN',
                    'quantity'=>(int)$it['quantity'], 'reason'=>$v['reason'] ?? 'CUSTOMER_RETURN',
                    'external_reference'=>$v['order_id'], 'refund_reference'=>$v['refund_reference'] ?? null,
                    'correlation_id'=>(string) Str::uuid(), 'created_at'=>new \MongoDB\BSON\UTCDateTime(now()),
                ]);
                app(InventoryStatusService::class)->sync(
                    $this->businessId,
                    (string) $it['product_id'],
                    isset($it['variant_id']) ? (string) $it['variant_id'] : null,
                    (string) $it['location_id']
                );
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($applied) as $item) {
                try {
                    $inv->updateOne(
                        ['business_id' => $this->businessId, 'product_id' => (string) $item['product_id'],
                         'variant_id' => $item['variant_id'] ?? null, 'location_id' => (string) $item['location_id'],
                         '$expr' => ['$and' => [
                             ['$gte' => ['$on_hand', (int) $item['quantity']]],
                             ['$gte' => ['$available', (int) $item['quantity']]],
                         ]]],
                        ['$inc' => ['on_hand' => -(int) $item['quantity'], 'available' => -(int) $item['quantity']],
                         '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
                    );
                    app(InventoryStatusService::class)->sync(
                        $this->businessId,
                        (string) $item['product_id'],
                        isset($item['variant_id']) ? (string) $item['variant_id'] : null,
                        (string) $item['location_id']
                    );
                } catch (\Throwable $compensationException) {
                    Log::critical('Team4 return compensation failed', [
                        'item' => $item,
                        'error' => $compensationException->getMessage(),
                    ]);
                }
                $mov->deleteOne(['_id' => $item['_movement_id']]);
            }
            Log::error('Team4 return failed', ['error'=>$e->getMessage()]);
            $idem->updateOne(['service'=>$service,'idempotency_key'=>$v['idempotency_key']],
                ['$set'=>['status'=>'FAILED','unexpired_processing_at'=>null,'updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]]);
            return response()->json(['message'=>'Error al procesar devolución.'], 500);
        }

        $res = [
            'order_id' => $v['order_id'],
            'status' => 'RETURNED',
            'items' => array_map(fn ($item) => array_diff_key($item, ['_movement_id' => true]), $applied),
            'processed_at' => now()->toIso8601String(),
        ];
        $idem->updateOne(['service'=>$service,'idempotency_key'=>$v['idempotency_key']],
            ['$set'=>['status'=>'COMPLETED','result'=>$res,'unexpired_processing_at'=>null,'updated_at'=>new \MongoDB\BSON\UTCDateTime(now())]]);
        app(AuditService::class)->log('inventory.returned', [
            'actor_id' => 'TEAM3',
            'business_id' => $this->businessId,
            'entity_type' => 'InventoryReturn',
            'entity_id' => $v['idempotency_key'],
            'action' => 'returned',
            'after' => $res,
        ]);
        return response()->json($res, 201);
    }
}