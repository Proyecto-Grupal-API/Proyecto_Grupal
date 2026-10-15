<?php

namespace App\Api\InventoryModule;

use App\Exceptions\InventoryModule\ProductReferenceNotFound;
use App\Http\Controllers\Controller;
use App\Services\InventoryModule\Team7AdapterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Team7ApiController extends Controller
{
    public function __construct(protected Team7AdapterService $adapter) {}

    /**
     * Availability.
     * - GET  /api/v1/inventory/availability?product_reference=X&variant_sku=Y
     *   -> {available, available_quantity, warehouse_id, items:[...]}
     * - POST /api/v1/inventory/availability  body {items:[...]}
     *   -> {available, items:[...]}  (bulk backward-compat)
     */
    public function availability(Request $request)
    {
        if ($request->isMethod('GET')) {
            $v = $request->validate([
                'business_id'       => 'nullable|string|max:100',
                'product_reference' => 'required|string',
                'variant_sku'       => 'nullable|string',
                'location_id'       => 'nullable|string',
                'quantity'          => 'nullable|integer|min:1|max:100000',
            ]);

            $items = [[
                'product_reference' => $v['product_reference'],
                'variant_sku'       => $v['variant_sku'] ?? null,
                'location_id'       => $v['location_id'] ?? null,
                'quantity'          => (int) ($v['quantity'] ?? 1),
            ]];
        } else {
            $v = $request->validate([
                'items'                     => 'required|array|min:1|max:100',
                'items.*.product_reference' => 'required|string',
                'items.*.variant_sku'       => 'nullable|string',
                'items.*.location_id'       => 'nullable|string',
                'items.*.quantity'          => 'required|integer|min:1|max:1000',
            ]);
            $items = $v['items'];
        }

        try {
            $res   = $this->adapter->availability($items);
            $first = $res['items'][0] ?? null;

            return response()->json([
                'available'          => $res['available'],
                'available_quantity' => $first['available']    ?? 0,
                'warehouse_id'       => $first['warehouse_id'] ?? null,
                'items'              => $res['items'],
            ], 200);
        } catch (ProductReferenceNotFound $e) {
            return response()->json([
                'error'   => 'product_not_found',
                'message' => $e->getMessage(),
            ], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('E7 availability failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al verificar disponibilidad.'], 500);
        }
    }

    /**
     * Reserve. Acepta tanto body flat (spec E7) como bulk con items[].
     */
    public function reserve(Request $request)
    {
        $v = $request->validate([
            'origin_reference'          => 'required|string|max:100',
            'origin_type'               => 'required|string|max:50',
            'idempotency_key'           => 'required|string|min:8|max:128',
            'expires_at'                => 'nullable|date|after:now',
            'business_id'               => 'nullable|string|max:100',

            // Bulk
            'items'                     => 'nullable|array|min:1|max:100',
            'items.*.product_reference' => 'required_with:items|string',
            'items.*.variant_sku'       => 'nullable|string',
            'items.*.location_id'       => 'nullable|string',
            'items.*.quantity'          => 'required_with:items|integer|min:1|max:1000',

            // Flat (spec E7)
            'product_reference'         => 'required_without:items|string',
            'variant_sku'               => 'nullable|string',
            'location_id'               => 'nullable|string',
            'quantity'                  => 'nullable|integer|min:1|max:1000',
        ]);

        // Normalizar flat -> items[]
        if (empty($v['items'])) {
            $v['items'] = [[
                'product_reference' => $v['product_reference'],
                'variant_sku'       => $v['variant_sku'] ?? null,
                'location_id'       => $v['location_id'] ?? null,
                'quantity'          => (int) ($v['quantity'] ?? 1),
            ]];
        }

        try {
            return response()->json($this->adapter->reserve($v), 201);
        } catch (ProductReferenceNotFound $e) {
            return response()->json([
                'error'   => 'product_not_found',
                'message' => $e->getMessage(),
            ], 404);
        } catch (\DomainException $e) {
            return response()->json([
                'error'  => 'insufficient_stock',
                'reason' => $e->getMessage(),
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('E7 reserve failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al reservar.'], 500);
        }
    }

    public function confirm(Request $request, string $id)
    {
        $v = $request->validate([
            'delivered_at'    => 'nullable|date',
            'delivered_by'    => 'nullable|string|max:100',
            'idempotency_key' => 'required|string|min:8|max:128',
        ]);
        try {
            return response()->json($this->adapter->confirm($id, $v), 200);
        } catch (\DomainException $e) {
            return response()->json(['error' => 'invalid_state', 'message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            Log::error('E7 confirm failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al confirmar.'], 500);
        }
    }

    public function release(Request $request, string $id)
    {
        $v = $request->validate([
            'reason'          => 'nullable|string|max:200',
            'idempotency_key' => 'required|string|min:8|max:128',
        ]);
        try {
            return response()->json($this->adapter->release($id, $v), 200);
        } catch (\DomainException $e) {
            return response()->json(['error' => 'invalid_state', 'message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            Log::error('E7 release failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al liberar.'], 500);
        }
    }

    public function show(Request $request, string $id)
    {
        $r = $this->adapter->show($id);
        if ($r === null) {
            return response()->json(['message' => 'Reserva no encontrada'], 404);
        }
        return response()->json($r, 200);
    }
}