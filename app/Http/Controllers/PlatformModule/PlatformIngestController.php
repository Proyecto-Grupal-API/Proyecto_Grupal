<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\AnalyticsEvent;
use App\Models\PlatformModule\AuditCorrelation;
use App\Models\PlatformModule\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PlatformIngestController
{
    private const DOMAINS = ['services', 'identity', 'wallet', 'marketplace', 'inventory', 'community'];

    public function storeEvent(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'event_id' => 'required|uuid',
            'event_name' => ['required', 'string', 'max:100', 'regex:/^[a-z]+(\.[a-z_]+)+\.v\d+$/'],
            'source_domain' => 'required|in:'.implode(',', self::DOMAINS),
            'aggregate_id' => 'required|string|max:100',
            'occurred_at' => 'required|date',
            'payload' => 'required|array',
        ]);

        if ($v->fails()) {
            return $this->error($request, 'sobre_invalido', $v->errors()->first(), 422, $v->errors()->toArray());
        }

        $d = $v->validated();
        $source = $request->attributes->get('platform_source');
        abort_unless(is_string($source), 401);
        abort_unless(($d['source_domain'] ?? $source) === $source, 403);
        $d['source_domain'] = $source;
        $this->assertMinimalPayload($d['payload'] ?? $d['before'] ?? []);
        $this->assertMinimalPayload($d['after'] ?? []);
        $digest = $this->digest($d);
        $d['envelope_hash'] = $digest;

        if (! Str::startsWith($d['event_name'], $d['source_domain'].'.')) {
            return $this->error($request, 'sobre_invalido', 'event_name debe empezar con source_domain.', 422);
        }

        if (Arr::hasAny($d['payload'], ['name', 'nombre', 'email', 'correo'])) {
            return $this->error($request, 'sobre_invalido', 'El payload no debe incluir nombres ni correos; usa solo ids.', 422);
        }

        try {
            $event = AnalyticsEvent::firstOrCreate(
                ['event_id' => $d['event_id']],
                [
                    'envelope_hash' => $digest,
                    'event_name' => $d['event_name'],
                    'source_domain' => $d['source_domain'],
                    'entity_type' => explode('.', $d['event_name'])[1] ?? 'unknown',
                    'entity_id' => $d['aggregate_id'],
                    'aggregate_id' => $d['aggregate_id'],
                    'event_type' => $d['event_name'],
                    'occurred_at' => $d['occurred_at'],
                    'payload' => $d['payload'],
                    'processed' => false,
                ]
            );
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'E11000')) {
                throw $e;
            }

            return $this->duplicate($d['event_id'], $digest, isset($d['event_name']) ? AnalyticsEvent::class : AuditLog::class);
        }

        if (! $event->wasRecentlyCreated) {
            return $this->duplicate($d['event_id'], $digest, AnalyticsEvent::class);
        }

        return response()->json(
            ['estado' => $event->wasRecentlyCreated ? 'aceptado' : 'duplicado', 'event_id' => $d['event_id']],
            $event->wasRecentlyCreated ? 202 : 200
        );
    }

    public function storeAudit(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'event_id' => 'required|uuid',
            'actor_id' => 'required|string|max:100',
            'actor_role' => 'required|string|max:50',
            'action' => 'required|string|max:100',
            'entity_type' => 'required|string|max:100',
            'entity_id' => 'required|string|max:100',
            'before' => 'nullable|array',
            'after' => 'nullable|array',
            'reason' => 'nullable|string|max:500',
            'correlation_id' => 'required|string|max:100',
            'ip_address' => 'nullable|ip',
            'device' => 'nullable|string|max:255',
            'occurred_at' => 'required|date',
        ]);

        if ($v->fails()) {
            return $this->error($request, 'sobre_invalido', $v->errors()->first(), 422, $v->errors()->toArray());
        }

        $d = $v->validated();
        $source = $request->attributes->get('platform_source');
        abort_unless(is_string($source), 401);
        abort_unless(($d['source_domain'] ?? $source) === $source, 403);
        $d['source_domain'] = $source;
        $this->assertMinimalPayload($d['payload'] ?? $d['before'] ?? []);
        $this->assertMinimalPayload($d['after'] ?? []);
        $digest = $this->digest($d);
        $d['envelope_hash'] = $digest;

        if (AuditLog::where('event_id', $d['event_id'])->exists()) {
            return $this->duplicate($d['event_id'], $digest, isset($d['event_name']) ? AnalyticsEvent::class : AuditLog::class);
        }

        try {
            AuditCorrelation::firstOrCreate(
                ['correlation_id' => $d['correlation_id']],
                ['origin_domain' => $source, 'description' => 'Operación sensible recibida por API', 'related_entities' => []]
            );
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'E11000')) {
                throw $e; // otra petición ya creó la correlación: es válido
            }
        }

        try {
            AuditLog::create($d);
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'E11000')) {
                throw $e;
            }

            return $this->duplicate($d['event_id'], $digest, isset($d['event_name']) ? AnalyticsEvent::class : AuditLog::class);
        }

        return response()->json(['estado' => 'aceptado', 'event_id' => $d['event_id']], 202);
    }

    private function digest(array $value): string
    {
        $sort = function (array $data) use (&$sort): array {
            if (! array_is_list($data)) {
                ksort($data);
            } foreach ($data as &$v) {
                if (is_array($v)) {
                    $v = $sort($v);
                }
            }

return $data;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function duplicate(string $id, string $digest, string $model): JsonResponse
    {
        $stored = $model::where('event_id', $id)->first();
        abort_unless($stored && is_string($stored->envelope_hash) && hash_equals($stored->envelope_hash, $digest), 409, 'El identificador ya existe con otro contenido.');

        return response()->json(['estado' => 'duplicado', 'event_id' => $id]);
    }

    private function assertMinimalPayload(array $value): void
    {
        foreach ($value as $key => $entry) {
            abort_if(is_string($key) && in_array(strtolower($key), ['name', 'nombre', 'email', 'correo', 'password', 'token', 'secret', 'documento', 'expediente'], true), 422, 'Utiliza identificadores y estados; no datos personales.');
            if (is_array($entry)) {
                $this->assertMinimalPayload($entry);
            }
        }
    }

    private function error(Request $request, string $codigo, string $mensaje, int $status, array $detalles = []): JsonResponse
    {
        return response()->json(array_filter([
            'codigo' => $codigo,
            'mensaje' => $mensaje,
            'correlacion_id' => $request->header('X-Correlation-Id') ?: (string) Str::uuid(),
            'detalles' => $detalles ?: null,
        ]), $status);
    }
}
