<?php

namespace App\Http\Controllers;

use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Location;
use App\Services\InventoryService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;

class StockCountController extends Controller
{
    protected string $businessId = 'BUS-CD-SOUV-001';

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = 25;

        $query = StockCount::where('business_id', $this->businessId);
        if ($q !== '') {
            $regex = '/' . preg_quote($q, '/') . '/i';
            $query->where('folio', 'regex', $regex);
        }
        $counts = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $warehousesMap = Warehouse::where('business_id', $this->businessId)
            ->get()
            ->keyBy(fn($w) => (string) $w->_id);

        $data = collect($counts->items())->map(function ($c) use ($warehousesMap) {
            $attrs = $c->getAttributes();
            $wh = $warehousesMap->get((string) ($attrs['warehouse_id'] ?? ''));
            return [
                '_id' => (string) ($attrs['_id'] ?? ''),
                'folio' => (string) ($attrs['folio'] ?? ''),
                'warehouse_id' => (string) ($attrs['warehouse_id'] ?? ''),
                'warehouse_name' => $wh ? (string) $wh->name : '—',
                'status' => (string) ($attrs['status'] ?? ''),
                'started_by' => (string) ($attrs['started_by'] ?? '—'),
                'differences_count' => (int) ($attrs['differences_count'] ?? 0),
                'notes' => (string) ($attrs['notes'] ?? ''),
            ];
        })->values()->all();

        $warehousesList = $warehousesMap->map(fn($w) => [
            '_id' => (string) $w->_id,
            'name' => (string) $w->name,
            'code' => (string) $w->code,
        ])->values()->all();

        // KPIs
        $coll = DB::connection('mongodb')->getCollection('stock_counts');
        $total = $coll->countDocuments(['business_id' => $this->businessId]);
        $draft = $coll->countDocuments(['business_id' => $this->businessId, 'status' => 'DRAFT']);
        $closed = $coll->countDocuments(['business_id' => $this->businessId, 'status' => 'CLOSED']);

        // Suma de differences_count
        $agg = $coll->aggregate([
            ['$match' => ['business_id' => $this->businessId]],
            ['$group' => ['_id' => null, 'total_diff' => ['$sum' => '$differences_count']]],
        ])->toArray();
        $totalDiff = 0;
        if (!empty($agg)) {
            $a = (array) $agg[0];
            $totalDiff = (int) ($a['total_diff'] ?? 0);
        }

        $kpis = [
            ['label' => 'Total conteos', 'value' => $total],
            ['label' => 'En borrador', 'value' => $draft, 'color' => $draft > 0 ? 'warning' : 'default'],
            ['label' => 'Cerrados', 'value' => $closed, 'color' => 'success'],
            ['label' => 'Diferencias acumuladas', 'value' => $totalDiff, 'color' => $totalDiff > 0 ? 'danger' : 'default'],
        ];

