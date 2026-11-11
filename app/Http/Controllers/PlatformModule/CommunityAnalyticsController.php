<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\AnalyticsEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;

final class CommunityAnalyticsController
{
    private const EVENTS = [
        'community.report.published.v1', 'community.report.withdrawn.v1',
        'community.survey.closed.v1', 'community.election.closed.v1',
    ];

    // Public aggregate keys only. Unknown keys never become UI copy or leak identifiers.
    private const METRIC_UNITS = [
        'eventos_iniciados' => 'count', 'eventos_cancelados' => 'count',
        'reservas_confirmadas' => 'count', 'reservas_espera' => 'count',
        'reservas_pago_pendiente' => 'count', 'asistencias' => 'count',
        'eventos_cobros_confirmados' => 'count', 'eventos_devoluciones_confirmadas' => 'count',
        'eventos_cobrado_bruto_centavos' => 'centavos_mxn', 'eventos_devuelto_centavos' => 'centavos_mxn',
        'eventos_cobrado_neto_centavos' => 'centavos_mxn',
        'solicitudes_enviadas' => 'count', 'solicitudes_pendiente' => 'count',
        'solicitudes_en_revision' => 'count', 'solicitudes_aprobada' => 'count',
        'solicitudes_rechazada' => 'count', 'solicitudes_retirada' => 'count', 'solicitudes_cancelada' => 'count',
        'aprobaciones_periodo' => 'count', 'personas_aprobadas' => 'count',
        'asignaciones_pendientes' => 'count', 'monto_pendiente_centavos' => 'centavos_mxn',
        'apoyos_entregados' => 'count', 'apoyos_entregados_locker' => 'count',
        'apoyos_entregados_impresiones' => 'count', 'apoyos_entregados_bono' => 'count',
        'mensajes_entregados' => 'count', 'mensajes_leidos' => 'count',
        'encuestas_cerradas' => 'count', 'encuestas_participaciones' => 'count',
        'votaciones_cerradas' => 'count', 'votaciones_participaciones' => 'count',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'organization_id' => ['sometimes', 'required', 'string', 'regex:/\A[a-fA-F0-9]{24}\z/'],
            'days' => ['sometimes', 'required', 'integer', 'between:1,366'],
            'page' => ['sometimes', 'required', 'integer', 'between:1,2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'between:1,100'],
        ]);
        $until = CarbonImmutable::now();
        $days = (int) ($filters['days'] ?? 30);
        $since = $until->subDays($days);
        $organization = isset($filters['organization_id']) ? strtolower($filters['organization_id']) : null;
        $reports = $processes = [];
        $linked = false;

        // Resolve current state BEFORE applying the lower window bound: never resurrect a
        // withdrawn report or a stale consultation revision. Future occurrences cannot act yet.
        // envelope_hash is the existing signed-ingest marker; it is not a new signature scheme.
        $events = AnalyticsEvent::where('source_domain', 'community')->whereIn('event_name', self::EVENTS)
            ->where('occurred_at', '<=', $until)->orderByDesc('occurred_at')->orderByDesc('_id')->cursor();
        foreach ($events as $event) {
            $row = $this->readEvent($event, $until);
            if ($row === null || ($organization !== null && $row['data']['organization_id'] !== $organization)) {
                continue;
            }
            $linked = true;
            $key = $row['data']['organization_id'].':'.$row['id'];
            if ($row['kind'] === 'report') {
                // At the same occurrence instant, withdrawal wins regardless of storage order.
                $rank = [$row['occurred'], $row['withdrawn'] ? 1 : 0, $row['event_id']];
                if (! isset($reports[$key]) || $rank > $reports[$key]['rank']) {
                    $reports[$key] = [...$row, 'rank' => $rank];
                }
            } else {
                $key = $row['kind'].':'.$key;
                $rank = [$row['revision'], $row['occurred'], $row['event_id']];
                if (! isset($processes[$key]) || $rank > $processes[$key]['rank']) {
                    $processes[$key] = [...$row, 'rank' => $rank];
                }
            }
        }

