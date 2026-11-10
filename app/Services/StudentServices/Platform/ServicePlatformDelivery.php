<?php

namespace App\Services\StudentServices\Platform;

use App\Models\StudentServices\Platform\ServicePlatformEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Entrega al Equipo 7 los eventos analíticos pendientes del Equipo 5
 * (REQ-M5-E7-001, POST /api/v1/plataforma/eventos). La auditoría la publica
 * el piloto de Daniel (PlatformAuditDelivery); este emisor no la toca.
 *
 * Usa la misma configuración que ese piloto (platform.services_delivery) y
 * el mismo transporte: firma HMAC-SHA256 con el secreto del dominio
 * "services" sobre "timestamp|POST|/ruta|sha256(cuerpo)".
 *
 * Reglas de entrega (sección 5 de la respuesta del Equipo 7):
 *  - 200/202 con el mismo event_id y estado aceptado/duplicado: entregado.
 *  - 409/413/422: rechazado; no se reintenta sin revisar (contenido inválido).
 *  - 401/403/429/5xx, red o acuse incorrecto: se reintenta con espera
 *    creciente (1 min, 2, 4... hasta 1 h), siempre con el mismo event_id.
 */
class ServicePlatformDelivery
{
    public const EVENTS_PATH = '/api/v1/plataforma/eventos';

    public const DOMAIN = 'services';

    private const REJECTED_STATUSES = [409, 413, 422];

    private const MAX_BACKOFF_SECONDS = 3600;

    /**
     * @return array{enabled: bool, sent: int, retry: int, rejected: int}
     */
    public function deliver(int $limit = 100): array
    {
        $result = ['enabled' => false, 'sent' => 0, 'retry' => 0, 'rejected' => 0];

        if (config('platform.services_delivery.enabled') !== true) {
            return $result;
        }

        [$url, $secret] = $this->target();
        $result['enabled'] = true;

        $pending = ServicePlatformEvent::query()
            ->where('status', ServicePlatformEvent::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($pending as $event) {
            $outcome = $this->send($url, $secret, $event->envelope());
            $this->record($event, $outcome);
            $result[$outcome['state']]++;
        }

        return $result;
    }

    /**
     * Encabezados firmados para un cuerpo JSON ya serializado.
     *
     * @return array<string, string>
     */
    public function signedHeaders(string $path, string $json, string $secret, ?int $timestamp = null): array
    {
        $time = (string) ($timestamp ?? time());

        return [
            'X-Platform-Domain' => self::DOMAIN,
            'X-Platform-Timestamp' => $time,
            'X-Platform-Signature' => hash_hmac('sha256', $time.'|POST|'.$path.'|'.hash('sha256', $json), $secret),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{state: 'sent'|'retry'|'rejected', error: string|null}
     */
    private function send(string $url, string $secret, array $body): array
    {
        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->withoutRedirecting()
                ->withHeaders([
                    ...$this->signedHeaders(self::EVENTS_PATH, $json, $secret),
                    'X-Correlation-Id' => (string) $body['event_id'],
                ])
                ->withBody($json, 'application/json')
                ->post($url.self::EVENTS_PATH);
        } catch (Throwable) {
            // No se guarda el detalle: puede incluir la URL interna.
            return ['state' => 'retry', 'error' => 'transport_unavailable'];
        }

        $status = $response->status();

        if (in_array($status, [200, 202], true)) {
            $acknowledged = $response->json('event_id') === $body['event_id']
                && in_array($response->json('estado'), ['aceptado', 'duplicado'], true);

            return $acknowledged
                ? ['state' => 'sent', 'error' => null]
                : ['state' => 'retry', 'error' => 'invalid_receipt_'.$status];
        }

        if (in_array($status, self::REJECTED_STATUSES, true)) {
            $code = $response->json('codigo');

            return ['state' => 'rejected', 'error' => 'http_'.$status.(is_string($code) ? ':'.Str::limit($code, 40, '') : '')];
        }

        return ['state' => 'retry', 'error' => 'http_'.$status];
    }

    /**
     * @param  array{state: string, error: string|null}  $outcome
     */
    private function record(ServicePlatformEvent $event, array $outcome): void
    {
        $attempts = $event->attempts + 1;

        $event->update([
            'status' => match ($outcome['state']) {
                'sent' => ServicePlatformEvent::SENT,
                'rejected' => ServicePlatformEvent::REJECTED,
                default => ServicePlatformEvent::PENDING,
            },
            'attempts' => $attempts,
            'last_error' => $outcome['error'],
            'next_attempt_at' => $outcome['state'] === 'retry' ? $this->nextAttempt($attempts) : null,
            'sent_at' => $outcome['state'] === 'sent' ? now() : null,
        ]);
    }

    private function nextAttempt(int $attempts): CarbonInterface
    {
        $seconds = min(self::MAX_BACKOFF_SECONDS, 60 * (2 ** min($attempts - 1, 12)));

        return now()->addSeconds($seconds);
    }

    /**
     * URL del receptor y secreto del dominio, con la misma validación que el
     * piloto de auditoría: solo https (http únicamente al propio contenedor en
     * local/testing), sin credenciales, ruta ni query en la URL.
     *
     * @return array{0: string, 1: string}
     */
    private function target(): array
    {
        $url = rtrim((string) config('platform.services_delivery.url'), '/');
        $parts = parse_url($url);

        $valid = is_array($parts)
            && isset($parts['host'], $parts['scheme'])
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && empty($parts['path'])
            && ($parts['scheme'] === 'https'
                || (app()->environment(['local', 'testing'])
                    && $parts['scheme'] === 'http'
                    && in_array($parts['host'], ['app', 'localhost', '127.0.0.1'], true)));

        if (! $valid) {
            throw new RuntimeException('Origen del receptor del Equipo 7 inválido (PLATFORM_SERVICES_API_URL).');
        }

        $secret = config('platform.ingest.secrets.'.self::DOMAIN);

        if (! is_string($secret) || strlen($secret) < 20) {
            throw new RuntimeException('Falta la credencial de transporte del Equipo 5 (PLATFORM_SERVICES_SECRET, mínimo 20 caracteres).');
        }

        return [$url, $secret];
    }
}
