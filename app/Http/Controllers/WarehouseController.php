<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Models\Warehouse;
use App\Models\Location;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    protected string $businessId = 'BUS-CD-SOUV-001';

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = 25;

        $query = Warehouse::where('business_id', $this->businessId);

        if ($q !== '') {
            $regex = '/' . preg_quote($q, '/') . '/i';
            $query->where(function ($sub) use ($regex) {
                $sub->where('code', 'regex', $regex)
                    ->orWhere('name', 'regex', $regex);
            });
        }

        $warehouses = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $warehousesData = collect($warehouses->items())->map(function ($w) {
            $locationsCount = Location::where('warehouse_id', (string) $w->_id)->count();
            return [
                '_id' => (string) $w->_id,
                'code' => (string) $w->code,
                'name' => (string) $w->name,
                'type' => (string) $w->type,
                'active' => (bool) $w->active,
                'locations_count' => $locationsCount,
            ];
        })->values()->all();

        $allWarehouses = Warehouse::where('business_id', $this->businessId)
            ->orderBy('name', 'asc')
            ->get()
            ->map(fn($w) => ['_id' => (string) $w->_id, 'name' => (string) $w->name, 'code' => (string) $w->code])
            ->values()->all();

        $locations = Location::where('business_id', $this->businessId)
            ->orderBy('created_at', 'desc')
            ->take(200)
            ->get()
            ->map(function ($l) {
                return [
                    '_id' => (string) $l->_id,
                    'warehouse_id' => (string) $l->warehouse_id,
                    'code' => (string) $l->code,
                    'name' => (string) $l->name,
                    'type' => (string) $l->type,
                    'capacity' => (int) ($l->capacity ?? 0),
                    'active' => (bool) $l->active,
                ];
            })
            ->values()->all();

        // KPIs
        $totalWarehouses = Warehouse::where('business_id', $this->businessId)->count();
        $activeWarehouses = Warehouse::where('business_id', $this->businessId)->where('active', true)->count();
        $totalLocations = Location::where('business_id', $this->businessId)->count();
        $activeLocations = Location::where('business_id', $this->businessId)->where('active', true)->count();

        $kpis = [
            ['label' => 'Total almacenes', 'value' => $totalWarehouses],
            ['label' => 'Activos', 'value' => $activeWarehouses, 'color' => 'success'],
            ['label' => 'Total ubicaciones', 'value' => $totalLocations],
            ['label' => 'Ubicaciones activas', 'value' => $activeLocations],
        ];

        return Inertia::render('Equipo4/Almacenes', [
            'warehouses' => $warehousesData,
            'allWarehouses' => $allWarehouses,
            'locations' => $locations,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $warehouses->currentPage(),
                'last_page' => $warehouses->lastPage(),
                'per_page' => $warehouses->perPage(),
                'total' => $warehouses->total(),
                'from' => $warehouses->firstItem() ?? 0,
                'to' => $warehouses->lastItem() ?? 0,
            ],
            'filters' => ['q' => $q],
        ]);
    }

    public function store(StoreWarehouseRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['business_id'] = $this->businessId;
        Warehouse::create($validated);
        return redirect()->route('equipo4.almacenes.index')->with('success', 'Almacén creado exitosamente.');
    }

    public function update(UpdateWarehouseRequest $request, string $id): RedirectResponse
    {
        $warehouse = Warehouse::where('_id', $id)->where('business_id', $this->businessId)->first();
        if (!$warehouse) abort(404, 'Almacén no encontrado.');
        $warehouse->update($request->validated());
        return redirect()->route('equipo4.almacenes.index')->with('success', 'Almacén actualizado exitosamente.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $warehouse = Warehouse::where('_id', $id)->where('business_id', $this->businessId)->first();
        if (!$warehouse) abort(404, 'Almacén no encontrado.');

        $locationsCount = Location::where('warehouse_id', $id)->count();
        if ($locationsCount > 0) {
            return redirect()->back()->withErrors(['error' => 'No se puede eliminar: el almacén tiene ' . $locationsCount . ' ubicaciones asociadas.']);
        }

        $warehouse->delete();
        return redirect()->route('equipo4.almacenes.index')->with('success', 'Almacén eliminado exitosamente.');
    }
}
