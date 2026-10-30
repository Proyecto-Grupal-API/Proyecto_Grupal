<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpEventDispatcher
{
    protected string $secret;
    protected string $baseUrl;

    public function __construct()
    {
        // Usamos el secreto compartido que ellos indican en su contrato
        $this->secret = (string) env('TEAM_SERVICE_SHARED_SECRET', 'secreto_equipo4_eq3_2026');
        // URL exacta que nos indican (para Docker local)
        $this->baseUrl = (string) env('TEAM7_WEBHOOK_URL', 'http://host.docker.internal:8000/api/v1/integrations/ingest-event');
    }

    /**
     * Envía un evento al Equipo 7 con la firma HMAC exacta que solicitaron.
     */
    public function dispatch(string $eventType, array $payload): bool
    {
        $body = [
            'event_id'      => 'evt_inv_' . uniqid(),
            'event_type'    => $eventType,
            'source_domain' => 'inventory',
            'occurred_at'   => now()->toIso8601String(),
            'payload'       => $payload,
        ];

        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $path = '/api/v1/integrations/ingest-event';
        $bodyHash = hash('sha256', $bodyJson);
        
        // Firma exacta del contrato: {timestamp}|POST|/path|{sha256(body)}
        $signaturePayload = "{$timestamp}|POST|{$path}|{$bodyHash}";
        $signature = hash_hmac('sha256', $signaturePayload, $this->secret);

        try {
            $response = Http::withHeaders([
                'Content-Type'      => 'application/json',
                'Accept'            => 'application/json',
                'X-Team-Timestamp'  => $timestamp,
                'X-Team-Signature'  => $signature,
            ])->withBody($bodyJson, 'application/json')
              ->post($this->baseUrl);

            if ($response->successful()) {
                Log::info('Evento despachado a Eq 7 exitosamente', ['event_type' => $eventType, 'status' => $response->status()]);
                return true;
            }

            Log::warning('Fallo al despachar evento a Eq 7', [
                'event_type' => $eventType,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('Excepción al despachar evento a Eq 7', [
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }
}