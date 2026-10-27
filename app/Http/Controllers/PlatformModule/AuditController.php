<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\AuditCorrelation;
use App\Models\PlatformModule\AuditLog;
use App\Services\PlatformModule\PlatformActor as User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AuditController
{
    /**
     * Consulta paginada de registros de auditoría transversal (módulo 7.8).
     * Corrige el filtro de student_id y añade filtros por actor, acción, entidad y rango de fechas.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $user = $request->attributes->get('platform_actor');

        $query = AuditLog::orderBy('created_at', 'desc');

        // Si el usuario no tiene permiso de ver auditoría global, solo ve las suyas
        if ($user && ! $user->hasRole(User::ROLE_ADMIN, User::ROLE_PLATFORM_ADMIN, User::ROLE_AUDITOR)) {
            $query->where('actor_id', (string) $user->getKey());
        } elseif ($actorId = $request->query('actor_id')) {
            $query->where('actor_id', $actorId);
        }

        // Corrección del filtro student_id: busca en actor_id o en after.student_id
        if ($studentId = $request->query('student_id')) {
            $query->where(function ($q) use ($studentId) {
                $q->where('actor_id', $studentId)
                    ->orWhere('after.student_id', $studentId)
                    ->orWhere('entity_id', $studentId);
            });
        }

        if ($action = $request->query('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        if ($entityType = $request->query('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        if ($correlationId = $request->query('correlation_id')) {
            $query->where('correlation_id', $correlationId);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Detalle de un registro de auditoría.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('platform_actor');
        $log = AuditLog::findOrFail($id);
        abort_unless($actor->hasPermission('audit.view') || ($actor->hasPermission('audit.view_own') && $log->actor_id === $actor->getKey()), 403);
        if ($actor->hasPermission('audit.view')) {
            $log->load('correlation');
        }

        return response()->json($log);
    }

    /**
     * Trazabilidad de una operación completa mediante su correlation_id.
     */
    public function correlation(Request $request, string $correlationId): JsonResponse
    {
        abort_unless($request->attributes->get('platform_actor')->hasPermission('audit.view'), 403);
        $correlation = AuditCorrelation::where('correlation_id', $correlationId)->firstOrFail();
        $logs = AuditLog::where('correlation_id', $correlationId)->orderBy('created_at', 'asc')->get();

        return response()->json([
            'correlation' => $correlation,
            'logs' => $logs,
        ]);
    }
}
