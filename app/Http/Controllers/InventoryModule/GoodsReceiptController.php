<?php

namespace App\Http\Controllers\InventoryModule;

use App\Http\Controllers\Controller;

use App\Models\InventoryModule\GoodsReceipt;
use App\Models\InventoryModule\GoodsReceiptItem;
use App\Models\InventoryModule\PurchaseOrder;
use App\Models\InventoryModule\PurchaseOrderItem;
use App\Models\InventoryModule\Location;
use App\Services\InventoryModule\InventoryService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;

class GoodsReceiptController extends Controller
{

    public function __construct()
    {
    }
    use \App\Services\InventoryModule\UsesBusinessContext;

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = 25;

        $query = GoodsReceipt::where('business_id', $this->businessId());

        if ($q !== '') {
            $regex = '/' . preg_quote($q, '/') . '/i';
            $query->where('folio', 'regex', $regex);
        }

        $receipts = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $ordersMap = PurchaseOrder::where('business_id', $this->businessId())
            ->get()
            ->keyBy(fn($o) => (string) $o->_id);

        $receiptsData = collect($receipts->items())->map(function ($r) use ($ordersMap) {
            $attrs = $r->getAttributes();
            $order = $ordersMap->get((string) ($attrs['purchase_order_id'] ?? ''));

            $receivedAt = null;
            $rawDate = $attrs['received_at'] ?? null;
            if ($rawDate instanceof \DateTimeInterface) {
                $receivedAt = $rawDate->format(\DateTimeInterface::ATOM);
            } elseif (is_string($rawDate)) {
                try { $receivedAt = \Illuminate\Support\Carbon::parse($rawDate)->toIso8601String(); } catch (\Throwable $e) { $receivedAt = null; }
            }

            return [
                '_id' => (string) ($c->_id ?? $w->_id ?? $s->_id ?? $p->_id ?? $i->_id ?? $r->_id ?? $a->_id ?? $o->_id ?? $u->_id ?? ''),
                'folio' => (string) ($attrs['folio'] ?? ''),
                'purchase_order_id' => (string) ($attrs['purchase_order_id'] ?? ''),
                'purchase_order_folio' => $order ? (string) $order->folio : 'Desconocida',
                'received_by' => (string) ($attrs['received_by'] ?? 'SYSTEM'),
                'status' => (string) ($attrs['status'] ?? ''),
                'notes' => (string) ($attrs['notes'] ?? ''),
                'received_at' => $receivedAt,
            ];
        })->values()->all();

