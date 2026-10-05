<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Domains\Financial\Services\LedgerService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialAdjustmentController extends Controller
{
    public function hold(
        Request $request,
        string $walletId,
        LedgerService $ledgerService
    ): JsonResponse {
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reference_type' => ['nullable', 'string', 'max:100'],
            'reference_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $idempotencyKey = $this->idempotencyKey($request);
        if (!$idempotencyKey) {
            return $this->error(
                $request,
                'El encabezado Idempotency-Key es obligatorio para crear una retención.'
            );
        }

        $wallet = Wallet::where(
            'public_id',
            $walletId
        )->firstOrFail();

        try {
            $transaction = $ledgerService->hold(
                $wallet,
                $validated['amount_cents'],
                $idempotencyKey,
                $validated['reference_type'] ?? null,
                $validated['reference_id'] ?? null,
                $validated['metadata'] ?? []
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($request, $exception->getMessage(), 409);
        }

        return $this->transactionResponse($request, $transaction, 201);
    }

    public function release(
        Request $request,
        string $transactionId,
        LedgerService $ledgerService
    ): JsonResponse {
        $idempotencyKey = $this->idempotencyKey($request);
        if (!$idempotencyKey) {
            return $this->error(
                $request,
                'El encabezado Idempotency-Key es obligatorio para liberar una retención.'
            );
        }

        $transaction = FinancialTransaction::where(
            'public_id',
            $transactionId
        )->firstOrFail();

        try {
            $released = $ledgerService->release(
                $transaction,
                $idempotencyKey
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($request, $exception->getMessage(), 409);
        }

        return $this->transactionResponse($request, $released);
    }

    public function refund(
        Request $request,
        string $transactionId,
        FinancialAdjustmentService $adjustmentService
    ): JsonResponse {
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $idempotencyKey = $this->idempotencyKey($request);
        if (!$idempotencyKey) {
            return $this->error(
                $request,
                'El encabezado Idempotency-Key es obligatorio para solicitar una devolución.'
            );
        }

        $transaction = FinancialTransaction::where(
            'public_id',
            $transactionId
        )->firstOrFail();

        try {
            $refund = $adjustmentService->refund(
                $transaction,
                $validated['amount_cents'],
                $idempotencyKey,
                $validated['reason'] ?? null
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($request, $exception->getMessage(), 409);
        }

        return $this->transactionResponse($request, $refund, 201);
    }

    public function reverse(
        Request $request,
        string $transactionId,
        FinancialAdjustmentService $adjustmentService
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $idempotencyKey = $this->idempotencyKey($request);
        if (!$idempotencyKey) {
            return $this->error(
                $request,
                'El encabezado Idempotency-Key es obligatorio para reversar una operación.'
            );
        }

        $transaction = FinancialTransaction::where(
            'public_id',
            $transactionId
        )->firstOrFail();

        try {
            $reversal = $adjustmentService->reverse(
                $transaction,
                $idempotencyKey,
                $validated['reason'] ?? null
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($request, $exception->getMessage(), 409);
        }

        return $this->transactionResponse($request, $reversal, 201);
    }

    private function transactionResponse(
        Request $request,
        FinancialTransaction $transaction,
        int $status = 200
    ): JsonResponse {
        $transaction->loadMissing([]);

        $entries = \App\Domains\Financial\Models\LedgerEntry::where(
            'transaction_id',
            $transaction->public_id
        )
            ->orderBy('id')
            ->get()
            ->map(function ($entry) {
                return [
                    'id' => $entry->public_id,
                    'wallet_id' => $entry->wallet_id,
                    'movement_type' => $entry->movement_type->value,
                    'amount_cents' => $entry->amount_cents,
                    'available_balance_after_cents' =>
                        $entry->available_balance_after_cents,
                    'held_balance_after_cents' =>
                        $entry->held_balance_after_cents,
                    'created_at' => $entry->created_at?->toISOString(),
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'id' => $transaction->public_id,
                'idempotency_key' => $transaction->idempotency_key,
                'status' => $transaction->status->value,
                'reference_type' => $transaction->reference_type,
                'reference_id' => $transaction->reference_id,
                'original_transaction_id' =>
                    $transaction->original_transaction_id,
                'metadata' => $transaction->metadata,
                'entries' => $entries,
                'created_at' => $transaction->created_at?->toISOString(),
                'updated_at' => $transaction->updated_at?->toISOString(),
            ],
            'meta' => $this->meta($request),
        ], $status);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return $key ? trim($key) : null;
    }

    private function error(
        Request $request,
        string $message,
        int $status = 422
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'meta' => $this->meta($request),
        ], $status);
    }

    private function meta(Request $request): array
    {
        return [
            'request_id' => $request->header(
                'X-Request-Id',
                (string) str()->uuid()
            ),
            'api_version' => 'v1',
        ];
    }
}
