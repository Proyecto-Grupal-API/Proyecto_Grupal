<?php

namespace App\Http\Controllers;

use App\Models\ReorderRule;
use App\Models\Product;
use App\Models\Location;
use App\Services\AlertGeneratorService;
use App\Services\ReorderRuleService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReorderRuleController extends Controller
{
    protected string $businessId = 'BUS-CD-SOUV-001';

    public function index(Request $request): Response
    {
        return app(AlertController::class)->index($request);
    }

    /**
     * Sincroniza reglas para TODOS los productos del negocio.
     * Usa ReorderRuleService::syncForProduct() que crea reglas faltantes
     * y actualiza min/max de las existentes sin tocar reorder_point manual.
     */
    public function syncAll(ReorderRuleService $rules): RedirectResponse
    {
        $products = Product::where('business_id', $this->businessId)->get();

        $createdCount = 0;
        $updatedCount = 0;

        // Contar reglas antes
        $beforeCount = ReorderRule::where('business_id', $this->businessId)->count();

        foreach ($products as $product) {
            try {
                $rules->syncForProduct((string) $product->_id);
            } catch (\Throwable $e) {
                // continuar con el siguiente producto
            }
        }

        $afterCount = ReorderRule::where('business_id', $this->businessId)->count();
        $createdCount = max(0, $afterCount - $beforeCount);
        $updatedCount = $afterCount - $createdCount;

        return redirect()->route('equipo4.alertas.index')
            ->with('success', sprintf(
                'Reglas sincronizadas: %d nuevas, %d actualizadas.',
                $createdCount,
                $afterCount
            ));
    }

    public function update(Request $request, string $id, AlertGeneratorService $alerts): RedirectResponse
    {
        $validated = $request->validate([
            'reorder_point' => 'required|integer|min:0|max:1000000',
            'active' => 'required|boolean',
        ]);

        $rule = ReorderRule::where('_id', $id)
            ->where('business_id', $this->businessId)
            ->first();

        if (!$rule) {
            return redirect()->route('equipo4.alertas.index')
                ->withErrors(['error' => 'Regla no encontrada.']);
        }

        $productId = (string) $rule->product_id;
        $locationId = (string) $rule->location_id;

        $rule->reorder_point = (int) $validated['reorder_point'];
        $rule->active = (bool) $validated['active'];
        $rule->save();

        try {
            if (!$rule->active) {
                $alerts->resolveFor($productId, $locationId);
            } else {
                $alerts->syncOne($productId, $locationId);
            }
        } catch (\Throwable $e) {
            // no-op
        }

        return redirect()->route('equipo4.alertas.index')
            ->with('success', 'Punto de reorden actualizado.');
    }

    public function reset(string $id, ReorderRuleService $rules, AlertGeneratorService $alerts): RedirectResponse
    {
        $rule = ReorderRule::where('_id', $id)
            ->where('business_id', $this->businessId)
            ->first();

        if (!$rule) {
            return redirect()->route('equipo4.alertas.index')
                ->withErrors(['error' => 'Regla no encontrada.']);
        }

        $productId = (string) $rule->product_id;
        $locationId = (string) $rule->location_id;

        $rules->recalculateForProduct($productId);

        try {
            $alerts->syncOne($productId, $locationId);
        } catch (\Throwable $e) {
            // no-op
        }

        return redirect()->route('equipo4.alertas.index')
            ->with('success', 'Punto de reorden recalculado con los valores del producto.');
    }
}