        $reports = array_values(array_filter($reports, fn ($row) => ! $row['withdrawn'] && $row['occurred']->gte($since)));
        usort($reports, fn ($a, $b) => ($b['occurred'] <=> $a['occurred'])
            ?: strcmp($a['data']['organization_id'].':'.$a['id'], $b['data']['organization_id'].':'.$b['id']));
        $stats = ['report_count' => count($reports), 'closed_surveys' => 0, 'closed_elections' => 0];
        foreach ($processes as $row) {
            if ($row['occurred']->gte($since)) {
                $stats[$row['kind'] === 'survey' ? 'closed_surveys' : 'closed_elections']++;
            }
        }
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 10);

        return response()->json([
            'linked' => $linked,
            'window' => [
                'days' => $days, 'since' => $since->toISOString(), 'until' => $until->toISOString(),
                'basis' => 'occurred_at', 'report_state' => 'current_until_now',
                'description' => 'Ventana inclusiva por occurred_at del último snapshot publicado. Estado vigente hasta ahora: un retiro posterior prevalece; se excluyen eventos futuros. Procesos por organización, tipo e ID, con la mayor revisión válida. No se suman métricas entre reportes.',
            ],
            'stats' => $linked ? $stats : null,
            'metric_units' => self::METRIC_UNITS,
            'reports' => [
                'data' => array_map(fn ($row) => $row['data'], array_slice($reports, ($page - 1) * $perPage, $perPage)),
                'current_page' => $page, 'per_page' => $perPage, 'total' => count($reports),
                'last_page' => max(1, (int) ceil(count($reports) / $perPage)),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function readEvent(AnalyticsEvent $event, CarbonImmutable $until): ?array
    {
        if ($event->source_domain !== 'community' || ! in_array($event->event_name, self::EVENTS, true)
            || ! is_string($event->envelope_hash) || ! preg_match('/\A[a-f0-9]{64}\z/', $event->envelope_hash)
            || ! is_string($event->event_id) || ! Str::isUuid($event->event_id)
            || ! empty($event->processing_error) || ! $this->objectId($event->aggregate_id)
            || ! ($event->getRawOriginal('occurred_at') instanceof UTCDateTime
                || $event->getRawOriginal('occurred_at') instanceof \DateTimeInterface)) {
            return null;
        }
        $payload = $event->payload;
        $occurred = $event->occurred_at;
        if (! is_array($payload) || ! $occurred || $occurred->gt($until)
            || ! $this->objectId($payload['organization_id'] ?? null)) {
            return null;
        }
        $occurred = CarbonImmutable::instance($occurred);
        $report = str_starts_with($event->event_name, 'community.report.');
        $idField = $report ? 'report_id' : 'consultation_id';
        $keys = $report ? ['organization_id', 'report_id', 'version', 'period', 'generated_at', 'metrics']
            : ['organization_id', 'consultation_id', 'revision', 'eligible_count', 'participation_count', 'closed_at'];
        if (! $this->exactKeys($payload, $keys) || ! $this->objectId($payload[$idField])
            || strtolower($event->aggregate_id) !== strtolower($payload[$idField])) {
            return null;
        }
        $date = $this->isoDate($payload[$report ? 'generated_at' : 'closed_at']);
        // MongoDB stores milliseconds; an ISO source may retain finer fractional precision.
        if ($date === null || $date->gt($occurred->addMicroseconds(999)) || $date->gt($until)) {
            return null;
        }
        $base = [
            'id' => strtolower($payload[$idField]), 'occurred' => $occurred,
            'event_id' => strtolower($event->event_id),
        ];
        if (! $report) {
            if (! is_int($payload['revision']) || $payload['revision'] < 1
                || ! $this->count($payload['eligible_count']) || ! $this->count($payload['participation_count'])
                || $payload['participation_count'] > $payload['eligible_count']) {
                return null;
            }

            return [...$base, 'kind' => $event->event_name === 'community.survey.closed.v1' ? 'survey' : 'election',
                'revision' => $payload['revision'], 'data' => ['organization_id' => strtolower($payload['organization_id'])]];
        }

        $period = $payload['period'];
        if ($payload['version'] !== 1 || ! is_array($period) || ! $this->exactKeys($period, ['start', 'end'])
            || ! $this->calendarDate($period['start']) || ! $this->calendarDate($period['end'])
            || $period['start'] > $period['end']
            || $period['end'] > $date->setTimezone('America/Mexico_City')->toDateString()
            || CarbonImmutable::parse($period['start'])->diffInDays(CarbonImmutable::parse($period['end'])) > 365
            || ! is_array($payload['metrics'])) {
            return null;
        }
        foreach ($payload['metrics'] as $key => $value) {
            if (! is_string($key) || ! $this->count($value)) {
                return null;
            }
        }

        return [...$base, 'kind' => 'report', 'withdrawn' => $event->event_name === 'community.report.withdrawn.v1',
            'data' => [
                'organization_id' => strtolower($payload['organization_id']), 'report_id' => $base['id'],
                'version' => 1, 'period' => $period, 'generated_at' => $date->toISOString(),
                'occurred_at' => $occurred->toISOString(),
                'metrics' => (object) array_intersect_key($payload['metrics'], self::METRIC_UNITS),
            ]];
    }

    private function objectId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-fA-F0-9]{24}\z/', $value) === 1;
    }

    private function count(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private function exactKeys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
    }

    private function calendarDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    private function isoDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $value)
            || ! $this->calendarDate(substr($value, 0, 10))) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Exception) {
            return null;
        }
    }
}
