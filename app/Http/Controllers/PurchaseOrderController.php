<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Models\Product;
use App\Models\GoodsReceipt;
use App\Services\InventoryService;
use App\Services\AuditService;
use App\Services\IdentityService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;

class PurchaseOrderController extends Controller
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $showHistory = $request->boolean('history', false);
        $perPage = 25;

        // Suppliers map
        $suppliersMap = [];
        foreach (DB::connection('mongodb')->getCollection('suppliers')->find(['business_id' => $this->businessId]) as $s) {
            $doc = (array) $s;
            $suppliersMap[(string) ($doc['_id'] ?? '')] = (string) ($doc['legal_name'] ?? 'Desconocido');
        }

        $filter = ['business_id' => $this->businessId];
        if (!$showHistory) {
            $filter['status'] = ['$in' => ['BORRADOR', 'SOLICITADA', 'AUTORIZADA', 'RECIBIDA_PARCIAL']];
        }
        if ($q !== '') {
            $filter['folio'] = ['$regex' => $q, '$options' => 'i'];
        }

        $cursor = DB::connection('mongodb')->getCollection('purchase_orders')->find($filter, ['sort' => ['created_at' => -1]]);
        $all = [];
        foreach ($cursor as $o) {
            $all[] = (array) $o;
        }

        $total = count($all);
        $page = max(1, (int) $request->query('page', 1));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $from = ($page - 1) * $perPage;
        $items = array_slice($all, $from, $perPage);

        $ordersData = [];
        foreach ($items as $o) {
            $sid = (string) ($o['supplier_id'] ?? '');
            $expectedAt = null;
            $raw = $o['expected_at'] ?? null;
            if ($raw instanceof \DateTimeInterface) {
                $expectedAt = $raw->format('Y-m-d');
            } elseif ($raw instanceof \MongoDB\BSON\UTCDateTime) {
                $expectedAt = $raw->toDateTime()->format('Y-m-d');
            } elseif (is_string($raw)) {
                try { $expectedAt = \Illuminate\Support\Carbon::parse($raw)->format('Y-m-d'); } catch (\Throwable $e) {}
            }

            $ordersData[] = [
                '_id' => (string) ($o['_id'] ?? ''),
                'folio' => (string) ($o['folio'] ?? ''),
                'supplier_id' => $sid,
                'supplier_name' => $suppliersMap[$sid] ?? 'Desconocido',
                'status' => (string) ($o['status'] ?? ''),
                'expected_at' => $expectedAt,
                'notes' => (string) ($o['notes'] ?? ''),
                'total_estimated' => (float) ($o['total_estimated'] ?? 0),
                'items_count' => (int) ($o['items_count'] ?? 0),
            ];
        }

                // Dropdowns
        $suppliersList = [];
        $suppliersCollection = DB::connection('mongodb')->getCollection('suppliers');
        foreach ($suppliersCollection->find(['business_id' => $this->businessId]) as $s) {
            $doc = (array) $s;
            $suppliersList[] = [
                '_id' => (string) ($doc['_id'] ?? ''),
                'legal_name' => (string) ($doc['legal_name'] ?? 'Desconocido'),
                'code' => (string) ($doc['code'] ?? ''),
                'supplied_products' => (array) ($doc['supplied_products'] ?? []),
            ];
        }

        $productsList = [];
        foreach (DB::connection('mongodb')->getCollection('products')->find(['business_id' => $this->businessId, 'active' => true], ['sort' => ['name' => 1]]) as $p) {
            $doc = (array) $p;
            $productsList[] = [
                '_id' => (string) ($doc['_id'] ?? ''),
                'sku' => (string) ($doc['sku'] ?? ''),
                'name' => (string) ($doc['name'] ?? ''),
            ];
        }

        // KPIs
        $baseFilter = ['business_id' => $this->businessId];
        $totalActive = DB::connection('mongodb')->getCollection('purchase_orders')->countDocuments(array_merge($baseFilter, ['status' => ['$in' => ['BORRADOR', 'SOLICITADA', 'AUTORIZADA', 'RECIBIDA_PARCIAL']]]));
        $borradores = DB::connection('mongodb')->getCollection('purchase_orders')->countDocuments(array_merge($baseFilter, ['status' => 'BORRADOR']));
        $solicitadas = DB::connection('mongodb')->getCollection('purchase_orders')->countDocuments(array_merge($baseFilter, ['status' => 'SOLICITADA']));
        $autorizadas = DB::connection('mongodb')->getCollection('purchase_orders')->countDocuments(array_merge($baseFilter, ['status' => 'AUTORIZADA']));

        $kpis = [
            ['label' => 'OCs activas', 'value' => $totalActive],
            ['label' => 'Borradores', 'value' => $borradores],
            ['label' => 'Solicitadas', 'value' => $solicitadas, 'color' => 'warning'],
            ['label' => 'Autorizadas', 'value' => $autorizadas, 'color' => 'success'],
        ];

        return Inertia::render('Equipo4/Compras', [
            'orders' => $ordersData,
            'suppliersList' => $suppliersList,
            'productsList' => $productsList,
            'showHistory' => $showHistory,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? $from + 1 : 0,
                'to' => min($from + $perPage, $total),
            ],
            'filters' => ['q' => $q, 'history' => $showHistory ? '1' : ''],
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $items = $validated['items'];
        unset($validated['items']);

        $count = DB::connection('mongodb')->getCollection('purchase_orders')->countDocuments(['business_id' => $this->businessId]);
        $folio = 'OC-' . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
        $total = collect($items)->sum(fn($i) => $i['quantity'] * $i['unit_cost']);

        $orderId = new ObjectId();

        try {
            DB::connection('mongodb')->getCollection('purchase_orders')->insertOne([
                '_id' => $orderId,
                'business_id' => $this->businessId,
                'supplier_id' => (string) ($validated['supplier_id'] ?? ''),
                'folio' => $folio,
                'status' => (string) ($validated['status'] ?? 'SOLICITADA'),
                'requested_by' => app(IdentityService::class)->resolveCurrentUserId(),
                'authorized_by' => null,
                'expected_at' => new \MongoDB\BSON\UTCDateTime(\Illuminate\Support\Carbon::parse($validated['expected_at'])),
                'notes' => (string) ($validated['notes'] ?? ''),
                'total_estimated' => (float) $total,
                'items_count' => count($items),
                'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]);

            foreach ($items as $index => $item) {
                $pDoc = DB::connection('mongodb')->getCollection('products')->findOne([
                    '_id' => new ObjectId($item['product_id']),
                    'business_id' => $this->businessId,
                ]);
                $p = $pDoc ? (array) $pDoc : [];
                DB::connection('mongodb')->getCollection('purchase_order_items')->insertOne([
                    '_id' => new ObjectId(),
                    'business_id' => $this->businessId,
                    'purchase_order_id' => (string) $orderId,
                    'line' => $index + 1,
                    'product_id' => (string) $item['product_id'],
                    'product_sku' => (string) ($p['sku'] ?? ''),
                    'product_name' => (string) ($p['name'] ?? ''),
                    'quantity' => (int) $item['quantity'],
                    'unit_cost' => (float) $item['unit_cost'],
                    'subtotal' => (float) ($item['quantity'] * $item['unit_cost']),
                    'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Fallo al crear OC.', ['error' => $e->getMessage()]);
            DB::connection('mongodb')->getCollection('purchase_order_items')->deleteMany(['purchase_order_id' => (string) $orderId]);
            DB::connection('mongodb')->getCollection('purchase_orders')->deleteOne(['_id' => $orderId]);
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'No se pudo crear: ' . $e->getMessage()]);
        }

        $this->auditPurchaseOrder('created', (string) $orderId, [
            'folio' => $folio,
            'status' => (string) ($validated['status'] ?? 'SOLICITADA'),
            'supplier_id' => (string) ($validated['supplier_id'] ?? ''),
            'items_count' => count($items),
        ]);

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'OC ' . $folio . ' creada.');
    }

    public function update(UpdatePurchaseOrderRequest $request, string $id): RedirectResponse
    {
        $orderDoc = DB::connection('mongodb')->getCollection('purchase_orders')->findOne([
            '_id' => new ObjectId($id),
            'business_id' => $this->businessId,
        ]);
        if (!$orderDoc) return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'OC no encontrada.']);
        $o = (array) $orderDoc;
        $currentStatus = (string) ($o['status'] ?? '');
        if (!in_array($currentStatus, ['BORRADOR', 'SOLICITADA'], true)) {
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'No se puede editar en estado ' . $currentStatus . '.']);
        }

        $validated = $request->validated();
        if ($validated['status'] !== $currentStatus) {
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'El estado solo puede cambiarse mediante las acciones de autorización o cancelación.']);
        }
        $items = $validated['items'];
        unset($validated['items']);
        $total = collect($items)->sum(fn($i) => $i['quantity'] * $i['unit_cost']);

        try {
            DB::connection('mongodb')->getCollection('purchase_orders')->updateOne(
                ['_id' => new ObjectId($id), 'business_id' => $this->businessId],
                ['$set' => [
                    'supplier_id' => (string) $validated['supplier_id'],
                    'expected_at' => new \MongoDB\BSON\UTCDateTime(\Illuminate\Support\Carbon::parse($validated['expected_at'])),
                    'notes' => (string) ($validated['notes'] ?? ''),
                    'status' => (string) ($validated['status'] ?? $o['status']),
                    'total_estimated' => (float) $total,
                    'items_count' => count($items),
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]]
            );

            DB::connection('mongodb')->getCollection('purchase_order_items')->deleteMany([
                'purchase_order_id' => $id,
                'business_id' => $this->businessId,
            ]);

            foreach ($items as $index => $item) {
                $pDoc = DB::connection('mongodb')->getCollection('products')->findOne([
                    '_id' => new ObjectId($item['product_id']),
                    'business_id' => $this->businessId,
                ]);
                $p = $pDoc ? (array) $pDoc : [];
                DB::connection('mongodb')->getCollection('purchase_order_items')->insertOne([
                    '_id' => new ObjectId(),
                    'business_id' => $this->businessId,
                    'purchase_order_id' => $id,
                    'line' => $index + 1,
                    'product_id' => (string) $item['product_id'],
                    'product_sku' => (string) ($p['sku'] ?? ''),
                    'product_name' => (string) ($p['name'] ?? ''),
                    'quantity' => (int) $item['quantity'],
                    'unit_cost' => (float) $item['unit_cost'],
                    'subtotal' => (float) ($item['quantity'] * $item['unit_cost']),
                    'created_at' => new \MongoDB\BSON\UTCDateTime(now()),
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Fallo al actualizar OC.', ['error' => $e->getMessage()]);
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'No se pudo actualizar: ' . $e->getMessage()]);
        }

        $this->auditPurchaseOrder('updated', $id, [
            'folio' => (string) ($o['folio'] ?? ''),
            'status' => $currentStatus,
            'supplier_id' => (string) $validated['supplier_id'],
            'items_count' => count($items),
        ], $o);

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'OC actualizada.');
    }

    public function changeStatus(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate(['action' => 'required|string|in:autorizar,cancelar']);
        $userId = (string) $request->attributes->get('team4_user_id')
            ?: app(IdentityService::class)->resolveCurrentUserId();
        $requiredPermission = $validated['action'] === 'autorizar'
            ? 'compras.authorize'
            : 'compras.cancel';
        abort_unless(app(IdentityService::class)->userCan($userId, $requiredPermission), 403);

        $orderDoc = DB::connection('mongodb')->getCollection('purchase_orders')->findOne([
            '_id' => new ObjectId($id),
            'business_id' => $this->businessId,
        ]);
        if (!$orderDoc) return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'OC no encontrada.']);
        $o = (array) $orderDoc;
        $currentStatus = (string) ($o['status'] ?? '');

        if (in_array($currentStatus, ['RECIBIDA_TOTAL', 'CANCELADA'])) {
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'La OC ya está en estado ' . $currentStatus . '.']);
        }

        $action = $validated['action'];

        if ($action === 'autorizar') {
            if (!in_array($currentStatus, ['BORRADOR', 'SOLICITADA'])) {
                return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'Solo se autorizan OCs en Borrador o Solicitada.']);
            }
            $actorId = app(IdentityService::class)->resolveCurrentUserId();
            DB::connection('mongodb')->getCollection('purchase_orders')->updateOne(
                ['_id' => new ObjectId($id), 'business_id' => $this->businessId],
                ['$set' => ['status' => 'AUTORIZADA', 'authorized_by' => $actorId, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
            );
            $this->auditPurchaseOrder('authorized', $id, ['status' => 'AUTORIZADA'], $o);
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'OC autorizada.');
        }

        if ($action === 'cancelar') {
            $hasReceipts = DB::connection('mongodb')->getCollection('goods_receipts')->countDocuments(['purchase_order_id' => $id]) > 0;
            if ($hasReceipts) {
                return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'No se puede cancelar: tiene recepciones.']);
            }
            DB::connection('mongodb')->getCollection('purchase_orders')->updateOne(
                ['_id' => new ObjectId($id), 'business_id' => $this->businessId],
                ['$set' => ['status' => 'CANCELADA', 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
            );
            $this->auditPurchaseOrder('cancelled', $id, ['status' => 'CANCELADA'], $o);
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'OC cancelada.');
        }

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH));
    }

    public function show(string $id)
    {
        $orderDoc = DB::connection('mongodb')->getCollection('purchase_orders')->findOne([
            '_id' => new ObjectId($id),
            'business_id' => $this->businessId,
        ]);
        if (!$orderDoc) return response()->json(['error' => 'OC no encontrada'], 404);
        $o = (array) $orderDoc;

        $items = [];
        $receiptIds = [];
        foreach (DB::connection('mongodb')->getCollection('goods_receipts')->find([
            'purchase_order_id' => $id,
            'business_id' => $this->businessId,
        ], ['projection' => ['_id' => 1]]) as $receipt) {
            $receiptIds[] = (string) ((array) $receipt)['_id'];
        }

        $receivedByLine = [];
        if ($receiptIds !== []) {
            foreach (DB::connection('mongodb')->getCollection('goods_receipt_items')->find([
                'goods_receipt_id' => ['$in' => $receiptIds],
                'business_id' => $this->businessId,
            ]) as $receiptItem) {
                $received = (array) $receiptItem;
                $itemId = (string) ($received['purchase_order_item_id'] ?? '');
                $receivedByLine[$itemId] = ($receivedByLine[$itemId] ?? 0) + (int) ($received['quantity'] ?? 0);
            }
        }

        foreach (DB::connection('mongodb')->getCollection('purchase_order_items')->find([
            'purchase_order_id' => $id,
            'business_id' => $this->businessId,
        ], ['sort' => ['line' => 1]]) as $i) {
            $doc = (array) $i;
            $itemId = (string) ($doc['_id'] ?? '');
            $ordered = (int) ($doc['quantity'] ?? 0);
            $received = $receivedByLine[$itemId] ?? 0;
            $items[] = [
                'purchase_order_item_id' => $itemId,
                'product_id' => (string) ($doc['product_id'] ?? ''),
                'product_sku' => (string) ($doc['product_sku'] ?? ''),
                'product_name' => (string) ($doc['product_name'] ?? ''),
                'quantity' => $ordered,
                'received_quantity' => $received,
                'remaining_quantity' => max(0, $ordered - $received),
                'unit_cost' => (float) ($doc['unit_cost'] ?? 0),
                'subtotal' => (float) ($doc['subtotal'] ?? 0),
            ];
        }

        $expectedAt = null;
        $raw = $o['expected_at'] ?? null;
        if ($raw instanceof \DateTimeInterface) $expectedAt = $raw->format('Y-m-d');
        elseif ($raw instanceof \MongoDB\BSON\UTCDateTime) $expectedAt = $raw->toDateTime()->format('Y-m-d');
        elseif (is_string($raw)) { try { $expectedAt = \Illuminate\Support\Carbon::parse($raw)->format('Y-m-d'); } catch (\Throwable $e) {} }

        return response()->json([
            '_id' => (string) ($o['_id'] ?? ''),
            'folio' => (string) ($o['folio'] ?? ''),
            'supplier_id' => (string) ($o['supplier_id'] ?? ''),
            'status' => (string) ($o['status'] ?? ''),
            'expected_at' => $expectedAt,
            'notes' => (string) ($o['notes'] ?? ''),
            'total_estimated' => (float) ($o['total_estimated'] ?? 0),
            'items' => $items,
        ]);
    }

    public function destroy(string $id): RedirectResponse
    {
        $orderDoc = DB::connection('mongodb')->getCollection('purchase_orders')->findOne([
            '_id' => new ObjectId($id),
            'business_id' => $this->businessId,
        ]);
        if (!$orderDoc) return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'OC no encontrada.']);

        $hasReceipts = DB::connection('mongodb')->getCollection('goods_receipts')->countDocuments(['purchase_order_id' => $id]) > 0;
        if ($hasReceipts) {
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'No se puede eliminar: tiene recepciones.']);
        }

        DB::connection('mongodb')->getCollection('purchase_order_items')->deleteMany([
            'purchase_order_id' => $id,
            'business_id' => $this->businessId,
        ]);
        DB::connection('mongodb')->getCollection('purchase_orders')->deleteOne([
            '_id' => new ObjectId($id),
            'business_id' => $this->businessId,
        ]);
        $this->auditPurchaseOrder('deleted', $id, null, (array) $orderDoc);

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'OC eliminada.');
    }

    private function auditPurchaseOrder(string $action, string $id, ?array $after, ?array $before = null): void
    {
        app(AuditService::class)->log('purchase_order.' . $action, [
            'actor_id' => app(IdentityService::class)->resolveCurrentUserId(),
            'business_id' => $this->businessId,
            'entity_type' => 'PurchaseOrder',
            'entity_id' => $id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
