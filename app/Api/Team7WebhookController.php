<?php

namespace App\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Team7WebhookController extends Controller
{
    protected string $secret;
    protected int $allowedClockSkew;

    public function __construct()
    {
        $this->secret = (string) config('team4.service_secret', env('TEAM_SERVICE_SHARED_SECRET', 'secreto_equipo4_eq3_2026'));
        $this->allowedClockSkew = (int) env('TEAM_SERVICE_ALLOWED_CLOCK_SKEW', 300);
    }

    public function receive(Request $request)
    {
        $timestamp = $request->header('X-Team-Timestamp');
        $signature = $request->header('X-Team-Signature');

        if (!$timestamp || !$signature) {
            return response()->json(['error' => 'missing_headers', 'message' => 'Faltan headers de firma.'], 401);
        }

        // Validar tolerancia de reloj
        if (abs(time() - (int) $timestamp) > $this->allowedClockSkew) {
            return response()->json(['error' => 'timestamp_expired', 'message' => 'Timestamp fuera de tolerancia.'], 401);
        }

        // Calcular firma esperada
        $body = $request->getContent();
        $path = $request->getPathInfo();
        $bodyHash = hash('sha256', $body);
        $signaturePayload = "{$timestamp}|{$request->method()}|{$path}|{$bodyHash}";
        $expectedSignature = hash_hmac('sha256', $signaturePayload, $this->secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json(['error' => 'invalid_signature', 'message' => 'Firma HMAC inválida.'], 401);
        }

        $payload = $request->all();
        $eventId = $payload['event_id'] ?? null;
        $eventType = $payload['event_type'] ?? null;

        if (!$eventId || !$eventType) {
            return response()->json(['error' => 'invalid_payload', 'message' => 'Faltan event_id o event_type.'], 422);
        }

        // Idempotencia: verificar si el evento ya fue procesado
        $coll = \DB::connection('mongodb')->getCollection('integration_inbox');
        $existing = $coll->findOne(['event_id' => $eventId]);
        if ($existing) {
            return response()->json(['status' => 'already_processed', 'event_id' => $eventId], 200);
        }

        try {
            // Guardar el evento en la bandeja de entrada (inbox)
            $coll->insertOne([
                '_id' => new \MongoDB\BSON\ObjectId(),
                'event_id' => $eventId,
                'event_type' => $eventType,
                'source_domain' => $payload['source_domain'] ?? 'unknown',
                'occurred_at' => $payload['occurred_at'] ?? now()->toIso8601String(),
                'payload' => $payload['payload'] ?? [],
                'received_at' => new \MongoDB\BSON\UTCDateTime(now()),
                'status' => 'RECEIVED',
            ]);

            // Procesar según el tipo de evento
            switch ($eventType) {
                case 'inventory.movement_created':
                    // TODO: Procesar movimiento (quizás actualizar algo si Eq 7 nos manda)
                    Log::info('Eq 7: Movimiento de inventario recibido', ['event_id' => $eventId]);
                    break;
                case 'inventory.stock_updated':
                    Log::info('Eq 7: Stock actualizado', ['event_id' => $eventId]);
                    break;
                case 'inventory.adjustment_made':
                    Log::info('Eq 7: Ajuste de inventario', ['event_id' => $eventId]);
                    break;
                case 'inventory.cost_updated':
                    Log::info('Eq 7: Costo actualizado', ['event_id' => $eventId]);
                    break;
                case 'inventory.return_processed':
                    Log::info('Eq 7: Devolución procesada', ['event_id' => $eventId]);
                    break;
                case 'inventory.reservation.expired':
                    Log::info('Eq 7: Reserva expirada', ['event_id' => $eventId]);
                    break;
                default:
                    Log::warning('Eq 7: Tipo de evento no reconocido', ['event_type' => $eventType, 'event_id' => $eventId]);
                    break;
            }

            // Marcar como procesado
            $coll->updateOne(['event_id' => $eventId], ['$set' => ['status' => 'PROCESSED', 'processed_at' => new \MongoDB\BSON\UTCDateTime(now())]]);

            return response()->json(['status' => 'processed', 'event_id' => $eventId], 201);

        } catch (\Throwable $e) {
            Log::error('Eq 7: Error procesando evento', ['event_id' => $eventId, 'error' => $e->getMessage()]);
            $coll->updateOne(['event_id' => $eventId], ['$set' => ['status' => 'FAILED', 'error' => $e->getMessage()]]);
            return response()->json(['error' => 'processing_failed', 'message' => 'Error al procesar el evento.'], 500);
        }
    }
}