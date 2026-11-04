<?php

namespace App\Services\Integrations;

use App\Models\IntegrationLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cliente HTTP de integración con el Equipo 4 (Inventarios, Almacenes y Abastecimiento).
 *
 * Se comunica con la API de Equipo 4 bajo el protocolo HMAC-SHA256 acordado:
 * - Base URL: config('services.team4.base_url')
 * - Secret:   config('services.team4.shared_secret')
 * - Headers:  X-Team-Timestamp, X-Team-Signature
 */
class Team4InventoryService
{
    protected string $baseUrl;
    protected string $secret;
    protected string $businessId;
    protected int $timeout;
    protected string $driver;

    public function __construct()
    {
        $this->baseUrl    = rtrim((string) config('services.team4.base_url', 'http://localhost:8004'), '/');
        $this->secret     = (string) config('services.team4.shared_secret');
        $this->businessId = (string) config('services.team4.business_id', 'BUS-CD-SOUV-001');
        $this->timeout    = (int) config('services.team4.timeout', 5);
        $this->driver     = (string) config('services.team4.driver', 'team4');
    }

    /**
     * Indica si el cliente está en modo activo para consultar el servidor real.
     */
    public function isEnabled(): bool
    {
        return $this->driver === 'team4';
    }

    /**
     * Consulta la disponibilidad física de un producto en el inventario de Equipo 4.
     * GET /api/v1/inventory/availability
     */
    public function checkAvailability(string $productReference, int $quantity = 1, ?string $variantSku = null, ?string $businessId = null): array
    {
        if (! $this->isEnabled()) {
            return [
                'available'          => true,
                'available_quantity' => 999,
                'warehouse_id'       => 'simulated_warehouse',
                'simulated'          => true,
            ];
        }

        $query = [
            'product_reference' => $productReference,
            'quantity'          => $quantity,
            'business_id'       => $businessId ?? $this->businessId,
        ];
        if ($variantSku) {
            $query['variant_sku'] = $variantSku;
        }

        $endpoint = '/api/v1/inventory/availability?' . http_build_query($query);
        $response = $this->sendRequest('GET', $endpoint);

        if ($response->successful()) {
            return $response->json();
        }

        if ($response->status() === 404) {
            return [
                'available'          => false,
                'available_quantity' => 0,
                'error'              => 'product_not_found',
                'message'            => $response->json('message', 'Producto no encontrado en inventario.'),
            ];
        }

        return [
            'available'          => false,
            'available_quantity' => 0,
            'error'              => 'inventory_error',
            'message'            => $response->json('message', 'Error al consultar disponibilidad.'),
        ];
    }

    /**
     * Reserva stock para un canje de recompensa física en Equipo 4.
     * POST /api/v1/inventory/reservations
     *
     * Devuelve array con ['success' => true, 'reservation_id' => '...', 'status' => 'reserved']
     * o ['success' => false, 'error' => 'insufficient_stock', ...]
     */
    public function createReservation(
        string $originReference,
        string $productReference,
        int $quantity = 1,
        ?string $variantSku = null,
        ?string $expiresAt = null,
        ?string $idempotencyKey = null,
        ?string $businessId = null
    ): array {
        $idempotencyKey = $idempotencyKey ?? 'e7-reserve-' . $originReference;

        if (! $this->isEnabled()) {
            return [
                'success'          => true,
                'reservation_id'   => 'sim_res_' . Str::random(16),
                'status'           => 'reserved',
                'origin_reference' => $originReference,
                'simulated'        => true,
            ];
        }

        $payload = [
            'origin_reference'  => $originReference,
            'origin_type'       => 'REWARD',
            'product_reference' => $productReference,
            'variant_sku'       => $variantSku,
            'quantity'          => $quantity,
            'idempotency_key'   => $idempotencyKey,
            'business_id'       => $businessId ?? $this->businessId,
        ];

        if ($expiresAt) {
            $payload['expires_at'] = $expiresAt;
        }

        $response = $this->sendRequest('POST', '/api/v1/inventory/reservations', $payload);

        if ($response->status() === 201 || $response->successful()) {
            $data = $response->json();
            return [
                'success'          => true,
                'reservation_id'   => $data['reservation_id'] ?? ($data['_id'] ?? null),
                'status'           => $data['status'] ?? 'reserved',
                'origin_reference' => $originReference,
                'raw'              => $data,
            ];
        }

        if ($response->status() === 409) {
            return [
                'success' => false,
                'error'   => 'insufficient_stock',
                'message' => $response->json('reason', $response->json('message', 'Existencia insuficiente en inventario.')),
            ];
        }

        return [
            'success' => false,
            'error'   => 'reservation_failed',
            'status'  => $response->status(),
            'message' => $response->json('message', 'No se pudo completar la reserva de stock.'),
        ];
    }

