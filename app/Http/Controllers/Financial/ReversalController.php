<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\FinancialControlResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ReversalController extends Controller
{
    use FinancialControlResponses;

    public function show(Request $request, string $reversalId): JsonResponse
    {
        $transaction = FinancialTransaction::where('public_id', $reversalId)
            ->where('reference_type', 'REVERSO')->firstOrFail();
        return $this->ok($request, $this->data($transaction));
    }

    public function store(Request $request, string $transactionId, FinancialAdjustmentService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000', 'regex:/\S/u']]);
        $key = validator(['key' => $request->header('Idempotency-Key')], [
            'key' => ['required', 'string', 'max:255', 'regex:/\S/u'],
        ])->validate()['key'];
        $original = FinancialTransaction::where('public_id', $transactionId)->firstOrFail();
        try {
            $transaction = $service->reverse($original, $key, $data['reason'], $this->authenticatedActor($request));
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }
        return $this->ok($request, $this->data($transaction), $transaction->wasRecentlyCreated ? 201 : 200);
    }

    private function data(FinancialTransaction $transaction): array
    {
        return [
            'id' => strtolower($transaction->public_id),
            'original_transaction_id' => strtolower($transaction->original_transaction_id),
            'status' => $transaction->status->value,
            'executed_by' => $transaction->metadata['executed_by'] ?? null,
            'reason' => $transaction->metadata['reason'] ?? null,
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }
}
