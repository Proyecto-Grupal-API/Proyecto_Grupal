<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\AnalyticsEvent;
use App\Models\PlatformModule\AuditLog;
use App\Models\PlatformModule\IntegrationLog;
use App\Models\PlatformModule\WebhookEvent;
use App\Services\PlatformModule\CommerceEventProcessor;
use App\Services\PlatformModule\PlatformAuditTrail;
use App\Services\PlatformModule\PointsLedger\DTO\EarnPointsRequest;
use App\Services\PlatformModule\PointsLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    /** Reprocesa un evento que quedó en falla (por ejemplo, una venta de un negocio que aún no tenía regla activa). */
    public function retryWebhook(Request $request, string $id): JsonResponse
    {
        $webhook = WebhookEvent::findOrFail($id);
        if ($webhook->status !== 'failed') {
            return response()->json(['success' => false, 'error' => 'Sólo se reintentan eventos en falla.', 'webhook' => $webhook], 422);
        }
        $event = AnalyticsEvent::where('event_id', $webhook->event_id)->first();
        $processor = app(CommerceEventProcessor::class);
        if (! $event || ! $processor->handles((string) $event->event_name)) {
            return response()->json(['success' => false, 'error' => 'El evento original ya no está disponible para reprocesarse.', 'webhook' => $webhook], 422);
        }
        try {
            $result = $processor->handle($event);
        } catch (\Throwable $e) {
            report($e);
            $result = ['status' => 'failed', 'detail' => 'Error interno al procesar el evento.'];
        }
        $webhook->update(['status' => $result['status'], 'detail' => $result['detail'], 'retry_count' => (int) $webhook->retry_count + 1,
            'processed_at' => $result['status'] === 'failed' ? null : now()]);
        app(PlatformAuditTrail::class)->record($request, $request->attributes->get('platform_actor'), 'webhook.retried',
            'WebhookEvent', (string) $webhook->_id, 'integrations', ['after' => ['status' => $result['status'], 'detail' => $result['detail'], 'retry_count' => $webhook->retry_count]]);

        return response()->json(['success' => $result['status'] !== 'failed', 'message' => $result['detail'], 'error' => $result['status'] === 'failed' ? $result['detail'] : null,
            'webhook' => $webhook->fresh()], $result['status'] === 'failed' ? 422 : 200);
    }

    /** Salud de las integraciones (7.10): por dominio emisor, si tiene credencial, cuántos eventos llegaron y cuándo el último. */
    public function health(): JsonResponse
    {
        $labels = ['identity' => 'Identidad (E1)', 'wallet' => 'Finanzas (E2)', 'marketplace' => 'Tienda (E3)', 'inventory' => 'Inventarios (E4)',
            'services' => 'Servicios (E5)', 'community' => 'Comunidad (E6)'];
        try {
            DB::connection('platform')->getMongoDB()->command(['ping' => 1]);
            $database = 'ok';
        } catch (\Throwable) {
            $database = 'sin respuesta';
        }
        $domains = [];
        foreach ($labels as $domain => $label) {
            $secret = config('platform.ingest.secrets.'.$domain);
            // Recibido = eventos analíticos más sobres de auditoría entregados por el dominio (los que traen huella de sobre).
            $audits = fn () => AuditLog::where('source_domain', $domain)->whereNotNull('envelope_hash');
            $events = AnalyticsEvent::where('source_domain', $domain)->count() + $audits()->count();
            $last = AnalyticsEvent::where('source_domain', $domain)->orderBy('created_at', 'desc')->first()?->created_at;
            $lastAudit = $audits()->orderBy('created_at', 'desc')->first()?->created_at;
            $latest = collect([$last, $lastAudit])->filter()->max();
            $domains[] = ['domain' => $domain, 'label' => $label, 'credential' => is_string($secret) && strlen($secret) >= 20, 'events_total' => $events,
                'last_event_at' => $latest ? $latest->toIso8601String() : null, 'failed_webhooks' => WebhookEvent::where('source', $domain)->where('status', 'failed')->count()];
        }

        return response()->json(['ingest_enabled' => config('platform.ingest.enabled') === true, 'database' => $database, 'domains' => $domains,
            'webhooks' => ['total' => WebhookEvent::count(), 'failed' => WebhookEvent::where('status', 'failed')->count(),
                'processed' => WebhookEvent::where('status', 'processed')->count(), 'ignored' => WebhookEvent::where('status', 'ignored')->count()],
            'logs_total' => IntegrationLog::count()]);
    }

    /**
     * Ingesta de evento de venta desde otro equipo (Equipo 3 Marketplace, Servicios, etc.):
     * Valida contrato, registra AnalyticsEvent con idempotencia y acumula puntos automáticamente.
     */
    public function ingestEvent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_type' => 'required|string|in:order.completed,service.purchased,sale.confirmed',
            'source_domain' => 'required|string',
            'student_id' => 'required|string',
            'business_id' => 'required|string',
            'sale_amount' => 'required|numeric|min:0',
            'order_reference' => 'required|string',
            'idempotency_key' => 'required|string',
            'campaign_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        // Registrar o verificar idempotencia en analytics_events
        $existing = AnalyticsEvent::where('idempotency_key', $validated['idempotency_key'])->first();
        if ($existing && $existing->processed) {
            return response()->json([
                'success' => true,
                'message' => 'Evento ya procesado previamente (idempotente).',
                'replayed' => true,
            ]);
        }

        $analyticsEvent = AnalyticsEvent::firstOrCreate(
            ['idempotency_key' => $validated['idempotency_key']],
            [
                'source_domain' => $validated['source_domain'],
                'event_type' => $validated['event_type'],
                'entity_type' => 'Order',
                'entity_id' => $validated['order_reference'],
                'payload' => $validated,
                'processed' => false,
            ]
        );

        try {
            $ledgerResult = $this->ledger->earn(new EarnPointsRequest(
                studentId: $validated['student_id'],
                businessId: $validated['business_id'],
                sourceDomain: $validated['source_domain'],
                sourceReference: $validated['order_reference'],
                saleAmount: (float) $validated['sale_amount'],
                idempotencyKey: $validated['idempotency_key'],
                campaignId: $validated['campaign_id'] ?? null,
                metadata: $validated['metadata'] ?? [],
                actorId: $validated['student_id'],
                actorRole: 'system',
            ));

            $analyticsEvent->update([
                'processed' => true,
                'processed_at' => now(),
            ]);

            IntegrationLog::create([
                'domain' => $validated['source_domain'],
                'direction' => 'inbound',
                'endpoint' => '/api/v1/integrations/ingest-event',
                'status' => 'success',
                'attempt' => 1,
                'request_payload' => $validated,
                'response_payload' => ['points_earned' => $ledgerResult->ledgerEntry->amount],
                'correlation_id' => (string) Str::uuid(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Evento de venta procesado y puntos acumulados.',
                'points_earned' => $ledgerResult->ledgerEntry->amount,
                'new_balance' => $ledgerResult->account->balance,
            ], 201);

        } catch (\Throwable $e) {
            $analyticsEvent->update([
                'processing_error' => $e->getMessage(),
            ]);

            IntegrationLog::create([
                'domain' => $validated['source_domain'],
                'direction' => 'inbound',
                'endpoint' => '/api/v1/integrations/ingest-event',
                'status' => 'failed',
                'attempt' => 1,
                'request_payload' => $validated,
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
