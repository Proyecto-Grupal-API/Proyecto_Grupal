<?php

namespace App\Http\Controllers\InventoryModule;

use App\Http\Controllers\Controller;

use App\Services\InventoryModule\InventoryService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;

class InventoryController extends Controller
{

    public function __construct()
    {
    }
    use \App\Services\InventoryModule\UsesBusinessContext;

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = 25;

        // Leer productos, locations y warehouses directo de Mongo
        $productsMap = [];
        foreach (DB::connection('inventory')->getCollection('products')->find(['business_id' => $this->businessId()]) as $p) {
            $doc = (array) $p;
            $productsMap[(string) ($doc['_id'] ?? '')] = [
                'sku' => (string) ($doc['sku'] ?? ''),
                'name' => (string) ($doc['name'] ?? ''),
            ];
        }

        $locationsMap = [];
        foreach (DB::connection('inventory')->getCollection('locations')->find(['business_id' => $this->businessId()]) as $l) {
            $doc = (array) $l;
            $locationsMap[(string) ($doc['_id'] ?? '')] = [
                'name' => (string) ($doc['name'] ?? ''),
                'warehouse_id' => (string) ($doc['warehouse_id'] ?? ''),
            ];
        }

        $warehousesMap = [];
        foreach (DB::connection('inventory')->getCollection('warehouses')->find(['business_id' => $this->businessId()]) as $w) {
            $doc = (array) $w;
            $warehousesMap[(string) ($doc['_id'] ?? '')] = (string) ($doc['name'] ?? '');
        }

        // Filtro de busqueda por SKU/nombre
        $filter = ['business_id' => $this->businessId()];
        if ($q !== '') {
            $matchedProductIds = [];
            foreach ($productsMap as $pid => $info) {
                if (stripos($info['sku'], $q) !== false || stripos($info['name'], $q) !== false) {
                    $matchedProductIds[] = $pid;
                }
            }
            if (empty($matchedProductIds)) {
                $filter['_id'] = ['$in' => []];
            } else {
                $filter['product_id'] = ['$in' => $matchedProductIds];
            }
        }

        $cursor = DB::connection('inventory')->getCollection('inventories')->find($filter, ['sort' => ['updated_at' => -1]]);
        $all = [];
        foreach ($cursor as $inv) {
            $all[] = (array) $inv;
        }

        $total = count($all);
        $page = max(1, (int) $request->query('page', 1));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $from = ($page - 1) * $perPage;
        $items = array_slice($all, $from, $perPage);

        $data = [];
        foreach ($items as $inv) {
            $pid = (string) ($inv['product_id'] ?? '');
            $lid = (string) ($inv['location_id'] ?? '');
            $product = $productsMap[$pid] ?? null;
            $location = $locationsMap[$lid] ?? null;
            $whName = $location ? ($warehousesMap[$location['warehouse_id']] ?? '—') : '—';

            $data[] = [
                '_id' => (string) ($inv['_id'] ?? ''),
                'product_id' => $pid,
                'sku' => $product['sku'] ?? '(desconocido)',
                'product_name' => $product['name'] ?? '(desconocido)',
                'warehouse_name' => $whName,
                'location_name' => $location['name'] ?? '—',
                'location_id' => $lid,
                'on_hand' => (int) ($inv['on_hand'] ?? 0),
                'reserved' => (int) ($inv['reserved'] ?? 0),
                'available' => (int) ($inv['available'] ?? 0),
                'status' => (string) ($inv['status'] ?? ''),
            ];
        }

        // KPIs
        $totalSkus = $total;
        $sumOnHand = 0; $sumAvailable = 0; $sumReserved = 0; $lowStock = 0;
        foreach ($all as $inv) {
            $sumOnHand += (int) ($inv['on_hand'] ?? 0);
            $sumAvailable += (int) ($inv['available'] ?? 0);
            $sumReserved += (int) ($inv['reserved'] ?? 0);
            if ((int) ($inv['available'] ?? 0) <= 5) $lowStock++;
        }

        $kpis = [
            ['label' => 'SKUs en inventario', 'value' => $totalSkus],
            ['label' => 'Unidades totales', 'value' => $sumOnHand],
            ['label' => 'Disponibles', 'value' => $sumAvailable, 'color' => 'success'],
            ['label' => 'Bajo stock (≤5)', 'value' => $lowStock, 'color' => $lowStock > 0 ? 'warning' : 'default'],
        ];

        $productsList = [];
        foreach ($productsMap as $pid => $p) {
            $productsList[] = ['_id' => $pid, 'sku' => $p['sku'], 'name' => $p['name']];
        }

        return Inertia::render('Equipo4/Inventario', [
            'inventory' => $data,
            'productsList' => $productsList,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? $from + 1 : 0,
                'to' => min($from + $perPage, $total),
            ],
            'filters' => ['q' => $q],
        ]);
    }

    public function adjust(Request $request, InventoryService $inventoryService): RedirectResponse
    {
        $validated = $request->validate([
            'inventory_id' => 'required|string',
            'quantity' => 'required|integer',
            'reason' => 'required|string|min:3|max:500',
        ]);

        $invDoc = DB::connection('inventory')->getCollection('inventories')->findOne([
            '_id' => new ObjectId($validated['inventory_id']),
            'business_id' => $this->businessId(),
        ]);

        if (!$invDoc) {
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'Registro de inventario no encontrado.']);
        }

        $inv = (array) $invDoc;

        try {
            $inventoryService->changeStock([
                'business_id' => $this->businessId(),
                'product_id' => (string) ($inv['product_id'] ?? ''),
                'variant_id' => $inv['variant_id'] ?? null,
                'location_id' => (string) ($inv['location_id'] ?? ''),
                'quantity' => (int) $validated['quantity'],
                'type' => 'ADJUSTMENT',
                'reason' => 'Ajuste manual: ' . $validated['reason'],
                'external_reference' => null,
                'actor_id' => \App\Services\InventoryModule\ActorResolver::resolve(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Fallo al ajustar inventario.', ['error' => $e->getMessage()]);
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'No se pudo ajustar: ' . $e->getMessage()]);
        }

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Ajuste aplicado correctamente.');
    }
}
