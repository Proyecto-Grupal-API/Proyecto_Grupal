<?php

namespace App\Http\Controllers\Financial\Concerns;

use App\Domains\Financial\Models\FinancialLimit;
use App\Domains\Financial\Models\FinancialLimitChange;
use App\Domains\Financial\Models\Reconciliation;
use App\Domains\Financial\Models\ReconciliationDifference;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Support\FinancialCorrelation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Formato de respuesta compartido por los endpoints de 2.10.
 * Conserva la forma {data, meta{request_id, api_version}} de la API
 * financiera existente.
 */
trait FinancialControlResponses
{
    protected function authenticatedActor(Request $request): string
    {
        $clientId = $request->attributes->get('oauth_client_id');

        abort_unless(is_string($clientId) && trim($clientId) !== '', 401);

        return 'service:' . $clientId;
    }

    protected function meta(Request $request, array $extra = []): array
    {
        return array_merge([
            'request_id' => $request->header('X-Request-Id', (string) str()->uuid()),
            'api_version' => 'v1',
        ], $extra);
    }

    protected function ok(Request $request, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $this->meta($request),
        ], $status);
    }

    protected function paginated(Request $request, LengthAwarePaginator $page, callable $map): JsonResponse
    {
        return response()->json([
            'data' => collect($page->items())->map($map)->values(),
            'meta' => $this->meta($request, [
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                ],
            ]),
        ]);
    }

    /**
     * Respuesta 503 cuando un módulo externo (p. ej. roles) no responde.
     * El control no se omite: la operación se rechaza completa.
     */
    protected function dependencyUnavailable(Request $request, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'DEPENDENCY_UNAVAILABLE',
            'correlation_id' => app(FinancialCorrelation::class)->id(),
            'meta' => $this->meta($request),
        ], 503);
    }

    protected function conflict(Request $request, string $message, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $message,
        ], $extra, [
            'meta' => $this->meta($request),
        ]), 409);
    }

    protected function limitData(FinancialLimit $limit): array
    {
        return [
            'id' => $limit->public_id,
            'name' => $limit->name,
            'subject_type' => $limit->subject_type->value,
            'subject_id' => $limit->subject_id,
            'operation' => $limit->operation->value,
            'period' => $limit->period->value,
            'metric' => $limit->metric->value,
            'max_amount_cents' => $limit->max_amount_cents,
            'max_count' => $limit->max_count,
            'action' => $limit->action->value,
            'currency' => $limit->currency,
            'active' => $limit->active,
            'valid_from' => $limit->valid_from?->toISOString(),
            'valid_until' => $limit->valid_until?->toISOString(),
            'reason' => $limit->reason,
            'created_by' => $limit->created_by,
            'updated_by' => $limit->updated_by,
            'created_at' => $limit->created_at?->toISOString(),
            'updated_at' => $limit->updated_at?->toISOString(),
        ];
    }

    protected function alertData(TransactionAlert $alert, bool $withHistory = false): array
    {
        $data = [
            'id' => $alert->public_id,
            'alert_type' => $alert->alert_type->value,
            'status' => $alert->status->value,
            'limit_id' => $alert->limit_id,
            'wallet_id' => $alert->wallet_id,
            'transaction_id' => $alert->transaction_id,
            'ledger_entry_id' => $alert->ledger_entry_id,
            'operation' => $alert->operation->value,
            'reference_type' => $alert->reference_type,
            'reference_id' => $alert->reference_id,
            'amount_cents' => $alert->amount_cents,
            'limit_period' => $alert->limit_period?->value,
            'limit_metric' => $alert->limit_metric?->value,
            'limit_action' => $alert->limit_action?->value,
            'observed_value' => $alert->observed_value,
            'threshold_value' => $alert->threshold_value,
            'details' => $alert->details,
            'detected_at' => $alert->detected_at?->toISOString(),
            'status_changed_by' => $alert->status_changed_by,
            'status_changed_at' => $alert->status_changed_at?->toISOString(),
            'resolution_note' => $alert->resolution_note,
            // Evidencia (2.10 / REQ-E2-2.10-01)
            'outcome' => $alert->outcome?->value,
            'correlation_id' => $alert->correlation_id,
            'period_start' => $alert->period_start?->toISOString(),
            'period_end' => $alert->period_end?->toISOString(),
        ];

        if ($withHistory) {
            $data['history'] = $alert->statusChanges()
                ->orderBy('id')
                ->get()
                ->map(fn ($change) => [
                    'from_status' => $change->from_status?->value,
                    'to_status' => $change->to_status->value,
                    'actor_id' => $change->actor_id,
                    'note' => $change->note,
                    'created_at' => $change->created_at?->toISOString(),
                ])
                ->values();
        }

        return $data;
    }

    protected function reconciliationData(Reconciliation $reconciliation): array
    {
        return [
            'id' => $reconciliation->public_id,
            'business_date' => $reconciliation->business_date?->format('Y-m-d'),
            'timezone' => $reconciliation->timezone,
            'scope' => $reconciliation->scope->value,
            'scope_wallet_ids' => $reconciliation->scope_wallet_ids,
            'status' => $reconciliation->status->value,
            'started_at' => $reconciliation->started_at?->toISOString(),
            'finished_at' => $reconciliation->finished_at?->toISOString(),
            'executed_by' => $reconciliation->executed_by,
            'checks_executed' => $reconciliation->checks_executed,
            'differences_count' => $reconciliation->differences_count,
            'summary' => $reconciliation->summary,
            'error_message' => $reconciliation->error_message,
            'trigger_source' => $reconciliation->trigger_source?->value,
            'attempt' => $reconciliation->attempt,
            'idempotency_key' => $reconciliation->idempotency_key,
            'cash_status' => $reconciliation->cash_status?->value,
            'new_differences_count' => $reconciliation->new_differences_count,
            'recurring_differences_count' => $reconciliation->recurring_differences_count,
        ];
    }

    protected function differenceData(ReconciliationDifference $difference): array
    {
        return [
            'id' => $difference->public_id,
            'reconciliation_id' => $difference->reconciliation_id,
            'check_code' => $difference->check_code->value,
            'entity_type' => $difference->entity_type,
            'entity_id' => $difference->entity_id,
            'wallet_id' => $difference->wallet_id,
            'expected_cents' => $difference->expected_cents,
            'actual_cents' => $difference->actual_cents,
            'difference_cents' => $difference->difference_cents,
            'details' => $difference->details,
            'status' => $difference->status->value,
            'resolved_by' => $difference->resolved_by,
            'resolved_at' => $difference->resolved_at?->toISOString(),
            'resolution_note' => $difference->resolution_note,
            'business_date' => $difference->business_date?->format('Y-m-d'),
            'occurrences' => $difference->occurrences,
            'last_seen_reconciliation_id' => $difference->last_seen_reconciliation_id,
            'last_seen_at' => $difference->last_seen_at?->toISOString(),
            'seen_after_resolution' => $difference->seenAfterResolution(),
        ];
    }

    protected function limitChangeData(FinancialLimitChange $change): array
    {
        return [
            'id' => $change->public_id,
            'limit_id' => $change->limit_id,
            'change_type' => $change->change_type->value,
            'actor_id' => $change->actor_id,
            'reason' => $change->reason,
            'correlation_id' => $change->correlation_id,
            'before' => $change->before,
            'after' => $change->after,
            'created_at' => $change->created_at?->toISOString(),
        ];
    }
}
