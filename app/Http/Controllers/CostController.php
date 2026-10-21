<?php

namespace App\Http\Controllers;

use App\Models\PricingReference;
use App\Models\Product;
use App\Services\CostService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;

class CostController extends Controller
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

    private function formatRawDate($value, string $format = 'Y-m-d H:i'): ?string
    {
        if ($value === null) return null;
        try {
            if ($value instanceof \DateTimeInterface) return $value->format($format);
            if (is_string($value)) return Carbon::parse($value)->format($format);
            if (is_array($value) && isset($value['$date'])) return Carbon::parse($value['$date'])->format($format);
            if (is_object($value) && method_exists($value, 'toDateTime')) return Carbon::instance($value->toDateTime())->format($format);
            return null;
        } catch (\Throwable $e) { return null; }
    }

        public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = 25;

        // 1. Traer todos los productos actuales
        $products = Product::where('business_id', $this->businessId)->get();
        $activeProductsMap = [];
        foreach ($products as $p) {
            $activeProductsMap[(string) $p->sku] = [
                'id' => (string) $p->_id,
                'name' => (string) $p->name,
                'category' => (string) $p->category,
                'status' => $p->active ? 'active' : 'inactive',
                'exists' => true,
            ];
        }

        // 2. Traer el último costo de cada SKU desde cost_histories
        $collection = DB::connection('mongodb')->getCollection('cost_histories');
        $pipeline = [
            ['$match' => ['business_id' => $this->businessId]],
            ['$sort' => ['effective_at' => -1]],
            ['$group' => [
                '_id' => '$sku',
                'last_cost' => ['$first' => '$cost'],
                'product_id' => ['$first' => '$product_id'],
                'sku' => ['$first' => '$sku'],
                'product_exists' => ['$first' => '$product_exists'],
                'product_status' => ['$first' => '$product_status'],
            ]],
        ];

        if ($q !== '') {
            $pipeline[] = ['$match' => ['sku' => ['$regex' => $q, '$options' => 'i']]];
        }

        $costs = $collection->aggregate($pipeline)->toArray();

        $activeCosts = [];
        $orphanCosts = [];

        foreach ($costs as $cost) {
            $cost = (array) $cost;
            $sku = (string) $cost['sku'];
            
            $productInfo = $activeProductsMap[$sku] ?? null;

            $item = [
                '_id' => (string) ($cost['product_id'] ?? $cost['_id']),
                'sku' => $sku,
                'name' => $productInfo ? $productInfo['name'] : 'Producto Eliminado',
                'category' => $productInfo ? $productInfo['category'] : '',
                'last_cost' => (float) ($cost['last_cost'] ?? 0),
                'product_status' => $productInfo ? $productInfo['status'] : 'deleted',
                'product_exists' => $productInfo ? true : false,
            ];

            if ($productInfo) {
                $activeCosts[] = $item;
            } else {
                $orphanCosts[] = $item;
            }
        }

        // Ordenar por costo (o por nombre)
        usort($activeCosts, fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($orphanCosts, fn($a, $b) => strcmp($a['sku'], $b['sku']));

        // KPIs
        $totalProducts = count($activeProductsMap);
        $totalWithCost = count($activeCosts) + count($orphanCosts);
        $orphansCount = count($orphanCosts);

        $kpis = [
            ['label' => 'Productos activos', 'value' => $totalProducts, 'color' => 'success'],
            ['label' => 'Con costo registrado', 'value' => count($activeCosts), 'color' => 'default'],
            ['label' => 'Historiales huérfanos', 'value' => $orphansCount, 'color' => $orphansCount > 0 ? 'warning' : 'default'],
        ];

        return Inertia::render('Equipo4/Costos', [
            'activeCosts' => $activeCosts,
            'orphanCosts' => $orphanCosts,
            'productsList' => $products->map(fn($p) => ['_id' => (string)$p->_id, 'sku' => (string)$p->sku, 'name' => (string)$p->name])->values()->all(),
            'kpis' => $kpis,
            'filters' => ['q' => $q],
        ]);
    }

    public function store(Request $request, CostService $costService): RedirectResponse
    {
        $validated = $request->validate([
            'sku' => 'required|string',
            'cost' => 'required|numeric|min:0|max:1000000',
            'source' => 'nullable|string|in:MANUAL,PURCHASE_ORDER,RECEIPT',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'suggested_price' => 'nullable|numeric|min:0|max:10000000',
        ]);

        try {
            $product = Product::where('business_id', $this->businessId)
                ->where('sku', $validated['sku'])
                ->first();

            $productExists = !is_null($product);
            $productStatus = $product ? ($product->active ? 'active' : 'inactive') : 'deleted';
            $productId = $product ? (string) $product->_id : null;

            $costService->register([
                'product_id' => $productId,
                'sku' => $validated['sku'],
                'product_exists' => $productExists,
                'product_status' => $productStatus,
                'cost' => (float) $validated['cost'],
                'source' => $validated['source'] ?? 'MANUAL',
                'reference' => $validated['reference'] ?? '',
                'notes' => $validated['notes'] ?? '',
                'suggested_price' => $validated['suggested_price'] ?? null,
                'actor_id' => 'USR-ADMIN-001',
            ]);
        } catch (\Throwable $e) {
            Log::error('Fallo al registrar costo.', ['error' => $e->getMessage()]);
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'No se pudo registrar: ' . $e->getMessage()]);
        }

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Costo registrado.');
    }

    public function history(string $productId)
    {
        try {
            $collection = DB::connection('mongodb')->getCollection('cost_histories');
            $cursor = $collection->find(
                ['business_id' => $this->businessId, 'product_id' => $productId],
                ['sort' => ['created_at' => -1], 'limit' => 30]
            );

            $data = [];
            foreach ($cursor as $doc) {
                $doc = (array) $doc;
                $data[] = [
                    '_id' => (string) ($doc['_id'] ?? ''),
                    'cost' => (float) ($doc['cost'] ?? 0),
                    'source' => (string) ($doc['source'] ?? ''),
                    'reference' => (string) ($doc['reference'] ?? ''),
                    'notes' => (string) ($doc['notes'] ?? ''),
                    'effective_at' => $this->formatRawDate($doc['effective_at'] ?? $doc['created_at'] ?? null),
                ];
            }

            return response()->json(['history' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['history' => [], 'error' => $e->getMessage()], 500);
        }
    }
}