    /**
     * Confirma el consumo definitivo del stock reservado cuando la recompensa es entregada al estudiante.
     * POST /api/v1/inventory/reservations/{id}/confirm
     */
    public function confirmReservation(string $reservationId, string $deliveredBy = 'Staff', ?string $idempotencyKey = null): array
    {
        if (str_starts_with($reservationId, 'sim_') || ! $this->isEnabled()) {
            return [
                'success'        => true,
                'reservation_id' => $reservationId,
                'status'         => 'consumed',
                'simulated'      => true,
            ];
        }

        $payload = [
            'delivered_by'    => $deliveredBy,
            'delivered_at'    => now()->toIso8601String(),
            'idempotency_key' => $idempotencyKey ?? ('e7-confirm-' . $reservationId),
        ];

        $response = $this->sendRequest('POST', "/api/v1/inventory/reservations/{$reservationId}/confirm", $payload);

        return [
            'success' => $response->successful(),
            'status'  => $response->json('status', 'consumed'),
            'raw'     => $response->json(),
        ];
    }

    /**
     * Libera el stock reservado en caso de cancelación de canje o expiración.
     * POST /api/v1/inventory/reservations/{id}/release
     */
    public function releaseReservation(string $reservationId, string $reason = 'CANCELLED', ?string $idempotencyKey = null): array
    {
        if (str_starts_with($reservationId, 'sim_') || ! $this->isEnabled()) {
            return [
                'success'        => true,
                'reservation_id' => $reservationId,
                'status'         => 'released',
                'simulated'      => true,
            ];
        }

        $payload = [
            'reason'          => $reason,
            'idempotency_key' => $idempotencyKey ?? ('e7-release-' . $reservationId),
        ];

        $response = $this->sendRequest('POST', "/api/v1/inventory/reservations/{$reservationId}/release", $payload);

        return [
            'success' => $response->successful(),
            'status'  => $response->json('status', 'released'),
            'raw'     => $response->json(),
        ];
    }

    /**
     * Envía la petición HTTP con la firma criptográfica HMAC-SHA256 requerida por Team4ServiceSignature.
     */
    protected function sendRequest(string $method, string $pathWithQuery, ?array $body = null): Response
    {
        // Separar path de query string para el cómputo de la firma (la spec de Eq.4 firma sin query string)
        $parsedUrl  = parse_url($pathWithQuery);
        $cleanPath  = '/' . ltrim($parsedUrl['path'] ?? $pathWithQuery, '/');
        $fullUrl    = $this->baseUrl . $pathWithQuery;

        $bodyString = ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH']))
            ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        $timestamp  = (string) time();
        $bodyHash   = hash('sha256', $bodyString);
        $payload    = $timestamp . '|' . strtoupper($method) . '|' . $cleanPath . '|' . $bodyHash;
        $signature  = hash_hmac('sha256', $payload, $this->secret);

        $headers = [
            'X-Team-Timestamp' => $timestamp,
            'X-Team-Signature' => $signature,
            'Accept'           => 'application/json',
            'Content-Type'     => 'application/json',
        ];

        $correlationId = (string) Str::uuid();

        try {
            $client = Http::withHeaders($headers)
                ->timeout($this->timeout);

            if ($method === 'GET') {
                $response = $client->get($fullUrl);
            } else {
                $response = $client->withBody($bodyString, 'application/json')->send($method, $fullUrl);
            }

            IntegrationLog::create([
                'domain'            => 'inventory',
                'direction'         => 'outbound',
                'endpoint'          => $cleanPath,
                'status'            => $response->successful() ? 'success' : 'failed',
                'attempt'           => 1,
                'request_payload'   => $body ?? ['query' => $parsedUrl['query'] ?? ''],
                'response_payload'  => $response->json() ?? ['status' => $response->status()],
                'correlation_id'    => $correlationId,
            ]);

            return $response;

        } catch (\Throwable $e) {
            Log::error("Error de comunicación HTTP con Equipo 4 ({$method} {$cleanPath})", [
                'error' => $e->getMessage(),
            ]);

            IntegrationLog::create([
                'domain'          => 'inventory',
                'direction'       => 'outbound',
                'endpoint'        => $cleanPath,
                'status'          => 'failed',
                'attempt'         => 1,
                'request_payload' => $body ?? [],
                'error_message'   => $e->getMessage(),
                'correlation_id'  => $correlationId,
            ]);

            // Crear una respuesta simulada de error 503
            return new Response(
                new \GuzzleHttp\Psr7\Response(
                    503,
                    ['Content-Type' => 'application/json'],
                    json_encode(['message' => 'Servicio de inventario (Equipo 4) no disponible: ' . $e->getMessage()])
                )
            );
        }
    }
}
