<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\AuditLog;
use App\Models\PlatformModule\EarningRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EarningRuleController
{
    /**
     * Listado de reglas de acumulación de puntos (módulo 7.3).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $query = EarningRule::orderBy('created_at', 'desc');

        if ($businessId = $request->query('business_id')) {
            $query->where('business_id', $businessId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('business_id', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Creación de regla de acumulación.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'required|string',
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|in:amount_spent,fixed,event',
            'points_per_unit' => 'nullable|integer|min:0',
            'unit_amount' => 'nullable|numeric|min:0',
            'fixed_points' => 'nullable|integer|min:0',
            'multiplier' => 'nullable|numeric|min:0.1',
            'max_points_per_operation' => 'nullable|integer|min:1',
            'daily_cap' => 'nullable|integer|min:1',
            'active_from' => 'nullable|date',
            'active_to' => 'nullable|date|after_or_equal:active_from',
            'status' => 'nullable|string|in:active,inactive,draft',
            'excluded_products' => 'nullable|array',
        ]);

        $rule = EarningRule::create([
            'business_id' => $validated['business_id'],
            'name' => $validated['name'],
            'type' => $validated['type'] ?? 'amount_spent',
            'points_per_unit' => (int) ($validated['points_per_unit'] ?? 1),
            'unit_amount' => (float) ($validated['unit_amount'] ?? 1.0),
            'fixed_points' => isset($validated['fixed_points']) ? (int) $validated['fixed_points'] : null,
            'multiplier' => (float) ($validated['multiplier'] ?? 1.0),
            'max_points_per_operation' => isset($validated['max_points_per_operation']) ? (int) $validated['max_points_per_operation'] : null,
            'daily_cap' => isset($validated['daily_cap']) ? (int) $validated['daily_cap'] : null,
            'active_from' => $validated['active_from'] ?? now(),
            'active_to' => $validated['active_to'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'excluded_products' => $validated['excluded_products'] ?? [],
        ]);

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'earning_rule.created',
            'entity_type' => 'EarningRule',
            'entity_id' => (string) $rule->_id,
            'after' => $rule->toArray(),
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'rules',
        ]);

        return response()->json(['success' => true, 'rule' => $rule], 201);
    }

    /**
     * Detalle de una regla.
     */
    public function show(string $id): JsonResponse
    {
        $rule = EarningRule::findOrFail($id);

        return response()->json($rule);
    }

    /**
     * Edición de regla.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $rule = EarningRule::findOrFail($id);
        $before = $rule->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'nullable|string|in:amount_spent,fixed,event',
            'points_per_unit' => 'nullable|integer|min:0',
            'unit_amount' => 'nullable|numeric|min:0',
            'fixed_points' => 'nullable|integer|min:0',
            'multiplier' => 'nullable|numeric|min:0.1',
            'max_points_per_operation' => 'nullable|integer|min:1',
            'daily_cap' => 'nullable|integer|min:1',
            'active_from' => 'nullable|date',
            'active_to' => 'nullable|date',
            'status' => 'nullable|string|in:active,inactive,draft',
            'excluded_products' => 'nullable|array',
        ]);

        $rule->update($validated);

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'earning_rule.updated',
            'entity_type' => 'EarningRule',
            'entity_id' => (string) $rule->_id,
            'before' => $before,
            'after' => $rule->fresh()->toArray(),
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'rules',
        ]);

        return response()->json(['success' => true, 'rule' => $rule->fresh()]);
    }

    /**
     * Alternar estado (activo / inactivo).
     */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $rule = EarningRule::findOrFail($id);
        $beforeStatus = $rule->status;
        $newStatus = $request->input('status', $beforeStatus === 'active' ? 'inactive' : 'active');

        $rule->update(['status' => $newStatus]);

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'earning_rule.status_changed',
            'entity_type' => 'EarningRule',
            'entity_id' => (string) $rule->_id,
            'before' => ['status' => $beforeStatus],
            'after' => ['status' => $newStatus],
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'rules',
        ]);

        return response()->json(['success' => true, 'rule' => $rule->fresh()]);
    }
}
