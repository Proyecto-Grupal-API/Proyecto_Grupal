<?php

namespace App\Services\PlatformModule;

use App\Models\PlatformModule\AuditLog;
use Illuminate\Http\Request;

/**
 * Registro de auditoría de las acciones administrativas de E7 (7.6). Antes, cada controlador repetía el mismo
 * bloque AuditLog::create con actor, rol, IP y dispositivo (23 copias); aquí queda una sola definición (rúbrica 5.6).
 */
final class PlatformAuditTrail
{
    /**
     * @param  object|null  $actor  Actor de plataforma (getKey() y role) o null para acciones del sistema.
     * @param  array<string, mixed>  $details  before/after/reason/correlation_id y otros datos de la acción.
     */
    public function record(Request $request, ?object $actor, string $action, string $entityType, string $entityId,
        string $sourceDomain, array $details = []): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actor ? (string) $actor->getKey() : 'system',
            'actor_role' => $actor?->role ?? 'admin',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            ...$details,
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
            'source_domain' => $sourceDomain,
        ]);
    }
}