        return Inertia::render('Equipo4/Conteos', [
            'counts' => $data,
            'warehousesList' => $warehousesList,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $counts->currentPage(),
                'last_page' => $counts->lastPage(),
                'per_page' => $counts->perPage(),
                'total' => $counts->total(),
                'from' => $counts->firstItem() ?? 0,
                'to' => $counts->lastItem() ?? 0,
            ],
            'filters' => ['q' => $q],
        ]);
    }

    private function nextFolio(): string
    {
        $last = StockCount::where('business_id', $this->businessId)
            ->orderBy('folio', 'desc')
            ->first();

        if (!$last || empty($last->folio)) return 'CNT-00001';
        $lastFolio = (string) $last->folio;
        $num = (int) preg_replace('/[^0-9]/', '', $lastFolio);
        return 'CNT-' . str_pad((string) ($num + 1), 5, '0', STR_PAD_LEFT);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|string',
            'notes' => 'nullable|string|max:500',
        ]);

        $warehouse = Warehouse::where('_id', $validated['warehouse_id'])
            ->where('business_id', $this->businessId)
            ->first();

        if (!$warehouse) return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'Almacén no encontrado.']);

        $locations = Location::where('warehouse_id', $validated['warehouse_id'])->get();
        $locationIds = [];
        foreach ($locations as $loc) $locationIds[] = (string) $loc->_id;

        if (count($locationIds) === 0) return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'El almacén no tiene ubicaciones configuradas.']);

        $inventoryItems = Inventory::where('business_id', $this->businessId)->whereIn('location_id', $locationIds)->get();
        if ($inventoryItems->isEmpty()) return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'No hay existencias registradas en este almacén.']);

        $folio = $this->nextFolio();
        $productsMap = Product::where('business_id', $this->businessId)->get()->keyBy(fn($p) => (string) $p->_id);

        $stockCountObjectId = new ObjectId();
        DB::connection('mongodb')->getCollection('stock_counts')->insertOne([
            '_id' => $stockCountObjectId,
            'business_id' => $this->businessId,
            'folio' => $folio,
            'warehouse_id' => (string) $validated['warehouse_id'],
            'location_id' => null,
            'status' => 'DRAFT',
            'started_by' => 'USR-ADMIN-001',
            'closed_by' => null,
            'notes' => (string) ($validated['notes'] ?? ''),
            'differences_count' => 0,
            'created_at' => now()->toDateTime(),
            'updated_at' => now()->toDateTime(),
        ]);

        $stockCountId = (string) $stockCountObjectId;

        $itemsToInsert = [];
        foreach ($inventoryItems as $inv) {
            $attrs = $inv->getAttributes();
            $product = $productsMap->get((string) ($attrs['product_id'] ?? ''));
            $itemsToInsert[] = [
                '_id' => new ObjectId(),
                'business_id' => $this->businessId,
                'stock_count_id' => $stockCountId,
                'product_id' => (string) ($attrs['product_id'] ?? ''),
                'product_sku' => $product ? (string) $product->sku : '',
                'product_name' => $product ? (string) $product->name : '',
                'expected_qty' => (int) ($attrs['available'] ?? 0),
                'counted_qty' => 0,
                'difference' => 0,
                'unit_cost' => 0,
                'notes' => '',
                'created_at' => now()->toDateTime(),
                'updated_at' => now()->toDateTime(),
            ];
        }

        if (!empty($itemsToInsert)) {
            DB::connection('mongodb')->getCollection('stock_count_items')->insertMany($itemsToInsert);
        }

        return redirect()->route('equipo4.conteos.index')->with('success', 'Conteo ' . $folio . ' creado.');
    }

    public function show(string $id)
    {
        $stockCount = StockCount::where('_id', $id)->where('business_id', $this->businessId)->first();
        if (!$stockCount) return response()->json(['error' => 'Conteo no encontrado.'], 404);

        $productsMap = Product::where('business_id', $this->businessId)->get()->keyBy(fn($p) => (string) $p->_id);

        $items = StockCountItem::where('stock_count_id', $id)
            ->orderBy('product_sku', 'asc')
            ->get()
            ->map(function ($i) use ($productsMap) {
                $attrs = $i->getAttributes();
                $product = $productsMap->get((string) ($attrs['product_id'] ?? ''));
                return [
                    '_id' => (string) ($attrs['_id'] ?? ''),
                    'product_id' => (string) ($attrs['product_id'] ?? ''),
                    'product_sku' => (string) ($attrs['product_sku'] ?? ''),
                    'product_name' => (string) ($attrs['product_name'] ?? ''),
                    'expected_qty' => (int) ($attrs['expected_qty'] ?? 0),
                    'counted_qty' => (int) ($attrs['counted_qty'] ?? 0),
                    'difference' => (int) ($attrs['difference'] ?? 0),
                    'stock_max' => $product ? (int) $product->stock_max : 0,
                ];
            })->values()->all();

        $attrs = $stockCount->getAttributes();

        return response()->json([
            '_id' => (string) ($attrs['_id'] ?? ''),
            'folio' => (string) ($attrs['folio'] ?? ''),
            'status' => (string) ($attrs['status'] ?? ''),
            'notes' => (string) ($attrs['notes'] ?? ''),
            'items' => $items,
        ]);
    }

    public function capture(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|string',
            'items.*.counted_qty' => 'required|integer|min:0|max:1000000',
        ]);

        $stockCount = StockCount::where('_id', $id)->where('business_id', $this->businessId)->first();
        if (!$stockCount) return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'Conteo no encontrado.']);
        if ($stockCount->status !== 'DRAFT') return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'Solo se pueden capturar conteos en Borrador.']);

        $itemsCollection = DB::connection('mongodb')->getCollection('stock_count_items');
        $differencesCount = 0;
        $updatedCount = 0;

        foreach ($validated['items'] as $input) {
            $itemId = (string) $input['item_id'];
            $counted = (int) $input['counted_qty'];

            try { $itemObjectId = new ObjectId($itemId); } catch (\Throwable $e) { continue; }

            $existingItem = $itemsCollection->findOne(['_id' => $itemObjectId]);
            if (!$existingItem) continue;

            $expected = (int) ($existingItem['expected_qty'] ?? 0);
            $difference = $counted - $expected;

            $itemsCollection->updateOne(
                ['_id' => $itemObjectId],
                ['$set' => [
                    'counted_qty' => $counted,
                    'difference' => $difference,
                    'updated_at' => now()->toDateTime(),
                ]]
            );

            if ($difference !== 0) $differencesCount++;
            $updatedCount++;
        }

        DB::connection('mongodb')->getCollection('stock_counts')->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => ['differences_count' => $differencesCount, 'updated_at' => now()->toDateTime()]]
        );

        return redirect()->route('equipo4.conteos.index')->with('success', 'Conteo actualizado. Items: ' . $updatedCount . '. Diferencias: ' . $differencesCount);
    }

    public function close(string $id, InventoryService $inventoryService): RedirectResponse
    {
        $stockCount = StockCount::where('_id', $id)->where('business_id', $this->businessId)->first();
        if (!$stockCount) return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'Conteo no encontrado.']);
        if ($stockCount->status !== 'DRAFT') return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'Solo se pueden cerrar conteos en Borrador.']);

        $items = StockCountItem::where('stock_count_id', $id)->get();
        if ($items->isEmpty()) return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'El conteo no tiene items.']);

        $appliedAdjustments = [];
        $errors = [];
        $applied = 0;

        foreach ($items as $item) {
            $attrs = $item->getAttributes();
            $difference = (int) ($attrs['difference'] ?? 0);
            if ($difference === 0) continue;

            $inv = Inventory::where('business_id', $this->businessId)
                ->where('product_id', (string) ($attrs['product_id'] ?? ''))
                ->first();

            if (!$inv) { $errors[] = 'Sin inventario para SKU ' . ($attrs['product_sku'] ?? ''); continue; }

            $invAttrs = $inv->getAttributes();

            try {
                $inventoryService->changeStock([
                    'business_id' => $this->businessId,
                    'product_id' => (string) ($invAttrs['product_id'] ?? ''),
                    'variant_id' => $invAttrs['variant_id'] ?? null,
                    'location_id' => (string) ($invAttrs['location_id'] ?? ''),
                    'quantity' => $difference,
                    'type' => 'ADJUSTMENT',
                    'reason' => 'Conteo físico ' . ($stockCount->folio ?? ''),
                    'external_reference' => (string) ($stockCount->folio ?? ''),
                    'actor_id' => 'USR-ADMIN-001',
                ]);
                $appliedAdjustments[] = [
                    'product_id' => (string) ($invAttrs['product_id'] ?? ''),
                    'location_id' => (string) ($invAttrs['location_id'] ?? ''),
                    'variant_id' => $invAttrs['variant_id'] ?? null,
                    'delta' => $difference,
                ];
                $applied++;
            } catch (\Throwable $e) {
                $errors[] = 'SKU ' . ($attrs['product_sku'] ?? '') . ': ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($appliedAdjustments as $adj) {
                try {
                    $inventoryService->changeStock([
                        'business_id' => $this->businessId,
                        'product_id' => $adj['product_id'],
                        'variant_id' => $adj['variant_id'],
                        'location_id' => $adj['location_id'],
                        'quantity' => -$adj['delta'],
                        'type' => 'ADJUSTMENT',
                        'reason' => 'Reversion conteo fallido',
                        'external_reference' => (string) ($stockCount->folio ?? ''),
                        'actor_id' => 'SYSTEM',
                    ]);
                } catch (\Throwable $e) {}
            }
            return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'Errores: ' . implode(' | ', $errors)]);
        }

        DB::connection('mongodb')->getCollection('stock_counts')->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => ['status' => 'CLOSED', 'closed_by' => 'USR-ADMIN-001', 'updated_at' => now()->toDateTime()]]
        );

        return redirect()->route('equipo4.conteos.index')->with('success', 'Conteo cerrado. ' . $applied . ' ajustes aplicados.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $stockCount = StockCount::where('_id', $id)->where('business_id', $this->businessId)->first();
        if (!$stockCount) return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'Conteo no encontrado.']);
        if ($stockCount->status !== 'DRAFT') return redirect()->route('equipo4.conteos.index')->withErrors(['error' => 'Solo se pueden eliminar conteos en Borrador.']);

        DB::connection('mongodb')->getCollection('stock_count_items')->deleteMany(['stock_count_id' => $id]);
        DB::connection('mongodb')->getCollection('stock_counts')->deleteOne(['_id' => new ObjectId($id)]);

        return redirect()->route('equipo4.conteos.index')->with('success', 'Conteo eliminado.');
    }
}
