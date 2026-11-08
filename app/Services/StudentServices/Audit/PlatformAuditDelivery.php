<?php

namespace App\Services\StudentServices\Audit;

use App\Models\StudentServices\Audit\ServiceAuditLog;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlatformAuditDelivery
{
    private const PATH = '/api/v1/plataforma/auditoria';

    private const PILOT_ACTIONS = [
        'library.fine.waived' => 'library_fine',
        'library.fine.cancelled' => 'library_fine',
        'rental.deposit.charged' => 'rental',
    ];

    public function deliver(int $limit = 100): array
    {
        if (config('platform.services_delivery.enabled') !== true) {
            return ['enabled' => false, 'sent' => 0, 'failed' => 0];
        }

        $cutoff = config('platform.services_delivery.since');
        $format = 'Y-m-d\TH:i:s\Z';
        $since = is_string($cutoff) ? DateTimeImmutable::createFromFormat('!'.$format, $cutoff, new DateTimeZone('UTC')) : false;
        if (! $since || $since->format($format) !== $cutoff) {
            throw new InvalidArgumentException('Define el corte UTC del piloto, por ejemplo 2026-11-08T18:00:00Z.');
        }

        $url = rtrim((string) config('platform.services_delivery.url'), '/');
        $parts = parse_url($url);
        $secret = config('platform.ingest.secrets.services');
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || array_intersect_key($parts, array_flip(['user', 'pass', 'path', 'query', 'fragment'])) !== []
            || ! ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http'
                && app()->environment(['local', 'testing']) && in_array($parts['host'], ['app', 'localhost', '127.0.0.1'], true)))
            || ! is_string($secret) || strlen($secret) < 20) {
            throw new InvalidArgumentException('Configura un origen válido de E7 y la credencial exclusiva de Servicios.');
        }

        $rows = ServiceAuditLog::where('source_domain', 'services')->whereNull('published_at')
            ->where('occurred_at', '>=', $since)->whereIn('action', array_keys(self::PILOT_ACTIONS))
            ->orderBy('last_publication_attempt_at')->orderBy('_id')->limit($limit)->get();
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $pending = ServiceAuditLog::whereKey($row->getKey())->whereNull('published_at');
            $pending->update(['last_publication_attempt_at' => now()]);
            if ($this->publish($row, $url, $secret)) {
                $sent += $pending->update(['published_at' => now()]);
            } else {
                $failed++;
            }
        }

        return ['enabled' => true, 'sent' => $sent, 'failed' => $failed];
    }

    private function publish(ServiceAuditLog $row, string $url, string $secret): bool
    {
        if (! is_string($row->event_id) || ! Str::isUuid($row->event_id)
            || $row->entity_type !== self::PILOT_ACTIONS[$row->action]
            || ! is_string($row->entity_id) || ! preg_match('/\A[a-f0-9]{24}\z/i', $row->entity_id)
            || ! $row->occurred_at) {
            return false;
        }

        // Only canonical identity IDs cross the boundary. Reasons and snapshots remain in E5.
        $actor = is_string($row->actor_id) && preg_match('/\A[a-f0-9]{24}\z/i', $row->actor_id)
            ? strtolower($row->actor_id) : 'not_recorded';
        $json = json_encode([
            'event_id' => $row->event_id, 'actor_id' => $actor, 'actor_role' => 'not_recorded',
            'action' => $row->action, 'entity_type' => $row->entity_type, 'entity_id' => $row->entity_id,
            'correlation_id' => 'services:'.$row->event_id, 'occurred_at' => $row->occurred_at->toISOString(),
        ], JSON_THROW_ON_ERROR);
        $time = (string) time();
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(10)->withoutRedirecting()->withHeaders([
                'X-Platform-Domain' => 'services', 'X-Platform-Timestamp' => $time,
                'X-Platform-Signature' => hash_hmac('sha256', $time.'|POST|'.self::PATH.'|'.hash('sha256', $json), $secret),
            ])->withBody($json, 'application/json')->post($url.self::PATH);
        } catch (ConnectionException) {
            return false;
        }

        return $response->json('event_id') === $row->event_id
            && (($response->status() === 202 && $response->json('estado') === 'aceptado')
                || ($response->status() === 200 && $response->json('estado') === 'duplicado'));
    }
}
