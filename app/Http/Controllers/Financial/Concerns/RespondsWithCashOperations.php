<?php

namespace App\Http\Controllers\Financial\Concerns;

use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Support\FinancialCorrelation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contrato JSON común de recargas y retiros (2.3/2.4/2.10). Antes estaba copiado línea a línea en
 * TopUpController y WithdrawalController; un solo lugar evita que ambos contratos diverjan.
 */
trait RespondsWithCashOperations
{
    protected function apiMeta(Request $request): array
    {
        return [
            'request_id' => $request->header('X-Request-Id', (string) str()->uuid()),
            'api_version' => 'v1',
        ];
    }

    protected function cashOperationData(TopUp|Withdrawal $operation, bool $withUpdatedAt = true): array
    {
        $data = [
            'id' => $operation->public_id,
            'wallet_id' => $operation->wallet_id,
            'folio' => $operation->folio,
            'amount_cents' => $operation->amount_cents,
            'currency' => $operation->currency,
            'method' => $operation->method->value,
            'status' => $operation->status->value,
            'agent_id' => $operation->agent_id,
            'external_reference' => $operation->external_reference,
            'created_at' => $operation->created_at?->toISOString(),
        ];
        if ($withUpdatedAt) {
            $data['updated_at'] = $operation->updated_at?->toISOString();
        }

        return $data;
    }

    /** @param class-string<\BackedEnum> $methodEnum */
    protected function cashOperationRules(string $methodEnum): array
    {
        return [
            'wallet_id' => ['required', 'string'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum($methodEnum)],
            'agent_id' => ['nullable', 'string', 'max:255'],
            'external_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function cashOperationResponse(Request $request, TopUp|Withdrawal $operation, int $status = 200, bool $withUpdatedAt = true): JsonResponse
    {
        return response()->json([
            'data' => $this->cashOperationData($operation, $withUpdatedAt),
            'meta' => $this->apiMeta($request),
        ], $status);
    }

    /** Traduce los rechazos de dominio a la misma respuesta que antes daba cada controlador. */
    protected function cashOperationFailure(Request $request, \Throwable $exception): JsonResponse
    {
        if ($exception instanceof FinancialLimitExceededException) {
            // 2.10: operación bloqueada por un límite configurado.
            return response()->json(array_merge(
                ['message' => $exception->getMessage()],
                $exception->toArray(),
                ['meta' => $this->apiMeta($request)],
            ), 409);
        }
        if ($exception instanceof FinancialDependencyUnavailableException) {
            // 2.10: un límite por rol no pudo evaluarse; la operación se rechaza completa (no se omite el control).
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'DEPENDENCY_UNAVAILABLE',
                'correlation_id' => app(FinancialCorrelation::class)->id(),
                'meta' => $this->apiMeta($request),
            ], 503);
        }

        return $this->cashOperationConflict($request, $exception);
    }

    /** Conflicto de estado simple: mensaje + meta, 409. */
    protected function cashOperationConflict(Request $request, \Throwable $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage(), 'meta' => $this->apiMeta($request)], 409);
    }
}
