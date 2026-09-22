<?php

namespace App\Http\Controllers;

use App\Models\StockAlert;
use App\Models\Product;
use App\Models\Location;
use App\Models\ReorderRule;
use App\Services\AlertGeneratorService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

class AlertController extends Controller
{
    protected string $businessId = 'BUS-CD-SOUV-001';

    public function index(Request $request): Response
    {
        $cacheKey = 'equipo4_alerts_last_generate';
        if (!Cache::has($cacheKey)) {
            try {
                app(AlertGeneratorService::class)->generate();
                Cache::put($cacheKey, now()->timestamp, 5);
            } catch (\Throwable $e) {}
        }

        $q = trim((string) $request->query('q', ''));
        $showHistory = $request->boolean('history', false);

        $query = StockAlert::where('business_id', $this->businessId);
        if (!$showHistory) $query->where('status', 'ACTIVE');
        if ($q !== '') {
            $regex = '/' . preg_quote($q, '/') . '/i';
            $query->where('message', 'regex', $regex);
        }

        $alerts = $query->orderBy('created_at', 'desc')->limit(200)->get();

        $productsMap = Product::where('business_id', $this->businessId)->get()->keyBy(fn($p) => (string) $p->_id);
        $locationsMap = Location::where('business_id', $this->businessId)->get()->keyBy(fn($l) => (string) $l->_id);

        $alertsData = $alerts->map(function ($a) use ($productsMap, $locationsMap) {
            $attrs = $a->getAttributes();
            $product = $productsMap->get((string) ($attrs['product_id'] ?? ''));
            $location = $locationsMap->get((string) ($attrs['location_id'] ?? ''));

            return [
                '_id' => (string) ($attrs['_id'] ?? ''),
                'product_sku' => $product ? (string) $product->sku : '—',
                'product_name' => $product ? (string) $product->name : '—',
                'location_name' => $location ? (string) $location->name : '—',
                'current_qty' => (int) ($attrs['current_qty'] ?? 0),
                'threshold' => (int) ($attrs['threshold'] ?? 0),
                'priority' => (string) ($attrs['priority'] ?? ''),
                'status' => (string) ($attrs['status'] ?? ''),
                'message' => (string) ($attrs['message'] ?? ''),
                'resolution_reason' => (string) ($attrs['resolution_reason'] ?? ''),
            ];
        })->values()->all();

        $rules = ReorderRule::where('business_id', $this->businessId)->orderBy('created_at', 'desc')->get();
        $rulesData = $rules->map(function ($r) use ($productsMap, $locationsMap) {
            $attrs = $r->getAttributes();
            $product = $productsMap->get((string) ($attrs['product_id'] ?? ''));
            $location = $locationsMap->get((string) ($attrs['location_id'] ?? ''));
            return [
                '_id' => (string) ($attrs['_id'] ?? ''),
                'product_id' => (string) ($attrs['product_id'] ?? ''),
                'product_sku' => $product ? (string) $product->sku : '—',
                'product_name' => $product ? (string) $product->name : '—',
                'location_id' => (string) ($attrs['location_id'] ?? ''),
                'location_name' => $location ? (string) $location->name : '—',
                'min_qty' => (int) ($attrs['min_qty'] ?? 0),
                'max_qty' => (int) ($attrs['max_qty'] ?? 0),
                'reorder_point' => (int) ($attrs['reorder_point'] ?? 0),
                'active' => (bool) ($attrs['active'] ?? false),
            ];
        })->values()->all();

        $productsList = $productsMap->map(fn($p) => ['_id' => (string) $p->_id, 'sku' => (string) $p->sku, 'name' => (string) $p->name])->values()->all();
        $locationsList = $locationsMap->map(fn($l) => ['_id' => (string) $l->_id, 'code' => (string) $l->code, 'name' => (string) $l->name])->values()->all();

        // KPIs
        $monthStart = new \MongoDB\BSON\UTCDateTime(strtotime(date('Y-m-01')) * 1000);
        $coll = DB::connection('mongodb')->getCollection('stock_alerts');

        $activeTotal = $coll->countDocuments(['business_id' => $this->businessId, 'status' => 'ACTIVE']);
        $critical = $coll->countDocuments(['business_id' => $this->businessId, 'status' => 'ACTIVE', 'priority' => 'CRITICAL']);
        $high = $coll->countDocuments(['business_id' => $this->businessId, 'status' => 'ACTIVE', 'priority' => 'HIGH']);
        $resolvedMonth = $coll->countDocuments([
            'business_id' => $this->businessId,
            'status' => ['$in' => ['RESOLVED', 'DISMISSED']],
            'updated_at' => ['$gte' => $monthStart],
        ]);

        $kpis = [
            ['label' => 'Alertas activas', 'value' => $activeTotal],
            ['label' => 'Críticas', 'value' => $critical, 'color' => 'danger'],
            ['label' => 'Altas', 'value' => $high, 'color' => 'warning'],
            ['label' => 'Resueltas este mes', 'value' => $resolvedMonth, 'color' => 'success'],
        ];

        return Inertia::render('Equipo4/Alertas', [
            'alerts' => $alertsData,
            'rules' => $rulesData,
            'productsList' => $productsList,
            'locationsList' => $locationsList,
            'showHistory' => $showHistory,
            'kpis' => $kpis,
            'filters' => ['q' => $q, 'history' => $showHistory ? '1' : ''],
        ]);
    }

    public function generate(AlertGeneratorService $generator): RedirectResponse
    {
        try {
            $stats = $generator->generate();
            Cache::forget('equipo4_alerts_last_generate');
            $msg = sprintf('Alertas recalculadas. Creadas: %d, Actualizadas: %d, Resueltas: %d', $stats['created'], $stats['updated'], $stats['resolved']);
            return redirect()->route('equipo4.alertas.index')->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->route('equipo4.alertas.index')->withErrors(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function discard(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ], [
            'reason.required' => 'Debes indicar el motivo del descarte.',
            'reason.min' => 'El motivo debe tener al menos 5 caracteres.',
        ]);

        $alert = StockAlert::where('_id', $id)->where('business_id', $this->businessId)->first();
        if (!$alert) return redirect()->route('equipo4.alertas.index')->withErrors(['error' => 'Alerta no encontrada.']);

        DB::connection('mongodb')->getCollection('stock_alerts')->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => [
                'status' => 'DISMISSED',
                'resolution_reason' => $validated['reason'],
                'dismissed_by' => 'USR-ADMIN-001',
                'dismissed_at' => now()->toDateTime(),
                'updated_at' => now()->toDateTime(),
            ]]
        );

        return redirect()->route('equipo4.alertas.index')->with('success', 'Alerta descartada.');
    }
}