        $receivableOrders = PurchaseOrder::where('business_id', $this->businessId())
            ->where('status', 'AUTORIZADA')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($o) => [
                '_id' => (string) $o->_id,
                'folio' => (string) $o->folio,
                'supplier_id' => (string) $o->supplier_id,
            ])
            ->values()->all();

        // KPIs
        $monthStart = now()->startOfMonth()->toDateTime();
        $weekAgo = now()->subDays(7)->toDateTime();

        $totalReceipts = GoodsReceipt::where('business_id', $this->businessId())->count();
        $receiptsMonth = GoodsReceipt::where('business_id', $this->businessId())->where('created_at', '>=', $monthStart)->count();
        $receiptsWeek = GoodsReceipt::where('business_id', $this->businessId())->where('created_at', '>=', $weekAgo)->count();
        $pendingOrders = PurchaseOrder::where('business_id', $this->businessId())->where('status', 'AUTORIZADA')->count();

        $kpis = [
            ['label' => 'Total recepciones', 'value' => $totalReceipts],
            ['label' => 'Pendientes por recibir', 'value' => $pendingOrders, 'color' => $pendingOrders > 0 ? 'warning' : 'default'],
            ['label' => 'Este mes', 'value' => $receiptsMonth],
            ['label' => 'Últimos 7 días', 'value' => $receiptsWeek, 'color' => 'success'],
        ];

        return Inertia::render('Equipo4/Recepciones', [
            'receipts' => $receiptsData,
            'receivableOrders' => $receivableOrders,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'per_page' => $receipts->perPage(),
                'total' => $receipts->total(),
                'from' => $receipts->firstItem() ?? 0,
                'to' => $receipts->lastItem() ?? 0,
            ],
            'filters' => ['q' => $q],
        ]);
    }

    public function orderDetails(string $orderId)
    {
        $order = PurchaseOrder::where('_id', $orderId)->where('business_id', $this->businessId())->first();
        if (!$order) return response()->json(['error' => 'OC no encontrada'], 404);

        $items = PurchaseOrderItem::where('purchase_order_id', $orderId)
            ->orderBy('line', 'asc')
            ->get()
            ->map(fn($i) => [
                'purchase_order_item_id' => (string) $i->_id,
                'product_id' => (string) $i->product_id,
                'product_sku' => (string) $i->product_sku,
                'product_name' => (string) $i->product_name,
                'quantity' => max(0,(int)$i->quantity - (int) GoodsReceiptItem::where('business_id',$this->businessId())->where('purchase_order_item_id',(string)$i->getKey())->sum('quantity')),
                'ordered_quantity' => (int)$i->quantity,
                'unit_cost' => (float) $i->unit_cost,
            ])->filter(fn ($line)=>$line['quantity']>0)->values()->all();

        return response()->json([
            '_id' => (string) $order->_id,
            'folio' => (string) $order->folio,
            'items' => $items,
        ]);
    }

    public function store(Request $request, InventoryService $inventoryService, \App\Services\InventoryModule\CostService $costService): RedirectResponse
    {
        $key='inventory-receipt:'.hash('sha256', $this->businessId().'|'.(string)$request->input('purchase_order_id'));
        $lock=\Illuminate\Support\Facades\Cache::lock($key, 60);
        if (!$lock->get()) { throw \Illuminate\Validation\ValidationException::withMessages(['items'=>'Esta orden ya está recibiéndose. Intenta nuevamente.']); }
        try { return $this->receive($request,$inventoryService,$costService); }
        finally { $lock->release(); }
    }

    private function receive(Request $request, InventoryService $inventoryService, \App\Services\InventoryModule\CostService $costService): RedirectResponse
    {
        $validated = $request->validate([
            'purchase_order_id' => 'required|string',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|string|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
        ]);

        $order = PurchaseOrder::where('_id', $validated['purchase_order_id'])
            ->where('business_id', $this->businessId())
            ->first();

        if (!$order) return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'Orden de compra no encontrada.']);
        if ($order->status !== 'AUTORIZADA') return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'Solo se pueden recibir OCs en estado AUTORIZADA.']);

        foreach ($validated['items'] as $index=>$item) {
            $line=PurchaseOrderItem::where('_id',$item['purchase_order_item_id'])
                ->where('business_id',$this->businessId())->where('purchase_order_id',(string)$order->getKey())->first();
            $received=$line ? (int) GoodsReceiptItem::where('business_id',$this->businessId())
                ->where('purchase_order_item_id',(string)$line->getKey())->sum('quantity') : 0;
            if (!$line || $item['quantity'] > (int)$line->quantity-$received) {
                throw \Illuminate\Validation\ValidationException::withMessages(['items.'.$index.'.quantity'=>'La cantidad excede el pendiente de la orden o la partida no pertenece a ella.']);
            }
        }

        $defaultLocationId = $this->getDefaultLocationId();
        if (empty($defaultLocationId)) return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'No hay ubicaciones configuradas.']);

        $folio = 'REC-' . str_pad((string) (GoodsReceipt::where('business_id', $this->businessId())->count() + 1), 5, '0', STR_PAD_LEFT);

        $receipt = null;
        $createdReceiptItemIds = [];
        $stockMovementsDone = [];

        try {
            $receipt = new GoodsReceipt();
            $receipt->_id = new ObjectId();
            $receipt->fill([
                'business_id' => $this->businessId(),
                'purchase_order_id' => (string) $order->_id,
                'folio' => $folio,
                'received_by' => \App\Services\InventoryModule\ActorResolver::resolve(),
                'status' => 'COMPLETADA',
                'notes' => $validated['notes'] ?? '',
                'received_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]);
            $receipt->save();

            foreach ($validated['items'] as $item) {
                $orderItem = PurchaseOrderItem::where('_id', $item['purchase_order_item_id'])
                    ->where('purchase_order_id', (string) $order->_id)
                    ->first();

                if (!$orderItem) continue;

                $qty = (int) $item['quantity'];

                $receiptItem = new GoodsReceiptItem();
                $receiptItem->_id = new ObjectId();
                $receiptItem->fill([
                    'business_id' => $this->businessId(),
                    'goods_receipt_id' => (string) $receipt->_id,
                    'purchase_order_item_id' => (string) $orderItem->_id,
                    'product_id' => (string) $orderItem->product_id,
                    'product_sku' => (string) $orderItem->product_sku,
                    'quantity' => $qty,
                    'unit_cost' => (float) $orderItem->unit_cost,
                ]);
                $receiptItem->save();
                $createdReceiptItemIds[] = (string) $receiptItem->_id;

                $inventoryService->changeStock([
                    'business_id' => $this->businessId(),
                    'product_id' => (string) $orderItem->product_id,
                    'variant_id' => null,
                    'location_id' => $defaultLocationId,
                    'quantity' => $qty,
                    'type' => 'RECEIPT',
                    'reason' => 'Recepcion OC ' . $order->folio,
                    'external_reference' => $folio,
                    'actor_id' => \App\Services\InventoryModule\ActorResolver::resolve(),
                ]);

                // Auto-registrar costo
                try {
                    $costService->register([
                        'product_id' => (string) $orderItem->product_id,
                        'cost' => (float) $orderItem->unit_cost,
                        'source' => 'RECEIPT',
                        'reference' => $folio,
                        'notes' => 'Recepcion OC ' . $order->folio,
                        'suggested_price' => null,
                        'actor_id' => \App\Services\InventoryModule\ActorResolver::resolve(),
                    ]);
                } catch (\Throwable $costEx) {
                    Log::warning('No se pudo registrar el costo automatico.', ['error' => $costEx->getMessage()]);
                }

                $stockMovementsDone[] = ['product_id' => (string) $orderItem->product_id, 'quantity' => $qty];
            }

            $complete=PurchaseOrderItem::where('business_id',$this->businessId())->where('purchase_order_id',(string)$order->getKey())->get()
                ->every(fn ($line)=>(int) GoodsReceiptItem::where('business_id',$this->businessId())
                    ->where('purchase_order_item_id',(string)$line->getKey())->sum('quantity') >= (int)$line->quantity);
            $order->status = $complete ? 'RECIBIDA_TOTAL' : 'AUTORIZADA';
            $order->save();

        } catch (\Throwable $e) {
            Log::error('Fallo al registrar recepcion.', ['folio' => $folio, 'error' => $e->getMessage()]);

            foreach ($stockMovementsDone as $sm) {
                try {
                    $inventoryService->changeStock([
                        'business_id' => $this->businessId(),
                        'product_id' => $sm['product_id'],
                        'variant_id' => null,
                        'location_id' => $defaultLocationId,
                        'quantity' => -$sm['quantity'],
                        'type' => 'ADJUSTMENT',
                        'reason' => 'Reversion recepcion fallida ' . $folio,
                        'external_reference' => $folio,
                        'actor_id' => 'SYSTEM',
                    ]);
                } catch (\Throwable $ex) {
                    Log::critical('Fallo compensacion de stock.', ['error' => $ex->getMessage()]);
                }
            }
            if (!empty($createdReceiptItemIds)) { try { GoodsReceiptItem::whereIn('_id', $createdReceiptItemIds)->delete(); } catch (\Throwable $ex) {} }
            if ($receipt !== null && $receipt->_id) { try { $receipt->delete(); } catch (\Throwable $ex) {} }

            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'No se pudo registrar la recepción: ' . $e->getMessage()]);
        }

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Recepción ' . $folio . ' registrada.');
    }

    private function getDefaultLocationId(): string
    {
        $location = Location::where('business_id', $this->businessId())->first();
        return $location ? (string) $location->_id : '';
    }

    public function destroy(string $id): RedirectResponse
    {
        $receipt = GoodsReceipt::where('_id', $id)->where('business_id', $this->businessId())->first();
        if (!$receipt) abort(404, 'Recepción no encontrada.');

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors([
            'error' => 'Las recepciones no se pueden eliminar porque ya afectaron el stock.'
        ]);
    }
}
