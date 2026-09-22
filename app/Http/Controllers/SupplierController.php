<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\Supplier;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    protected string $businessId = 'BUS-CD-SOUV-001';

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = 25;

        $query = Supplier::where('business_id', $this->businessId);

        if ($q !== '') {
            $regex = '/' . preg_quote($q, '/') . '/i';
            $query->where(function ($sub) use ($regex) {
                $sub->where('code', 'regex', $regex)
                    ->orWhere('legal_name', 'regex', $regex)
                    ->orWhere('trade_name', 'regex', $regex)
                    ->orWhere('contact_name', 'regex', $regex);
            });
        }

        $suppliers = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $suppliersData = collect($suppliers->items())->map(function ($s) {
            return [
                '_id' => (string) $s->_id,
                'code' => (string) $s->code,
                'legal_name' => (string) $s->legal_name,
                'trade_name' => (string) ($s->trade_name ?? ''),
                'tax_id' => (string) ($s->tax_id ?? ''),
                'contact_name' => (string) $s->contact_name,
                'contact_email' => (string) ($s->contact_email ?? ''),
                'contact_phone' => (string) ($s->contact_phone ?? ''),
                'payment_terms' => (string) $s->payment_terms,
                'notes' => (string) ($s->notes ?? ''),
                'status' => (string) $s->status,
            ];
        })->values()->all();

        // KPIs
        $baseQuery = Supplier::where('business_id', $this->businessId);
        $total = (clone $baseQuery)->count();
        $activos = (clone $baseQuery)->where('status', 'ACTIVO')->count();
        $inactivos = (clone $baseQuery)->where('status', 'INACTIVO')->count();
        $suspendidos = (clone $baseQuery)->where('status', 'SUSPENDIDO')->count();

        $kpis = [
            ['label' => 'Total proveedores', 'value' => $total],
            ['label' => 'Activos', 'value' => $activos, 'color' => 'success'],
            ['label' => 'Inactivos', 'value' => $inactivos],
            ['label' => 'Suspendidos', 'value' => $suspendidos, 'color' => $suspendidos > 0 ? 'warning' : 'default'],
        ];

        return Inertia::render('Equipo4/Proveedores', [
            'suppliers' => $suppliersData,
            'kpis' => $kpis,
            'pagination' => [
                'current_page' => $suppliers->currentPage(),
                'last_page' => $suppliers->lastPage(),
                'per_page' => $suppliers->perPage(),
                'total' => $suppliers->total(),
                'from' => $suppliers->firstItem() ?? 0,
                'to' => $suppliers->lastItem() ?? 0,
            ],
            'filters' => ['q' => $q],
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['business_id'] = $this->businessId;
        Supplier::create($validated);
        return redirect()->route('equipo4.proveedores.index')->with('success', 'Proveedor creado exitosamente.');
    }

    public function update(UpdateSupplierRequest $request, string $id): RedirectResponse
    {
        $supplier = Supplier::where('_id', $id)->where('business_id', $this->businessId)->first();
        if (!$supplier) abort(404, 'Proveedor no encontrado.');
        $supplier->update($request->validated());
        return redirect()->route('equipo4.proveedores.index')->with('success', 'Proveedor actualizado exitosamente.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $supplier = Supplier::where('_id', $id)->where('business_id', $this->businessId)->first();
        if (!$supplier) abort(404, 'Proveedor no encontrado.');
        $supplier->delete();
        return redirect()->route('equipo4.proveedores.index')->with('success', 'Proveedor eliminado exitosamente.');
    }
}
