<?php

namespace App\Http\Controllers\InventoryModule;

use App\Http\Controllers\Controller;

use App\Services\InventoryModule\AlertGeneratorService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

class AlertController extends Controller
{

    public function __construct()
    {
    }
    use \App\Services\InventoryModule\UsesBusinessContext;

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

        // Mapa de productos y locations
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
            $locationsMap[(string) ($doc['_id'] ?? '')] = (string) ($doc['name'] ?? '');
        }

        // === ALERTAS ===
        $alertFilter = ['business_id' => $this->businessId()];
        if (!$showHistory) $alertFilter['status'] = 'ACTIVE';
        if ($q !== '') $alertFilter['message'] = ['$regex' => $q, '$options' => 'i'];

        $alertsData = [];
        $alertCursor = DB::connection('inventory')->getCollection('stock_alerts')
            ->find($alertFilter, ['sort' => ['created_at' => -1], 'limit' => 200]);
        foreach ($alertCursor as $a) {
            $doc = (array) $a;
            $pid = (string) ($doc['product_id'] ?? '');
            $lid = (string) ($doc['location_id'] ?? '');
            $p = $productsMap[$pid] ?? null;

            $alertsData[] = [
                '_id' => (string) ($doc['_id'] ?? ''),
                'product_sku' => $p['sku'] ?? '—',
                'product_name' => $p['name'] ?? '—',
                'location_name' => $locationsMap[$lid] ?? '—',
                'current_qty' => (int) ($doc['current_qty'] ?? 0),
                'threshold' => (int) ($doc['threshold'] ?? 0),
                'priority' => (string) ($doc['priority'] ?? ''),
                'status' => (string) ($doc['status'] ?? ''),
                'message' => (string) ($doc['message'] ?? ''),
                'resolution_reason' => (string) ($doc['resolution_reason'] ?? ''),
            ];
        }

        // === REGLAS DE REORDEN ===
        $rulesData = [];
        $ruleCursor = DB::connection('inventory')->getCollection('reorder_rules')
            ->find(['business_id' => $this->businessId()], ['sort' => ['created_at' => -1]]);
        foreach ($ruleCursor as $r) {
            $doc = (array) $r;
            $pid = (string) ($doc['product_id'] ?? '');
            $lid = (string) ($doc['location_id'] ?? '');
            $p = $productsMap[$pid] ?? null;

            $rulesData[] = [
                '_id' => (string) ($doc['_id'] ?? ''),
                'product_id' => $pid,
                'product_sku' => $p['sku'] ?? '—',
                'product_name' => $p['name'] ?? '—',
                'location_id' => $lid,
                'location_name' => $locationsMap[$lid] ?? '—',
                'min_qty' => (int) ($doc['min_qty'] ?? 0),
                'max_qty' => (int) ($doc['max_qty'] ?? 0),
                'reorder_point' => (int) ($doc['reorder_point'] ?? 0),
                'active' => (bool) ($doc['active'] ?? false),
            ];
        }

        // Listas para dropdowns
        $productsList = [];
        foreach ($productsMap as $pid => $p) {
            $productsList[] = ['_id' => $pid, 'sku' => $p['sku'], 'name' => $p['name']];
        }
        $locationsList = [];
        foreach ($locationsMap as $lid => $name) {
            $locationsList[] = ['_id' => $lid, 'code' => '', 'name' => $name];
        }

        // KPIs
        $monthStart = new \MongoDB\BSON\UTCDateTime(strtotime(date('Y-m-01')) * 1000);
        $alertsColl = DB::connection('inventory')->getCollection('stock_alerts');

        $activeTotal = $alertsColl->countDocuments(['business_id' => $this->businessId(), 'status' => 'ACTIVE']);
        $critical = $alertsColl->countDocuments(['business_id' => $this->businessId(), 'status' => 'ACTIVE', 'priority' => 'CRITICAL']);
        $high = $alertsColl->countDocuments(['business_id' => $this->businessId(), 'status' => 'ACTIVE', 'priority' => 'HIGH']);
        $resolvedMonth = $alertsColl->countDocuments([
            'business_id' => $this->businessId(),
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
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function discard(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $alertDoc = DB::connection('inventory')->getCollection('stock_alerts')->findOne([
            '_id' => new ObjectId($id),
            'business_id' => $this->businessId(),
        ]);
        if (!$alertDoc) return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->withErrors(['error' => 'Alerta no encontrada.']);

        DB::connection('inventory')->getCollection('stock_alerts')->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => [
                'status' => 'DISMISSED',
                'resolution_reason' => $validated['reason'],
                'dismissed_by' => \App\Services\InventoryModule\ActorResolver::resolve(),
                'dismissed_at' => new \MongoDB\BSON\UTCDateTime(now()),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(now()),
            ]]
        );

        return redirect(parse_url(request()->headers->get("referer") ?: "/", PHP_URL_PATH))->with('success', 'Alerta descartada.');
    }
}
