<?php

namespace App\Services\StudentServices\Audit;

use App\Models\StudentServices\Audit\ServiceAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registra operaciones sensibles del Equipo 5. Toma el actor, la IP, el
 * dispositivo y la correlación de la petición en curso; un servicio por API
 * llega como `api:{client_id}`.
 *
 * Nunca rompe la operación: si la bitácora falla se reporta y se sigue.
 */
class ServiceAuditor
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        string $entityType,
        string $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $actorId = null,
    ): ?ServiceAuditLog {
        try {
            $request = request();

            return ServiceAuditLog::create([
                'event_id' => (string) Str::uuid(),
                'source_domain' => 'services',
                'actor_id' => $actorId ?? $this->currentActor($request),
                'actor_role' => null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'before' => $before,
                'after' => $after,
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'correlation_id' => $request->attributes->get('correlation_id')
                    ?? $request->header('X-Correlation-Id'),
                'ip_address' => $request->ip(),
                'device' => Str::limit((string) $request->userAgent(), 250, ''),
                'occurred_at' => now(),
                'published_at' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function currentActor(Request $request): ?string
    {
        $clientId = $request->attributes->get('oauth_client_id');

        if ($clientId !== null) {
            return 'api:'.$clientId;
        }

        $user = $request->user();

        return $user !== null ? (string) $user->getAuthIdentifier() : null;
    }
}
