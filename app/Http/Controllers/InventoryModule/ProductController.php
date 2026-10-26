<?php

namespace App\Http\Controllers\InventoryModule;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryModule\StoreProductRequest;
use App\Http\Requests\InventoryModule\UpdateProductRequest;
use App\Models\InventoryModule\Category;
use App\Models\InventoryModule\Product;
use App\Models\InventoryModule\ReorderRule;
use App\Services\InventoryModule\AlertGeneratorService;
use App\Services\InventoryModule\ReorderRuleService;
use App\Services\InventoryModule\UsesBusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\ObjectId;

class ProductController extends Controller
{
    public function __construct() {}

    use UsesBusinessContext;

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

        $query = Product::where('business_id', $this->businessId());

        if ($onlyCategory !== null) {
            $query->where('category', $onlyCategory);
        }

        if ($q !== '') {
            $regex = '/'.preg_quote($q, '/').'/i';
            $query->where(function ($sub) use ($regex) {
                $sub->where('sku', 'regex', $regex)
                    ->orWhere('name', 'regex', $regex);
            });
        }

        $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $productsData = collect($products->items())->map(function ($product) {
            return [
                '_id' => (string) $product->_id,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'description' => (string) ($product->description ?? ''),
                'category' => (string) $product->category,
                'sizes' => (array) ($product->sizes ?? []), 'colors' => (array) ($product->colors ?? []), 'seasons' => (array) ($product->seasons ?? []),
                'stock_min' => (int) $product->stock_min,
                'stock_max' => (int) $product->stock_max,
                'active' => (bool) $product->active,
            ];
        })->values()->all();

        $categories = Category::where('business_id', $this->businessId())
            ->where('active', true)
            ->orderBy('name', 'asc')
            ->get()
            ->map(fn ($cat) => [
                'slug' => (string) $cat->slug,
                'has_sizes' => (bool) $cat->has_sizes, 'has_colors' => (bool) $cat->has_colors, 'has_seasons' => (bool) $cat->has_seasons,
                'name' => (string) $cat->name,
            ])
            ->values()
            ->all();

        // KPIs usando coleccion directa (evita bugs de Eloquent + Mongo)
        $collection = DB::connection('inventory')->getCollection('products');
        $filter = ['business_id' => $this->businessId()];
        if ($onlyCategory !== null) {
            $filter['category'] = $onlyCategory;
        }

        $totalProducts = $collection->countDocuments($filter);
        $activeProducts = $collection->countDocuments(array_merge($filter, ['active' => true]));

        // Productos con alerta urgente activa
        $alertsCollection = DB::connection('inventory')->getCollection('stock_alerts');
        $productIdsWithAlerts = $alertsCollection->distinct('product_id', [
            'business_id' => $this->businessId(),
            'status' => 'ACTIVE',
            'priority' => ['$in' => ['HIGH', 'CRITICAL']],
        ]);

        $withAlertsCount = 0;
        if (! empty($productIdsWithAlerts)) {
            // Filtrar por los productos del negocio (y opcionalmente categoria)
            $withAlertsCount = $collection->countDocuments(array_merge(
                $filter,
                ['_id' => ['$in' => array_map(fn ($id) => new ObjectId((string) $id), $productIdsWithAlerts)]]
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

    public function store(StoreProductRequest $request, ReorderRuleService $reorderService): RedirectResponse
    {
        $validated = $request->validated();
        $validated['business_id'] = $this->businessId();

        $product = Product::create($validated);

        try {
            $reorderService->syncForProduct((string) $product->_id);
        } catch (\Throwable $e) {
            // no-op
        }

        return redirect(parse_url(request()->headers->get('referer') ?: '/', PHP_URL_PATH))->with('success', 'Producto creado exitosamente.');
    }

    public function update(UpdateProductRequest $request, string $id, ReorderRuleService $reorderService): RedirectResponse
    {
        $product = Product::where('_id', $id)
            ->where('business_id', $this->businessId())
            ->first();

        if (! $product) {
            abort(404, 'Producto no encontrado.');
        }

        $product->update($request->validated());

        try {
            $reorderService->syncForProduct($id);
        } catch (\Throwable $e) {
            // no-op
        }

        return redirect(parse_url(request()->headers->get('referer') ?: '/', PHP_URL_PATH))->with('success', 'Producto actualizado exitosamente.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $product = Product::where('_id', $id)
            ->where('business_id', $this->businessId())
            ->first();

        if (! $product) {
            abort(404, 'Producto no encontrado.');
        }

        try {
            $alerts = app(AlertGeneratorService::class);
            $rules = ReorderRule::where('product_id', $id)->get();
            foreach ($rules as $rule) {
                $alerts->resolveFor((string) $rule->product_id, (string) $rule->location_id);
            }
            ReorderRule::where('product_id', $id)->delete();
        } catch (\Throwable $e) {
            // no-op
        }

        $product->delete();

        return redirect(parse_url(request()->headers->get('referer') ?: '/', PHP_URL_PATH))->with('success', 'Producto eliminado exitosamente.');
    }
}
