<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\AuditLog;
use App\Models\PlatformModule\Reward;
use App\Services\PlatformModule\PlatformActor as User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardController
{
    /**
     * Catálogo de recompensas:
     * - Estudiantes y profesores solo ven recompensas con status='active'.
     * - Administradores y auditores ven todas (o filtran por status).
     * - Soporta búsqueda por nombre/descripción, filtro por negocio y rango de puntos.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $user = $request->attributes->get('platform_actor');

        $query = Reward::orderBy('created_at', 'desc');

        // Restricción por rol: estudiantes y profesores solo ven recompensas activas
        if (! $user || in_array($user->role, [User::ROLE_STUDENT, User::ROLE_PROFESSOR], true)) {
            $query->where('status', 'active');
        } elseif ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($businessId = $request->query('business_id')) {
            $query->where('business_id', $businessId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($maxPoints = $request->query('max_points')) {
            $query->where('points_required', '<=', (int) $maxPoints);
        }

        if ($minPoints = $request->query('min_points')) {
            $query->where('points_required', '>=', (int) $minPoints);
        }

        $rewards = $query->paginate($perPage);

        // Agrega stock disponible calculado a cada elemento
        $rewards->getCollection()->transform(function (Reward $reward) {
            $reward->available_stock = $reward->availableStock();

            return $reward;
        });

        return response()->json($rewards);
    }

    /**
     * Creación de recompensa con validación estricta y auditoría.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'points_required' => 'required_without:points_cost|nullable|integer|min:1',
            'points_cost' => 'required_without:points_required|nullable|integer|min:1',
            'stock' => 'nullable|integer|min:0',
            'is_physical' => 'nullable|boolean',
            'status' => 'nullable|string|in:active,inactive,draft',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'limit_per_user' => 'nullable|integer|min:1',
            'images' => 'nullable|array',
            'linked_product_reference' => 'nullable|string',
        ]);

        $points = $validated['points_required'] ?? $validated['points_cost'];

        $reward = Reward::create([
            'business_id' => $validated['business_id'] ?? null,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'points_required' => (int) $points,
            'stock' => (int) ($validated['stock'] ?? 0),
            'reserved_stock' => 0,
            'is_physical' => (bool) ($validated['is_physical'] ?? false),
            'status' => $validated['status'] ?? 'active',
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_to' => $validated['valid_to'] ?? null,
            'limit_per_user' => $validated['limit_per_user'] ?? null,
            'images' => $validated['images'] ?? [],
            'linked_product_reference' => $validated['linked_product_reference'] ?? null,
        ]);

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'reward.created',
            'entity_type' => 'Reward',
            'entity_id' => (string) $reward->getKey(),
            'after' => $reward->toArray(),
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'rewards',
        ]);

        return response()->json(['success' => true, 'reward' => $reward], 201);
    }

    /**
     * Detalle de una recompensa.
     */
    public function show(string $id): JsonResponse
    {
        $reward = Reward::findOrFail($id);
        $reward->available_stock = $reward->availableStock();

        return response()->json($reward);
    }

    /**
     * Actualización segura de recompensa (con validación de campos, sin $request->all()).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $reward = Reward::findOrFail($id);
        $before = $reward->toArray();

        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'points_required' => 'sometimes|nullable|integer|min:1',
            'points_cost' => 'sometimes|nullable|integer|min:1',
            'stock' => 'nullable|integer|min:0',
            'is_physical' => 'nullable|boolean',
            'status' => 'nullable|string|in:active,inactive,draft',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date',
            'limit_per_user' => 'nullable|integer|min:1',
            'images' => 'nullable|array',
            'linked_product_reference' => 'nullable|string',
        ]);

        if (isset($validated['points_cost']) && ! isset($validated['points_required'])) {
            $validated['points_required'] = $validated['points_cost'];
            unset($validated['points_cost']);
        }

        $reward->update($validated);

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'reward.updated',
            'entity_type' => 'Reward',
            'entity_id' => (string) $reward->getKey(),
            'before' => $before,
            'after' => $reward->fresh()->toArray(),
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'rewards',
        ]);

        return response()->json([
            'success' => true,
            'reward' => $reward->fresh(),
        ]);
    }

    /**
     * Eliminación de recompensa con auditoría.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $reward = Reward::findOrFail($id);
        $before = $reward->toArray();

        $reward->delete();

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'reward.deleted',
            'entity_type' => 'Reward',
            'entity_id' => $id,
            'before' => $before,
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'rewards',
        ]);

        return response()->json(['success' => true]);
    }
}
