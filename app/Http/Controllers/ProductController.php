<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Models\Category;
use App\Services\ReorderRuleService;
use App\Services\ProductVariantService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{

    public function __construct()
    {
        $this->businessId = (string) config('team4.business_id', 'BUS-CD-SOUV-001');
    }
    protected string $businessId;

    public function index(Request $request): Response
    {
        return $this->buildProductsIndex($request, null);
    }

    public function souvenirs(Request $request): Response
    {
        return $this->buildProductsIndex($request, 'souvenirs');
    }

    private function buildProductsIndex(Request $request, ?string $onlyCategory = null): Response
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = 25;

        $query = Product::where('business_id', $this->businessId);

        if ($onlyCategory !== null) {
            $query->where('category', $onlyCategory);
        }

        if ($q !== '') {
            $regex = '/' . preg_quote($q, '/') . '/i';
            $query->where(function ($sub) use ($regex) {
                $sub->where('sku', 'regex', $regex)
                    ->orWhere('name', 'regex', $regex);
            });
        }

        $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $productsData = collect($products->items())->map(function ($product) {
            $preorder = (array) ($product->preorder ?? []);
            $availableAt = $preorder['available_at'] ?? null;
            if ($availableAt instanceof \MongoDB\BSON\UTCDateTime) {
                $preorder['available_at'] = $availableAt->toDateTime()->format(DATE_ATOM);
            } elseif ($availableAt instanceof \DateTimeInterface) {
                $preorder['available_at'] = $availableAt->format(DATE_ATOM);
            }

            return [
                '_id' => (string) $product->_id,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'description' => (string) ($product->description ?? ''),
                'category' => (string) $product->category,
                'sizes' => (array) ($product->sizes ?? []),
                'colors' => (array) ($product->colors ?? []),
                'seasons' => (array) ($product->seasons ?? []),
                'collections' => (array) ($product->collections ?? []),
                'packages' => (array) ($product->packages ?? []),
                'preorder' => $preorder,
                'official' => (bool) ($product->official ?? false),
                'stock_min' => (int) $product->stock_min,
                'stock_max' => (int) $product->stock_max,
                'active' => (bool) $product->active,
            ];
        })->values()->all();

        $categories = Category::where('business_id', $this->businessId)
            ->where('active', true)
            ->orderBy('name', 'asc')
            ->get()
            ->map(fn($cat) => [
                'slug' => (string) $cat->slug,
                'name' => (string) $cat->name,
            ])
            ->values()
            ->all();

        // KPIs usando coleccion directa (evita bugs de Eloquent + Mongo)
        $collection = DB::connection('mongodb')->getCollection('products');
        $filter = ['business_id' => $this->businessId];
        if ($onlyCategory !== null) {
            $filter['category'] = $onlyCategory;
        }

        $totalProducts = $collection->countDocuments($filter);
        $activeProducts = $collection->countDocuments(array_merge($filter, ['active' => true]));

        // Productos con alerta urgente activa
        $alertsCollection = DB::connection('mongodb')->getCollection('stock_alerts');
        $productIdsWithAlerts = $alertsCollection->distinct('product_id', [
            'business_id' => $this->businessId,
            'status' => 'ACTIVE',
            'priority' => ['$in' => ['HIGH', 'CRITICAL']],
        ]);

        $withAlertsCount = 0;
        if (!empty($productIdsWithAlerts)) {
            // Filtrar por los productos del negocio (y opcionalmente categoria)
            $withAlertsCount = $collection->countDocuments(array_merge(
                $filter,
                ['_id' => ['$in' => array_map(fn($id) => new \MongoDB\BSON\ObjectId((string) $id), $productIdsWithAlerts)]]
            ));
        }

        $kpis = [
            ['label' => 'Total productos', 'value' => $totalProducts],
            ['label' => 'Activos', 'value' => $activeProducts, 'color' => 'success'],
            ['label' => 'Inactivos', 'value' => $totalProducts - $activeProducts],
            ['label' => 'Con alerta', 'value' => $withAlertsCount, 'color' => $withAlertsCount > 0 ? 'danger' : 'default'],
        ];

        $view = $onlyCategory === 'souvenirs' ? 'Equipo4/Souvenirs' : 'Equipo4/Productos';

        return Inertia::render($view, [
            'products' => $productsData,
            'categories' => $categories,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'from' => $products->firstItem() ?? 0,
                'to' => $products->lastItem() ?? 0,
            ],
            'filters' => ['q' => $q],
        ]);
    }

    public function store(
        StoreProductRequest $request,
        ReorderRuleService $reorderService,
        ProductVariantService $variantService
    ): RedirectResponse
    {
        $validated = $request->validated();
        $validated['business_id'] = $this->businessId;

        $product = Product::create($validated);

        $variantService->sync($product);
        $reorderService->syncForProduct((string) $product->_id);

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Producto creado exitosamente.');
    }

    public function update(
        UpdateProductRequest $request,
        string $id,
        ReorderRuleService $reorderService,
        ProductVariantService $variantService
    ): RedirectResponse
    {
        $product = Product::where('_id', $id)
            ->where('business_id', $this->businessId)
            ->first();

        if (!$product) abort(404, 'Producto no encontrado.');

        $product->update($request->validated());

        $variantService->sync($product);
        $reorderService->syncForProduct($id);

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Producto actualizado exitosamente.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $product = Product::where('_id', $id)
            ->where('business_id', $this->businessId)
            ->first();

        if (!$product) abort(404, 'Producto no encontrado.');

        $alerts = app(\App\Services\AlertGeneratorService::class);
        $rules = \App\Models\ReorderRule::where('business_id', $this->businessId)
            ->where('product_id', $id)
            ->get();
        foreach ($rules as $rule) {
            $alerts->resolveFor((string) $rule->product_id, (string) $rule->location_id);
        }
        \App\Models\ReorderRule::where('business_id', $this->businessId)
            ->where('product_id', $id)
            ->delete();
        DB::connection('mongodb')->getCollection('product_variants')->updateMany(
            ['business_id' => $this->businessId, 'product_id' => $id],
            ['$set' => ['active' => false, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
        );

        $product->delete();

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Producto eliminado exitosamente.');
    }
}
