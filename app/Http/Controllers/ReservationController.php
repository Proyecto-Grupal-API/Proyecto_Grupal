<?php

namespace App\Http\Controllers;

use App\Models\StockReservation;
use App\Models\Product;
use App\Models\Location;
use App\Services\ReservationService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReservationController extends Controller
{
    protected string $businessId = 'BUS-CD-SOUV-001';

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
        $showHistory = $request->boolean('history', false);
        $perPage = 25;

        $query = StockReservation::where('business_id', $this->businessId);
        if (!$showHistory) $query->where('status', 'RESERVED');

        if ($q !== '') {
            $regex = '/' . preg_quote($q, '/') . '/i';
            $query->where(function ($sub) use ($regex) {
                $sub->where('external_reference', 'regex', $regex)->orWhere('reservation_id', 'regex', $regex);
            });
        }

        $reservations = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $productsMap = Product::where('business_id', $this->businessId)->get()->keyBy(fn($p) => (string) $p->_id);
        $locationsMap = Location::where('business_id', $this->businessId)->get()->keyBy(fn($l) => (string) $l->_id);

        $data = collect($reservations->items())->map(function ($r) use ($productsMap, $locationsMap) {
            $attrs = $r->getAttributes();
            $product = $productsMap->get((string) ($attrs['product_id'] ?? ''));
            $location = $locationsMap->get((string) ($attrs['location_id'] ?? ''));

            $rawId = $attrs['_id'] ?? null;
            $idString = '';
            if ($rawId instanceof \MongoDB\BSON\ObjectId) $idString = (string) $rawId;
            elseif (is_string($rawId)) $idString = $rawId;
            elseif (is_object($rawId) && method_exists($rawId, '__toString')) $idString = (string) $rawId;

            return [
                '_id' => $idString,
                'reservation_id' => (string) ($attrs['reservation_id'] ?? ''),
                'product_sku' => $product ? (string) $product->sku : '—',
                'product_name' => $product ? (string) $product->name : '—',
                'location_name' => $location ? (string) $location->name : '—',
                'quantity' => (int) ($attrs['quantity'] ?? 0),
                'source' => (string) ($attrs['source'] ?? ''),
                'status' => (string) ($attrs['status'] ?? ''),
                'external_reference' => (string) ($attrs['external_reference'] ?? ''),
                'expires_at' => $this->formatRawDate($attrs['expires_at'] ?? null),
            ];
        })->values()->all();

        $productsList = $productsMap->map(fn($p) => ['_id' => (string) $p->_id, 'sku' => (string) $p->sku, 'name' => (string) $p->name])->values()->all();
        $locationsList = $locationsMap->map(fn($l) => ['_id' => (string) $l->_id, 'code' => (string) $l->code, 'name' => (string) $l->name])->values()->all();

        // KPIs
        $coll = DB::connection('mongodb')->getCollection('stock_reservations');

        $active = $coll->countDocuments(['business_id' => $this->businessId, 'status' => 'RESERVED']);
        $byCheckout = $coll->countDocuments(['business_id' => $this->businessId, 'status' => 'RESERVED', 'source' => 'CHECKOUT']);
        $byReward = $coll->countDocuments(['business_id' => $this->businessId, 'status' => 'RESERVED', 'source' => ['$in' => ['REWARD', 'CANJE']]]);

        // Suma de unidades reservadas
        $agg = $coll->aggregate([
            ['$match' => ['business_id' => $this->businessId, 'status' => 'RESERVED']],
            ['$group' => ['_id' => null, 'total_qty' => ['$sum' => '$quantity']]],
        ])->toArray();
        $totalQty = 0;
        if (!empty($agg)) {
            $a = (array) $agg[0];
            $totalQty = (int) ($a['total_qty'] ?? 0);
        }

        $kpis = [
            ['label' => 'Reservas activas', 'value' => $active],
            ['label' => 'Unidades apartadas', 'value' => $totalQty, 'color' => $totalQty > 0 ? 'warning' : 'default'],
            ['label' => 'Por checkout', 'value' => $byCheckout],
            ['label' => 'Por recompensas', 'value' => $byReward],
        ];

        return Inertia::render('Equipo4/Reservas', [
            'reservations' => $data,
            'productsList' => $productsList,
            'locationsList' => $locationsList,
            'showHistory' => $showHistory,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $reservations->currentPage(),
                'last_page' => $reservations->lastPage(),
                'per_page' => $reservations->perPage(),
                'total' => $reservations->total(),
                'from' => $reservations->firstItem() ?? 0,
                'to' => $reservations->lastItem() ?? 0,
            ],
            'filters' => ['q' => $q, 'history' => $showHistory ? '1' : ''],
        ]);
    }

    public function store(Request $request, ReservationService $reservationService): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|string',
            'location_id' => 'required|string',
            'quantity' => 'required|integer|min:1|max:100000',
            'source' => 'nullable|string|in:MANUAL,CHECKOUT,REWARD,CANJE,ORDER',
            'external_reference' => 'nullable|string|max:100',
            'expires_at' => 'required|date|after:now',
        ]);

        try {
            $reservationService->reserve([
                'product_id' => $validated['product_id'],
                'variant_id' => null,
                'location_id' => $validated['location_id'],
                'quantity' => (int) $validated['quantity'],
                'source' => $validated['source'] ?? 'MANUAL',
                'external_reference' => $validated['external_reference'] ?? '',
                'expires_at' => $validated['expires_at'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Fallo al crear reserva.', ['error' => $e->getMessage()]);
            return redirect()->route('equipo4.reservas.index')->withErrors(['error' => 'No se pudo reservar: ' . $e->getMessage()]);
        }

        return redirect()->route('equipo4.reservas.index')->with('success', 'Reserva creada exitosamente.');
    }

    public function release(string $id, ReservationService $reservationService): RedirectResponse
    {
        try {
            $reservationService->release($id, 'MANUAL');
        } catch (\Throwable $e) {
            Log::error('Fallo al liberar reserva.', ['id' => $id, 'error' => $e->getMessage()]);
            return redirect()->route('equipo4.reservas.index')->withErrors(['error' => 'No se pudo liberar: ' . $e->getMessage()]);
        }
        return redirect()->route('equipo4.reservas.index')->with('success', 'Reserva liberada y stock devuelto.');
    }

    public function expireOverdue(ReservationService $reservationService): RedirectResponse
    {
        try {
            $count = $reservationService->expireOverdue();
        } catch (\Throwable $e) {
            return redirect()->route('equipo4.reservas.index')->withErrors(['error' => 'Error: ' . $e->getMessage()]);
        }
        return redirect()->route('equipo4.reservas.index')->with('success', $count . ' reserva(s) vencida(s) liberada(s).');
    }
}
