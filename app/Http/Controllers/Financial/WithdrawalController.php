<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Services\WithdrawalService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WithdrawalController extends Controller
{
    public function show(
        Request $request,
        string $withdrawalId
    ): JsonResponse {
        $withdrawal = Withdrawal::where(
            'public_id',
            $withdrawalId
        )->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $withdrawal->public_id,
                'wallet_id' => $withdrawal->wallet_id,
                'folio' => $withdrawal->folio,
                'amount_cents' => $withdrawal->amount_cents,
                'currency' => $withdrawal->currency,
                'method' => $withdrawal->method->value,
                'status' => $withdrawal->status->value,
                'agent_id' => $withdrawal->agent_id,
                'external_reference' =>
                    $withdrawal->external_reference,
                'created_at' =>
                    $withdrawal->created_at?->toISOString(),
                'updated_at' =>
                    $withdrawal->updated_at?->toISOString(),
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
        WithdrawalService $withdrawalService
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
                Rule::enum(WithdrawalMethod::class),
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

        try {
            $withdrawal = $withdrawalService->create(
                $wallet,
                $validated['amount_cents'],
                WithdrawalMethod::from($validated['method']),
                $validated['agent_id'] ?? null,
                $validated['external_reference'] ?? null
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
                'id' => $withdrawal->public_id,
                'wallet_id' => $withdrawal->wallet_id,
                'folio' => $withdrawal->folio,
                'amount_cents' => $withdrawal->amount_cents,
                'currency' => $withdrawal->currency,
                'method' => $withdrawal->method->value,
                'status' => $withdrawal->status->value,
                'agent_id' => $withdrawal->agent_id,
                'external_reference' =>
                    $withdrawal->external_reference,
                'created_at' =>
                    $withdrawal->created_at?->toISOString(),
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
}