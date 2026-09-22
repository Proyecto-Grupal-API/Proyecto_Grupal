<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;

class KardexController extends Controller
{
    protected string $businessId = 'BUS-CD-SOUV-001';

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('type', ''));
        $perPage = 25;

        $query = StockMovement::where('business_id', $this->businessId);

        if ($q !== '') {
            $regex = '/' . preg_quote($q, '/') . '/i';
            $query->where(function ($sub) use ($regex) {
                $sub->where('external_reference', 'regex', $regex)
                    ->orWhere('reason', 'regex', $regex);
            });
        }

        if ($type !== '') {
            $query->where('type', $type);
        }

        $movements = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $productsMap = Product::where('business_id', $this->businessId)
            ->get()
            ->keyBy(fn($p) => (string) $p->_id);

        $data = collect($movements->items())->map(function ($m) use ($productsMap) {
            $product = $productsMap->get((string) $m->product_id);
            $attrs = $m->getAttributes();

            $createdAt = null;
            $rawDate = $attrs['created_at'] ?? null;
            if ($rawDate instanceof \DateTimeInterface) {
                $createdAt = $rawDate->format('d/m/Y H:i');
            } elseif (is_string($rawDate)) {
                try { $createdAt = \Illuminate\Support\Carbon::parse($rawDate)->format('d/m/Y H:i'); } catch (\Throwable $e) { $createdAt = null; }
            }

            return [
                '_id' => (string) $m->_id,
                'type' => (string) ($attrs['type'] ?? ''),
                'product_sku' => $product ? (string) $product->sku : '',
                'product_name' => $product ? (string) $product->name : '',
                'quantity' => (int) ($attrs['quantity'] ?? 0),
                'reason' => (string) ($attrs['reason'] ?? ''),
                'external_reference' => (string) ($attrs['external_reference'] ?? ''),
                'actor_id' => (string) ($attrs['actor_id'] ?? 'SYSTEM'),
                'created_at' => $createdAt,
            ];
        })->values()->all();

        // KPIs
        $weekAgo = now()->subDays(7)->toDateTime();
        $today = now()->startOfDay()->toDateTime();

        $totalMovements = StockMovement::where('business_id', $this->businessId)->count();
        $todayMovements = StockMovement::where('business_id', $this->businessId)->where('created_at', '>=', $today)->count();
        $weekMovements = StockMovement::where('business_id', $this->businessId)->where('created_at', '>=', $weekAgo)->count();
        $adjustmentsWeek = StockMovement::where('business_id', $this->businessId)
            ->where('created_at', '>=', $weekAgo)
            ->where('type', 'ADJUSTMENT')
            ->count();

        $kpis = [
            ['label' => 'Total movimientos', 'value' => $totalMovements],
            ['label' => 'Hoy', 'value' => $todayMovements, 'color' => 'success'],
            ['label' => 'Últimos 7 días', 'value' => $weekMovements],
            ['label' => 'Ajustes (7d)', 'value' => $adjustmentsWeek, 'color' => $adjustmentsWeek > 0 ? 'warning' : 'default'],
        ];

        return Inertia::render('Equipo4/Kardex', [
            'movements' => $data,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
                'from' => $movements->firstItem() ?? 0,
                'to' => $movements->lastItem() ?? 0,
            ],
            'filters' => ['q' => $q, 'type' => $type],
        ]);
    }
}
