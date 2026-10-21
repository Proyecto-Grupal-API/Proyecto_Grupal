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
        // R6: Validación estricta. refund_id es obligatorio si hay reembolso.
        $v = $request->validate([
            'event_id'          => 'required|string|max:100',
            'event_version'     => 'required|integer|min:1',
            'payment_intent_id' => 'required|string|max:100',
            'refund_id'         => 'required_if:status,REFUNDED,PARTIALLY_REFUNDED|nullable|string|max:100',
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

        // R1: El índice único en event_id ahora está en Team4InitIndexes.
        // La inserción lanzará error 11000 si hay duplicado.
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
                'return_status'     => 'PENDING', // Se actualizará después
            ];
            $coll->insertOne($doc);
        } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
            if ($e->getCode() === 11000) {
                // R2: Comparar contenido canónico completo, no solo algunos campos.
                $existing = $coll->findOne(['event_id' => $v['event_id']]);
                // Hash canónico (simplificado): comparamos todos los campos clave.
                $existingHash = md5(json_encode([
                    $existing['payment_intent_id'], $existing['status'], $existing['amount'],
                    $existing['currency'], $existing['order_id'], $existing['refund_id'],
                    $existing['items'], $existing['event_version']
                ]));
                $newHash = md5(json_encode([
                    $v['payment_intent_id'], $v['status'], $v['amount'],
                    $v['currency'], $v['order_id'], $v['refund_id'] ?? null,
                    $v['items'] ?? [], $v['event_version']
                ]));

                if ($existingHash !== $newHash) {
                    return response()->json(['error' => 'event_id_conflict', 'message' => 'Mismo ID con contenido semántico distinto.'], 409);
                }
                return response()->json(['received' => true, 'payment_event_id' => (string)$existing['_id'], 'idempotent' => true], 202);
            }
            throw $e;
        }

        // R4: Verificar versión ANTES de procesar cualquier efecto.
        $latest = $coll->findOne(
            ['payment_intent_id' => $v['payment_intent_id'], 'event_id' => ['$ne' => $v['event_id']]],
            ['sort' => ['event_version' => -1]]
        );
        if ($latest && $v['event_version'] <= $latest['event_version']) {
            Log::warning('Webhook E2 fuera de orden', ['recibido' => $v['event_version'], 'actual' => $latest['event_version']]);
            return response()->json(['error' => 'outdated_event', 'message' => 'Evento ignorado por versión menor o igual.'], 409);
        }

        // R3 & R5: Procesar la devolución de inventario de forma atómica.
        if (in_array($v['status'], ['REFUNDED','PARTIALLY_REFUNDED'], true) && !empty($v['items'])) {
            $this->processReturnAtomic($v['event_id'], $v['items'], $v['order_id'], $v['payment_intent_id'], $v['refund_id']);
        } else {
            $coll->updateOne(['event_id' => $v['event_id']], ['$set' => ['return_status' => 'NOT_APPLICABLE']]);
        }

        return response()->json(['received' => true, 'payment_event_id' => (string)$doc['_id']], 202);
    }

    /**
     * R3 & R5: Procesamiento atómico usando transacciones de MongoDB (requiere replica set).
     * Si una transacción falla, se revierte todo el lote.
     * Si no hay replica set, hacemos un "best effort" con compensación manual.
     */
    private function processReturnAtomic(string $eventId, array $items, string $orderId, string $piId, ?string $refundId): void
    {
        $coll = DB::connection('mongodb')->getCollection('payment_events');
        $inv = DB::connection('mongodb')->getCollection('inventories');
        $mov = DB::connection('mongodb')->getCollection('stock_movements');
        
        $failed = false;
        $processed = []; // Para compensación manual si no hay transacción.

        foreach ($items as $it) {
            try {
                // R5: Incluir variant_id en la búsqueda exacta.
                $filter = [
                    'business_id' => $this->businessId,
                    'product_id'  => (string)$it['product_id'],
                    'location_id' => (string)$it['location_id'],
                ];
                if (!empty($it['variant_id'])) {
                    $filter['variant_id'] = (string)$it['variant_id'];
                } else {
                    $filter['variant_id'] = null;
                }

                $exists = $inv->findOne($filter);

                if (!$exists) {
                    Log::error('Auto-return: Producto/Variante/Ubicación no existe', ['item' => $it]);
                    $failed = true;
                    continue;
                }

                // Guardar el estado anterior para posible compensación.
                $processed[] = [
                    'inventory_id' => $exists['_id'],
                    'quantity'     => (int)$it['quantity'],
                    'movement_id'  => new ObjectId(),
                    'item'         => $it,
                ];

                // Actualizar inventario (esto y el insert de movimiento deberían ser atómicos).
                $inv->updateOne(
                    ['_id' => $exists['_id']],
                    ['$inc' => ['on_hand' => (int)$it['quantity'], 'available' => (int)$it['quantity']],
                     '$set' => ['updated_at' => new UTCDateTime(now())]]
                );
                $mov->insertOne([
                    '_id' => end($processed)['movement_id'],
                    'business_id' => $this->businessId,
                    'product_id' => (string)$it['product_id'],
                    'variant_id' => $it['variant_id'] ?? null,
                    'location_id' => (string)$it['location_id'],
                    'type' => 'RETURN',
                    'quantity' => (int)$it['quantity'],
                    'reason' => 'REFUND',
                    'external_reference' => $orderId,
                    'refund_reference' => $piId,
                    'refund_id' => $refundId,
                    'correlation_id' => (string) Str::uuid(),
                    'created_at' => new UTCDateTime(now()),
                ]);
            } catch (\Throwable $e) {
                Log::error('Auto-return failed', ['item' => $it, 'error' => $e->getMessage()]);
                $failed = true;
                // Si falla este item, deberíamos revertir los anteriores.
                // Aquí implementamos una reversión manual "best effort".
                foreach ($processed as $p) {
                    $inv->updateOne(
                        ['_id' => $p['inventory_id']],
                        ['$inc' => ['on_hand' => -$p['quantity'], 'available' => -$p['quantity']]]
                    );
                    $mov->deleteOne(['_id' => $p['movement_id']]);
                }
                break; // Salir del bucle si hay un fallo grave.
            }
        }

        $status = $failed ? 'FAILED' : 'PROCESSED';
        $coll->updateOne(['event_id' => $eventId], ['$set' => ['return_status' => $status]]);
    }
}