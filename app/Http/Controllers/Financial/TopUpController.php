<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\TopUpService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TopUpController extends Controller
{
public function show(
    Request $request,
    string $topUpId
): JsonResponse {
    $topUp = \App\Domains\Financial\Models\TopUp::where(
        'public_id',
        $topUpId
    )->firstOrFail();

    return response()->json([
        'data' => [
            'id' => $topUp->public_id,
            'wallet_id' => $topUp->wallet_id,
            'folio' => $topUp->folio,
            'amount_cents' => $topUp->amount_cents,
            'currency' => $topUp->currency,
            'method' => $topUp->method->value,
            'status' => $topUp->status->value,
            'agent_id' => $topUp->agent_id,
            'external_reference' =>
                $topUp->external_reference,
            'created_at' =>
                $topUp->created_at?->toISOString(),
            'updated_at' =>
                $topUp->updated_at?->toISOString(),
        ],
        'meta' => [
            'request_id' => $request->header(
                'X-Request-Id',
                (string) str()->uuid()
            ),
            'api_version' => 'v1',
        ],
    ]);
}
 public function store(
        Request $request,
        TopUpService $topUpService
    ): JsonResponse {
        $validated = $request->validate([
            'wallet_id' => [
                'required',
                'string',
            ],
            'amount_cents' => [
                'required',
                'integer',
                'min:1',
            ],
            'method' => [
                'required',
                Rule::enum(TopUpMethod::class),
            ],
            'agent_id' => [
                'nullable',
                'string',
                'max:255',
            ],
            'external_reference' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $wallet = Wallet::where(
            'public_id',
            $validated['wallet_id']
        )->firstOrFail();

        $topUp = $topUpService->create(
            $wallet,
            $validated['amount_cents'],
            TopUpMethod::from($validated['method']),
            $validated['agent_id'] ?? null,
            $validated['external_reference'] ?? null
        );

        return response()->json([
            'data' => [
                'id' => $topUp->public_id,
                'wallet_id' => $topUp->wallet_id,
                'folio' => $topUp->folio,
                'amount_cents' => $topUp->amount_cents,
                'currency' => $topUp->currency,
                'method' => $topUp->method->value,
                'status' => $topUp->status->value,
                'agent_id' => $topUp->agent_id,
                'external_reference' =>
                    $topUp->external_reference,
                'created_at' =>
                    $topUp->created_at?->toISOString(),
            ],
            'meta' => [
                'request_id' => $request->header(
                    'X-Request-Id',
                    (string) str()->uuid()
                ),
                'api_version' => 'v1',
            ],
        ], 201);
    }

public function complete(
    Request $request,
    string $topUpId,
    TopUpService $topUpService
): JsonResponse {
    $request->validate([
        'idempotency_key' => [
            'nullable',
            'string',
            'max:255',
        ],
    ]);

    $idempotencyKey = $request->header(
        'Idempotency-Key'
    );

    if (! $idempotencyKey) {
        return response()->json([
            'message' =>
                'The Idempotency-Key header is required.',
        ], 422);
    }

    $topUp = \App\Domains\Financial\Models\TopUp::where(
        'public_id',
        $topUpId
    )->firstOrFail();

   try {
    $topUp = $topUpService->complete(
        $topUp,
        $idempotencyKey
    );
} catch (\InvalidArgumentException $exception) {
    return response()->json([
        'message' => $exception->getMessage(),
        'meta' => [
            'request_id' => $request->header(
                'X-Request-Id',
                (string) str()->uuid()
            ),
            'api_version' => 'v1',
        ],
    ], 409);
}

    return response()->json([
        'data' => [
            'id' => $topUp->public_id,
            'wallet_id' => $topUp->wallet_id,
            'folio' => $topUp->folio,
            'amount_cents' => $topUp->amount_cents,
            'currency' => $topUp->currency,
            'method' => $topUp->method->value,
            'status' => $topUp->status->value,
            'agent_id' => $topUp->agent_id,
            'external_reference' =>
                $topUp->external_reference,
            'created_at' =>
                $topUp->created_at?->toISOString(),
            'updated_at' =>
                $topUp->updated_at?->toISOString(),
        ],
        'meta' => [
            'request_id' => $request->header(
                'X-Request-Id',
                (string) str()->uuid()
            ),
            'api_version' => 'v1',
        ],
    ]);
 }
}
