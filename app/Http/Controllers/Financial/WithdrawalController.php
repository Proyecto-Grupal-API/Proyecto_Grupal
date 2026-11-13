<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Services\WithdrawalService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\RespondsWithCashOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    use RespondsWithCashOperations;

    public function show(Request $request, string $withdrawalId): JsonResponse
    {
        return $this->cashOperationResponse($request, Withdrawal::where('public_id', $withdrawalId)->firstOrFail());
    }

    public function store(Request $request, WithdrawalService $withdrawalService): JsonResponse
    {
        $validated = $request->validate($this->cashOperationRules(WithdrawalMethod::class));
        $wallet = Wallet::where('public_id', $validated['wallet_id'])->firstOrFail();

        try {
            $withdrawal = $withdrawalService->create(
                $wallet,
                $validated['amount_cents'],
                WithdrawalMethod::from($validated['method']),
                $validated['agent_id'] ?? null,
                $validated['external_reference'] ?? null
            );
        } catch (FinancialDependencyUnavailableException|\InvalidArgumentException $exception) {
            return $this->cashOperationFailure($request, $exception);
        }

        return $this->cashOperationResponse($request, $withdrawal, 201, withUpdatedAt: false);
    }
}
