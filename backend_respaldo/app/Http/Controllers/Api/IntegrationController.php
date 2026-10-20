<?php

namespace App\Http\Controllers\Api;

use App\Models\AnalyticsEvent;
use App\Models\AuditLog;
use App\Models\IntegrationLog;
use App\Models\WebhookEvent;
use App\Services\PointsLedger\DTO\EarnPointsRequest;
use App\Services\PointsLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrationController
{
    public function __construct(private readonly PointsLedgerService $ledger) {}

    /**
     * Listado de logs de integración y llamadas a servicios externos (módulo 7.10).
     */
    public function logs(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = IntegrationLog::orderBy('created_at', 'desc');

        if ($domain = $request->query('domain')) {
            $query->where('domain', $domain);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($direction = $request->query('direction')) {
            $query->where('direction', $direction);
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Listado de webhooks y eventos asíncronos recibidos.
     */
    public function webhooks(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = WebhookEvent::orderBy('created_at', 'desc');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Reintento de procesamiento de un webhook fallido.
     */
    public function retryWebhook(Request $request, string $id): JsonResponse
    {
        $webhook = WebhookEvent::findOrFail($id);

        $webhook->increment('retry_count');
        $webhook->update([
            'status'        => 'processed',
            'processed_at'  => now(),
            'next_retry_at' => null,
        ]);

        $user = $request->user();
        AuditLog::create([
            'actor_id'      => $user ? (string) $user->getKey() : 'system',
            'actor_role'    => $user?->role ?? 'admin',
            'action'        => 'webhook.retried',
            'entity_type'   => 'WebhookEvent',
            'entity_id'     => (string) $webhook->_id,
            'after'         => ['status' => 'processed', 'retry_count' => $webhook->retry_count],
            'ip_address'    => $request->ip(),
            'device'        => $request->userAgent(),
            'source_domain' => 'integrations',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evento reintentado y marcado como procesado.',
            'webhook' => $webhook->fresh(),
        ]);
    }

    /**
     * Ingesta de evento de venta desde otro equipo (Equipo 3 Marketplace, Servicios, etc.):
     * Valida contrato, registra AnalyticsEvent con idempotencia y acumula puntos automáticamente.
     */
    public function ingestEvent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_type'      => 'required|string|in:order.completed,service.purchased,sale.confirmed',
            'source_domain'   => 'required|string',
            'student_id'      => 'required|string',
            'business_id'     => 'required|string',
            'sale_amount'     => 'required|numeric|min:0',
            'order_reference' => 'required|string',
            'idempotency_key' => 'required|string',
            'campaign_id'     => 'nullable|string',
            'metadata'        => 'nullable|array',
        ]);

        // Registrar o verificar idempotencia en analytics_events
        $existing = AnalyticsEvent::where('idempotency_key', $validated['idempotency_key'])->first();
        if ($existing && $existing->processed) {
            return response()->json([
                'success'  => true,
                'message'  => 'Evento ya procesado previamente (idempotente).',
                'replayed' => true,
            ]);
        }

        $analyticsEvent = AnalyticsEvent::firstOrCreate(
            ['idempotency_key' => $validated['idempotency_key']],
            [
                'source_domain'   => $validated['source_domain'],
                'event_type'      => $validated['event_type'],
                'entity_type'     => 'Order',
                'entity_id'       => $validated['order_reference'],
                'payload'         => $validated,
                'processed'       => false,
            ]
        );

        try {
            $ledgerResult = $this->ledger->earn(new EarnPointsRequest(
                studentId:       $validated['student_id'],
                businessId:      $validated['business_id'],
                sourceDomain:    $validated['source_domain'],
                sourceReference: $validated['order_reference'],
                saleAmount:      (float) $validated['sale_amount'],
                idempotencyKey:  $validated['idempotency_key'],
                campaignId:      $validated['campaign_id'] ?? null,
                metadata:        $validated['metadata'] ?? [],
                actorId:         $validated['student_id'],
                actorRole:       'system',
            ));

            $analyticsEvent->update([
                'processed'    => true,
                'processed_at' => now(),
            ]);

            IntegrationLog::create([
                'domain'            => $validated['source_domain'],
                'direction'         => 'inbound',
                'endpoint'          => '/api/v1/integrations/ingest-event',
                'status'            => 'success',
                'attempt'           => 1,
                'request_payload'   => $validated,
                'response_payload'  => ['points_earned' => $ledgerResult->ledgerEntry->amount],
                'correlation_id'    => (string) Str::uuid(),
            ]);

            return response()->json([
                'success'       => true,
                'message'       => 'Evento de venta procesado y puntos acumulados.',
                'points_earned' => $ledgerResult->ledgerEntry->amount,
                'new_balance'   => $ledgerResult->account->balance,
            ], 201);

        } catch (\Throwable $e) {
            $analyticsEvent->update([
                'processing_error' => $e->getMessage(),
            ]);

            IntegrationLog::create([
                'domain'          => $validated['source_domain'],
                'direction'       => 'inbound',
                'endpoint'        => '/api/v1/integrations/ingest-event',
                'status'          => 'failed',
                'attempt'         => 1,
                'request_payload' => $validated,
                'error_message'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 422);
        }
    }
}
