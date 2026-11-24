<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\AuditCorrelation;
use App\Models\PlatformModule\AuditLog;
use App\Services\PlatformModule\PlatformActor as User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use MongoDB\BSON\Regex;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController
{
    /**
     * Consulta paginada de registros de auditoría transversal (módulo 7.8).
     * Corrige el filtro de student_id y añade filtros por actor, acción, entidad y rango de fechas.
     */
    private function filtered(Request $request)
    {
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

        // Búsqueda libre (literal, sin distinguir mayúsculas) sobre acción, entidad, cargo y dominio; exacta sobre identificadores.
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $pattern = new Regex(preg_quote(mb_substr($search, 0, 100), '/'), 'i');
            $query->where(function ($q) use ($pattern, $search) {
                foreach (['action', 'entity_type', 'actor_role', 'source_domain', 'reason'] as $field) {
                    $q->orWhere($field, 'regex', $pattern);
                }
                $q->orWhere('entity_id', $search)->orWhere('actor_id', $search)->orWhere('correlation_id', $search);
            });
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        return response()->json($this->filtered($request)->paginate($perPage));
    }

    /** Exportación CSV de la bitácora con los mismos filtros de la consulta (rúbrica 10.3 y 10.4). */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->attributes->get('platform_actor')->hasPermission('audit.view'), 403, 'No tienes permiso para exportar la auditoría.');
        $rows = $this->filtered($request)->limit(5000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['fecha', 'accion', 'entidad', 'id_entidad', 'actor', 'cargo', 'dominio', 'ip', 'correlacion']);
            foreach ($rows as $log) {
                fputcsv($out, [optional($log->created_at)->toIso8601String(), $log->action, $log->entity_type, $log->entity_id,
                    $log->actor_id, $log->actor_role, $log->source_domain, $log->ip_address, $log->correlation_id]);
            }
            fclose($out);
        }, 'auditoria-recompensas-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
