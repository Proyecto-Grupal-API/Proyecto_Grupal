<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\RewardProgramMembership;
use App\Services\PlatformModule\PlatformAuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessController
{
    /**
     * Listado de negocios participantes en el programa de recompensas (módulo 7.2).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $query = RewardProgramMembership::with(['earningRules'])->orderBy('created_at', 'desc');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('business_id', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Adhesión de un nuevo negocio al programa.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'required|string|max:100|unique:platform.reward_program_memberships,business_id',
            'business_name' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'contact_email' => 'nullable|email',
            'compensation_model' => 'nullable|string|in:fixed,commission,hybrid',
            'status' => 'nullable|string|in:active,inactive,pending',
        ]);

        $membership = RewardProgramMembership::create([
            'business_id' => $validated['business_id'],
            'business_name' => $validated['business_name'],
            'category' => $validated['category'] ?? 'General',
            'contact_email' => $validated['contact_email'] ?? null,
            'compensation_model' => $validated['compensation_model'] ?? 'fixed',
            'status' => $validated['status'] ?? 'active',
            'joined_at' => now(),
            'accepted_terms_at' => now(),
        ]);

        $user = $request->attributes->get('platform_actor');
        app(PlatformAuditTrail::class)->record($request, $user, 'business.registered', 'RewardProgramMembership', (string) $membership->_id, 'businesses', [
            'after' => $membership->toArray(),
        ]);

        return response()->json(['success' => true, 'business' => $membership], 201);
    }

    /**
     * Detalle de un negocio.
     */
    public function show(string $id): JsonResponse
    {
        $membership = RewardProgramMembership::with(['earningRules', 'rewards'])->where('business_id', $id)
            ->orWhere('_id', $id)
            ->firstOrFail();

        return response()->json($membership);
    }

    /**
     * Edición de información del negocio.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $membership = RewardProgramMembership::where('business_id', $id)
            ->orWhere('_id', $id)
            ->firstOrFail();

        $before = $membership->toArray();

        $validated = $request->validate([
            'business_name' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|max:100',
            'contact_email' => 'nullable|email',
            'compensation_model' => 'nullable|string|in:fixed,commission,hybrid',
            'status' => 'nullable|string|in:active,inactive,suspended',
        ]);

        $membership->update($validated);

        $user = $request->attributes->get('platform_actor');
        app(PlatformAuditTrail::class)->record($request, $user, 'business.updated', 'RewardProgramMembership', (string) $membership->_id, 'businesses', [
            'before' => $before,
            'after' => $membership->fresh()->toArray(),
        ]);

        return response()->json(['success' => true, 'business' => $membership->fresh()]);
    }

    /**
     * Alternar estado (activo / inactivo / suspendido).
     */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $membership = RewardProgramMembership::where('business_id', $id)
            ->orWhere('_id', $id)
            ->firstOrFail();

        $beforeStatus = $membership->status;
        $newStatus = $request->input('status', $beforeStatus === 'active' ? 'inactive' : 'active');

        $membership->update([
            'status' => $newStatus,
            'deactivated_at' => $newStatus === 'inactive' ? now() : null,
            'deactivation_reason' => $request->input('reason'),
        ]);

        $user = $request->attributes->get('platform_actor');
        app(PlatformAuditTrail::class)->record($request, $user, 'business.status_changed', 'RewardProgramMembership', (string) $membership->_id, 'businesses', [
            'before' => ['status' => $beforeStatus],
            'after' => ['status' => $newStatus],
        ]);

        return response()->json(['success' => true, 'business' => $membership->fresh()]);
    }
}
