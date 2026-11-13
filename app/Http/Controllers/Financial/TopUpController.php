<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\TopUpService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\RespondsWithCashOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TopUpController extends Controller
{
    use RespondsWithCashOperations;

    public function show(Request $request, string $topUpId): JsonResponse
    {
        return $this->cashOperationResponse($request, TopUp::where('public_id', $topUpId)->firstOrFail());
    }

    public function store(Request $request, TopUpService $topUpService): JsonResponse
    {
        $validated = $request->validate($this->cashOperationRules(TopUpMethod::class));
        $wallet = Wallet::where('public_id', $validated['wallet_id'])->firstOrFail();

        try {
            $topUp = $topUpService->create(
                $wallet,
                $validated['amount_cents'],
                TopUpMethod::from($validated['method']),
                $validated['agent_id'] ?? null,
                $validated['external_reference'] ?? null
            );
        } catch (FinancialDependencyUnavailableException|\InvalidArgumentException $exception) {
            return $this->cashOperationFailure($request, $exception);
        }

        return $this->cashOperationResponse($request, $topUp, 201, withUpdatedAt: false);
    }

    public function complete(Request $request, string $topUpId, TopUpService $topUpService): JsonResponse
    {
        $request->validate(['idempotency_key' => ['nullable', 'string', 'max:255']]);
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }
        $topUp = TopUp::where('public_id', $topUpId)->firstOrFail();

        try {
            $topUp = $topUpService->complete($topUp, $idempotencyKey);
        } catch (\InvalidArgumentException $exception) {
            // Sólo conflictos de estado (mensaje + meta, 409); otros errores no se transforman aquí.
            return $this->cashOperationConflict($request, $exception);
        }

        return $this->cashOperationResponse($request, $topUp);
    }
}
