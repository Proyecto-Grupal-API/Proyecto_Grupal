<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\AuditCorrelation;
use App\Models\PlatformModule\AuditLog;
use App\Models\PlatformModule\FraudFlag;
use App\Models\PlatformModule\PointsAccount;
use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\PointsPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GovernanceController
{
    /**
     * Listado de políticas de puntos (vencimiento, topes, periodos de gracia).
     */
    public function getPolicies(): JsonResponse
    {
        $policies = PointsPolicy::all();

        return response()->json($policies);
    }

    /**
     * Creación de política de gobierno de puntos.
     */
    public function storePolicy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'expiration_days' => 'nullable|integer|min:1',
            'expiration_batch_strategy' => 'nullable|string',
            'daily_cap_points' => 'nullable|integer|min:1',
            'max_redemptions_per_day' => 'nullable|integer|min:1',
            'reversal_grace_period_days' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:active,inactive',
            'config' => 'nullable|array',
        ]);

        $policy = PointsPolicy::create($validated);

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'policy.created',
            'entity_type' => 'PointsPolicy',
            'entity_id' => (string) $policy->_id,
            'after' => $policy->toArray(),
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'governance',
        ]);

        return response()->json(['success' => true, 'policy' => $policy], 201);
    }

    /**
     * Actualización de política de puntos.
     */
    public function updatePolicy(Request $request, string $id): JsonResponse
    {
        $policy = PointsPolicy::findOrFail($id);
        $before = $policy->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'expiration_days' => 'nullable|integer|min:1',
            'expiration_batch_strategy' => 'nullable|string',
            'daily_cap_points' => 'nullable|integer|min:1',
            'max_redemptions_per_day' => 'nullable|integer|min:1',
            'reversal_grace_period_days' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:active,inactive',
            'config' => 'nullable|array',
        ]);

        $policy->update($validated);

        $user = $request->attributes->get('platform_actor');
        AuditLog::create([
            'actor_id' => $user ? (string) $user->getKey() : 'system',
            'actor_role' => $user?->role ?? 'admin',
            'action' => 'policy.updated',
            'entity_type' => 'PointsPolicy',
            'entity_id' => (string) $policy->_id,
            'before' => $before,
            'after' => $policy->fresh()->toArray(),
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'governance',
        ]);

        return response()->json(['success' => true, 'policy' => $policy->fresh()]);
    }

    /**
     * Listado de alertas de fraude con filtros por severidad y estado.
     */
    public function getFraudFlags(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $query = FraudFlag::orderBy('created_at', 'desc');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }

        if ($studentId = $request->query('student_id')) {
            $query->where('student_id', $studentId);
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Detalle de una alerta de fraude.
     */
    public function showFraudFlag(string $id): JsonResponse
    {
        $flag = FraudFlag::with(['ledgerEntry', 'redemption'])->findOrFail($id);

        return response()->json($flag);
    }

    /**
     * Revisión y resolución de alerta de fraude con notas y auditoría.
     */
    public function reviewFraudFlag(Request $request, string $id): JsonResponse
    {
        $flag = FraudFlag::findOrFail($id);
        $before = $flag->toArray();

        $validated = $request->validate([
            'status' => 'required|string|in:open,under_review,resolved,dismissed',
            'notes' => 'required|string|max:1000',
        ]);

        $user = $request->attributes->get('platform_actor');

        $flag->update([
            'status' => $validated['status'],
            'notes' => ($flag->notes ? $flag->notes."\n" : '').'['.now()->toDateTimeString().' por '.($user->name ?? 'Admin').']: '.$validated['notes'],
            'reviewed_by' => (string) $user->getKey(),
            'reviewed_at' => now(),
        ]);

        AuditLog::create([
            'actor_id' => (string) $user->getKey(),
            'actor_role' => $user->role,
            'action' => 'fraud_flag.reviewed',
            'entity_type' => 'FraudFlag',
            'entity_id' => (string) $flag->_id,
            'before' => $before,
            'after' => $flag->fresh()->toArray(),
            'reason' => $validated['notes'],
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => 'governance',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Alerta de fraude actualizada correctamente.',
            'flag' => $flag->fresh(),
        ]);
    }

    /**
     * Ajuste manual de puntos (operación restringida y auditada).
     */
    public function manualAdjustment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|string',
            'amount' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:500',
            'idempotency_key' => 'nullable|string',
        ]);

        $user = $request->attributes->get('platform_actor');
        $studentId = $validated['student_id'];
        $amount = (int) $validated['amount'];
        $idempotencyKey = $validated['idempotency_key'] ?? (string) Str::uuid();

        // Si es reducción de puntos, verificar que no supere el balance disponible
        if ($amount < 0) {
            $available = (int) PointsLedger::where('student_id', $studentId)->sum('amount');
            if ($available + $amount < 0) {
                return response()->json([
                    'success' => false,
                    'error' => "El ajuste negativo ({$amount}) excede el saldo disponible ({$available}).",
                ], 422);
            }
        }

        $result = DB::connection('platform')->transaction(function () use ($studentId, $amount, $validated, $idempotencyKey, $user, $request) {
            $ledgerEntry = PointsLedger::create([
                'student_id' => $studentId,
                'idempotency_key' => $idempotencyKey,
                'type' => 'adjust',
                'amount' => $amount,
                'source_domain' => 'governance',
                'source_reference' => 'manual_adjustment',
                'related_ledger_id' => null,
                'rule_id' => null,
                'business_id' => null,
                'description' => "Ajuste manual de administración: {$validated['reason']}",
                'metadata' => [
                    'authorized_by' => (string) $user->getKey(),
                    'reason' => $validated['reason'],
                ],
            ]);

            $account = PointsAccount::firstOrCreate(
                ['student_id' => $studentId],
                ['balance' => 0, 'pending_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0]
            );

            if ($amount > 0) {
                $account->increment('balance', $amount);
                $account->increment('lifetime_earned', $amount);
            } else {
                $account->decrement('balance', abs($amount));
            }
            $account->update(['last_ledger_entry_id' => (string) $ledgerEntry->_id]);

            $correlationId = (string) Str::uuid();
            AuditCorrelation::create([
                'correlation_id' => $correlationId,
                'origin_domain' => 'governance',
                'description' => "Ajuste manual de puntos: {$validated['reason']}",
                'related_entities' => [
                    'points_ledger' => (string) $ledgerEntry->_id,
                    'points_account' => (string) $account->_id,
                ],
            ]);

            AuditLog::create([
                'actor_id' => (string) $user->getKey(),
                'actor_role' => $user->role,
                'action' => 'points.adjusted_manually',
                'entity_type' => 'PointsLedger',
                'entity_id' => (string) $ledgerEntry->_id,
                'after' => ['amount' => $amount, 'student_id' => $studentId],
                'reason' => $validated['reason'],
                'correlation_id' => $correlationId,
                'ip_address' => $request->ip(),
                'device' => $request->userAgent(),
                'source_domain' => 'governance',
            ]);

            return ['ledger' => $ledgerEntry, 'account' => $account->fresh()];
        });

        return response()->json([
            'success' => true,
            'message' => 'Ajuste manual aplicado y auditado correctamente.',
            'ledger' => $result['ledger'],
            'account' => $result['account'],
        ], 201);
    }
}
