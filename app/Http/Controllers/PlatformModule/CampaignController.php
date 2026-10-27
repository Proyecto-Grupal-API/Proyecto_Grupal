<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\AuditLog;
use App\Models\PlatformModule\PointCampaign;
use App\Services\PlatformModule\PlatformActor as User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController
{
    /**
     * Listado de campañas:
     * - Estudiantes: solo campañas activas y vigentes.
     * - Profesores: activas + las académicas propuestas por ellos.
     * - Administradores: todas con filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $user = $request->attributes->get('platform_actor');

        $query = PointCampaign::orderBy('created_at', 'desc');

        if (! $user || $user->role === User::ROLE_STUDENT) {
            $query->where('status', 'active');
        } elseif ($user->role === User::ROLE_PROFESSOR) {
            $query->where(function ($q) use ($user) {
                $q->where('status', 'active')
                    ->orWhere('sponsor_id', (string) $user->getKey());
            });
        } elseif ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Creación de campaña por administrador.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'nullable|string|in:multiplier,bonus,academic,special',
            'sponsor_type' => 'nullable|string|in:platform,business,academic',
            'sponsor_id' => 'nullable|string',
            'multiplier' => 'required|numeric|min:1.0|max:10.0',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'status' => 'nullable|string|in:active,draft,inactive',
            'scope' => 'nullable|string|in:global,business,department',
            'scope_ids' => 'nullable|array',
        ]);

        $campaign = PointCampaign::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'] ?? 'multiplier',
            'sponsor_type' => $validated['sponsor_type'] ?? 'platform',
            'sponsor_id' => $validated['sponsor_id'] ?? null,
            'multiplier' => (float) $validated['multiplier'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'scope' => $validated['scope'] ?? 'global',
            'scope_ids' => $validated['scope_ids'] ?? [],
        ]);

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'campaign.created',
            'entity_type' => 'PointCampaign',
            'entity_id' => (string) $campaign->_id,
            'after' => $campaign->toArray(),
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'campaigns',
        ]);

        return response()->json(['success' => true, 'campaign' => $campaign], 201);
    }

    /**
     * Propuesta de campaña académica por parte de un profesor (queda en borrador/pendiente).
     */
    public function propose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:1000',
            'multiplier' => 'nullable|numeric|min:1.0|max:5.0',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'scope_ids' => 'nullable|array', // asignaturas o grupos
        ]);

        $user = $request->attributes->get('platform_actor');

        $campaign = PointCampaign::create([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'type' => 'academic',
            'sponsor_type' => 'academic',
            'sponsor_id' => (string) $user->getKey(),
            'multiplier' => (float) ($validated['multiplier'] ?? 1.5),
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'] ?? null,
            'status' => 'draft', // requiere aprobación de administración
            'scope' => 'global',
            'scope_ids' => $validated['scope_ids'] ?? [],
        ]);

        AuditLog::create([
            'actor_id' => (string) $user->getKey(),
            'actor_role' => $user->role,
            'action' => 'campaign.proposed_academic',
            'entity_type' => 'PointCampaign',
            'entity_id' => (string) $campaign->_id,
            'after' => $campaign->toArray(),
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'campaigns',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Campaña académica propuesta exitosamente. Pendiente de activación por administración.',
            'campaign' => $campaign,
        ], 201);
    }

    /**
     * Detalle de campaña.
     */
    public function show(string $id): JsonResponse
    {
        $campaign = PointCampaign::findOrFail($id);

        return response()->json($campaign);
    }

    /**
     * Actualización de campaña.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $campaign = PointCampaign::findOrFail($id);
        $before = $campaign->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'nullable|string|in:multiplier,bonus,academic,special',
            'multiplier' => 'nullable|numeric|min:1.0|max:10.0',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'status' => 'nullable|string|in:active,draft,inactive',
            'scope' => 'nullable|string|in:global,business,department',
            'scope_ids' => 'nullable|array',
        ]);

        $campaign->update($validated);

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'campaign.updated',
            'entity_type' => 'PointCampaign',
            'entity_id' => (string) $campaign->_id,
            'before' => $before,
            'after' => $campaign->fresh()->toArray(),
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'campaigns',
        ]);

        return response()->json(['success' => true, 'campaign' => $campaign->fresh()]);
    }

    /**
     * Alternar estado o activar borrador.
     */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $campaign = PointCampaign::findOrFail($id);
        $beforeStatus = $campaign->status;
        $newStatus = $request->input('status', $beforeStatus === 'active' ? 'inactive' : 'active');

        $campaign->update(['status' => $newStatus]);

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'campaign.status_changed',
            'entity_type' => 'PointCampaign',
            'entity_id' => (string) $campaign->_id,
            'before' => ['status' => $beforeStatus],
            'after' => ['status' => $newStatus],
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'campaigns',
        ]);

        return response()->json(['success' => true, 'campaign' => $campaign->fresh()]);
    }
}
