<?php

namespace App\Http\Controllers\Api;

use App\Models\AnalyticsEvent;
use App\Models\AuditCorrelation;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

class PlatformIngestController
{
    private const DOMAINS = ['services', 'identity', 'wallet', 'marketplace', 'inventory', 'community'];

    public function storeEvent(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'event_id'      => 'required|uuid',
            'event_name'    => ['required', 'string', 'max:100', 'regex:/^[a-z]+(\.[a-z_]+)+\.v\d+$/'],
            'source_domain' => 'required|in:' . implode(',', self::DOMAINS),
            'aggregate_id'  => 'required|string|max:100',
            'occurred_at'   => 'required|date',
            'payload'       => 'required|array'
        ]);

        if ($v->fails()) {
            return $this->error($request, 'sobre_invalido', $v->errors()->first(), 422, $v->errors()->toArray());
        }

        $d = $v->validated();

        if (! Str::startsWith($d['event_name'], $d['source_domain'] . '.')) {
            return $this->error($request, 'sobre_invalido', 'event_name debe empezar con source_domain.', 422);
        }

        if (Arr::hasAny($d['payload'], ['name', 'nombre', 'email', 'correo'])) {
            return $this->error($request, 'sobre_invalido', 'El payload no debe incluir nombres ni correos; usa solo ids.', 422);
        }

        try {
            $event = AnalyticsEvent::firstOrCreate(
                ['event_id' => $d['event_id']],
                [
                    'event_name'    => $d['event_name'],
                    'source_domain' => $d['source_domain'],
                    'entity_type'   => explode('.', $d['event_name'])[1] ?? 'unknown',
                    'entity_id'     => $d['aggregate_id'],
                    'aggregate_id'  => $d['aggregate_id'],
                    'event_type'    => $d['event_name'],
                    'occurred_at'   => $d['occurred_at'],
                    'payload'       => $d['payload'],
                    'processed'     => false,
                ]
            );
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'E11000')) {
                throw $e;
            }
            return response()->json(['estado' => 'duplicado', 'event_id' => $d['event_id']], 200);
        }

        return response()->json(
            ['estado' => $event->wasRecentlyCreated ? 'aceptado' : 'duplicado', 'event_id' => $d['event_id']],
            $event->wasRecentlyCreated ? 202 : 200
        );
    }

    public function storeAudit(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'event_id'       => 'required|uuid',
            'actor_id'       => 'required|string|max:100',
            'actor_role'     => 'required|string|max:50',
            'action'         => 'required|string|max:100',
            'entity_type'    => 'required|string|max:100',
            'entity_id'      => 'required|string|max:100',
            'before'         => 'nullable|array',
            'after'          => 'nullable|array',
            'reason'         => 'nullable|string|max:500',
            'correlation_id' => 'required|string|max:100',
            'ip_address'     => 'nullable|ip',
            'device'         => 'nullable|string|max:255',
            'occurred_at'    => 'required|date',
        ]);

        if ($v->fails()) {
            return $this->error($request, 'sobre_invalido', $v->errors()->first(), 422, $v->errors()->toArray());
        }

        $d = $v->validated();

        if (AuditLog::where('event_id', $d['event_id'])->exists()) {
            return response()->json(['estado' => 'duplicado', 'event_id' => $d['event_id']], 200);
        }

        try {
            AuditCorrelation::firstOrCreate(
                ['correlation_id' => $d['correlation_id']],
                ['origin_domain' => 'services', 'description' => 'Operación sensible recibida por API', 'related_entities' => []]
            );
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'E11000')) {
                throw $e; // otra petición ya creó la correlación: es válido
            }
        }

        try {
            AuditLog::create($d + ['source_domain' => 'services']);
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'E11000')) {
                throw $e;
            }
            return response()->json(['estado' => 'duplicado', 'event_id' => $d['event_id']], 200);
        }

        return response()->json(['estado' => 'aceptado', 'event_id' => $d['event_id']], 202);
    }

    private function error(Request $request, string $codigo, string $mensaje, int $status, array $detalles = []): JsonResponse
    {
        return response()->json(array_filter([
            'codigo'         => $codigo,
            'mensaje'        => $mensaje,
            'correlacion_id' => $request->header('X-Correlation-Id') ?: (string) Str::uuid(),
            'detalles'       => $detalles ?: null,
        ]), $status);
    }
}